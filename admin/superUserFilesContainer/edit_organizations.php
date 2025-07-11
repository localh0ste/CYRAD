<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$org_id = intval($_GET['id'] ?? 0);
$error = $success = "";

if ($org_id <= 0) {
    echo "Invalid organization ID.";
    exit;
}

// Fetch organization info
$stmt = $conn->prepare("SELECT * FROM organizations WHERE id = ?");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    echo "Organization not found.";
    exit;
}
$org = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $org_name = trim($_POST['org_name']);
    $address = trim($_POST['address']);
    $contact_email = trim($_POST['contact_email']);
    $contact_number = trim($_POST['contact_number']);

    if (empty($org_name)) {
        $error = "Organization name is required.";
    } else {
        $stmt = $conn->prepare("UPDATE organizations SET org_name = ?, address = ?, contact_email = ?, contact_number = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $org_name, $address, $contact_email, $contact_number, $org_id);
        if ($stmt->execute()) {
            $success = "Organization updated successfully.";
            $org = [
                'org_name' => $org_name,
                'address' => $address,
                'contact_email' => $contact_email,
                'contact_number' => $contact_number,
            ] + $org;
        } else {
            $error = "Failed to update organization: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Organization</title>
    <link rel="icon" href="../images/dashboard_icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: url("../images/dashboard.jpg") no-repeat center center fixed;
            background-size: cover;
            font-family: 'Roboto', sans-serif;
            color: #fff;
            padding: 40px;
        }

        .container {
            max-width: 600px;
            background: rgba(0, 0, 0, 0.85);
            padding: 30px;
            border-radius: 16px;
            margin: auto;
        }

        h2 {
            text-align: center;
            color: rgba(250, 9, 78, 0.86);
            margin-bottom: 20px;
        }

        label {
            font-weight: bold;
            margin-bottom: 4px;
            display: block;
            color: #ddd;
        }

        form {
            display: flex;
            flex-direction: column;
        }

        input, textarea {
            margin-bottom: 16px;
            padding: 10px;
            border-radius: 8px;
            border: none;
            font-size: 16px;
            background-color: #1f1f1f;
            color: #fff;
        }

        .btn {
            background-color: rgba(250, 9, 78, 0.86);
            color: white;
            font-weight: bold;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .btn:hover {
            background-color: rgba(148, 10, 49, 0.86);
        }

        .message {
            margin: 10px 0;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }

        .error { background: #ff4d4d; }
        .success { background: #4CAF50; }

        .back {
            display: inline-block;
            margin-top: 15px;
            color: #ddd;
            text-decoration: none;
        }

        .back:hover {
            color: #fff;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Edit Organization</h2>

    <?php if (!empty($error)) echo "<div class='message error'>$error</div>"; ?>
    <?php if (!empty($success)) echo "<div class='message success'>$success</div>"; ?>

    <form method="POST">
        <label for="org_name">Organization Name *</label>
        <input type="text" name="org_name" id="org_name" value="<?= htmlspecialchars($org['org_name']) ?>" required>

        <label for="address">Address</label>
        <textarea name="address" id="address" rows="3"><?= htmlspecialchars($org['address']) ?></textarea>

        <label for="contact_email">Contact Email</label>
        <input type="email" name="contact_email" id="contact_email" value="<?= htmlspecialchars($org['contact_email']) ?>">

        <label for="contact_number">Contact Number</label>
        <input type="text" name="contact_number" id="contact_number" value="<?= htmlspecialchars($org['contact_number']) ?>">

        <button type="submit" class="btn">Update</button>
    </form>

    <a href="view_organizations.php" class="back">&larr; Back to Organizations</a>
</div>
</body>
</html>
