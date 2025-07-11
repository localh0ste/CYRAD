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
    echo "<p style='color: red; text-align:center;'>No admin found for this organization.</p>";
    echo "<p style='text-align:center;'><a href='view_organizations.php'>← Back</a></p>";
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
<html>
<head>
    <title>Edit Admin Profile</title>
    <link rel="icon" href="../images/admin_icon.png">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url("../images/dashboard.jpg");
            background-size: cover;
            background-position: center;
            color: #fff;
            padding: 40px;
            margin: 0;
        }

        .form-box {
            background: rgba(0, 0, 0, 0.85);
            max-width: 600px;
            margin: auto;
            padding: 35px;
            border-radius: 16px;
            box-shadow: 0 0 15px rgba(0,0,0,0.5);
        }

        h2 {
            color: rgba(250, 9, 78, 0.86);
            margin-bottom: 25px;
            text-align: center;
        }

        label {
            display: block;
            margin-top: 18px;
            font-weight: bold;
            font-size: 15px;
            color: rgba(250, 9, 78, 0.86);
        }

        input, textarea, select {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            border: none;
            border-radius: 8px;
            margin-top: 6px;
            background-color: #1f1f1f;
            color: white;
            box-sizing: border-box;
        }

        button {
            margin-top: 25px;
            width: 100%;
            background-color: rgba(250, 9, 78, 0.86);
            color: white;
            border: none;
            padding: 12px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #ccc;
            text-decoration: none;
        }

        .back-link:hover {
            color: white;
        }

        .message {
            margin-top: 12px;
            color: #4caf50;
        }

        .error {
            margin-top: 12px;
            color: #f44336;
        }
    </style>
</head>
<body>
<div class="form-box">
    <h2>Edit Admin Profile</h2>

    <?php if ($success): ?><div class="message"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>

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

        <hr style="margin: 30px 0; border-color: #555;">

        <label>New Password</label>
        <input type="password" name="new_password" placeholder="Leave blank if not changing">

        <label>Confirm Password</label>
        <input type="password" name="confirm_password" placeholder="Repeat new password">

        <button type="submit">Save Changes</button>
    </form>

    <a class="back-link" href="view_admin.php?id=<?= $organization_id ?>">&larr; Back to Profile</a>
</div>
</body>
</html>
