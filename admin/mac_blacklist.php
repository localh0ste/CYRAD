<?php
/**
 * CYRAD — MAC blacklist (per organization).
 *
 * Entries are added automatically when a user hits their data cap or a guest
 * ticket is revoked/expired; admins can also add/remove MACs by hand. Listed
 * MACs are rejected at RADIUS authorize time and kicked by the router enforcer.
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/router_policy.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$org_id = (int)($_SESSION['organization_id'] ?? 0);
$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
            throw new RuntimeException('Invalid form token. Reload and try again.');
        }
        if (isset($_POST['add_mac'])) {
            $mac = cyrad_normalize_mac((string)($_POST['mac'] ?? ''));
            if ($mac === '') throw new RuntimeException('Enter a valid MAC address (aa:bb:cc:dd:ee:ff).');
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') $reason = 'Blocked by admin';
            $hours = (int)($_POST['hours'] ?? 0);
            $expires = $hours > 0 ? gmdate('Y-m-d H:i:s', time() + $hours * 3600) : null;
            cyrad_blacklist_add($conn, $org_id, $mac, $reason, '', $expires);
            $_SESSION['flash'] = "MAC $mac blacklisted" . ($expires ? " (until $expires UTC)." : ' (until removed).');
        } elseif (isset($_POST['remove_mac'])) {
            $id = (int)$_POST['remove_mac'];
            $st = $conn->prepare("DELETE FROM mac_blacklist WHERE id=? AND organization_id=?");
            $st->bind_param('ii', $id, $org_id); $st->execute();
            $n = $st->affected_rows; $st->close();
            $_SESSION['flash'] = $n ? "MAC unblocked." : "Entry not found.";
        } elseif (isset($_POST['clear_expired'])) {
            $st = $conn->prepare("DELETE FROM mac_blacklist WHERE organization_id=? AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()");
            $st->bind_param('i', $org_id); $st->execute();
            $n = $st->affected_rows; $st->close();
            $_SESSION['flash'] = "$n expired entr" . ($n === 1 ? 'y' : 'ies') . " removed.";
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = $e->getMessage();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: mac_blacklist.php', true, 303);
    exit;
}

$rows = [];
$st = $conn->prepare("SELECT id, mac, reason, username, created_at, expires_at,
                             (expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()) AS expired
                      FROM mac_blacklist WHERE organization_id=? ORDER BY created_at DESC LIMIT 500");
$st->bind_param('i', $org_id);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — MAC Blacklist</title>
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
      <div><strong>MAC Blacklist</strong></div>
      <div class="org">Org #<?= $org_id ?></div>
    </header>
    <div class="content">
      <?php if ($msg !== ''): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <div class="card glass">
        <h3><i class="fas fa-ban" style="color:var(--danger,#e5484d)"></i> Block a device</h3>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div style="display:flex;gap:14px;flex-wrap:wrap">
            <div style="flex:1;min-width:200px"><label>MAC address</label>
              <input name="mac" placeholder="aa:bb:cc:dd:ee:ff" required></div>
            <div style="flex:2;min-width:220px"><label>Reason</label>
              <input name="reason" maxlength="255" placeholder="Lost/stolen device, abuse, …"></div>
            <div style="flex:1;min-width:150px"><label>Duration</label>
              <select name="hours">
                <option value="0">Until removed</option>
                <option value="1">1 hour</option>
                <option value="24">24 hours</option>
                <option value="168">7 days</option>
              </select></div>
          </div>
          <p><button class="btn danger" type="submit" name="add_mac" value="1">
            <i class="fas fa-ban"></i> Blacklist MAC</button></p>
        </form>
        <p style="color:var(--muted);font-size:0.82rem">
          Listed MACs are rejected on every RADIUS authentication and kicked by the router enforcer.
          Cap hits and revoked/expired guest tickets are added here automatically; raising a user's
          limit (or resetting usage) removes their entries so they can rejoin.
        </p>
      </div>

      <div class="card glass">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <h3 style="margin:0">Blacklisted MACs (<?= count($rows) ?>)</h3>
          <form method="POST" style="margin-left:auto">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <button class="btn sm ghost" type="submit" name="clear_expired" value="1">Remove expired</button>
          </form>
        </div>
        <table>
          <thead><tr><th>MAC</th><th>Owner</th><th>Reason</th><th>Added (UTC)</th><th>Expires (UTC)</th><th></th></tr></thead>
          <tbody>
          <?php if ($rows): foreach ($rows as $r): ?>
            <tr<?= $r['expired'] ? ' style="opacity:.55"' : '' ?>>
              <td><code><?= htmlspecialchars($r['mac']) ?></code></td>
              <td><?= $r['username'] !== '' ? htmlspecialchars($r['username']) : '—' ?></td>
              <td><?= htmlspecialchars($r['reason']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($r['created_at']) ?></td>
              <td style="color:var(--muted)"><?= $r['expires_at'] ? htmlspecialchars($r['expires_at']) : 'until removed' ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('Unblock <?= htmlspecialchars($r['mac'], ENT_QUOTES) ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <button class="btn sm" type="submit" name="remove_mac" value="<?= (int)$r['id'] ?>">Unblock</button>
                </form>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" style="color:var(--muted)">No blacklisted MACs.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
