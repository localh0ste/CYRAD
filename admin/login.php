<?php
session_start();

// Generate manual CAPTCHA
function generateCaptcha() {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha = '';
    for ($i = 0; $i < 5; $i++) {
        $captcha .= $characters[rand(0, strlen($characters) - 1)];
    }
    $_SESSION['admin_captcha'] = $captcha;
}

// Generate new CAPTCHA on GET request
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    generateCaptcha();
}

$error = "";

// Handle POST request
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $captcha_input = strtoupper(trim($_POST['captcha'] ?? ''));
    $captcha_stored = $_SESSION['admin_captcha'] ?? '';
    $turnstile_response = $_POST['cf-turnstile-response'] ?? '';

    // 1. Manual CAPTCHA validation
    if ($captcha_input !== $captcha_stored) {
        $error = "Incorrect manual CAPTCHA.";
        generateCaptcha(); // refresh CAPTCHA
    } else {
        // 2. Cloudflare CAPTCHA validation
        if (!$turnstile_response) {
            $error = "Cloudflare CAPTCHA not verified.";
        } else {
            $verifyResponse = file_get_contents("https://challenges.cloudflare.com/turnstile/v0/siteverify", false, stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-type: application/x-www-form-urlencoded",
                    'content' => http_build_query([
                        'secret'   => '0x4AAAAAABkksOWq7ZTc6-RVtHJsoBuEEWw',
                        'response' => $turnstile_response,
                        'remoteip' => $_SERVER['REMOTE_ADDR']
                    ])
                ]
            ]));

            $captchaSuccess = json_decode($verifyResponse);
            if (!$captchaSuccess->success) {
                $error = "Cloudflare CAPTCHA verification failed.";
            } else {
                // 3. Proceed to check login credentials
                require_once __DIR__ . '/config/db.php';
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }

                $stmt = $conn->prepare("SELECT password, organization_id, role FROM admin_users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($hashedPassword, $organization_id, $role);
                    $stmt->fetch();

                    if (password_verify($password, $hashedPassword)) {
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_user'] = $username;
                        $_SESSION['organization_id'] = $organization_id;
                        $_SESSION['admin_role'] = $role;

                        if ($role === 'super_admin') {
                          header("Location: superUserFilesContainer/super_dashboard.php");
                        } else {
                          header("Location: dashboard.php");
                        }
                        exit;
                    } else {
                        $error = "Invalid username or password.";
                        generateCaptcha();
                    }
                } else {
                    $error = "Invalid username or password.";
                    generateCaptcha();
                }

                $stmt->close();
                $conn->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login</title>
  <link rel="icon" href="images/admin_icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background: url("images/background.jpg");
      background-size: cover;
      height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .login-card {
      background-color: rgba(0, 0, 0, 0.85);
      padding: 2.5rem;
      border-radius: 12px;
      width: 100%;
      max-width: 400px;
      color: #fff;
      box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
      animation: fadeIn 0.8s ease-in-out;
      text-align: center;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    h2 {
      margin-bottom: 1.5rem;
    }

    .input-group {
      position: relative;
      margin-bottom: 1.2rem;
    }

    .input-group i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #888;
    }

    input[type="text"],
    input[type="password"] {
      width: 100%;
      padding: 10px 12px 10px 36px;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      background: #1f1f1f;
      color: #fff;
    }

    input[type="text"]::placeholder,
    input[type="password"]::placeholder {
      color: #aaa;
    }

    .captcha-group {
      margin-top: 1rem;
      text-align: left;
    }

    .captcha-code {
      font-size: 1.5rem;
      font-weight: bold;
      background-color: #1f1f1f;
      padding: 10px;
      letter-spacing: 5px;
      border-radius: 8px;
      margin-bottom: 10px;
      color: #d51b1b;
    }

    .btn {
      width: 100%;
      padding: 12px;
      background-color: #d51b1b;
      border: none;
      border-radius: 8px;
      color: white;
      font-weight: bold;
      font-size: 1rem;
      cursor: pointer;
      transition: background 0.3s ease;
      margin-top: 10px;
    }

    .btn:hover {
      background-color: #a31212;
    }

    .error {
      color: #ff6b6b;
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>
  <div class="login-card">
    <h2>Admin Login</h2>

    <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>

    <form method="POST">
      <div class="input-group">
        <i class="fas fa-user"></i>
        <input type="text" name="username" placeholder="Username" required />
      </div>

      <div class="input-group">
        <i class="fas fa-lock"></i>
        <input type="password" name="password" placeholder="Password" required />
      </div>

      <!-- Manual CAPTCHA -->
      <div class="captcha-group">
        <label for="captcha">CAPTCHA:</label>
        <div class="captcha-code"><?php echo $_SESSION['admin_captcha']; ?></div>
        <input type="text" name="captcha" placeholder="Enter CAPTCHA" required />
      </div>

      <!-- Cloudflare CAPTCHA -->
      <div class="captcha-group">
        <div class="cf-turnstile" data-sitekey="0x4AAAAAABkksJ5If9NK0zB7"></div>
      </div>

      <button class="btn" type="submit">
        <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i>Login
      </button>
    </form>
  </div>
</body>
</html>
