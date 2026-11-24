<?php
/**
 * CYRAD — live RADIUS sessions.
 *
 * One row per currently connected 802.1X/RADIUS client, with the three things
 * needed to identify a device: username, device MAC and Acct-Session-Id.
 * Super admins see every org; org admins only their own.
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$org_id  = (int)($_SESSION['organization_id'] ?? 0);
$role    = $_SESSION['admin_role'] ?? 'admin';
$isSuper = ($role === 'super_admin');
$orgJoin = $isSuper ? "1=1" : "(c.organization_id = $org_id OR p.organization_id = $org_id)";
$orgShadowJoin = "LEFT JOIN user_profiles p ON p.username = a.username";

function fmtBytes2($b) {
    $b = (float)$b;
    if ($b >= 1099511627776) return round($b/1099511627776, 2) . ' TB';
    if ($b >= 1073741824)    return round($b/1073741824, 2) . ' GB';
    if ($b >= 1048576)       return round($b/1048576, 1) . ' MB';
    return round($b/1024) . ' KB';
}
function fmtDuration($s) {
    $s = max(0, (int)$s);
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
    return ($h ? $h . 'h ' : '') . ($m ? $m . 'm ' : '') . $sec . 's';
}

$rows = [];
$r = @$conn->query(
  "SELECT a.username, a.acctsessionid, a.callingstationid, a.calledstationid,
          a.framedipaddress, a.nasipaddress, a.acctstarttime, a.acctupdatetime,
          COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0) octets,
          TIMESTAMPDIFF(SECOND, a.acctstarttime, COALESCE(a.acctupdatetime, NOW())) secs
   FROM radacct a
   LEFT JOIN radcheck c ON a.username = c.username
   $orgShadowJoin
   WHERE $orgJoin
     AND (a.acctstoptime IS NULL OR a.acctstoptime > NOW()-INTERVAL 10 MINUTE)
   ORDER BY a.acctstarttime DESC"
);
if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — Active Sessions</title>
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
      <div><strong>Active Sessions</strong></div>
      <div class="org"><i class="fas fa-signal" style="color:var(--cyan)"></i> <?= count($rows) ?> connected · <?= $isSuper ? 'All organizations' : 'Org #' . $org_id ?></div>
    </header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-signal" style="color:var(--cyan)"></i> Connected devices</h3>
        <table>
          <thead>
            <tr>
              <th>Username</th><th>Device MAC</th><th>Session ID</th><th>IP</th>
              <th>NAS</th><th>SSID / BSSID</th><th>Connected</th><th>Duration</th><th>Usage</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): ?>
            <?php foreach ($rows as $s): ?>
              <tr>
                <td><code><?= htmlspecialchars($s['username'] ?? '') ?></code></td>
                <td><code><?= htmlspecialchars($s['callingstationid'] ?? '—') ?></code></td>
                <td><code style="font-size:0.8rem"><?= htmlspecialchars($s['acctsessionid'] ?? '') ?></code></td>
                <td><code><?= htmlspecialchars($s['framedipaddress'] ?? '—') ?></code></td>
                <td><?= htmlspecialchars($s['nasipaddress'] ?? '—') ?></td>
                <td><?= htmlspecialchars($s['calledstationid'] ?? '—') ?></td>
                <td><?= htmlspecialchars($s['acctstarttime'] ?? '') ?></td>
                <td><?= htmlspecialchars(fmtDuration($s['secs'] ?? 0)) ?></td>
                <td><?= htmlspecialchars(fmtBytes2($s['octets'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="9" style="color:var(--muted)">No active sessions.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <p style="color:var(--muted);font-size:0.82rem">
          A session stays listed for 10 minutes after its Accounting-Stop, so a recent disconnect remains visible briefly.
        </p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
