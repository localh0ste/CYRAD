<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Super Admin Dashboard</title>
  <link rel="icon" href="data:,">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>SUPER ADMIN</small></div></div>
    <?php require dirname(__DIR__) . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar">
      <div><strong>Super Admin Console</strong></div>
      <div class="org">
        <a href="../change_password.php" style="color:var(--muted);text-decoration:none">Change Password</a>
        <a href="../logout.php" style="color:var(--muted);text-decoration:none;margin-left:14px">Logout</a>
      </div>
    </header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-crown" style="color:var(--cyan)"></i> Welcome, Super Admin</h3>
        <p class="muted-sm">Manage organizations, administrators, and platform-wide users from the navigation on the left.</p>
      </div>

      <div class="split-even">
        <a class="card glass qa" href="create_organization.php" style="text-decoration:none">
          <i class="fas fa-building"></i> Create Organization
        </a>
        <a class="card glass qa" href="view_org_users.php" style="text-decoration:none">
          <i class="fas fa-users"></i> View Organization Users
        </a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
