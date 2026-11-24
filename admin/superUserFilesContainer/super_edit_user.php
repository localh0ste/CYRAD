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
        $stmt = $conn->prepare("INSERT INTO data_limits (username, organization_id, internet_type, internet_limit, usage_reset_ts) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("sisi", $new_username, $organization_id, $internet_type, $internet_limit_bytes);
    }
    $stmt->execute();

    // Forgive already-used data so an increased limit gives a fresh full allowance
    $rstmt = $conn->prepare("UPDATE data_limits SET usage_reset_ts = NOW() WHERE username = ? AND organization_id = ?");
    $rstmt->bind_param("si", $new_username, $organization_id);
    $rstmt->execute();
    $rstmt->close();

    // Re-enable the user so they can reconnect after a limit change / increase
    $reen = $conn->prepare("UPDATE radcheck SET value = SUBSTRING(value, 10) WHERE username = ? AND attribute = 'Cleartext-Password' AND LEFT(value, 9) = 'disabled_'");
    $reen->bind_param("s", $new_username);
    $reen->execute();
    $reen->close();
    // Auto-unban: raising/deleting the limit clears any active 24h block
    $ub = $conn->prepare("UPDATE data_limits SET banned_until = NULL WHERE username = ? AND organization_id = ?");
    $ub->bind_param("si", $new_username, $organization_id);
    $ub->execute();
    $ub->close();

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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit User - Super Admin</title>
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
    <header class="topbar"><div><strong>Edit User</strong></div></header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-user-pen" style="color:var(--cyan)"></i> Edit User</h3>

        <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

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

          <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Update</button>
        </form>

        <a class="btn ghost" style="margin-top:14px" href="view_org_users.php?view=all&organization_id=<?= htmlspecialchars($organization_id) ?>"><i class="fas fa-arrow-left"></i> Back</a>
      </div>
    </div>
  </div>
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
