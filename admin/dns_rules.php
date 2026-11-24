<?php
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

// --- Add rule ---
if (isset($_POST['add'])) {
    $pattern = trim($_POST['domain_pattern'] ?? '');
    $action  = in_array($_POST['action'] ?? '', ['block','allow']) ? $_POST['action'] : 'block';
    $desc    = trim($_POST['description'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $pattern = strtolower(preg_replace('/[^a-z0-9.*\-]/', '', $pattern));
    if ($pattern === '') {
        $msg = "<div class='msg err'>Domain pattern cannot be empty.</div>";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO dns_rules (organization_id, domain_pattern, action, description, enabled)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("isssi", $org_id, $pattern, $action, $desc, $enabled);
        $msg = $stmt->execute()
            ? "<div class='msg ok'>Rule added.</div>"
            : "<div class='msg err'>Error: " . htmlspecialchars($conn->error) . "</div>";
        $stmt->close();
    }
}

// --- Delete rule ---
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $stmt = $conn->prepare("DELETE FROM dns_rules WHERE id = ? AND organization_id = ?");
    $stmt->bind_param("ii", $id, $org_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dns_rules.php");
    exit;
}

// --- Toggle enabled ---
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE dns_rules SET enabled = 1 - enabled WHERE id = $id AND organization_id = $org_id");
    header("Location: dns_rules.php");
    exit;
}

$rules = $conn->query("SELECT d.id, d.domain_pattern, d.action, d.description, d.enabled, d.created_at FROM dns_rules d WHERE d.organization_id = $org_id ORDER BY d.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — DNS Rules</title>
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
      <div><strong>DNS Monitoring Rules</strong></div>
      <div class="org">Org #<?= $org_id ?></div>
    </header>
    <div class="content">
      <?= $msg ?>
      <div class="card">
        <h3>Add Rule</h3>
        <form method="POST">
          <label>Domain pattern (e.g. pornhub.com or *.ads.net)</label>
          <input type="text" name="domain_pattern" placeholder="example.com" required>
          <label>Action</label>
          <select name="action">
            <option value="block">Block (NXDOMAIN + alert)</option>
            <option value="allow">Allow (explicit allow)</option>
          </select>
          <input type="hidden" name="vlan_id" value="0">
          <label>Description</label>
          <input type="text" name="description" placeholder="Optional note">
          <label><input type="checkbox" name="enabled" checked style="width:auto;margin-right:6px;"> Enabled</label>
          <p><button class="btn" name="add" type="submit"><i class="fas fa-plus"></i> Add Rule</button></p>
        </form>
      </div>

      <div class="card">
        <h3>Active Rules</h3>
        <table>
          <thead><tr><th>Domain</th><th>Action</th><th>Note</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if ($rules && $rules->num_rows): ?>
            <?php while ($r = $rules->fetch_assoc()): ?>
              <tr>
                <td><code><?= htmlspecialchars($r['domain_pattern']) ?></code></td>
                <td><span class="pill <?= $r['action'] === 'block' ? 'block' : 'allow' ?>"><?= $r['action'] ?></span></td>
                <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
                <td><span class="pill <?= $r['enabled'] ? 'on' : 'off' ?>"><?= $r['enabled'] ? 'On' : 'Off' ?></span></td>
                <td>
                  <a class="btn sm ghost" href="?toggle=<?= $r['id'] ?>">Toggle</a>
                  <a class="btn sm danger" href="?del=<?= $r['id'] ?>" onclick="return confirm('Delete rule?')">Del</a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="5" style="color:var(--muted)">No rules yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <p style="color:var(--muted);font-size:0.82rem">
          Blocked domains are answered with NXDOMAIN by the CYRAD DNS blocker — no logging, no alerts.
        </p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
