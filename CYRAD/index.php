<?php
session_start();

// Generate CAPTCHA only on GET
if ($_SERVER["REQUEST_METHOD"] != "POST") {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $captcha = '';
  for ($i = 0; $i < 5; $i++) {
    $captcha .= $chars[rand(0, strlen($chars) - 1)];
  }
  $_SESSION['captcha'] = $captcha;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $username = trim($_POST["username"]);
  $password = trim($_POST["password"]);
  $captcha_input = strtoupper(trim($_POST["captcha"]));
  $captcha_session = $_SESSION["captcha"];

  // CAPTCHA validation
  if ($captcha_input !== $captcha_session) {
    $_SESSION['error'] = "Incorrect CAPTCHA. Please try again.";
    header("Location: login.php");
    exit;
  }

  // DB connection
  $host = "139.59.62.11";
  $db = "radius";
  $user = "radius";
  $pass = "buggy";

  $conn = new mysqli($host, $user, $pass, $db);
  if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
  }

  // First check radcheck for normal users
  $stmt = $conn->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $stmt->bind_result($stored_password);
  $stmt->fetch();
  $stmt->close();

  if ($stored_password && $stored_password === $password) {
    header("Location: keepalive.html");
    exit;
  }

  // If not a normal user, check guest_users table
  $stmt = $conn->prepare("SELECT password FROM guest_users WHERE username = ?");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $stmt->bind_result($guest_password);
  $stmt->fetch();
  $stmt->close();

  if ($guest_password && $guest_password === $password) {
    $_SESSION['username'] = $username; 
    header("Location: keepalive.html");
    exit;
  }

  // If both fail
  $_SESSION['error'] = "Invalid username or password.";
  header("Location: login.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CYRAD Authentication</title>
  <link rel="icon" href="images/favicon_logo.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #d51b1b;
      --accent: #f9f9f9;
      --radius: 12px;
      --shadow: 0 8px 24px rgba(0,0,0,0.15);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background: linear-gradient(135deg,#ffffff 0%, #e6e6e6 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: var(--accent);
      border: 2px solid var(--primary);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      animation: fadeIn 0.8s ease-out both;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .card-header {
      padding: 24px 16px 16px;
      text-align: center;
    }
    .card-header img {
      height: 64px;
      margin-bottom: 8px;
    }
    .card-header h1 {
      margin: 0;
      font-size: 1.35rem;
      font-weight: 700;
    }
    .card-body { padding: 0 32px 24px; }
    .note {
      font-size: 0.9rem;
      line-height: 1.4;
      margin: 16px 0 24px;
      color: #333;
    }
    label {
      display: block;
      margin-bottom: 6px;
      font-weight: 500;
    }
    input[type="text"],
    input[type="password"] {
      width: 100%;
      margin-bottom: 5px;
      padding: 10px 14px;
      font-size: 1rem;
      border: 1px solid #ccc;
      border-radius: var(--radius);
      outline: none;
      transition: border-color .25s ease;
    }
    input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 2px rgba(213,27,27,0.2);
    }
    .btn {
      margin-top: 24px;
      width: 100%;
      padding: 12px;
      font-size: 1rem;
      font-weight: 600;
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: var(--radius);
      cursor: pointer;
      transition: background .25s ease, transform .1s ease;
    }
    .btn:hover { background: #b11313; }
    .btn:active { transform: scale(.98); }
    .links {
      margin-top: 20px;
      font-size: 0.9rem;
      display: flex;
      flex-direction: column;
      gap: 6px;
      text-align: center;
    }
    .links a {
      color: var(--primary);
      text-decoration: none;
    }
    .links a:hover { text-decoration: underline; }
    .footer {
      text-align: center;
      font-size: 0.85rem;
      margin-top: 16px;
      color: #555;
    }
    .error {
      color: red;
      font-size: 0.9rem;
      margin-bottom: 12px;
      text-align: center;
    }
  </style>
</head>
<body>
  <main class="card" role="main" aria-labelledby="auth-title">
    <header class="card-header">
      <img src="images/CYRAD_LOGO.jpg" alt="Logo">
      <h1 id="auth-title">Authentication Required</h1>
    </header>
    <section class="card-body">
      <p class="note">
        Please enter your username and password to continue.
      </p>
      <?php
      if (isset($_SESSION['error'])) {
        echo '<p class="error">' . $_SESSION['error'] . '</p>';
        unset($_SESSION['error']);
      }
      ?>
      <form method="POST" action="login.php">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" required>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>

        <label for="captcha">Captcha: <strong><?php echo $_SESSION['captcha']; ?></strong></label>
        <input id="captcha" name="captcha" type="text" required>

        <button class="btn" type="submit">Continue</button>
      </form>
      <div class="links">
        <hr style="margin: 20px 0;">
        <a href="guest_registration.php">Register as Guest User</a>
      </div>
    </section>
  </main>
</body>
</html>
