<?php
session_start();

// Super admin only
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$org_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$org_id) {
    echo "Invalid organization ID.";
    exit;
}

// Fetch admin of this organization
$stmt = $conn->prepare("SELECT username, full_name, company_email, contact_number, gender, address, position FROM admin_users WHERE organization_id = ? AND role = 'admin'");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    echo "<p style='color: red; text-align:center; font-family:sans-serif;'>Admin not assigned for this organization.</p>";
    echo "<p style='text-align:center;'><a href='view_organizations.php'>← Back</a></p>";
    exit;
}

$stmt->bind_result($username, $full_name, $company_email, $contact_number, $gender, $address, $position);
$stmt->fetch();
$stmt->close();

// Get organization name
$org_name = "";
$org_stmt = $conn->prepare("SELECT org_name FROM organizations WHERE id = ?");
$org_stmt->bind_param("i", $org_id);
$org_stmt->execute();
$org_stmt->bind_result($org_name);
$org_stmt->fetch();
$org_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Profile</title>
  <link rel="icon" href="../images/admin_icon.png">
  <style>
    body {
      background: url("../images/dashboard.jpg") no-repeat center center fixed;
      background-size: cover;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #fff;
      margin: 0;
      padding: 40px;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
    }

    .profile-container {
      background: rgba(0, 0, 0, 0.85);
      padding: 30px;
      border-radius: 16px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.3);
      max-width: 600px;
      width: 100%;
    }

    h2 {
      text-align: center;
      color: rgba(250, 9, 78, 0.86);
      margin-bottom: 25px;
    }

    .profile-item {
      margin-bottom: 1rem;
    }

    .profile-item label {
      font-weight: bold;
      color: rgba(250, 9, 78, 0.86);
      display: block;
    }

    .profile-item div {
      padding: 10px;
      background-color: rgba(255,255,255,0.08);
      border-radius: 8px;
      margin-top: 5px;
    }

    .actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 20px;
    }

    .btn {
      display: inline-block;
      background-color: rgba(250, 9, 78, 0.86);
      color: white;
      padding: 10px 20px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: bold;
      transition: background 0.3s;
    }

    .btn:hover {
      background-color: #c1123e;
    }

    .back-link {
      color: #ccc;
      text-decoration: none;
    }

    .back-link:hover {
      color: white;
    }
  </style>
</head>
<body>
  <div class="profile-container">
    <h2>Admin Profile (<?= htmlspecialchars($org_name) ?>)</h2>

    <div class="profile-item">
      <label>Username</label>
      <div><?= htmlspecialchars($username) ?></div>
    </div>

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

    <div class="profile-item">
      <label>Organization ID</label>
      <div><?= htmlspecialchars($org_id) ?></div>
    </div>

    <div class="actions">
      <a href="edit_admin_profile.php?id=<?= $org_id ?>" class="btn">✏️ Edit Profile</a>
      <a href="view_organizations.php" class="back-link">← Back</a>
    </div>
  </div>
</body>
</html>
