<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$org_id = $_SESSION['organization_id'];
$error = "";
$success = "";
$user = null;
$all_users = [];
$view = $_GET['view'] ?? 'search';

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

// Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $del_username = $_POST['delete_username'];

    $stmt = $conn->prepare("DELETE FROM radcheck WHERE username = ? AND organization_id = ?");
    $stmt->bind_param("si", $del_username, $org_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM data_limits WHERE username = ? AND organization_id = ?");
    $stmt->bind_param("si", $del_username, $org_id);
    $stmt->execute();
    $stmt->close();

    $success = "User '$del_username' deleted successfully.";
}

// Disable User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_user'])) {
    $disable_username = $_POST['disable_username'];

    $stmt = $conn->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password' AND organization_id = ?");
    $stmt->bind_param("si", $disable_username, $org_id);
    $stmt->execute();
    $stmt->bind_result($currentPassword);
    $stmt->fetch();
    $stmt->close();

    if ($currentPassword) {
        if (str_starts_with($currentPassword, 'disabled_')) {
            $success = "User '$disable_username' is already disabled.";
        } else {
            $newPassword = "disabled_" . $currentPassword;
            $updateStmt = $conn->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password' AND organization_id = ?");
            $updateStmt->bind_param("ssi", $newPassword, $disable_username, $org_id);
            if ($updateStmt->execute()) {
                $success = "User '$disable_username' disabled successfully.";
            } else {
                $error = "Failed to disable user.";
            }
            $updateStmt->close();
        }
    } else {
        $error = "User '$disable_username' not found.";
    }
}

// Search User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'search' && isset($_POST['search_username'])) {
    $searchUsername = trim($_POST['search_username'] ?? '');
    if (!empty($searchUsername)) {
        $stmt = $conn->prepare("SELECT r.username, r.value as password, d.internet_type, d.internet_limit 
                                FROM radcheck r 
                                LEFT JOIN data_limits d ON r.username = d.username AND r.organization_id = d.organization_id 
                                WHERE r.username = ? AND r.attribute = 'Cleartext-Password' AND r.organization_id = ?");
        $stmt->bind_param("si", $searchUsername, $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            $stmt = $conn->prepare("SELECT nasipaddress, acctstarttime FROM radacct 
                                    WHERE username = ? ORDER BY acctstarttime DESC LIMIT 1");
            $stmt->bind_param("s", $searchUsername);
            $stmt->execute();
            $acct = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $user['nasipaddress'] = $acct['nasipaddress'] ?? 'N/A';
            $user['acctstarttime'] = $acct['acctstarttime'] ?? 'N/A';
        } else {
            $error = "No user found with username: " . htmlspecialchars($searchUsername);
        }
    } else {
        $error = "Please enter a username to search.";
    }
}

// View All Users
if ($view === 'all') {
    $stmt = $conn->prepare("SELECT r.username, r.value as password, d.internet_type, d.internet_limit,
                            (SELECT nasipaddress FROM radacct a WHERE a.username = r.username ORDER BY acctstarttime DESC LIMIT 1) as nasipaddress,
                            (SELECT acctstarttime FROM radacct a WHERE a.username = r.username ORDER BY acctstarttime DESC LIMIT 1) as acctstarttime
                            FROM radcheck r
                            LEFT JOIN data_limits d ON r.username = d.username AND r.organization_id = d.organization_id
                            WHERE r.attribute = 'Cleartext-Password' AND r.organization_id = ?
                            ORDER BY r.username ASC");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['internet_limit'] = ($row['internet_type'] === 'limited' && $row['internet_limit']) 
            ? intval($row['internet_limit'] / 1024 / 1024) . " MB" : "Unlimited";
        $row['nasipaddress'] = $row['nasipaddress'] ?? 'N/A';
        $row['acctstarttime'] = $row['acctstarttime'] ?? 'N/A';
        $all_users[] = $row;
    }
    $stmt->close();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Users Dashboard</title>
  <link rel="icon" href="images/users_icon.PNG">
  <style>
    body { margin: 0; font-family: 'Segoe UI', sans-serif; display: flex; height: 100vh; background-color: #f4f4f4; }
    .sidebar { width: 220px; background-color: #1e1e1e; color: #fff; padding: 1rem; }
    .sidebar a { display: block; color: #ccc; text-decoration: none; padding: 10px; border-radius: 6px; margin-bottom: 10px; }
    .sidebar a:hover, .sidebar a.active { background-color: rgba(250, 9, 78, 0.86); color: #fff; }
    .content { background-image: url("images/dashboard.jpg"); background-size: cover; flex: 1; padding: 2rem; overflow-y: auto; color: #fff; }
    input[type="text"] { padding: 10px; font-size: 1rem; border-radius: 6px; border: 1px solid #ccc; margin-bottom: 1rem; width: 50%; }
    button { padding: 10px 20px; font-size: 16px; border-radius: 6px; background-color: rgba(250, 9, 78, 0.86); border: none; color: white; cursor: pointer; }
    .error, .success { margin-top: 10px; padding: 10px; width: 50%; border-radius: 6px; }
    .error { background: #f8d7da; color: #721c24; }
    .success { background: #d4edda; color: #155724; }
    .user-card, table { background-color: rgba(0, 0, 0, 0.6); border-radius: 8px; padding: 1rem; color: white; width: 100%; margin-top: 1rem; }
    .user-card h2 { color: rgba(250, 9, 78, 0.86); }
    .link-btn { background-color: crimson; margin-top: 1rem; padding: 8px 12px; border-radius: 6px; color: white; text-decoration: none; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px; text-align: left; border-bottom: 1px solid #555; }
    th { background-color: rgba(250, 9, 78, 0.86); }
    tr:hover { background-color: rgba(255,255,255,0.05); }
  </style>
</head>
<body>
<div class="sidebar">
  <h2>📋 Admin Panel</h2>
  <a href="?view=search" class="<?= $view === 'search' ? 'active' : '' ?>">🔍 Search User</a>
  <a href="?view=all" class="<?= $view === 'all' ? 'active' : '' ?>">📃 View All Users</a>
  <a href="check_data_limit_users.php">📊 Check Internet Usage</a>
  <a href="add_user.php">➕ Add User</a>
  <a href="upload_users.php">📁 Upload CSV</a>
  <a href="download_users.php">⬇️ Download Users</a>
  <a href="dashboard.php">&larr; Back</a>
</div>
<div class="content">
  <?php if (!empty($success)): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($view === 'search'): ?>
    <h2>🔍 Search User</h2>
    <form method="POST">
      <input type="text" name="search_username" placeholder="Enter Username..." required value="<?= htmlspecialchars($_POST['search_username'] ?? '') ?>">
      <button type="submit">Search</button>
    </form>

    <?php if ($user): ?>
    <div class="user-card">
      <h2>User Details</h2>
      <p><strong>Username:</strong> <?= htmlspecialchars($user['username']) ?></p>
      <p><strong>Internet Type:</strong> <?= htmlspecialchars($user['internet_type']) ?></p>
      <p><strong>Limit:</strong> <?= htmlspecialchars($user['internet_limit']) ?></p>
      <p><strong>NAS IP:</strong> <?= htmlspecialchars($user['nasipaddress']) ?></p>
      <p><strong>Start Time:</strong> <?= htmlspecialchars($user['acctstarttime']) ?></p>
      <a class="link-btn" href="edit_user.php?username=<?= urlencode($user['username']) ?>">✏️ Edit</a>
      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?');">
        <input type="hidden" name="delete_username" value="<?= htmlspecialchars($user['username']) ?>">
        <button type="submit" name="delete_user" class="link-btn">🗑️ Delete</button>
      </form>
      <form method="POST" style="display:inline;" onsubmit="return confirm('Disable this user?');">
        <input type="hidden" name="disable_username" value="<?= htmlspecialchars($user['username']) ?>">
        <button type="submit" name="disable_user" class="link-btn">🚫 Disable</button>
      </form>
    </div>
    <?php endif; ?>

  <?php elseif ($view === 'all'): ?>
    <h2>📃 All Users</h2>
    <table>
      <thead>
        <tr>
          <th>Username</th>
          <th>Internet Type</th>
          <th>Internet Limit</th>
          <th>NAS IP</th>
          <th>Start Time</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($all_users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= htmlspecialchars($u['internet_type'] ?? 'unlimited') ?></td>
          <td><?= htmlspecialchars($u['internet_limit']) ?></td>
          <td><?= htmlspecialchars($u['nasipaddress']) ?></td>
          <td><?= htmlspecialchars($u['acctstarttime']) ?></td>
          <td>
            <a href="edit_user.php?username=<?= urlencode($u['username']) ?>" class="link-btn">✏️</a>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?');">
              <input type="hidden" name="delete_username" value="<?= htmlspecialchars($u['username']) ?>">
              <button type="submit" name="delete_user" class="link-btn">🗑️</button>
            </form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Disable this user?');">
              <input type="hidden" name="disable_username" value="<?= htmlspecialchars($u['username']) ?>">
              <button type="submit" name="disable_user" class="link-btn">🚫 Disable</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
<?php $conn->close(); ?>
