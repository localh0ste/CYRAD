<?php
/**
 * CYRAD — Guest tickets (admin-generated vouchers).
 *
 * Mint N guest Wi-Fi credentials at once:
 *   * a local radcheck row (Cleartext-Password) so the voucher authenticates
 *     against FreeRADIUS exactly like a normal Wi-Fi user,
 *   * a data_limits row (billing: internet type + cap),
 *   * optional explicit radreply Tunnel-* attributes pinning the guest to a
 *     VLAN (usually the "guests" VLAN; the Guest SSID's BSSID profile also
 *     assigns it automatically),
 *   * a guest_tickets row tracking expiry/reuse.
 *
 * Expiry / reuse are enforced by admin/expire_guest_tickets.php (see
 * /etc/cron.d/cyrad-guest from setup-cyrad.sh).
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/guest_mail.php';
require_once __DIR__ . '/lib/guest_ticket_ops.php';
$conn->query("SET time_zone='+00:00'");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$org_id  = (int)($_SESSION['organization_id'] ?? 0);
$admin_u = $_SESSION['admin_user'] ?? 'admin';
$msg = $_SESSION['guest_flash'] ?? '';
unset($_SESSION['guest_flash']);

// Friendly guarantee: every org has a default "guests" VLAN to pin tickets to.
$guest_vlan = ['id' => 0, 'tag' => null, 'name' => ''];

function gen_guest_uname() { return 'guest_' . bin2hex(random_bytes(6)); }
function gen_guest_pass()  { return bin2hex(random_bytes(8)); }

// One CSRF token per session, checked on every state-changing POST below. The
// page had none at all, which left mint/revoke open to a cross-site request.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_ok = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string)($_POST['csrf_token'] ?? '');
    if ($sent === '' || !hash_equals((string)$_SESSION['csrf_token'], $sent)) {
        $csrf_ok = false;
        $msg = "<div class='msg err'>Invalid or expired form token. Reload the page and try again.</div>";
    }
}

// Email / SMTP settings for this organization (same shared card the super-admin
// organization editor uses). Handled before mint so a settings POST can never
// be mistaken for a ticket action.
require_once __DIR__ . '/lib/smtp_settings_ui.php';
$org_row  = $conn->query("SELECT org_name FROM organizations WHERE id=$org_id")->fetch_assoc();
$org_name = (string)($org_row['org_name'] ?? ('Org #' . $org_id));
$smtp = guest_mail_load($conn, $org_id);
[$smtp, $smtp_handled, $smtp_msg] = smtp_settings_handle($conn, $org_id, $smtp, $csrf_ok, $org_name);
if ($smtp_handled && $smtp_msg !== '') $msg = $smtp_msg;

if ($csrf_ok && isset($_POST['mint'])) {
    $count   = max(1, min(50, (int)($_POST['count'] ?? 1)));
    $hours   = max(0, min(87600, (int)($_POST['hours'] ?? 24)));
    $it      = in_array($_POST['internet_type'] ?? '', ['limited','unlimited']) ? $_POST['internet_type'] : 'limited';
    // Unlimited means internet_limit = 0 (the convention used by add_user,
    // edit_user and the AD auto-registration), never a leftover MB value from
    // the form.
    $limit_mb = ($it === 'limited') ? (int)($_POST['internet_limit'] ?? 250) : 0;
    if ($it === 'limited' && $limit_mb < 50) $limit_mb = 50;
    $bytes   = $limit_mb * 1024 * 1024;
    $vlan_id = null;
    $notes   = trim($_POST['notes'] ?? '');
    $expires = $hours > 0 ? gmdate('Y-m-d H:i:s', time() + $hours * 3600) : null;

    $ok = 0; $made = [];
    $conn->begin_transaction();
    try {
        $recipients = guest_mail_recipients((string)($_POST['recipient_emails'] ?? ''), $count);
        if (strlen($notes) > 255) throw new RuntimeException('Notes must be at most 255 bytes.');
        for ($i = 0; $i < $count; $i++) {
            $u = gen_guest_uname(); $p = gen_guest_pass();
            $st = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value, organization_id) VALUES (?,'Cleartext-Password',':=',?,?)");
            $st->bind_param("ssi", $u, $p, $org_id); $st->execute(); $st->close();
            $st = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit, organization_id, usage_reset_ts) VALUES (?,?,?,?,NOW())");
            $st->bind_param("ssii", $u, $it, $bytes, $org_id); $st->execute(); $st->close();
            $st = $conn->prepare("INSERT INTO user_profiles (username, organization_id, user_info, auth_source) VALUES (?,?,?, 'guest')");
            $note = 'Guest ticket' . ($notes !== '' ? " — $notes" : '');
            $st->bind_param("sis", $u, $org_id, $note); $st->execute(); $st->close();
            $st = $conn->prepare("INSERT INTO guest_tickets (username, password, organization_id, vlan_id, expires_at, max_uses, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $max_use = 1;
            $st->bind_param("ssiisiss", $u, $p, $org_id, $vlan_id, $expires, $max_use, $notes, $admin_u);
            $st->execute(); $st->close();
            $ticket_id = (int)$conn->insert_id;
            if ($recipients[$i] !== '') guest_mail_enqueue($conn, $org_id, $ticket_id, $recipients[$i]);
            $made[] = ['u' => $u, 'p' => $p, 'expires' => $expires];
            $ok++;
        }
        $conn->commit();
        $msg = "<div class='msg ok'>$ok guest ticket(s) created" .
                ($expires ? " (expire " . htmlspecialchars($expires) . " UTC)" : " (no expiry)") . ".</div>";
        $queued = count(array_filter($recipients));
        if ($queued) $msg .= "<div class='msg ok'>$queued email(s) queued. Check delivery status below.</div>";
    } catch (Throwable $e) {
        $conn->rollback();
        $msg = "<div class='msg err'>Mint failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

if ($csrf_ok && isset($_POST['send_ticket'])) {
    $conn->begin_transaction();
    try {
        $email = trim((string)($_POST['recipient_email'] ?? ''));
        if ($email !== '') guest_mail_enqueue($conn, $org_id, (int)$_POST['send_ticket'], $email);
        $conn->commit();
        $msg = $email === '' ? "<div class='msg ok'>No email supplied; delivery skipped.</div>" : "<div class='msg ok'>Email queued for delivery.</div>";
    } catch (Throwable $e) {
        $conn->rollback(); $msg = "<div class='msg err'>" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

if ($csrf_ok && isset($_POST['revoke'])) {
    $id = (int)($_POST['revoke'] ?? 0);
    $st = $conn->prepare("SELECT username FROM guest_tickets WHERE id=? AND organization_id=?");
    $st->bind_param("ii", $id, $org_id); $st->execute(); $st->bind_result($un); $st->fetch(); $st->close();
    if ($un) {
        $conn->begin_transaction();
        $st = $conn->prepare("UPDATE guest_tickets SET status='revoked' WHERE id=? AND organization_id=?");
        $st->bind_param("ii", $id, $org_id); $st->execute(); $st->close();
        $st = $conn->prepare("UPDATE guest_email_queue SET status='cancelled',last_error='Ticket revoked' WHERE ticket_id=? AND organization_id=? AND status='queued'");
        $st->bind_param('ii', $id, $org_id); $st->execute(); $st->close();
        $conn->query("UPDATE radcheck SET value=CONCAT('disabled_',value) WHERE username='" . $conn->real_escape_string($un) . "' AND attribute='Cleartext-Password' AND LEFT(value,9)<>'disabled_'");
        $conn->query("DELETE FROM radreply WHERE username='" . $conn->real_escape_string($un) . "'");
        $conn->commit();
        // Cut the device off now and keep it off until the ticket is purged:
        // live sessions are disconnected and every known MAC is blacklisted.
        $purgeDays = max(1, (int)(defined('CYRAD_GUEST_PURGE_DAYS') ? CYRAD_GUEST_PURGE_DAYS : 7));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $purgeDays * 86400);
        cyrad_kick_user($conn, (string)$un);
        foreach (cyrad_user_macs($conn, (string)$un) as $mac) {
            cyrad_blacklist_add($conn, $org_id, $mac, 'Guest ticket revoked', (string)$un, $expiresAt);
        }
        $msg = "<div class='msg ok'>Ticket " . htmlspecialchars($un) . " revoked. It can no longer sign in.</div>";
    }
}

if ($csrf_ok && isset($_POST['delete_ticket'])) {
    $id = (int)($_POST['delete_ticket'] ?? 0);
    [$ok, $info] = guest_ticket_delete($conn, $id, $org_id);
    $msg = $ok
        ? "<div class='msg ok'>Ticket " . htmlspecialchars($info) . " deleted (account and billing row removed; past accounting kept).</div>"
        : "<div class='msg err'>Delete failed: " . htmlspecialchars($info) . "</div>";
}

// Redirect after POST: refreshing a result page must not mint/send twice.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['guest_flash'] = $msg;
    $redirect_filter = in_array($_GET['filter'] ?? '', ['active','dead','all'], true) ? $_GET['filter'] : 'active';
    header('Location: guest_tickets.php?filter=' . $redirect_filter, true, 303);
    exit;
}

// Status filter: keep the working tickets in view without a wall of dead rows.
$filter = in_array($_GET['filter'] ?? '', ['all', 'active', 'dead'], true) ? $_GET['filter'] : 'active';
$filter_sql = ['all' => '', 'active' => " AND t.status = 'active'",
               'dead'  => " AND t.status IN ('revoked','expired')"][$filter];

// Counts for the filter tabs.
$counts = ['all' => 0, 'active' => 0, 'dead' => 0];
$cq = $conn->query("SELECT status, COUNT(*) c FROM guest_tickets WHERE organization_id = $org_id GROUP BY status");
if ($cq) {
    while ($c = $cq->fetch_assoc()) {
        $n = (int)$c['c'];
        $counts['all'] += $n;
        if ($c['status'] === 'active') $counts['active'] = $n; else $counts['dead'] += $n;
    }
    $cq->free();
}

// ticket listing with live login count from accounting
$list = $conn->query(
    "SELECT t.id, t.username, t.password, t.status, t.expires_at, t.max_uses,
            t.vlan_id, t.notes, t.created_at,
            e.recipient AS email_recipient,e.status AS email_status,e.last_error AS email_error,
            (SELECT COUNT(DISTINCT a.acctuniqueid) FROM radacct a WHERE a.username = t.username COLLATE utf8mb4_unicode_ci) AS logins
     FROM guest_tickets t
     LEFT JOIN guest_email_queue e ON e.id=(SELECT MAX(q.id) FROM guest_email_queue q WHERE q.ticket_id=t.id AND q.organization_id=t.organization_id)
     WHERE t.organization_id = $org_id $filter_sql
     ORDER BY t.created_at DESC, t.id DESC LIMIT 500"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — Guest Tickets</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>CONTROL PANEL</small></div></div>
    <?php require __DIR__ . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar">
      <div><strong>Guest Wi-Fi tickets</strong></div>
      <div class="org">Org #<?= $org_id ?></div>
    </header>
    <div class="content">
      <?= $msg ?>
      <div class="card">
        <h3>Mint guest tickets</h3>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div style="display:flex;gap:14px;flex-wrap:wrap">
            <div style="flex:1;min-width:140px">
              <label>Quantity</label>
              <input type="number" name="count" min="1" max="50" value="1" required>
            </div>
            <div style="flex:1;min-width:140px">
              <label>Validity (hours, 0 = never)</label>
              <input type="number" name="hours" min="0" value="24" required>
            </div>
            <div style="flex:1;min-width:180px">
              <label for="guest_internet_type">Internet type</label>
              <select name="internet_type" id="guest_internet_type">
                <option value="limited">Limited</option>
                <option value="unlimited">Unlimited</option>
              </select>
            </div>
            <div style="flex:1;min-width:150px" id="guestLimitWrap">
              <label for="guest_internet_limit">Limit (MB)</label>
              <input type="number" name="internet_limit" id="guest_internet_limit" min="50" value="250">
            </div>
            <div style="flex:2;min-width:220px">
              <label>Notes (e.g. "Seminar 12-14 Jul, visitors")</label>
              <input type="text" name="notes">
            </div>
          </div>
          <label>Recipient emails (optional; one line per ticket, in creation order)</label>
          <textarea name="recipient_emails" rows="3" placeholder="guest@example.com"></textarea>
          <p>Blank lines skip email for that ticket. Leaving this field empty creates tickets without sending mail. Each address receives only its matching ticket.</p>
          <p><button class="btn" name="mint" type="submit"><i class="fas fa-ticket"></i> Mint Ticket(s)</button></p>
        </form>
        <p style="color:var(--muted);font-size:0.82rem">
          Guests join with these credentials. Expiry is enforced by the <code>cyrad-guest</code> cron.
        </p>
      </div>

      <div class="card">
        <?= smtp_settings_form($smtp, $org_name, (string)$_SESSION['csrf_token']) ?>
      </div>

      <div class="card">
        <h3>Tickets</h3>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
          <a class="btn sm <?= $filter === 'active' ? '' : 'ghost' ?>" href="?filter=active">Active (<?= (int)$counts['active'] ?>)</a>
          <a class="btn sm <?= $filter === 'dead' ? '' : 'ghost' ?>" href="?filter=dead">Revoked &amp; expired (<?= (int)$counts['dead'] ?>)</a>
          <a class="btn sm <?= $filter === 'all' ? '' : 'ghost' ?>" href="?filter=all">All (<?= (int)$counts['all'] ?>)</a>
        </div>
        <table>
          <thead><tr><th>Username</th><th>Password</th><th>Expires (UTC)</th><th>Logins</th><th>Status</th><th>Email</th><th></th></tr></thead>
          <tbody>
          <?php if ($list && $list->num_rows): ?>
            <?php while ($r = $list->fetch_assoc()): ?>
              <tr>
                <td><code style="padding:2px 8px;border-radius:6px;background:rgba(8,13,24,.6)"><?= htmlspecialchars($r['username']) ?></code></td>
                <td><code style="padding:2px 8px;border-radius:6px;background:rgba(8,13,24,.6)"><?= htmlspecialchars($r['password']) ?></code></td>
                <td><?= htmlspecialchars($r['expires_at'] ?? 'never') ?></td>
                <td><?= (int)$r['logins'] ?> / <?= (int)$r['max_uses'] ?></td>
                <td><span class="pill <?= $r['status'] === 'active' ? 'on' : (($r['status'] === 'revoked') ? 'block' : 'off') ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td style="white-space:nowrap">
                  <small><?= htmlspecialchars($r['email_status'] === 'sent' ? 'Accepted by mail server' : ($r['email_status'] ?? 'Not requested')) ?></small>
                  <?php if ($r['email_recipient']): ?><br><small><?= htmlspecialchars($r['email_recipient']) ?></small><?php endif; ?>
                  <?php if ($r['email_error']): ?><br><small style="white-space:normal"><?= htmlspecialchars($r['email_error']) ?></small><?php endif; ?>
                  <?php if ($r['status'] === 'active'): ?>
                    <form method="POST">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="send_ticket" value="<?= (int)$r['id'] ?>">
                      <input type="email" name="recipient_email" placeholder="Email (optional)" aria-label="Recipient email for <?= htmlspecialchars($r['username']) ?>">
                      <button class="btn sm" type="submit">Send email</button>
                    </form>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <form method="POST" style="display:inline"
                        onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($r['username'])) ?> permanently?\n\nIts RADIUS account and billing row are removed. Past accounting history is kept. This cannot be undone.')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="delete_ticket" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm danger" type="submit" title="Remove this ticket and its account"><i class="fas fa-trash"></i> Delete</button>
                  </form>
                  <?php if ($r['status'] === 'active'): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Revoke <?= htmlspecialchars(addslashes($r['username'])) ?>? It will no longer be able to sign in.')">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="revoke" value="<?= (int)$r['id'] ?>">
                      <button class="btn sm" type="submit"><i class="fas fa-ban"></i> Revoke</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="7" style="color:var(--muted)">
              <?= $filter === 'active' ? 'No active tickets.'
                : ($filter === 'dead' ? 'Nothing revoked or expired — no cleanup needed.' : 'No guest tickets yet.') ?>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <p style="color:var(--muted);font-size:0.82rem">
          <b>Revoke</b> only flips the status — the ticket stops working but its rows are kept as a record.
          <b>Delete</b> removes the ticket for good along with its RADIUS account (<code>radcheck</code>),
          billing row (<code>data_limits</code>) and any related rows, so nothing is left
          behind in the user list; past sessions in <code>radacct</code> are kept for reporting.
        </p>
      </div>
    </div>
  </div>
</div>
<script>
  // Unlimited means unlimited: hide the MB box and stop submitting it, so no
  // stale cap can be stored against a guest ticket that has no data limit.
  (function () {
    var type = document.getElementById('guest_internet_type');
    var wrap = document.getElementById('guestLimitWrap');
    var mb = document.getElementById('guest_internet_limit');
    if (!type || !wrap || !mb) return;
    function sync() {
      var unlimited = type.value === 'unlimited';
      wrap.style.display = unlimited ? 'none' : '';
      mb.disabled = unlimited;          // disabled inputs are not submitted
      mb.required = !unlimited;
    }
    type.addEventListener('change', sync);
    sync();
  })();
</script>
</body>
</html>
