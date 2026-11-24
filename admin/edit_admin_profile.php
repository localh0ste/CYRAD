<?php
session_start();
if (
    !isset($_SESSION['admin_logged_in']) ||
    !isset($_SESSION['admin_user']) ||
    !isset($_SESSION['organization_id'])
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';

$username = $_SESSION['admin_user'];
$organization_id = $_SESSION['organization_id'];
$error = "";
$success = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name']);
    $company_email = trim($_POST['company_email']);
    $contact_number = trim($_POST['contact_number']);
    $gender = trim($_POST['gender']);
    $address = trim($_POST['address']);
    $position = trim($_POST['position']);

    $stmt = $conn->prepare("UPDATE admin_users SET full_name=?, company_email=?, contact_number=?, gender=?, address=?, position=? WHERE username=? AND organization_id=?");
    $stmt->bind_param("sssssssi", $full_name, $company_email, $contact_number, $gender, $address, $position, $username, $organization_id);

    if ($stmt->execute()) {
        $success = "Profile updated successfully!";
    } else {
        $error = "Failed to update profile.";
    }
    $stmt->close();
}

// Fetch current data
$stmt = $conn->prepare("SELECT full_name, company_email, contact_number, gender, address, position FROM admin_users WHERE username = ? AND organization_id = ?");
$stmt->bind_param("si", $username, $organization_id);
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Profile</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>CONTROL PANEL</small></div></div>
    <?php require __DIR__ . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar">
      <div><strong>Edit Profile</strong></div>
      <div class="org"><?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?> · Org #<?= (int)($_SESSION['organization_id'] ?? 0) ?></div>
    </header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-id-badge" style="color:var(--cyan)"></i> Edit Profile</h3>

        <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST">
          <label>Full Name</label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($full_name) ?>" required>

          <label>Company Email</label>
          <input type="email" name="company_email" value="<?= htmlspecialchars($company_email) ?>" required>

          <label>Contact Number</label>
          <input type="text" name="contact_number" value="<?= htmlspecialchars($contact_number) ?>" required>

          <label>Gender</label>
          <select name="gender" required>
            <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
            <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
            <option value="Other" <?= $gender === 'Other' ? 'selected' : '' ?>>Other</option>
          </select>

          <label>Address</label>
          <textarea name="address" rows="3"><?= htmlspecialchars($address) ?></textarea>

          <label>Position</label>
          <input type="text" name="position" value="<?= htmlspecialchars($position) ?>">

          <button type="submit" class="btn">Save Changes</button>
        </form>

        <a class="btn ghost" href="dashboard.php" style="margin-top:14px"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
