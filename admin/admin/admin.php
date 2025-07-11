<?php
session_start();

// Check login
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_user'])) {
    header("Location: login.php");
    exit;
}

// DB Connection
require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch admin details
$username = $_SESSION['admin_user'];
$stmt = $conn->prepare("SELECT full_name, company_email, contact_number, gender, address, position FROM admin_users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($full_name, $company_email, $contact_number, $gender, $address, $position);
$stmt->fetch();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Admin Profile</title>
  <link rel="icon" href="images/admin_icon.png" />
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet" />
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background: url("images/background.jpg");
      background-size:cover;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
    }

    .profile-container {
      background-color: rgba(0, 0, 0, 0.85);
      backdrop-filter: blur(8px);
      border-radius: 16px;
      padding: 1.5rem;
      max-width: 600px;
      width: 90%;
      box-shadow: 0 8px 20px rgba(0,0,0,0.3);
      animation: fadeIn 0.8s ease-in-out;
    }

    h2 {
      text-align: center;
      margin-bottom: 2rem;
      font-size: 1.8rem;
      color: rgba(250, 9, 78, 0.86);
    }

    .profile-item {
      margin-bottom: 1.5rem;
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
    .profile-item label {
      display: block;
      font-weight: 700;
      color: rgba(250, 9, 78, 0.86);
      margin-bottom: 5px;
      font-size: 0.95rem;
    }

    .profile-item div {
      font-size: 1.05rem;
      color: #f1f1f1;
      padding: 10px 14px;
      background-color: rgba(255,255,255,0.08);
      border-radius: 8px;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media screen and (max-width: 600px) {
      .profile-container {
        padding: 2rem;
      }

      h2 {
        font-size: 1.5rem;
      }

      .profile-item div {
        font-size: 1rem;
      }
    }
    
  </style>
</head>
<body>
  <div class="profile-container">
    <h2>Admin Profile</h2>

    <div class="profile-item">
      <label>Full Name</label>
      <div><?= htmlspecialchars($full_name) ?></div>
    </div>

    <div class="profile-item">
      <label>Company Email</label>
      <div><?= htmlspecialchars($company_email) ?></div>
    </div>

    <div class="profile-item">
      <label>Contact Number</label>
      <div><?= htmlspecialchars($contact_number) ?></div>
    </div>

    <div class="profile-item">
      <label>Gender</label>
      <div><?= htmlspecialchars($gender) ?></div>
    </div>

    <div class="profile-item">
      <label>Address</label>
      <div><?= htmlspecialchars($address) ?></div>
    </div>

    <div class="profile-item">
      <label>Position</label>
      <div><?= htmlspecialchars($position) ?></div>
    </div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
  <a href="edit_admin_profile.php" style="
    display: inline-block;
    background-color: rgba(250, 9, 78, 0.86);
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
  ">✏️ Edit Profile</a>

  <a class="back" href="dashboard.php" style="
    color: #ccc;
    text-decoration: none;
    font-size: 15px;
  ">&larr; Back</a>
</div>

  </div>  
</body>
</html>
