<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_user'])) {
    header("Location: login.php");
    exit;
}

$success = "";
$error = "";

// Database connection
require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$current_user = $_SESSION['admin_user'];

// Ensure organization_id is stored in session
if (!isset($_SESSION['organization_id'])) {
    $stmt_org = $conn->prepare("SELECT organization_id FROM admin_users WHERE username = ?");
    $stmt_org->bind_param("s", $current_user);
    $stmt_org->execute();
    $stmt_org->bind_result($org_id);
    if ($stmt_org->fetch()) {
        $_SESSION['organization_id'] = $org_id;
    }
    $stmt_org->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Get current hashed password from DB
    $stmt = $conn->prepare("SELECT password FROM admin_users WHERE username = ?");
    $stmt->bind_param("s", $current_user);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($db_password);
        $stmt->fetch();

        // Verify old password
        if (!password_verify($old, $db_password)) {
            $error = "Old password is incorrect.";
        } elseif (strlen($new) < 5) {
            $error = "New password must be at least 5 characters.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            // Hash and update new password
            $hashed_new = password_hash($new, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE admin_users SET password = ? WHERE username = ?");
            $update->bind_param("ss", $hashed_new, $current_user);
            if ($update->execute()) {
                $success = "Password changed successfully!";
            } else {
                $error = "Failed to update password. Try again.";
            }
            $update->close();
        }
    } else {
        $error = "User not found.";
    }

    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Change Password</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>CONTROL PANEL</small></div></div>
    <?php require __DIR__ . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar"><div><strong>Change Password</strong></div></header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-key" style="color:var(--cyan)"></i> Change Password</h3>

        <?php if (!empty($error)): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
          <label for="old_password">Old Password</label>
          <input type="password" id="old_password" name="old_password" required>

          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" required>

          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required>

          <button class="btn" type="submit"><i class="fas fa-key"></i> Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
