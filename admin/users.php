<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/router_policy.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$org_id = (int)($_SESSION['organization_id'] ?? 0);

// ---- delete handling (single + bulk, org-scoped, CSRF-protected) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !empty($_POST['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

    $targets = [];
    $action  = '';
    if (isset($_POST['delete_user']) && is_string($_POST['delete_user'])) {
        $targets = [ $_POST['delete_user'] ];
        $action  = 'delete';
    } elseif (isset($_POST['bulk_delete']) && !empty($_POST['users']) && is_array($_POST['users'])) {
        $targets = array_values(array_unique(array_filter(array_map('strval', $_POST['users']), fn($x) => $x !== '')));
        $action  = 'delete';
    } elseif (isset($_POST['reset_user']) && is_string($_POST['reset_user'])) {
        $targets = [ $_POST['reset_user'] ];
        $action  = 'reset';
    } elseif (isset($_POST['bulk_reset']) && !empty($_POST['users']) && is_array($_POST['users'])) {
        $targets = array_values(array_unique(array_filter(array_map('strval', $_POST['users']), fn($x) => $x !== '')));
        $action  = 'reset';
    }

    if ($targets && $action === 'reset') {
        // Daily-reset a user by hand: clear the accounting window, lift bans and
        // blacklist entries, re-enable and kick the live session so the next
        // connect starts from a clean allowance. Revoked/expired guest tickets
        // are never resurrected.
        $reset = 0; $skipped = 0;
        foreach ($targets as $uname) {
            if (!preg_match('/^[a-zA-Z0-9_\\-\\.@]{1,64}$/', $uname)) continue;
            $r = cyrad_reset_user($conn, $uname, true);
            if (!empty($r['ok'])) $reset++; else $skipped++;
        }
        $_SESSION['flash'] = "$reset user(s) reset (usage cleared, ban/blacklist lifted, session kicked)."
            . ($skipped ? " $skipped skipped (revoked/expired guest ticket)." : '');
    } elseif ($targets) {
        $deleted = 0;
        $conn->begin_transaction();
        try {
            $del_rc = $conn->prepare("DELETE FROM radcheck      WHERE username = ? AND organization_id = ?");
            $del_dl = $conn->prepare("DELETE FROM data_limits  WHERE username = ? AND organization_id = ?");
            $del_rg = $conn->prepare("DELETE FROM radusergroup WHERE username = ?");
            $del_up = $conn->prepare("DELETE FROM user_profiles WHERE username = ?");
            $del_rr = $conn->prepare("DELETE FROM radreply WHERE username = ?");
            foreach ($targets as $uname) {
                if (!preg_match('/^[a-zA-Z0-9_\\-\\.@]{1,64}$/', $uname)) continue;
                $del_rc->bind_param("si", $uname, $org_id); $del_rc->execute();
                $del_dl->bind_param("si", $uname, $org_id); $del_dl->execute();
                $del_rg->bind_param("s",  $uname);          $del_rg->execute();
                $del_up->bind_param("s",  $uname);          $del_up->execute();
                $del_rr->bind_param("s",  $uname);          $del_rr->execute();
                if ($del_rc->affected_rows > 0 || $del_up->affected_rows > 0) $deleted++;
            }
            $conn->commit();
            $_SESSION['flash'] = $deleted . " user(s) deleted.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash'] = "Delete failed: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash'] = "No users selected.";
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $tab = isset($_POST['utab']) && in_array($_POST['utab'], ['local', 'ad', 'guest']) ? $_POST['utab'] : 'all';
    header("Location: users.php?t=" . urlencode($tab));
    exit;
}

$tab = 'all';
if (isset($_GET['t']) && in_array($_GET['t'], ['local', 'ad', 'guest'])) {
    $tab = $_GET['t'];
}

$stmt = $conn->prepare(
    "SELECT u.username,
            u.src,
            u.last_login,
            COALESCE(d.internet_type,'unlimited') AS type,
            COALESCE(d.internet_limit,0)        AS lim,
            COALESCE((SELECT 1 FROM radacct a
                       WHERE a.username = u.username
                         AND (a.acctstoptime IS NULL OR a.acctstoptime = '')
                       ORDER BY a.acctstarttime DESC LIMIT 1), 0) AS online
     FROM (
         SELECT username, 'local' AS src, organization_id AS oid, NULL AS last_login FROM radcheck
         UNION
         SELECT username, auth_source, organization_id, last_login FROM user_profiles
           WHERE auth_source <> 'local'
     ) u
     LEFT JOIN data_limits d ON d.username = u.username AND d.organization_id = u.oid
     WHERE u.oid = ?
     ORDER BY u.username"
);
$stmt->bind_param("i", $org_id);
$stmt->execute();
$res = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — Users</title>
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
      <div><strong>Users</strong></div>
      <div class="org"><?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?> · Org #<?= $org_id ?></div>
    </header>
    <div class="content">
      <?php if (!empty($msg)): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <div class="card glass">
        <form method="POST" id="usersForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="utab" id="utab" value="<?= htmlspecialchars($tab) ?>">
          <h3><i class="fas fa-users" style="color:var(--cyan)"></i> Wi-Fi Users</h3>
          <div class="utabs" id="userTabs" style="margin:6px 0 12px;display:flex;gap:6px;flex-wrap:wrap">
            <button type="button" class="utab" data-tab="all"  id="tab-all">All</button>
            <button type="button" class="utab" data-tab="local" id="tab-local">Local</button>
            <button type="button" class="utab" data-tab="ad"   id="tab-ad">Active Directory</button>
            <button type="button" class="utab" data-tab="guest" id="tab-guest">Guest</button>
          </div>
          <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button class="btn sm danger" type="submit" name="bulk_delete" id="bulkBtn" disabled
                    onclick="return confirm('Delete the selected user(s)? This cannot be undone.')">
              <i class="fas fa-trash"></i> Delete Selected
            </button>
            <button class="btn sm" type="submit" name="bulk_reset" id="bulkResetBtn" disabled
                    onclick="return confirm('Reset the selected user(s)? Usage window is cleared, bans and MAC blocks are lifted, live sessions are kicked and accounts re-enabled.')">
              <i class="fas fa-rotate-left"></i> Reset Selected
            </button>
            <span id="selCount" style="color:var(--muted)"></span>
            <input type="text" id="userSearch" placeholder="Search username…" style="width:auto;min-width:220px;flex:0 1 240px">
          </div>
          <table>
            <thead>
              <tr>
                <th><input type="checkbox" id="selAll" title="Select all"></th>
                <th>Username</th>
                <th>Auth</th>
                <th>Plan</th>
                <th>Data Cap</th>
                <th>Status</th>
                <th>Last Seen</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php while ($u = $res->fetch_assoc()): $src = $u['src'] ?? 'local'; ?>
              <tr data-src="<?= htmlspecialchars($src) ?>">
                <td><input type="checkbox" class="rowSel" name="users[]" value="<?= htmlspecialchars($u['username']) ?>"></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><span class="pill <?= $src === 'local' ? 'ghost' : 'block' ?>"><?= htmlspecialchars($src === 'ad' ? 'AD' : ($src === 'guest' ? 'Guest' : 'Local')) ?></span></td>
                <td><span class="pill <?= $u['type'] === 'unlimited' ? 'allow' : 'on' ?>"><?= htmlspecialchars($u['type']) ?></span></td>
                <td><?= $u['type'] === 'unlimited' ? '&#8734;' : round($u['lim'] / 1073741824, 2) . ' GB' ?></td>
                <td><span class="pill <?= $u['online'] ? 'allow' : 'off' ?>" data-status="<?= htmlspecialchars($u['username']) ?>"><?= $u['online'] ? 'Online' : 'Offline' ?></span></td>
                <td style="color:var(--muted);white-space:nowrap"><?= !empty($u['last_login']) ? date('Y-m-d H:i', strtotime($u['last_login'])) : '—' ?></td>
                <td>
                  <a class="btn sm" href="user_profile.php?user=<?= urlencode($u['username']) ?>">Profile</a>
                  <a class="btn sm ghost" href="edit_user.php?username=<?= urlencode($u['username']) ?>"><i class="fas fa-sliders"></i> Edit</a>
                  <button class="btn sm" type="submit" name="reset_user" value="<?= htmlspecialchars($u['username']) ?>"
                          onclick="return confirm('Reset usage for <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? Clears the accounting window, lifts bans/blacklist, kicks the live session and re-enables the account.')">
                    <i class="fas fa-rotate-left"></i> Reset
                  </button>
                  <button class="btn sm danger" type="submit" name="delete_user" value="<?= htmlspecialchars($u['username']) ?>"
                          onclick="return confirm('Delete user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? This cannot be undone.')">
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
const selAll  = document.getElementById('selAll');
const rowSel  = document.querySelectorAll('.rowSel');
const bulkBtn = document.getElementById('bulkBtn');
const bulkResetBtn = document.getElementById('bulkResetBtn');
const selCount = document.getElementById('selCount');
const userSearch = document.getElementById('userSearch');
const tbody = document.querySelector('#usersForm tbody');
const allRows = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];
const tabs   = Array.from(document.querySelectorAll('.utab'));
const utabEl = document.getElementById('utab');

let activeTab = 'all';
const urlTab = new URLSearchParams(location.search).get('t');
if (['local', 'ad', 'guest'].includes(urlTab)) activeTab = urlTab;

function visibleRows() {
  return allRows.filter(tr => tr.style.display !== 'none');
}
function updateCount() {
  const n = visibleRows().filter(tr => tr.querySelector('.rowSel').checked).length;
  bulkBtn.disabled = n === 0;
  if (bulkResetBtn) bulkResetBtn.disabled = n === 0;
  selCount.textContent = n ? n + ' selected' : '';
}
function applyFilter() {
  tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === activeTab));
  utabEl.value = activeTab;
  const q = userSearch ? userSearch.value.trim().toLowerCase() : '';
  allRows.forEach(tr => {
    const name = (tr.querySelector('td:nth-child(2)')?.textContent || '').toLowerCase();
    const src  = tr.dataset.src || 'local';
    const matchTab = activeTab === 'all' || src === activeTab;
    const matchQ   = !q || name.includes(q);
    tr.style.display = (matchTab && matchQ) ? '' : 'none';
  });
  const vis = visibleRows();
  selAll.checked = vis.length > 0 && vis.every(tr => tr.querySelector('.rowSel').checked);
  updateCount();
}

// per-tab counts, shown in the tab labels
const countBy = {};
allRows.forEach(tr => { const s = tr.dataset.src || 'local'; countBy[s] = (countBy[s] || 0) + 1; });
tabs.forEach(t => { if (t.dataset.tab !== 'all') t.textContent += ' (' + (countBy[t.dataset.tab] || 0) + ')'; });

tabs.forEach(t => t.addEventListener('click', () => { activeTab = t.dataset.tab; applyFilter(); }));

selAll.addEventListener('change', () => {
  visibleRows().forEach(tr => { tr.querySelector('.rowSel').checked = selAll.checked; });
  updateCount();
});
rowSel.forEach(c => c.addEventListener('change', () => {
  const vis = visibleRows();
  selAll.checked = vis.length > 0 && vis.every(tr => tr.querySelector('.rowSel').checked);
  updateCount();
}));

if (userSearch) {
  userSearch.addEventListener('input', applyFilter);
}

applyFilter();

// live online/offline refresh — authenticated via the admin session cookie
setInterval(async () => {
  try {
    const r = await fetch('api_user_status.php', { credentials: 'same-origin' });
    if (!r.ok) return;
    const d = await r.json();
    const map = {};
    (d.users || []).forEach(u => { map[u.username] = u.online; });
    document.querySelectorAll('[data-status]').forEach(el => {
      const on = map[el.getAttribute('data-status')];
      if (on === true)        { el.textContent = 'Online';  el.className = 'pill allow'; }
      else if (on === false)  { el.textContent = 'Offline'; el.className = 'pill off'; }
    });
  } catch (e) {}
}, 15000);
</script>
</body>
</html>
