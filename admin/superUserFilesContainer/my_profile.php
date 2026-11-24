<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$uid = (int) $_SESSION['admin_uid'];
$error = $success = "";

$stmt = $conn->prepare("SELECT full_name, telegram_uid FROM admin_users WHERE id = ?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$stmt->bind_result($full_name, $telegram_uid);
$stmt->fetch();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $telegram_uid = trim($_POST['telegram_uid'] ?? '');
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!empty($new)) {
        if (strlen($new) < 5) {
            $error = "New password must be at least 5 characters.";
        } elseif ($new !== $confirm) {
            $error = "Passwords do not match.";
        }
    }

    if (!$error) {
        if (!empty($new)) {
            $h = password_hash($new, PASSWORD_DEFAULT);
            $u = $conn->prepare("UPDATE admin_users SET full_name = ?, telegram_uid = ?, password = ? WHERE id = ?");
            $u->bind_param("sssi", $full_name, $telegram_uid, $h, $uid);
        } else {
            $u = $conn->prepare("UPDATE admin_users SET full_name = ?, telegram_uid = ? WHERE id = ?");
            $u->bind_param("ssi", $full_name, $telegram_uid, $uid);
        }
        if ($u->execute()) {
            $success = "Profile updated.";
        } else {
            $error = "Update failed.";
        }
        $u->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile</title>
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
    <header class="topbar"><div><strong>My Profile</strong></div></header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-id-badge" style="color:var(--cyan)"></i> Super Admin Profile</h3>
        <?php if (!empty($error)): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <form method="POST">
          <label>Full Name</label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($full_name) ?>">
          <label>Telegram Chat ID <small style="color:var(--muted)">(for OTP / alerts)</small></label>
          <input type="text" name="telegram_uid" value="<?= htmlspecialchars($telegram_uid) ?>" placeholder="Your Telegram numeric chat ID">
          <hr style="margin:22px 0;border-color:#3a2b33">
          <label>New Password <small style="color:var(--muted)">(leave blank to keep)</small></label>
          <input type="password" name="new_password" placeholder="New password">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" placeholder="Repeat password">
          <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
