<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$error = "";
$success = "";
$users = [];
$user = null;
$view = $_GET['view'] ?? 'search';
$selected_org_id = $_POST['organization_id'] ?? $_GET['organization_id'] ?? null;

// Fetch all organizations
$org_result = mysqli_query($conn, "SELECT id, org_name FROM organizations");

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $username = $_POST['delete_username'];
    $org_id = $_POST['organization_id'];

    $stmt = $conn->prepare("DELETE FROM radcheck WHERE username = ? AND organization_id = ?");
    $stmt->bind_param("si", $username, $org_id);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM data_limits WHERE username = ? AND organization_id = ?");
    $stmt->bind_param("si", $username, $org_id);
    $stmt->execute();

    $success = "User '$username' deleted.";
}

// Handle Disable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_user'])) {
    $username = $_POST['disable_username'];
    $org_id = $_POST['organization_id'];

    $stmt = $conn->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password' AND organization_id = ?");
    $stmt->bind_param("si", $username, $org_id);
    $stmt->execute();
    $stmt->bind_result($currentPassword);
    $stmt->fetch();
    $stmt->close();

    if ($currentPassword) {
        if (strpos($currentPassword, 'disabled_') === 0) {
            $success = "User is already disabled.";
        } else {
            $disabled = 'disabled_' . $currentPassword;
            $stmt = $conn->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password' AND organization_id = ?");
            $stmt->bind_param("ssi", $disabled, $username, $org_id);
            $stmt->execute();
            $success = "User '$username' disabled.";
        }
    } else {
        $error = "User not found.";
    }
}

// Search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_username'])) {
    $search = trim($_POST['search_username']);
    $org_id = $_POST['organization_id'];

    $stmt = $conn->prepare("SELECT r.username, r.value as password, d.internet_type, d.internet_limit 
        FROM radcheck r
        LEFT JOIN data_limits d ON r.username = d.username AND r.organization_id = d.organization_id
        WHERE r.username = ? AND r.attribute = 'Cleartext-Password' AND r.organization_id = ?");
    $stmt->bind_param("si", $search, $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        $stmt = $conn->prepare("SELECT nasipaddress, acctstarttime FROM radacct WHERE username = ? ORDER BY acctstarttime DESC LIMIT 1");
        $stmt->bind_param("s", $search);
        $stmt->execute();
        $acct = $stmt->get_result()->fetch_assoc();
        $user['nasipaddress'] = $acct['nasipaddress'] ?? 'N/A';
        $user['acctstarttime'] = $acct['acctstarttime'] ?? 'N/A';
    } else {
        $error = "No user found.";
    }
}

// View all
if ($view === 'all' && $selected_org_id) {
    $stmt = $conn->prepare("SELECT r.username, r.value as password, d.internet_type, d.internet_limit,
        (SELECT nasipaddress FROM radacct a WHERE a.username = r.username ORDER BY acctstarttime DESC LIMIT 1) as nasipaddress,
        (SELECT acctstarttime FROM radacct a WHERE a.username = r.username ORDER BY acctstarttime DESC LIMIT 1) as acctstarttime
        FROM radcheck r
        LEFT JOIN data_limits d ON r.username = d.username AND r.organization_id = d.organization_id
        WHERE r.attribute = 'Cleartext-Password' AND r.organization_id = ?
        ORDER BY r.username ASC");
    $stmt->bind_param("i", $selected_org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['internet_limit'] = ($row['internet_type'] === 'limited' && $row['internet_limit']) 
            ? intval($row['internet_limit'] / 1024 / 1024) . " MB" : "Unlimited";
        $row['nasipaddress'] = $row['nasipaddress'] ?? 'N/A';
        $row['acctstarttime'] = $row['acctstarttime'] ?? 'N/A';
        $users[] = $row;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Super Admin - View Users</title>
  <link rel="icon" href="../images/users_icon.PNG">
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      display: flex;
      height: 100vh;
    }
    .sidebar {
      width: 220px;
      background-color: #1e1e1e;
      color: #fff;
      padding: 1rem;
    }
    .sidebar a {
      display: block;
      color: #ccc;
      text-decoration: none;
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 10px;
    }
    .sidebar a:hover, .sidebar a.active {
      background-color: rgba(250, 9, 78, 0.86);
      color: #fff;
    }
    .content {
      flex: 1;
      padding: 2rem;
      overflow-y: auto;
      background: url('../images/dashboard.jpg');
      background-size: cover;
      color: #fff;
    }
    input, select {
      padding: 10px;
      font-size: 1rem;
      border-radius: 6px;
      margin: 0.5rem 0;
    }
    button {
      padding: 10px 15px;
      font-size: 14px;
      border-radius: 6px;
      background-color: crimson;
      border: none;
      color: white;
      cursor: pointer;
      margin: 2px;
    }
    .success, .error {
      padding: 10px;
      margin: 1rem 0;
      width: 60%;
      border-radius: 6px;
    }
    .success {
      background: #d4edda;
      color: #155724;
    }
    .error {
      background: #f8d7da;
      color: #721c24;
    }
    .user-card, table {
      background-color: rgba(0,0,0,0.7);
      padding: 1rem;
      border-radius: 8px;
      color: white;
      width: 100%;
      margin-top: 1rem;
    }
    table {
      border-collapse: collapse;
      width: 100%;
    }
    th, td {
      padding: 10px;
      border-bottom: 1px solid #999;
      text-align: center;
    }
    th {
      background: crimson;
    }
    .action-buttons form {
      display: inline-block;
    }
  </style>
</head>
<body>
<div class="sidebar">
  <h2>👑 Super Admin</h2>
  <a href="?view=search" class="<?= $view === 'search' ? 'active' : '' ?>">🔍 Search User Manually</a>
  <a href="?view=all&organization_id=<?= $selected_org_id ?>" class="<?= $view === 'all' ? 'active' : '' ?>">📃 View All</a>
  <a href="super_dashboard.php">← Back</a>
</div>

<div class="content">
  <h2><?= $view === 'search' ? "🔍 Search User" : "📃 All Users" ?></h2>

  <?php if (!empty($success)): ?><div class="success"><?= $success ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="error"><?= $error ?></div><?php endif; ?>

  <form method="POST">
    <label>Select Organization:</label><br>
    <select name="organization_id" required onchange="this.form.submit()">
      <option value="">-- Choose --</option>
      <?php mysqli_data_seek($org_result, 0); while ($org = mysqli_fetch_assoc($org_result)): ?>
        <option value="<?= $org['id'] ?>" <?= $selected_org_id == $org['id'] ? 'selected' : '' ?> >
          <?= htmlspecialchars($org['org_name']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </form>

  <?php if ($view === 'search' && $selected_org_id): ?>
    <form method="POST">
      <input type="hidden" name="organization_id" value="<?= $selected_org_id ?>">
      <input type="text" name="search_username" placeholder="Enter Username" required>
      <button type="submit">Search</button>
    </form>

    <?php if ($user): ?>
      <div class="user-card">
        <p><strong>Username:</strong> <?= $user['username'] ?></p>
        <p><strong>Internet:</strong> <?= $user['internet_type'] ?></p>
        <p><strong>Limit:</strong> <?= $user['internet_limit'] ?></p>
        <p><strong>NAS IP:</strong> <?= $user['nasipaddress'] ?></p>
        <p><strong>Start Time:</strong> <?= $user['acctstarttime'] ?></p>
        <div class="action-buttons">
          <form method="GET" action="super_edit_user.php">
            <input type="hidden" name="username" value="<?= $user['username'] ?>">
            <input type="hidden" name="organization_id" value="<?= $selected_org_id ?>">
            <button type="submit">✏️ Edit</button>
          </form>
          <form method="POST">
            <input type="hidden" name="organization_id" value="<?= $selected_org_id ?>">
            <input type="hidden" name="delete_username" value="<?= $user['username'] ?>">
            <button name="delete_user">🗑️ Delete</button>
          </form>
          <form method="POST">
            <input type="hidden" name="organization_id" value="<?= $selected_org_id ?>">
            <input type="hidden" name="disable_username" value="<?= $user['username'] ?>">
            <button name="disable_user">🚫 Disable</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  <?php elseif ($view === 'all' && $selected_org_id): ?>
    <table>
      <thead>
        <tr>
          <th>Username</th>
          <th>Internet</th>
          <th>Limit</th>
          <th>NAS IP</th>
          <th>Start Time</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['internet_type']) ?></td>
            <td><?= htmlspecialchars($u['internet_limit']) ?></td>
            <td><?= htmlspecialchars($u['nasipaddress']) ?></td>
            <td><?= htmlspecialchars($u['acctstarttime']) ?></td>
            <td class="action-buttons">
              <form method="GET" action="super_edit_user.php">
                <input type="hidden" name="username" value="<?= $u['username'] ?>">
                <input type="hidden" name="organization_id" value="<?= $selected_org_id ?>">
                <button type="submit">✏️ Edit</button>
              </form>
              <form method="POST">
                <input type="hidden" name="organization_id" value="<?= $selected_org_id ?>">
                <input type="hidden" name="delete_username" value="<?= $u['username'] ?>">
                <button name="delete_user">🗑️</button>
              </form>
              <form method="POST">
                <input type="hidden" name="organization_id" value="<?= $selected_org_id ?>">
                <input type="hidden" name="disable_username" value="<?= $u['username'] ?>">
                <button name="disable_user">🚫</button>
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
