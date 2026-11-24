<?php
session_start();

$error = '';
$success = '';
$step = 1;

require_once __DIR__ . '/lib/telegram_notify.php';

function fp_db() {
    require_once __DIR__ . '/config/db.php';
    return $conn;
}

// Step 2: verify OTP + set new password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    $otp = trim($_POST['otp'] ?? '');
    $pw  = $_POST['new_password'] ?? '';
    $pw2 = $_POST['confirm_password'] ?? '';

    if (empty($_SESSION['fp_username']) || empty($_SESSION['fp_otp']) || empty($_SESSION['fp_exp']) || time() > (int)$_SESSION['fp_exp']) {
        $error = 'Session expired. Please request a new code.';
        session_unset();
    } elseif ((string)$otp !== (string)$_SESSION['fp_otp']) {
        $error = 'Incorrect OTP. Please try again.';
    } elseif (strlen($pw) < 5) {
        $error = 'New password must be at least 5 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $conn = fp_db();
        $h = password_hash($pw, PASSWORD_DEFAULT);
        $u = $_SESSION['fp_username'];
        $stmt = $conn->prepare("UPDATE admin_users SET password = ? WHERE username = ? AND role IN ('admin','super_admin')");
        $stmt->bind_param("ss", $h, $u);
        if ($stmt->execute()) {
            $success = 'Password changed successfully. You can now log in.';
            $step = 3;
            session_unset();
        } else {
            $error = 'Failed to update password.';
        }
        $stmt->close();
        $conn->close();
    }
}

// Step 1: request OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    $username = trim($_POST['username'] ?? '');
    $conn = fp_db();
    $stmt = $conn->prepare(
        "SELECT au.username, au.telegram_uid, o.telegram_bot_token
         FROM admin_users au
         LEFT JOIN organizations o ON o.id = au.organization_id
         WHERE au.username = ? AND au.role IN ('admin','super_admin')"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($db_user, $telegram_uid, $bot_token);
    if (!$stmt->fetch() || empty($telegram_uid) || empty($bot_token)) {
        $error = 'Telegram OTP is not configured for this account. A super-admin must set the organization bot token (Super Admin → View Organizations → edit) and their own chat ID (Super Admin → My Profile).';
    } else {
        $otp = (string) random_int(100000, 999999);
        $_SESSION['fp_username'] = $db_user;
        $_SESSION['fp_otp'] = $otp;
        $_SESSION['fp_exp'] = time() + 600;
        $res = cyrad_send_telegram_to($bot_token, $telegram_uid,
            "CYRAD super-admin password reset code: <b>$otp</b>\nThis code expires in 10 minutes.");
        if (!empty($res['ok'])) {
            $success = 'An OTP has been sent to your Telegram. Enter it below to set a new password.';
            $step = 2;
        } else {
            $error = 'Failed to send Telegram OTP: ' . ($res['error'] ?? 'unknown error') . '. Check the bot token and your chat ID.';
        }
    }
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD — Super Admin Password Reset</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
  <div class="login-wrap">
    <form class="login-card glass" method="POST">
      <div class="brand" style="justify-content:center;margin-bottom:10px">
        <div>CYRAD<small>SUPER ADMIN</small></div>
      </div>
      <div class="title">Reset Password</div>
      <div class="sub">Verify via Telegram OTP</div>

      <?php if (!empty($error)) echo "<div class='msg err'>$error</div>"; ?>
      <?php if (!empty($success) && $step !== 3) echo "<div class='msg ok'>$success</div>"; ?>
      <?php if ($step === 3): ?>
        <div class="msg ok"><?= htmlspecialchars($success) ?></div>
        <p><a class="btn" href="login.php" style="width:100%;justify-content:center;margin-top:18px"><i class="fas fa-right-to-bracket"></i> Back to Login</a></p>
      <?php elseif ($step === 2): ?>
        <input type="hidden" name="action" value="verify">
        <label style="text-align:left">OTP (from Telegram)</label>
        <input type="text" name="otp" autocomplete="off" placeholder="6-digit code" required>
        <label style="text-align:left">New Password</label>
        <input type="password" name="new_password" placeholder="New password" required>
        <label style="text-align:left">Confirm Password</label>
        <input type="password" name="confirm_password" placeholder="Repeat password" required>
        <p><button class="btn" type="submit" style="width:100%;justify-content:center;margin-top:18px"><i class="fas fa-key"></i> Set New Password</button></p>
        <p style="text-align:center;margin-top:10px"><a href="forgot_password.php" style="color:var(--muted);font-size:.85rem">Request a new code</a></p>
      <?php else: ?>
        <input type="hidden" name="action" value="request">
        <label style="text-align:left">Super-admin Username</label>
        <input type="text" name="username" placeholder="Username" required>
        <p><button class="btn" type="submit" style="width:100%;justify-content:center;margin-top:18px"><i class="fas fa-paper-plane"></i> Send OTP</button></p>
      <?php endif; ?>
      <p style="text-align:center;margin-top:10px"><a href="login.php" style="color:var(--muted);font-size:.85rem">Back to Login</a></p>
    </form>
  </div>
</body>
</html>
