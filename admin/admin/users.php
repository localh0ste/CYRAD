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

$error = "";
$success = "";
$user = null;
$view = $_GET['view'] ?? 'search';

// Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $del_username = $_POST['delete_username'];

    // Delete from radcheck
    $stmt = $conn->prepare("DELETE FROM radcheck WHERE username = ?");
    $stmt->bind_param("s", $del_username);
    $stmt->execute();
    $stmt->close();

    // Delete from data_limits
    $stmt = $conn->prepare("DELETE FROM data_limits WHERE username = ?");
    $stmt->bind_param("s", $del_username);
    $stmt->execute();
    $stmt->close();

    // Optional: Delete from radacct (comment out if you want to keep session history)
    /*
    $stmt = $conn->prepare("DELETE FROM radacct WHERE username = ?");
    $stmt->bind_param("s", $del_username);
    $stmt->execute();
    $stmt->close();
    */

    $success = "User '$del_username' deleted successfully.";
}

// Search user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'search' && isset($_POST['search_username'])) {
    $searchUsername = trim($_POST['search_username'] ?? '');

    if (!empty($searchUsername)) {
        // Get user info
        $stmt = $conn->prepare("SELECT r.username, d.internet_type, d.internet_limit 
                                FROM radcheck r 
                                LEFT JOIN data_limits d ON r.username = d.username 
                                WHERE r.username = ?");
        $stmt->bind_param("s", $searchUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = "No user found with username: " . htmlspecialchars($searchUsername);
        } else {
            // Get NAS IP and AcctStartTime from radacct
            $stmt = $conn->prepare("SELECT nasipaddress, acctstarttime 
                                    FROM radacct 
                                    WHERE username = ? 
                                    ORDER BY acctstarttime DESC 
                                    LIMIT 1");
            $stmt->bind_param("s", $searchUsername);
            $stmt->execute();
            $result = $stmt->get_result();
            $acct = $result->fetch_assoc();
            $stmt->close();

            $user['nasipaddress'] = $acct['nasipaddress'] ?? 'N/A';
            $user['acctstarttime'] = $acct['acctstarttime'] ?? 'N/A';
        }
    } else {
        $error = "Please enter a username to search";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Users Dashboard</title>
  <link rel="icon" href="images/users_icon.PNG">
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      background-color: #f4f4f4;
      display: flex;
      height: 100vh;
    }
    .sidebar {
      width: 220px;
      background-color: #1e1e1e;
      color: #fff;
      padding: 1rem;
      display: flex;
      flex-direction: column;
    }
    .sidebar h2 { margin-bottom: 1rem; font-size: 1.2rem; }
    .sidebar a {
      color: #ccc;
      text-decoration: none;
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 10px;
      display: block;
    }
    .sidebar a:hover {
      background-color: rgba(250, 9, 78, 0.86);
      color: #fff;
    }
    .content {
      background-image:url("images/dashboard.jpg");
      background-size: cover;
      flex: 1;
      padding: 2rem;
      overflow-y: auto;
    }
    input[type="text"] {
      padding: 10px;
      width: 50%;
      font-size: 1rem;
      border-radius: 6px;
      border: 1px solid #ccc;
      margin-bottom: 1rem;
    }
    button {
      padding: 10px 20px;
      background-color: rgba(250, 9, 78, 0.86);
      font-size: 20px;
      border: none;
      color: white;
      border-radius: 6px;
      cursor: pointer;
    }
    button:hover {
      background-color: rgba(143, 12, 49, 0.86);
    }
    .error, .success {
      padding: 10px;
      border-radius: 6px;
      margin-top: 1rem;
      width: 50%;
    }
    .error {
      background: #f8d7da;
      color: #721c24;
    }
    .success {
      background: #d4edda;
      color: #155724;
    }
    .user-card {
      background-color: rgba(128, 128, 128, 0.3);
      border-radius: 8px;
      padding: 1.5rem;
      box-shadow: 0 0 8px rgba(0, 0, 0, 0.1);
      margin-top: 1rem;
      width: 50%;
      color: #fff;
    }
    .link-btn {
      background-color:rgba(250, 9, 78, 0.86);
      margin-top: 1rem;
      padding: 8px 14px;
      border-radius: 6px;
      color: white;
      text-decoration: none;
      font-size:15px;
    }
    .link-btn:hover {
      background-color: rgba(143, 12, 49, 0.86);
    }
    .content h2 { color:#ffffff; }
    .user-card h2 { color: rgba(250, 9, 78, 0.86); }
  </style>
</head>
<body>
  <div class="sidebar">
    <h2>📋 Admin Panel</h2>
    <a href="?view=search" class="<?= $view === 'search' ? 'active' : '' ?>">🔍 Search User</a>
    <a href="add_user.php">➕ Add User Manually</a>
    <a href="upload_users.php">📁 Upload via CSV</a>
    <a href="download_users.php">⬇️ Download Users</a>
    <a href="dashboard.php">&larr; Back</a>
  </div>

  <div class="content">
    <?php if ($view === 'search'): ?>
      <h2>🔍 Search User</h2>
      <form method="POST">
        <input type="text" name="search_username" placeholder="Enter Username..." required 
               value="<?= htmlspecialchars($_POST['search_username'] ?? '') ?>">
        <button type="submit">Search</button>
      </form>

      <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php elseif (!empty($success)): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <?php if ($user !== null): ?>
        <div class="user-card">
          <h2>User Details</h2>
          <p><strong>Username:</strong> <?= htmlspecialchars($user['username']) ?></p>
          <p><strong>Internet Type:</strong> <?= htmlspecialchars($user['internet_type'] ?? 'unlimited') ?></p>
          <p><strong>Internet Limit:</strong> 
            <?= ($user['internet_type'] ?? 'unlimited') === 'unlimited' ? 'Unlimited' : htmlspecialchars(intval($user['internet_limit'] / 1024 / 1024)) . ' MB' ?>
          </p>
          <p><strong>NAS IP:</strong> <?= htmlspecialchars($user['nasipaddress']) ?></p>
          <p><strong>Start Time:</strong> <?= htmlspecialchars($user['acctstarttime']) ?></p>

          <a href="edit_user.php?username=<?= urlencode($user['username']) ?>" class="link-btn">✏️ Edit</a>
          <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user?');">
            <input type="hidden" name="delete_username" value="<?= htmlspecialchars($user['username']) ?>">
            <button type="submit" name="delete_user" class="link-btn">🗑️ Delete</button>
          </form>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>
