<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || !isset($_SESSION['organization_id'])) {
    header("Location: login.php");
    exit;
}

$org_id = $_SESSION['organization_id'];

require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$users = [];
$query = "
    SELECT 
        d.username,
        d.internet_limit,
        d.internet_type,
        COALESCE(SUM(r.acctinputoctets + r.acctoutputoctets), 0) AS total_used,
        (d.internet_limit - COALESCE(SUM(r.acctinputoctets + r.acctoutputoctets), 0)) AS remaining_bytes
    FROM 
        data_limits d
    LEFT JOIN 
        radacct r ON d.username = r.username
          AND r.acctstarttime >= COALESCE((SELECT usage_reset_ts FROM data_limits WHERE username = d.username LIMIT 1), NOW())
    WHERE 
        d.organization_id = ?
        AND d.internet_limit > 0
        AND LOWER(d.internet_type) <> 'unlimited'
    GROUP BY 
        d.username, d.internet_limit, d.internet_type
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['remaining_mb'] = round($row['remaining_bytes'] / (1024 * 1024), 2);
        $row['internet_limit_mb'] = round($row['internet_limit'] / (1024 * 1024), 2);
        $row['used_mb'] = round($row['total_used'] / (1024 * 1024), 2);
        $users[] = $row;
    }
}

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Limited Users Data Left</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
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
      <div><strong>Data Left for Limited Users</strong></div>
      <div class="org"><?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?> · Org #<?= (int)($_SESSION['organization_id'] ?? 0) ?></div>
    </header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-chart-pie" style="color:var(--cyan)"></i> Data Left for Limited Users</h3>
        <table>
          <thead>
            <tr><th>Username</th><th>Internet Type</th><th>Limit (MB)</th><th>Used (MB)</th><th>Remaining (MB)</th></tr>
          </thead>
          <tbody>
          <?php if (!empty($users)): ?>
            <?php foreach ($users as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td><?= htmlspecialchars($u['internet_type']) ?></td>
              <td><?= $u['internet_limit_mb'] ?></td>
              <td><?= $u['used_mb'] ?></td>
              <td><?= $u['remaining_mb'] ?></td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="muted-sm">No limited users found for your organization.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
