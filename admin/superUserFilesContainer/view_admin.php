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
    $stmt->close();
    $conn->close();
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><link rel='stylesheet' href='../assets/theme.css'></head><body><div class='app'><div class='main'><div class='content'><div class='card glass'><div class='msg err'>Admin not assigned for this organization.</div><a class='btn ghost' href='view_organizations.php'><i class='fas fa-arrow-left'></i> Back</a></div></div></div></div></body></html>";
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Profile</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>SUPER ADMIN</small></div></div>
    <?php require dirname(__DIR__) . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar"><div><strong>Admin Profile</strong></div></header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-id-badge" style="color:var(--cyan)"></i> Admin Profile (<?= htmlspecialchars($org_name) ?>)</h3>

        <div class="profile-item"><label>Username</label><div><?= htmlspecialchars($username) ?></div></div>
        <div class="profile-item"><label>Full Name</label><div><?= htmlspecialchars($full_name) ?></div></div>
        <div class="profile-item"><label>Company Email</label><div><?= htmlspecialchars($company_email) ?></div></div>
        <div class="profile-item"><label>Contact Number</label><div><?= htmlspecialchars($contact_number) ?></div></div>
        <div class="profile-item"><label>Gender</label><div><?= htmlspecialchars($gender) ?></div></div>
        <div class="profile-item"><label>Address</label><div><?= htmlspecialchars($address) ?></div></div>
        <div class="profile-item"><label>Position</label><div><?= htmlspecialchars($position) ?></div></div>
        <div class="profile-item"><label>Organization ID</label><div><?= htmlspecialchars($org_id) ?></div></div>

        <div class="actions">
          <a href="edit_admin_profile.php?id=<?= $org_id ?>" class="btn"><i class="fas fa-pen"></i> Edit Profile</a>
          <a href="view_organizations.php" class="btn ghost"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
