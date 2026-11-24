<?php
/**
 * CYRAD — IP -> User map ("who has which IP right now").
 *
 * Rows every LAN IP currently attributable to a user:
 *   * RADIUS 802.1X sessions resolved by radacct.framedipaddress
 *     (+ calledstationid -> BSSID profile -> VLAN),
 *   * NAT'd clients resolved through the router-shared nat_mappings table
 *     (client_ip -> mac -> radacct.callingstationid -> user).
 *
 * This is exactly the lookup the DNS monitor performs per query, surfaced here
 * so an admin can see, per org, which user currently owns which IP.
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
$isSuper = ($_SESSION['admin_role'] ?? 'admin') === 'super_admin';
$orgC    = $isSuper ? "1=1" : "c.organization_id = $org_id";

function bssid_to_vlan($conn, $called) {
    return null;
}
function sq($conn, $s) { return $conn->real_escape_string($s); }

// 1) Direct RADIUS sessions with a framed IP
$direct = $rows = [];
$r = $conn->query(
  "SELECT a.username, a.acctsessionid sid, a.framedipaddress, a.callingstationid csmac, a.calledstationid called,
          COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0) octets, a.acctstarttime started
   FROM radacct a JOIN radcheck c ON a.username=c.username
   WHERE $orgC AND a.framedipaddress <> '' AND (a.acctstoptime IS NULL OR a.acctstoptime > NOW()-INTERVAL 10 MINUTE)
   ORDER BY a.acctstarttime DESC"
);
if ($r) while ($x = $r->fetch_assoc()) $direct[$x['framedipaddress']] = $x;

// 2) NAT'd clients via router-shared nat_mappings
$nat = $conn->query(
  "SELECT n.client_ip, n.mac, n.hostname, n.vlan_id, n.updated_at
   FROM nat_mappings n WHERE n.organization_id=$org_id AND n.updated_at > NOW()-INTERVAL 20 MINUTE
   ORDER BY n.client_ip"
);
$natRows = [];
if ($nat) while ($x = $nat->fetch_assoc()) {
    $user = null; $called = ''; $started = null; $octets = 0;
    if ($x['mac'] && $x['mac'] !== 'unknown') {
        $mac = sq($conn, $x['mac']);
        $rr = $conn->query("SELECT a.username, a.calledstationid called, COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0) octets, a.acctstarttime started
                            FROM radacct a
                            WHERE LOWER(REPLACE(REPLACE(a.callingstationid,'-',':'),' ',''))='$mac'
                            AND (a.acctstoptime IS NULL OR a.acctstoptime > NOW()-INTERVAL 20 MINUTE)
                            ORDER BY a.acctstarttime DESC LIMIT 1");
        if ($rr && $row = $rr->fetch_assoc()) { $user = $row['username']; $called = $row['called']; $started = $row['started']; $octets = (int)$row['octets']; }
    }
    if (isset($direct[$x['client_ip']])) continue; // already shown by direct map
    $natRows[] = [
        'ip' => $x['client_ip'], 'mac' => $x['mac'], 'hostname' => $x['hostname'],
        'user' => $user, 'called' => $called, 'started' => $started, 'octets' => $octets,
        'vlan' => null,
        'last' => $x['updated_at'],
    ];
}

$vlanName = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — IP &rarr; User</title>
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
      <div><strong>Live IP &rarr; User map</strong></div>
      <div class="org">Org #<?= $org_id ?></div>
    </header>
    <div class="content">
      <div class="card">
        <h3>Direct RADIUS sessions</h3>
        <table>
          <thead><tr><th>Username</th><th>Session ID</th><th>IP</th><th>MAC</th><th>VLAN</th><th>Connected (SSID/BSSID)</th><th>Started</th></tr></thead>
          <tbody>
          <?php if ($direct): ?>
            <?php foreach ($direct as $row): $v = bssid_to_vlan($conn, $row['called']); ?>
              <tr>
                <td><code><?= htmlspecialchars($row['username']) ?></code></td>
                <td><code style="font-size:0.8rem"><?= htmlspecialchars($row['sid'] ?? '') ?></code></td>
                <td><code><?= htmlspecialchars($row['framedipaddress']) ?></code></td>
                <td><?= htmlspecialchars($row['csmac'] ?? '') ?></td>
                <td><?= $v ? '<span class="pill block">'.htmlspecialchars(($vlanName[$v] ?? '').' ('.$v.')').'</span>' : '—' ?></td>
                <td><?= htmlspecialchars($row['called'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['started']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="7" style="color:var(--muted)">No live 802.1X sessions with a framed IP.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>NAT'd clients (router-shared board)</h3>
        <table>
          <thead><tr><th>Client IP</th><th>MAC</th><th>User (if RADIUS)</th><th>VLAN</th><th>Hostname</th><th>Last seen</th></tr></thead>
          <tbody>
          <?php if ($natRows): ?>
            <?php foreach ($natRows as $row): ?>
              <tr>
                <td><code><?= htmlspecialchars($row['ip']) ?></code></td>
                <td><code style="font-size:0.8rem"><?= htmlspecialchars($row['mac']) ?></code></td>
                <td><?= $row['user'] ? '<code>'.htmlspecialchars($row['user']).'</code>' : '<span style="color:var(--muted)">unauthenticated</span>' ?></td>
                <td><?= $row['vlan'] ? '<span class="pill block">'.htmlspecialchars(($vlanName[$row['vlan']] ?? '').' ('.$row['vlan'].')').'</span>' : '—' ?></td>
                <td><?= htmlspecialchars($row['hostname'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['last']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" style="color:var(--muted)">
              No NAT rows yet. NAT/DHCP board posting is optional; configure a gateway to POST its
              leases to <code>admin/api_nat_table.php</code>. Live WiFi identity is available on the
              Active Sessions page.
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <p style="color:var(--muted);font-size:0.82rem">
          This lives map is the same attribution the DNS monitor uses per query: source IP &rarr; MAC &rarr; user &rarr;
          org &rarr; DNS rules.
        </p>
      </div>
    </div>
  </div>
</div>
</body>
</html>