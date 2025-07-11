<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

// Show PHP errors (optional - for development only)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$error = "";
$success = isset($_GET['updated']) ? "User updated successfully." : "";

$username = $_GET['username'] ?? $_POST['username'] ?? '';
$organization_id = $_GET['organization_id'] ?? $_POST['organization_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = trim($_POST['new_username']);
    $new_password = trim($_POST['new_password']);
    $internet_type = $_POST['internet_type'];
    $internet_limit_mb = isset($_POST['internet_limit']) ? intval($_POST['internet_limit']) : 0;
    $internet_limit_bytes = $internet_type === 'limited' ? $internet_limit_mb * 1024 * 1024 : 0;

    // Update username and password in radcheck
    $stmt = $conn->prepare("UPDATE radcheck SET username = ?, value = ? WHERE username = ? AND attribute = 'Cleartext-Password' AND organization_id = ?");
    $stmt->bind_param("sssi", $new_username, $new_password, $username, $organization_id);
    $stmt->execute();

    // Update or insert into data_limits
    $stmt = $conn->prepare("SELECT * FROM data_limits WHERE username = ? AND organization_id = ?");
    $stmt->bind_param("si", $username, $organization_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE data_limits SET username = ?, internet_type = ?, internet_limit = ? WHERE username = ? AND organization_id = ?");
        $stmt->bind_param("ssisi", $new_username, $internet_type, $internet_limit_bytes, $username, $organization_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO data_limits (username, organization_id, internet_type, internet_limit) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sisi", $new_username, $organization_id, $internet_type, $internet_limit_bytes);
    }
    $stmt->execute();

    // Redirect to same page with updated data
    header("Location: super_edit_user.php?username=" . urlencode($new_username) . "&organization_id=" . urlencode($organization_id) . "&updated=1");
    exit;
}

// Fetch current user data
$stmt = $conn->prepare("SELECT r.username, r.value as password, d.internet_type, d.internet_limit 
    FROM radcheck r 
    LEFT JOIN data_limits d ON r.username = d.username AND r.organization_id = d.organization_id 
    WHERE r.username = ? AND r.attribute = 'Cleartext-Password' AND r.organization_id = ?");
$stmt->bind_param("si", $username, $organization_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("User not found.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User - Super Admin</title>
    <link rel="icon" href="../images/users_icon.PNG">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: url("../images/dashboard.jpg");
            background-size: cover;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            width: 90%;
            max-width: 700px;
            background: rgba(0, 0, 0, 0.85);
            padding: 2rem 2.5rem;
            border-radius: 12px;
            box-shadow: 0 0 15px rgba(0,0,0,0.8);
        }
        h2 {
            text-align: center;
            color: rgba(250, 9, 78, 0.86);
        }
        label {
            margin-top: 1rem;
            display: block;
        }
        input, select, .btn {
            width: 100%;
            padding: 10px;
            margin-top: 6px;
            border-radius: 5px;
            background-color: #333;
            color: white;
            border: none;
            box-sizing: border-box;
            font-size: 16px;
        }
        .btn {
            margin-top: 20px;
            background: rgba(250, 9, 78, 0.86);
            cursor: pointer;
        }
        .btn:hover {
            background: rgba(164, 4, 50, 0.86);
        }
        .alert {
            margin-top: 15px;
            padding: 10px;
            border-radius: 6px;
        }
        .success {
            background: #2ecc71;
            color: black;
        }
        .error {
            background: #e74c3c;
        }
        .back-link {
            display: block;
            margin-top: 20px;
            text-align: center;
            text-decoration: none;
            color: #ccc;
            background: #444;
            padding: 10px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Edit User</h2>

    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST">
        <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">
        <input type="hidden" name="organization_id" value="<?= htmlspecialchars($organization_id) ?>">

        <label>Username</label>
        <input type="text" name="new_username" value="<?= htmlspecialchars($user['username']) ?>" required>

        <label>Password</label>
        <input type="text" name="new_password" value="<?= htmlspecialchars($user['password']) ?>" required>

        <label>Internet Type</label>
        <select name="internet_type" id="internet_type" onchange="toggleLimit()" required>
            <option value="unlimited" <?= $user['internet_type'] === 'unlimited' ? 'selected' : '' ?>>Unlimited</option>
            <option value="limited" <?= $user['internet_type'] === 'limited' ? 'selected' : '' ?>>Limited</option>
        </select>

        <div id="limit_group" style="display: none;">
            <label>Internet Limit (MB)</label>
            <input type="number" name="internet_limit" id="internet_limit"
                   value="<?= isset($user['internet_limit']) ? intval($user['internet_limit'] / 1024 / 1024) : '' ?>">
        </div>

        <button type="submit" class="btn">Update</button>
    </form>

    <a class="back-link" href="view_org_users.php?view=all&organization_id=<?= htmlspecialchars($organization_id) ?>">← Back</a>
</div>

<script>
function toggleLimit() {
    const type = document.getElementById('internet_type').value;
    document.getElementById('limit_group').style.display = (type === 'limited') ? 'block' : 'none';
}
toggleLimit();
</script>
</body>
</html>
