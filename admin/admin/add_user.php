<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_user'])) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid CSRF token.";
        header("Location: add_user.php");
        exit;
    }

    $username = trim(strtolower($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $internet_type = $_POST['internet_type'] ?? '';
    $internet_limit_mb = $internet_type === 'limited' ? ($_POST['internet_limit'] ?? '') : null;

    if (empty($username) || empty($password) || empty($internet_type) || ($internet_type === 'limited' && empty($internet_limit_mb))) {
        $_SESSION['error'] = "Please fill all required fields.";
        header("Location: add_user.php");
        exit;
    }

    require_once __DIR__ . '/config/db.php';
    if ($conn->connect_error) {
        $_SESSION['error'] = "Database connection failed: " . $conn->connect_error;
        header("Location: add_user.php");
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $limit_bytes = ($internet_type === 'limited') ? intval($internet_limit_mb) * 1024 * 1024 : null;

    $check = $conn->prepare("SELECT username FROM radcheck WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $_SESSION['error'] = "Username already exists.";
    } else {
        $stmt1 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
        $stmt1->bind_param("ss", $username, $hashedPassword);
        $successInsert = $stmt1->execute();

        if ($successInsert && $internet_type === 'limited') {
            $stmt2 = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit) VALUES (?, ?, ?)");
            $stmt2->bind_param("ssi", $username, $internet_type, $limit_bytes);
            $successInsert = $stmt2->execute();
            $stmt2->close();
        }

        if ($successInsert) {
            $_SESSION['success'] = "User added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add user. Error: " . $conn->error;
        }

        $stmt1->close();
    }

    $check->close();
    $conn->close();
    header("Location: add_user.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add User</title>
  <link rel="icon" href="images/user_icon.PNG">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Roboto', sans-serif;
      background: url("images/CYNDIA.jpg");
      background-size: cover;
      padding: 40px;
      margin: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      color: #fff;
    }
    .form-container {
      background-color: #222;
      backdrop-filter: blur(8px);
      padding: 30px 40px;
      border-radius: 16px;
      max-width: 550px;
      width: 100%;
      box-shadow: 0 8px 20px rgba(0,0,0,0.3);
    }
    h2 {
      text-align: center;
      margin-bottom: 20px;
      color: rgba(250, 9, 78, 0.86);
    }
    form {
      display: flex;
      flex-direction: column;
    }
    label {
      font-weight: bold;
      margin-top: 1rem;
      margin-bottom: 5px;
    }
    input, select {
      background: #333;
      color: #fff;
      padding: 10px;
      font-size: 16px;
      border-radius: 8px;
      border: none;
      width: 100%;
      box-sizing: border-box;
    }
    .btn {
      margin-top: 20px;
      padding: 12px;
      background: rgba(250, 9, 78, 0.86);
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      cursor: pointer;
    }
    .btn:hover {
      background: rgb(184, 16, 55);
    }
    .error, .success {
      text-align: center;
      margin-top: 10px;
      font-weight: bold;
      padding: 10px;
      border-radius: 8px;
    }
    .error {
      background: rgba(255, 0, 0, 0.2);
      color: #ff4c4c;
    }
    .success {
      background: rgba(0, 255, 0, 0.2);
      color: #2ecc71;
    }
    #mb-input-container { display: none; }
    select, option {
      color: #fff;
      background-color: rgba(30, 30, 30, 0.95);
    }
    .back {
            margin-top: 20px;
            display: inline-block;
            color: #ccc;
            text-decoration: none;
        }
        .back:hover {
            color: white;
        }
  </style>
</head>
<body>
  <div class="form-container">
    <h2>Add User</h2>
    <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
    <?php if (!empty($success)) echo "<div class='success'>$success</div>"; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <label for="username">Username *</label>
      <input type="text" name="username" required>

      <label for="password">Password *</label>
      <input type="password" name="password" required>

      <label for="internet_type">Internet Type *</label>
      <select name="internet_type" id="internet_type" required onchange="toggleMBInput(this.value)">
        <option value="">-- Select --</option>
        <option value="unlimited">Unlimited</option>
        <option value="limited">Limited</option>
      </select>

      <div id="mb-input-container">
        <label for="internet_limit">Internet Limit (in MB) *</label>
        <input type="number" name="internet_limit" id="internet_limit">
      </div>

      <button class="btn" type="submit"><b>Add User</b></button>
    </form>
    <a class="back" href="users.php">&larr; Back</a>
  </div>

  <script>
    function toggleMBInput(value) {
      const mbContainer = document.getElementById('mb-input-container');
      const mbInput = document.getElementById('internet_limit');
      if (value === 'limited') {
        mbContainer.style.display = 'block';
        mbInput.required = true;
      } else {
        mbContainer.style.display = 'none';
        mbInput.required = false;
        mbInput.value = '';
      }
    }
  </script>
</body>
</html>
