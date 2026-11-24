<?php
session_start();
// Super admin only
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true
    || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}
require_once __DIR__ . '/../config/db.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$msg = "";

// Reset a specific org admin's dashboard password
if (isset($_POST['reset']) && !empty($_POST['uid']) && !empty($_POST['newpass'])) {
    $uid    = (int)$_POST['uid'];
    $newpass = $_POST['newpass'];
    if (strlen($newpass) < 8) {
        $msg = "<div class='msg err'>Password must be at least 8 characters.</div>";
    } else {
        $hash = password_hash($newpass, PASSWORD_DEFAULT);
        // Super admin may reset ANY admin account (org admins AND other super admins).
        $stmt = $conn->prepare(
            "UPDATE admin_users SET password = ? WHERE id = ? AND role IN ('admin','super_admin')"
        );
        $stmt->bind_param("si", $hash, $uid);
        $stmt->execute();
        $msg = $stmt->affected_rows
            ? "<div class='msg ok'>Dashboard password reset for admin #$uid.</div>"
            : "<div class='msg err'>No matching admin found.</div>";
        $stmt->close();
    }
}

// List org admins
$admins = $conn->query(
    "SELECT u.id, u.username, u.full_name, u.organization_id, o.org_name
     FROM admin_users u LEFT JOIN organizations o ON u.organization_id = o.id
     WHERE u.role = 'admin' ORDER BY u.organization_id, u.username"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — Reset Org Admin</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>SUPER ADMIN</small></div></div>
    <?php require dirname(__DIR__) . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar"><div><strong>Reset Organization Admin Password</strong></div></header>
    <div class="content">
      <?= $msg ?>
      <div class="card">
        <h3>Organization Dashboard Admins</h3>
        <table>
          <thead><tr><th>ID</th><th>Username</th><th>Org</th><th>Reset</th></tr></thead>
          <tbody>
          <?php if ($admins && $admins->num_rows): ?>
            <?php while ($a = $admins->fetch_assoc()): ?>
              <tr>
                <td><?= $a['id'] ?></td>
                <td><?= htmlspecialchars($a['username']) ?> <small style="color:var(--muted)"><?= htmlspecialchars($a['full_name'] ?? '') ?></small></td>
                <td><?= htmlspecialchars($a['org_name'] ?? '—') ?> <small style="color:var(--muted)">#<?= $a['organization_id'] ?></small></td>
                <td>
                  <form method="POST" style="display:flex;gap:8px">
                    <input type="hidden" name="uid" value="<?= $a['id'] ?>">
                    <input type="text" name="newpass" placeholder="New password (8+)" required style="max-width:200px">
                    <button class="btn sm" name="reset" type="submit"><i class="fas fa-rotate"></i> Set</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="4" style="color:var(--muted)">No org admins.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
