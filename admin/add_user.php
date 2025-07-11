<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://fonts.googleapis.com 'unsafe-inline'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_user']) || !isset($_SESSION['organization_id'])) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

function sanitize_input($data, $is_password = false) {
    return $is_password ? trim($data) : strip_tags(htmlspecialchars(trim($data), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function validate_username($username) {
    return preg_match('/^[a-zA-Z0-9_\-\.]{4,32}$/', $username);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token.";
        header("Location: add_user.php");
        exit;
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // regenerate

    $username = strtolower(sanitize_input($_POST['username'] ?? ''));
    $password = sanitize_input($_POST['password'] ?? '', true);
    $internet_type = sanitize_input($_POST['internet_type'] ?? '');
    $internet_limit_mb = ($internet_type === 'limited') ? ($_POST['internet_limit'] ?? '') : null;
    $organization_id = $_SESSION['organization_id'];

    if (!validate_username($username)) {
        $_SESSION['error'] = "Username must be 4–32 characters: only letters, numbers, underscore, hyphen, dot.";
        header("Location: add_user.php");
        exit;
    }

    if (empty($password) || strlen($password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters.";
        header("Location: add_user.php");
        exit;
    }

    if (!in_array($internet_type, ['unlimited', 'limited'])) {
        $_SESSION['error'] = "Invalid internet type selected.";
        header("Location: add_user.php");
        exit;
    }

    if ($internet_type === 'limited') {
        if (empty($internet_limit_mb) || !is_numeric($internet_limit_mb) || $internet_limit_mb < 100) {
            $_SESSION['error'] = "Internet limit must be at least 100MB.";
            header("Location: add_user.php");
            exit;
        }
        $internet_limit_bytes = intval($internet_limit_mb) * 1024 * 1024;
    }

    require_once __DIR__ . '/config/db.php';
    if ($conn->connect_error) {
        $_SESSION['error'] = "Database connection failed: " . $conn->connect_error;
        header("Location: add_user.php");
        exit;
    }

    $check = $conn->prepare("SELECT username FROM radcheck WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $_SESSION['error'] = "Username already exists.";
        $check->close();
        $conn->close();
        header("Location: add_user.php");
        exit;
    }

    $check->close();

    $conn->begin_transaction();
    try {
        $stmt1 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value, organization_id) VALUES (?, 'Cleartext-Password', ':=', ?, ?)");
        $stmt1->bind_param("ssi", $username, $password, $organization_id);
        $stmt1->execute();
        $stmt1->close();

        if ($internet_type === 'limited') {
            $stmt2 = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit, organization_id) VALUES (?, ?, ?, ?)");
            $stmt2->bind_param("ssii", $username, $internet_type, $internet_limit_bytes, $organization_id);
            $stmt2->execute();
            $stmt2->close();
        }

        $conn->commit();
        $_SESSION['success'] = "User added successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Add user failed: " . $e->getMessage());
        $_SESSION['error'] = "Failed to add user. Error: " . $e->getMessage();
    }

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
      display: block;
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
      width: 100%;
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
    .error { background: rgba(255, 0, 0, 0.2); color: #ff4c4c; }
    .success { background: rgba(0, 255, 0, 0.2); color: #2ecc71; }
    #mb-input-container { 
      display: none;
      margin-top: 1rem;
    }
    .back { 
      margin-top: 20px; 
      display: inline-block; 
      color: #ccc; 
      text-decoration: none; 
      text-align: center;
      width: 100%;
    }
    .back:hover { color: white; }
    .form-group {
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>
  <div class="form-container">
    <h2>Add User</h2>
    <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="form-group">
        <label for="username">Username *</label>
        <input type="text" name="username" id="username" required value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
      </div>

      <div class="form-group">
        <label for="password">Password *</label>
        <input type="password" name="password" id="password" required>
      </div>

      <div class="form-group">
        <label for="internet_type">Internet Type *</label>
        <select name="internet_type" id="internet_type" required>
          <option value="">-- Select --</option>
          <option value="unlimited" <?= (isset($_POST['internet_type']) && $_POST['internet_type'] === 'unlimited') ? 'selected' : '' ?>>Unlimited</option>
          <option value="limited" <?= (isset($_POST['internet_type']) && $_POST['internet_type'] === 'limited') ? 'selected' : '' ?>>Limited</option>
        </select>
      </div>

      <div class="form-group" id="mb-input-container">
        <label for="internet_limit">Internet Limit (in MB) *</label>
        <input type="number" name="internet_limit" id="internet_limit" min="100" value="<?= isset($_POST['internet_limit']) ? htmlspecialchars($_POST['internet_limit']) : '' ?>">
      </div>

      <button class="btn" type="submit"><b>Add User</b></button>
    </form>

    <a class="back" href="users.php">&larr; Back to Users</a>
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

    document.addEventListener("DOMContentLoaded", function() {
      const internetTypeSelect = document.getElementById('internet_type');
      
      // Initialize based on current selection (for form repopulation)
      toggleMBInput(internetTypeSelect.value);
      
      internetTypeSelect.addEventListener('change', function() {
        toggleMBInput(this.value);
      });
    });
  </script>
</body>
</html>