<?php
/**
 * CYRAD — WiFi / BSSID profiles: map an SSID's BSSID (AP MAC) to an
 * expected auth source.
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$org_id = (int)($_SESSION['organization_id'] ?? 0);
$msg = "";

function norm_bssid($b) {
    $b = strtoupper(trim($b));
    $b = preg_replace('/[^0-9A-F]/', '', $b);
    if (strlen($b) !== 12) return '';
    return implode(':', str_split($b, 2));
}

if (isset($_POST['add'])) {
    $ssid = trim($_POST['ssid'] ?? '');
    $bssid = norm_bssid($_POST['bssid'] ?? '');
    $auth = in_array($_POST['auth_source'] ?? '', ['local','ad','guest']) ? $_POST['auth_source'] : 'local';
    $desc = trim($_POST['description'] ?? '');
    if ($ssid === '' || $bssid === '') {
        $msg = "<div class='msg err'>SSID and a valid BSSID (6 hex pairs) are required.</div>";
    } else {
        $chk = $conn->prepare("SELECT id FROM ssid_profiles WHERE bssid=?");
        $chk->bind_param("s", $bssid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $msg = "<div class='msg err'>BSSID $bssid is already mapped (another org). Move/update it or delete first.</div>";
        } else {
            $st = $conn->prepare("INSERT INTO ssid_profiles (organization_id, ssid, bssid, auth_source, description) VALUES (?,?,?,?,?)");
            $st->bind_param("issss", $org_id, $ssid, $bssid, $auth, $desc);
            $msg = $st->execute()
                ? "<div class='msg ok'>BSSID $bssid (&ldquo;$ssid&rdquo;) mapped.</div>"
                : "<div class='msg err'>Error: " . htmlspecialchars($conn->error) . "</div>";
        }
        $chk->close();
    }
}

if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $conn->query("DELETE FROM ssid_profiles WHERE id=$id AND organization_id=$org_id");
    header("Location: wifi_profiles.php");
    exit;
}

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE ssid_profiles SET enabled = 1 - enabled WHERE id=$id AND organization_id=$org_id");
    header("Location: wifi_profiles.php");
    exit;
}

$list = $conn->query(
    "SELECT s.id, s.ssid, s.bssid, s.auth_source, s.enabled, s.description
     FROM ssid_profiles s
     WHERE s.organization_id=$org_id ORDER BY s.bssid"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — WiFi / BSSID</title>
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
      <div><strong>WiFi / BSSID mapping</strong></div>
      <div class="org">Org #<?= $org_id ?></div>
    </header>
    <div class="content">
      <?= $msg ?>
      <div class="card">
        <h3>Map an SSID/BSSID</h3>
        <form method="POST">
          <label>SSID</label>
          <input type="text" name="ssid" placeholder="CYRAD-Guest" required>
          <label>BSSID (AP MAC, e.g. AA:BB:CC:DD:EE:01)</label>
          <input type="text" name="bssid" placeholder="AA:BB:CC:DD:EE:01" required>
          <label>Expected auth source</label>
          <select name="auth_source">
            <option value="local">Local (radcheck)</option>
            <option value="ad">Active Directory (AD via RADIUS)</option>
            <option value="guest">Guest (voucher)</option>
          </select>
          <label>Description</label>
          <input type="text" name="description">
          <p><button class="btn" name="add" type="submit"><i class="fas fa-plus"></i> Map BSSID</button></p>
        </form>
      </div>

      <div class="card">
        <h3>Mapped SSIDs</h3>
        <table>
          <thead><tr><th>BSSID</th><th>SSID</th><th>Auth</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if ($list && $list->num_rows): ?>
            <?php while ($r = $list->fetch_assoc()): ?>
              <tr>
                <td><code style="padding:2px 8px;border-radius:6px;background:rgba(8,13,24,.6)"><?= htmlspecialchars($r['bssid']) ?></code></td>
                <td><?= htmlspecialchars($r['ssid']) ?></td>
                <td><span class="pill <?= $r['auth_source'] === 'ad' ? 'allow' : 'block' ?>"><?= htmlspecialchars($r['auth_source']) ?></span></td>
                <td><span class="pill <?= $r['enabled'] ? 'on' : 'off' ?>"><?= $r['enabled'] ? 'On' : 'Off' ?></span></td>
                <td>
                  <a class="btn sm ghost" href="?toggle=<?= $r['id'] ?>">Toggle</a>
                  <a class="btn sm danger" href="?del=<?= $r['id'] ?>" onclick="return confirm('Delete mapping?')">Del</a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="5" style="color:var(--muted)">No BSSID mappings yet. Add one above.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <p style="color:var(--muted);font-size:0.82rem">
          To read the running BSSIDs on the router: <code>hostapd_cli -i &lt;iface&gt; status | grep bssid</code> or
          <code>iwinfo &lt;iface&gt; info</code>. The BSSID is what the AP sends as RADIUS
          <code>Called-Station-Id</code> (MAC + SSID).
        </p>
      </div>
    </div>
  </div>
</div>
</body>
</html>