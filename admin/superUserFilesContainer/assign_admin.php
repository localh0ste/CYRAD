<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$error = $success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $full_name = trim($_POST['full_name']);
    $company_email = trim($_POST['company_email']);
    $contact_number = trim($_POST['contact_number']);
    $gender = $_POST['gender'];
    $address = trim($_POST['address']);
    $position = trim($_POST['position']);
    $organization_id = intval($_POST['organization_id']);

    if (empty($username) || empty($password) || !$organization_id) {
        $error = "Please fill all required fields.";
    } else {
        // Remove old admin if one exists
        $checkAdmin = $conn->prepare("SELECT id FROM admin_users WHERE organization_id = ? AND role = 'admin'");
        $checkAdmin->bind_param("i", $organization_id);
        $checkAdmin->execute();
        $checkAdmin->store_result();

        if ($checkAdmin->num_rows > 0) {
            $deleteOld = $conn->prepare("DELETE FROM admin_users WHERE organization_id = ? AND role = 'admin'");
            $deleteOld->bind_param("i", $organization_id);
            $deleteOld->execute();
            $deleteOld->close();
        }
        $checkAdmin->close();

        // Insert new admin
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO admin_users (username, password, full_name, company_email, contact_number, gender, address, position, organization_id, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin')");
        $stmt->bind_param("ssssssssi", $username, $hashedPassword, $full_name, $company_email, $contact_number, $gender, $address, $position, $organization_id);

        if ($stmt->execute()) {
            $success = "Admin assigned successfully.";
        } else {
            $error = "Failed to assign admin: " . $stmt->error;
        }
        $stmt->close();
    }
}

$orgs = $conn->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Assign Admin</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>SUPER ADMIN</small></div></div>
    <?php require dirname(__DIR__) . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar"><div><strong>Assign Admin to Organization</strong></div></header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-user-shield" style="color:var(--cyan)"></i> Assign Admin</h3>

        <?php if (!empty($error)): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
          <label>Select Organization *</label>
          <select name="organization_id" required>
            <option value="">-- Select Organization --</option>
            <?php while($org = $orgs->fetch_assoc()): ?>
              <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['org_name']) ?></option>
            <?php endwhile; ?>
          </select>

          <label>Username *</label>
          <input type="text" name="username" required>

          <label>Password *</label>
          <input type="password" name="password" required>

          <label>Full Name</label>
          <input type="text" name="full_name">

          <label>Company Email</label>
          <input type="email" name="company_email">

          <label>Contact Number</label>
          <input type="text" name="contact_number">

          <label>Gender</label>
          <select name="gender">
            <option value="">-- Gender --</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>

          <label>Address</label>
          <textarea name="address" rows="2"></textarea>

          <label>Position</label>
          <input type="text" name="position">

          <button type="submit" class="btn"><i class="fas fa-user-shield"></i> Assign Admin</button>
        </form>

        <a href="super_dashboard.php" class="btn ghost" style="margin-top:14px"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
