<?php
// Start session at very top
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard</title>
  <link rel="icon" href="images/dashboard_icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Roboto', sans-serif;
    }

    body {
      background: rgb(31, 225, 177);
      display: flex;
      height: 100vh;
      color: white;
    }

    .sidebar {
      width: 240px;
      background-color: rgb(12, 1, 23);
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 20px;
      box-shadow: 2px 0 5px rgba(0, 0, 0, 0.3);
      align-items: center;
    }

    .sidebar img {
      width: 230px;
      height: auto;
      border-radius: 10px;
      margin-bottom: 10px;
    }

    .sidebar h2 {
      color: rgba(250, 9, 78, 0.86);
      font-size: 1.2rem;
      margin-bottom: 5px;
    }

    .sidebar a {
      color: rgba(214, 201, 205, 0.86);
      text-decoration: none;
      font-size: 1rem;
      transition: color 0.3s;
      width: 100%;
      text-align: center;
    }

    .sidebar a:hover {
      color: white;
    }

    .main {
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    .header {
      background-color: #1c1c1c;
      padding: 15px 30px;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 20px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
    }

    .header a {
      color: white;
      text-decoration: none;
      font-weight: 500;
      transition: color 0.3s;
    }

    .header a:hover {
      color: rgba(255, 2, 74, 0.5);
    }

    .content {
      background-image: url("images/dashboard.jpg");
      background-size: cover;
      background-position: center;
      flex: 1;
      padding: 30px;
      color: #ddd;
    }
  </style>
</head>
<body>
  <div class="sidebar">
    <img src="images/logo.jpeg" alt="Company Logo">
    <h2>Admin Panel</h2>
    <a href="admin.php">Admin</a>
    <a href="users.php">Users</a>
  </div>

  <div class="main">
    <div class="header">
      <a href="change_password.php">Change Password</a>
      <a href="logout.php">Logout</a>
    </div>

    <div class="content">
      <h1>Welcome to the Dashboard</h1>
    </div>
  </div>
</body>
</html>
