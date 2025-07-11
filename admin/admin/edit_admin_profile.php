<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';

$username = $_SESSION['admin_user'];
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

    $stmt = $conn->prepare("UPDATE admin_users SET full_name=?, company_email=?, contact_number=?, gender=?, address=?, position=? WHERE username=?");
    $stmt->bind_param("sssssss", $full_name, $company_email, $contact_number, $gender, $address, $position, $username);

    if ($stmt->execute()) {
        $success = "Profile updated successfully!";
    } else {
        $error = "Failed to update profile.";
    }
    $stmt->close();
}

// Fetch current data
$stmt = $conn->prepare("SELECT full_name, company_email, contact_number, gender, address, position FROM admin_users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($full_name, $company_email, $contact_number, $gender, $address, $position);
$stmt->fetch();
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Profile</title>
    <link rel="icon" href="images/admin_icon.png">
    <style>
        body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: url("images/background.jpg");
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
    font-size: 28px;
    text-align: center;
}

label {
    display: block;
    margin-top: 18px;
    font-weight: bold;
    font-size: 15px;
    color: rgba(250, 9, 78, 0.86);;
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

textarea {
    resize: vertical;
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
    transition: background 0.3s ease;
}

button:hover {
    background-color: rgba(150, 10, 50, 0.86);
}

.back-link {
    display: inline-block;
    margin-top: 18px;
    color: #ccc;
    text-decoration: none;
    font-size: 15px;
}

.back-link:hover {
    color: white;
}

.message {
    margin-top: 12px;
    font-size: 15px;
    color: #4caf50;
}

.error {
    margin-top: 12px;
    font-size: 15px;
    color: #f44336;
}

    </style>
</head>
<body>
<div class="form-box">
    <h2>Edit Profile</h2>

    <?php if ($success): ?><div class="message"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

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

        <button type="submit">Save Changes</button>
    </form>

    <a class="back-link" href="dashboard.php">&larr; Back to Profile</a>
</div>
</body>
</html>
