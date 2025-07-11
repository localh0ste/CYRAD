<?php
$success = false;
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $host = "139.59.62.11";
    $db = "radius";
    $user = "radius";
    $pass = "buggy";

    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if (empty($username) || empty($password)) {
        $error = "Username and password are required.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = "Username must be 3-20 characters long and can contain letters, numbers, and underscores.";
    } else {
        $conn = new mysqli($host, $user, $pass, $db);
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $check = $conn->prepare("SELECT id FROM radcheck WHERE username = ? LIMIT 1");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username already exists. Please choose another.";
            $check->close();
        } else {
            $check->close();

            $limit_bytes = 2 * 1024 * 1024 * 1024; // 2GB

            // Insert into radcheck
            $stmt = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
            $stmt->bind_param("ss", $username, $password);
            $stmt->execute();
            $stmt->close();

            // Clean previous Max-Octets if any
            $cleanup = $conn->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Max-Octets'");
            $cleanup->bind_param("s", $username);
            $cleanup->execute();
            $cleanup->close();

            // Insert Max-Octets (2GB)
            $stmt = $conn->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Max-Octets', ':=', ?)");
            $stmt->bind_param("ss", $username, $limit_bytes);
            $stmt->execute();
            $stmt->close();

            $conn->close();
            $success = true;

            // Optional: Redirect after success
            // header("Location: login.php?username=" . urlencode($username));
            // exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CYRAD Guest Access</title>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #d51b1b;
      --accent: #f9f9f9;
      --radius: 12px;
      --shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background: linear-gradient(135deg, #ffffff 0%, #e6e6e6 100%);
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .card {
      width: 100%;
      max-width: 440px;
      background: var(--accent);
      border: 2px solid var(--primary);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      animation: fadeIn 0.8s ease-out both;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
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
      font-size: 1.3rem;
      font-weight: 700;
    }
    .card-body {
      padding: 0 32px 24px;
    }
    label {
      display: block;
      margin-bottom: 6px;
      font-weight: 500;
    }
    input {
      width: 100%;
      padding: 10px 14px;
      font-size: 1rem;
      border: 1px solid #ccc;
      border-radius: var(--radius);
      outline: none;
      margin-bottom: 16px;
    }
    input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 2px rgba(213, 27, 27, 0.2);
    }
    .btn {
      width: 100%;
      padding: 12px;
      font-size: 1rem;
      font-weight: 600;
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: var(--radius);
      cursor: pointer;
      margin-top: 10px;
    }
    .btn:hover { background: #b11313; }
    .error {
      color: red;
      font-size: 0.85rem;
      margin-bottom: 10px;
    }
    .success {
      background: #e7ffe7;
      color: #0a850a;
      padding: 12px;
      margin-top: 16px;
      border: 1px solid #0a850a;
      border-radius: var(--radius);
    }
    .links {
      margin-top: 20px;
      text-align: center;
      font-size: 0.9rem;
    }
    .links a {
      text-decoration: none;
      color: var(--primary);
    }
    a:hover { text-decoration: underline; }
    .note {
      font-size: 0.9rem;
      margin-bottom: 10px;
      color: #555;
    }
  </style>
</head>
<body>
  <main class="card" role="main" aria-labelledby="guest-title">
    <header class="card-header">
      <img src="images/CYRAD_LOGO.jpg" alt="CYRAD Logo">
      <h1 id="guest-title">Guest Access</h1>
    </header>
    <section class="card-body">
      <?php if ($success): ?>
        <div class="success">
          Guest account created successfully!<br>
          <strong>Username:</strong> <?= htmlspecialchars($username) ?><br>
          <strong>Limit:</strong> 2GB<br>
          <br>
          <a href="login.php" class="btn" style="text-decoration: none; display: inline-block;">Go to Login</a>
        </div>
      <?php else: ?>
        <p class="note">Register once and get 2GB internet access using your guest credentials.</p>
        <?php if (!empty($error)): ?>
          <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="">
          <label for="username">Username:</label>
          <input id="username" name="username" type="text" required placeholder="Enter username" />

          <label for="password">Password:</label>
          <input id="password" name="password" type="password" required placeholder="Enter password" />

          <button class="btn" type="submit">Register</button>
        </form>
      <?php endif; ?>
      <div class="links">
        <a href="login.php">Already a user? Login</a>
      </div>
    </section>
  </main>
</body>
</html>
