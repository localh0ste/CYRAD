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
    <meta charset="UTF-8">
    <title>Assign Admin</title>
    <link rel="icon" href="../images/admin_icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: url("../images/dashboard.jpg") no-repeat center center fixed;
            background-size: cover;
            font-family: 'Roboto', sans-serif;
            color: #fff;
            padding: 40px;
            margin: 0;
        }

        .container {
            max-width: 600px;
            margin: auto;
            background: rgba(0, 0, 0, 0.85);
            padding: 30px 40px;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        h2 {
            text-align: center;
            margin-bottom: 20px;
            color: rgba(250, 9, 78, 0.86);
        }

        form {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: bold;
            margin-top: 1rem;
            margin-bottom: 5px;
            color: #ccc;
        }

        input, select, textarea {
            background: #222;
            color: #fff;
            padding: 10px;
            font-size: 16px;
            border-radius: 8px;
            border: none;
            outline: none;
        }

        input:focus, select:focus, textarea:focus {
            background-color: #1a1a1a;
        }

        .btn {
            margin-top: 20px;
            padding: 12px;
            background: rgba(250, 9, 78, 0.86);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn:hover {
            background: rgb(184, 16, 55);
        }

        .message {
            margin: 10px 0;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }

        .error {
            background: rgba(255, 0, 0, 0.2);
            color: #ff4c4c;
        }

        .success {
            background: rgba(0, 255, 0, 0.2);
            color: #2ecc71;
        }

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #ccc;
            text-decoration: none;
        }

        .back:hover {
            color: white;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Assign Admin to Organization</h2>

    <?php if (!empty($error)): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <form method="POST">
        <label for="organization_id">Select Organization *</label>
        <select name="organization_id" required>
            <option value="">-- Select Organization --</option>
            <?php while($org = $orgs->fetch_assoc()): ?>
                <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['org_name']) ?></option>
            <?php endwhile; ?>
        </select>

        <label for="username">Username *</label>
        <input type="text" name="username" required>

        <label for="password">Password *</label>
        <input type="password" name="password" required>

        <label for="full_name">Full Name</label>
        <input type="text" name="full_name">

        <label for="company_email">Company Email</label>
        <input type="email" name="company_email">

        <label for="contact_number">Contact Number</label>
        <input type="text" name="contact_number">

        <label for="gender">Gender</label>
        <select name="gender">
            <option value="">-- Gender --</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
        </select>

        <label for="address">Address</label>
        <textarea name="address" rows="2"></textarea>

        <label for="position">Position</label>
        <input type="text" name="position">

        <button type="submit" class="btn">Assign Admin</button>
    </form>

    <a href="super_dashboard.php" class="back">&larr; Back to Dashboard</a>
</div>
</body>
</html>
