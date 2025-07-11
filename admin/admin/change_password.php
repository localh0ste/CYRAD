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
  <meta charset="UTF-8">
  <title>Change Password</title>
  <link rel="icon" href="images/pass_icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background-image : url("images/background.jpg");
      background-size: cover;
      height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      color: white;
    }

    .card {
      background-color: rgba(0, 0, 0, 0.85);
      padding: 2.5rem;
      border-radius: 12px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 0 20px rgba(0,0,0,0.5);
    }

    h2 {
      text-align: center;
      margin-bottom: 1.5rem;
      color: #EF2C5A;
    }

    form {
      display: flex;
      flex-direction: column;
    }

    label {
      margin-top: 1rem;
      font-weight: 500;
    }

    input,
    .btn {
      width: 100%;
      padding: 12px;
      margin-top: 8px;
      border-radius: 8px;
      border: none;
      font-size: 1rem;
      box-sizing: border-box;
    }

    input {
      background: #1f1f1f;
      color: white;
    }

    .btn {
      background-color: #EF2C5A;
      color: white;
      font-weight: bold;
      cursor: pointer;
      margin-top: 1.5rem;
      transition: background-color 0.3s ease;
    }

    .btn:hover {
      background-color: #c1224b;
    }

    .error {
      margin-top: 1rem;
      color: #ff6b6b;
      text-align: center;
    }

    .success {
      margin-top: 1rem;
      color: #0f0;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="card">
    <h2>Change Password</h2>
    
    <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
    <?php if (!empty($success)) echo "<div class='success'>$success</div>"; ?>

    <form method="POST">
      <label for="old_password">Old Password</label>
      <input type="password" id="old_password" name="old_password" required>

      <label for="new_password">New Password</label>
      <input type="password" id="new_password" name="new_password" required>

      <label for="confirm_password">Confirm New Password</label>
      <input type="password" id="confirm_password" name="confirm_password" required>

      <button class="btn" type="submit">Update Password</button>
    </form>
  </div>
</body>
</html>
