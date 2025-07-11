<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Super Admin Dashboard</title>
  <link rel="icon" href="../images/dashboard_icon.png">
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
      flex-direction: row;
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
      flex-shrink: 0;
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
      flex-wrap: wrap;
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
      background-image: url("../images/dashboard.jpg");
      background-size: cover;
      background-position: center;
      flex: 1;
      padding: 30px;
      color: #ddd;
    }

    .org-info {
      font-size: 0.95rem;
      color: #ccc;
      margin-top: 10px;
    }

    @media (max-width: 768px) {
      body {
        flex-direction: column;
      }

      .sidebar {
        width: 100%;
        flex-direction: row;
        justify-content: space-around;
        padding: 10px;
      }

      .sidebar img {
        display: none;
      }

      .sidebar h2 {
        font-size: 1rem;
      }

      .sidebar a {
        font-size: 0.9rem;
        padding: 8px;
      }

      .header {
        justify-content: center;
        gap: 15px;
        padding: 10px 20px;
      }

      .content {
        padding: 20px;
        text-align: center;
      }

      .content h1 {
        font-size: 1.4rem;
      }
    }
  </style>
</head>
<body>
  <div class="sidebar">
    <img src="../images/logo.jpeg" alt="Company Logo">
    <h2>Super Admin</h2>
    <a href="create_organization.php">Create Organization</a>
    <a href="assign_admin.php">Assign Admin</a>
    <a href="view_organizations.php">View & manage Organizations</a>
    <a href="view_org_users.php">View Organization Users</a>
  </div>

  <div class="main">
    <div class="header">
      <a href="../change_password.php">Change Password</a>
      <a href="../logout.php">Logout</a>
    </div>

    <div class="content">
      <h1>Welcome, Super Admin</h1>
      <div class="org-info">
        <strong>Role:</strong> Super Admin
      </div>
    </div>
  </div>
</body>
</html>
