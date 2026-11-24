<?php
session_start();

// --- Session-based add/sub CAPTCHA (anti-bot on admin login) ---
// Single challenge: random addition OR subtraction, both operands < 20.
function cyrad_new_captcha() {
    $a = random_int(0, 19);
    $b = random_int(0, 19);
    $op = (random_int(0, 1) === 0) ? '+' : '-';
    if ($op === '-') {
        if ($b > $a) { $t = $a; $a = $b; $b = $t; } // keep result non-negative
        $answer = $a - $b;
    } else {
        $answer = $a + $b;
    }
    $_SESSION['captcha_a'] = $a;
    $_SESSION['captcha_b'] = $b;
    $_SESSION['captcha_op'] = $op;
    $_SESSION['captcha_answer'] = $answer;
}
if (!isset($_SESSION['captcha_answer'])) {
    cyrad_new_captcha();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $captcha_input = trim($_POST['captcha'] ?? '');

    // Validate CAPTCHA first (random add or subtract)
    if (!isset($_SESSION['captcha_answer']) || (int)$captcha_input !== (int)$_SESSION['captcha_answer']) {
        $error = "Incorrect security code. Please try again.";
        cyrad_new_captcha();
    } else {
        require_once __DIR__ . '/config/db.php';
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $stmt = $conn->prepare("SELECT id, password, organization_id, role FROM admin_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($uid, $hashedPassword, $organization_id, $role);
            $stmt->fetch();

            if (password_verify($password, $hashedPassword)) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user'] = $username;
                $_SESSION['admin_uid'] = $uid;
                $_SESSION['organization_id'] = $organization_id;
                $_SESSION['admin_role'] = $role;

                // Rotate CAPTCHA after a successful login
                cyrad_new_captcha();
                header("Location: " . ($role === 'super_admin' ? 'superUserFilesContainer/super_dashboard.php' : 'dashboard.php'));
                exit;
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
        $stmt->close();
        $conn->close();
        cyrad_new_captcha();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — Login</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
  <div class="login-wrap">
    <form class="login-card glass" method="POST">
      <div class="brand" style="justify-content:center;margin-bottom:10px">
        <div>CYRAD<small>CONTROL PANEL</small></div>
      </div>
      <div class="title">Administrator Login</div>
      <div class="sub">Secure access to your RADIUS control plane</div>

      <?php if (!empty($error)) echo "<div class='msg err'>$error</div>"; ?>

      <label style="text-align:left">Username</label>
      <input type="text" name="username" placeholder="Username" required>
      <label style="text-align:left">Password</label>
      <input type="password" name="password" placeholder="Password" required>

      <label style="text-align:left">Security check</label>
      <div class="captcha-q"><strong><?= (int)($_SESSION['captcha_a'] ?? 0) ?> <?= $_SESSION['captcha_op'] ?? '+' ?> <?= (int)($_SESSION['captcha_b'] ?? 0) ?> = ?</strong></div>
      <input type="text" name="captcha" autocomplete="off" placeholder="Security code" required>

      <p><button class="btn" type="submit" style="width:100%;justify-content:center;margin-top:18px">
        <i class="fas fa-right-to-bracket"></i> Login
      </button></p>
      <p style="text-align:center;margin-top:10px"><a href="forgot_password.php" style="color:var(--muted);font-size:.85rem">Forgot password?</a></p>
    </form>
  </div>
</body>
</html>
