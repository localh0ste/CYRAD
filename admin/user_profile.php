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
$user   = $_GET['user'] ?? '';
if ($user === '') { header("Location: users.php"); exit; }
$user_e = $conn->real_escape_string($user);

// ownership: a regular admin may only view users in their own organization
$own = $conn->query("SELECT organization_id FROM radcheck WHERE username='$user_e' AND attribute='Cleartext-Password'")->fetch_assoc();
if (!$own || (int)($own['organization_id'] ?? -1) !== $org_id) {
    header("Location: users.php");
    exit;
}

// free-text note captured at user creation
$info_row  = $conn->query("SELECT user_info FROM user_profiles WHERE username='$user_e'")->fetch_assoc();
$user_info = $info_row ? (string)($info_row['user_info'] ?? '') : '';

// plan / cap
$lim = $conn->query("SELECT COALESCE(internet_type,'unlimited') AS type, COALESCE(internet_limit,0) AS lim FROM data_limits WHERE username='$user_e'")->fetch_assoc();
$type        = $lim['type'] ?? 'limited';
$limit_bytes = (int)($lim['lim'] ?? 0);

// data used (sum of all sessions)
$used_row = $conn->query("SELECT COALESCE(SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)),0) AS v FROM radacct WHERE username='$user_e'")->fetch_assoc();
$used_bytes = (int)($used_row['v'] ?? 0);

$gb  = fn($b) => round($b / 1073741824, 2);
$used_gb = $gb($used_bytes);

// bandwidth used THIS MONTH
$month_row = $conn->query(
    "SELECT COALESCE(SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)),0) AS v
     FROM radacct WHERE username='$user_e' AND acctstarttime >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
)->fetch_assoc();
$month_gb = $gb((int)($month_row['v'] ?? 0));

if ($type === 'unlimited') {
    $remaining_gb = '∞';
    $pct = 0;
} else {
    $remaining_bytes = max(0, $limit_bytes - $used_bytes);
    $remaining_gb = $gb($remaining_bytes);
    $pct = $limit_bytes > 0 ? min(100, round($used_bytes / $limit_bytes * 100)) : 0;
}
?>
<!DOCTYPE html>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — <?= htmlspecialchars($user) ?></title>
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
      <div><strong>User Profile</strong></div>
      <div class="org"><?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?> · Org #<?= $org_id ?></div>
    </header>
    <div class="content">
      <div class="card glass">
        <div class="brand" style="margin-bottom:6px">
          <div class="mark"><i class="fas fa-user"></i></div>
          <div><?= htmlspecialchars($user) ?><small><?= htmlspecialchars($type) ?> PLAN</small></div>
        </div>
      </div>

      <a class="btn sm" href="edit_user.php?username=<?= urlencode($user) ?>" style="margin:4px 0 10px"><i class="fas fa-sliders"></i> Edit Limit / Plan</a>

      <?php if ($user_info !== ''): ?>
      <div class="card glass">
        <h3><i class="fas fa-circle-info" style="color:var(--cyan)"></i> User Info</h3>
        <p style="white-space:pre-wrap"><?= htmlspecialchars($user_info) ?></p>
      </div>
      <?php endif; ?>

      <div class="grid cols-3">
        <div class="card stat glass"><div class="num"><?= $used_gb ?> GB</div><div class="lbl">Data Used</div></div>
        <div class="card stat glass"><div class="num"><?= $type === 'unlimited' ? '∞' : round($limit_bytes / 1073741824, 2) . ' GB' ?></div><div class="lbl">Data Cap</div></div>
        <div class="card stat glass"><div class="num"><?= $remaining_gb ?></div><div class="lbl">Internet Left</div></div>
      </div>

      <div class="grid cols-3">
        <div class="card stat glass"><div class="num"><?= $month_gb ?> GB</div><div class="lbl">Bandwidth This Month</div></div>
      </div>

      <?php if ($type !== 'unlimited' && $limit_bytes > 0): ?>
      <div class="card glass">
        <h3>Data usage</h3>
        <div style="height:14px;border-radius:999px;background:#0b1322;border:1px solid var(--border);overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:var(--grad);box-shadow:0 0 14px rgba(94,234,255,0.4)"></div>
        </div>
        <p style="color:var(--muted);font-size:0.82rem;margin-top:8px"><?= $pct ?>% of cap consumed</p>
      </div>
      <?php endif; ?>

      <a class="btn ghost sm" href="users.php"><i class="fas fa-arrow-left"></i> Back to users</a>
    </div>
  </div>
</div>
</body>
</html>
