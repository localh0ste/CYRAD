<?php
session_start();

// Only super_admin can access this
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$organization_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$organization_id) {
    echo "Invalid organization ID.";
    exit;
}

$error = $success = "";

// Fetch current admin for this organization
$old_username = '';
$stmt = $conn->prepare("SELECT username, full_name, company_email, contact_number, gender, address, position FROM admin_users WHERE organization_id = ? AND role = 'admin'");
$stmt->bind_param("i", $organization_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    $conn->close();
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><link rel='stylesheet' href='../assets/theme.css'></head><body><div class='app'><div class='main'><div class='content'><div class='card glass'><div class='msg err'>No admin found for this organization.</div><a class='btn ghost' href='view_organizations.php'><i class='fas fa-arrow-left'></i> Back</a></div></div></div></div></body></html>";
    exit;
}

$stmt->bind_result($old_username, $full_name, $company_email, $contact_number, $gender, $address, $position);
$stmt->fetch();
$stmt->close();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new_username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $company_email = trim($_POST['company_email']);
    $contact_number = trim($_POST['contact_number']);
    $gender = trim($_POST['gender']);
    $address = trim($_POST['address']);
    $position = trim($_POST['position']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    // Check if username is taken by another user
    if ($new_username !== $old_username) {
        $check = $conn->prepare("SELECT id FROM admin_users WHERE username = ? AND organization_id = ?");
        $check->bind_param("si", $new_username, $organization_id);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $error = "Username already taken by another user.";
        }
        $check->close();
    }

    if (!$error) {
        $update = $conn->prepare("UPDATE admin_users SET username=?, full_name=?, company_email=?, contact_number=?, gender=?, address=?, position=? WHERE username=? AND organization_id=? AND role='admin'");
        $update->bind_param("ssssssssi", $new_username, $full_name, $company_email, $contact_number, $gender, $address, $position, $old_username, $organization_id);
        $update->execute();
        $update->close();

        if ($new_username !== $old_username) {
            $old_username = $new_username;
        }

        // If password fields filled
        if (!empty($new_password) && !empty($confirm_password)) {
            if ($new_password === $confirm_password) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $pwd = $conn->prepare("UPDATE admin_users SET password=? WHERE username=? AND organization_id=? AND role='admin'");
                $pwd->bind_param("ssi", $hashed, $new_username, $organization_id);
                $pwd->execute();
                $pwd->close();
                $success .= "<br>Password updated.";
            } else {
                $error .= "<br>Passwords do not match.";
            }
        }

        if (!$error) {
            $success = "Admin profile updated successfully.";
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Admin Profile</title>
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
    <header class="topbar"><div><strong>Edit Admin Profile</strong></div></header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-user-edit" style="color:var(--cyan)"></i> Edit Admin Profile</h3>

        <?php if ($success): ?><div class="msg ok"><?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg err"><?= $error ?></div><?php endif; ?>

        <form method="POST">
          <label>Username</label>
          <input type="text" name="username" value="<?= htmlspecialchars($old_username) ?>" required>

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

          <hr style="margin: 22px 0; border-color:#3a2b33;">

          <label>New Password</label>
          <input type="password" name="new_password" placeholder="Leave blank if not changing">

          <label>Confirm Password</label>
          <input type="password" name="confirm_password" placeholder="Repeat new password">

          <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </form>

        <a class="btn ghost" style="margin-top:14px" href="view_admin.php?id=<?= $organization_id ?>"><i class="fas fa-arrow-left"></i> Back to Profile</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
