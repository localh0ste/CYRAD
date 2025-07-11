<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (
    !isset($_SESSION['admin_logged_in']) ||
    !isset($_SESSION['admin_user']) ||
    !isset($_SESSION['organization_id'])
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';

$org_id = $_SESSION['organization_id'];
$username = $_SESSION['admin_user'];

$success = "";
$error = "";
$user = null;

// Helper functions
function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function is_valid_username($username) {
    return preg_match('/^[a-zA-Z0-9_\-@.]+$/', $username);
}

function is_valid_password($password) {
    return strlen($password) >= 8;
}

// Success message
if (isset($_GET['updated']) && $_GET['updated'] == 1 && !isset($_GET['error'])) {
    $success = "User updated successfully.";
}

// Fetch user data
if (isset($_GET['username'])) {
    $username_param = sanitize_input($_GET['username']);

    $stmt = $conn->prepare("SELECT username, value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
    $stmt->bind_param("s", $username_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $radcheck = $result->fetch_assoc();
    $stmt->close();

    if ($radcheck) {
        $stmt = $conn->prepare("SELECT internet_type, internet_limit FROM data_limits WHERE username = ? AND organization_id = ?");
        $stmt->bind_param("si", $username_param, $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $datalimit = $result->fetch_assoc();
        $stmt->close();

        $user = [
            'username' => $radcheck['username'],
            'internet_type' => $datalimit ? $datalimit['internet_type'] : 'unlimited',
            'internet_limit' => $datalimit ? intval($datalimit['internet_limit'] / (1024 * 1024)) : ''
        ];
    } else {
        $error = "User not found.";
    }
} else {
    $error = "No username provided.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $original_username = sanitize_input($_POST['original_username']);
    $new_username = sanitize_input($_POST['username']);
    $password = sanitize_input($_POST['password']);
    $internet_type = $_POST['internet_type'];
    $internet_limit_mb = $internet_type === 'limited' ? intval($_POST['internet_limit']) : null;

    if (
        empty($new_username) || empty($internet_type) ||
        ($internet_type === 'limited' && (empty($internet_limit_mb) || $internet_limit_mb <= 0)) ||
        !is_valid_username($new_username)
    ) {
        $error = "Please fill all fields correctly. Username must be alphanumeric.";
    } else {
        // Update password (if changed)
        if (!empty($password)) {
            if (!is_valid_password($password)) {
                $error = "Password must be at least 8 characters.";
            } else {
                $stmt = $conn->prepare("UPDATE radcheck SET username=?, value=? WHERE username=? AND attribute='Cleartext-Password'");
                $stmt->bind_param("sss", $new_username, $password, $original_username);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("UPDATE radcheck SET username=? WHERE username=? AND attribute='Cleartext-Password'");
            $stmt->bind_param("ss", $new_username, $original_username);
            $stmt->execute();
            $stmt->close();
        }

        if (empty($error)) {
            // Check if user already has data_limits
            $stmt = $conn->prepare("SELECT username FROM data_limits WHERE username = ? AND organization_id = ?");
            $stmt->bind_param("si", $original_username, $org_id);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();

            if ($internet_type === 'limited') {
                $limit_bytes = $internet_limit_mb * 1024 * 1024;
                if ($exists) {
                    $stmt = $conn->prepare("UPDATE data_limits SET username=?, internet_type=?, internet_limit=? WHERE username=? AND organization_id=?");
                    $stmt->bind_param("ssisi", $new_username, $internet_type, $limit_bytes, $original_username, $org_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit, organization_id) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssii", $new_username, $internet_type, $limit_bytes, $org_id);
                }
                $stmt->execute();
                $stmt->close();
            } else {
                if ($exists) {
                    $stmt = $conn->prepare("DELETE FROM data_limits WHERE username = ? AND organization_id = ?");
                    $stmt->bind_param("si", $original_username, $org_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            header("Location: edit_user.php?username=" . urlencode($new_username) . "&updated=1");
            exit;
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link rel="icon" href="images/user_icon.PNG">
  <title>Edit User</title>
  <style>
    body { font-family: Arial; background: url("images/CYNDIA.jpg"); background-size:cover; color: white; padding: 2rem; }
    .container { max-width: 600px; margin: auto; background: #222; padding: 2rem; border-radius: 10px; }
    label { display: block; margin-top: 1rem; }
    input, select { width: 100%; padding: 0.5rem; margin-top: 0.25rem; background: #333; color: white; border: none; border-radius: 5px; }
    .btn { margin-top: 1.5rem; background: crimson; padding: 0.75rem; border: none; color: white; border-radius: 5px; font-size:20px; cursor: pointer; }
    .alert { margin-top: 1rem; padding: 0.75rem; border-radius: 5px; }
    .success { background: #2ecc71; color: black; }
    .error { background: #e74c3c; }
    h2 { color: rgba(250, 9, 78, 0.86); }
  </style>
</head>
<body>
<div class="container">
  <h2>Edit User</h2>

  <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <?php if ($user): ?>
  <form method="POST">
    <input type="hidden" name="original_username" value="<?= htmlspecialchars($user['username']) ?>">

    <label>Username</label>
    <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>

    <label>Password (leave blank to keep unchanged)</label>
    <input type="password" name="password" minlength="8">

    <label>Internet Type</label>
    <select name="internet_type" id="internet_type" onchange="toggleLimit()" required>
      <option value="unlimited" <?= $user['internet_type'] === 'unlimited' ? 'selected' : '' ?>>Unlimited</option>
      <option value="limited" <?= $user['internet_type'] === 'limited' ? 'selected' : '' ?>>Limited</option>
    </select>

    <div id="limit_group" style="display: none;">
      <label>Internet Limit (MB)</label>
      <input type="number" name="internet_limit" id="internet_limit" value="<?= htmlspecialchars($user['internet_limit']) ?>">
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
      <button type="submit" name="update_user" class="btn">Update</button>
      <a href="users.php" style="color: #ccc; text-decoration: none; font-size: 15px;">&larr; Back</a>
    </div>
  </form>
  <?php endif; ?>
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
