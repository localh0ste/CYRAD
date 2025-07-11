<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$success = '';
$error = '';

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_org'])) {
    $org_id = intval($_POST['org_id']);

    // 1. Delete users from radacct
    $stmt1 = $conn->prepare("DELETE FROM radacct WHERE username IN (SELECT username FROM radcheck WHERE organization_id = ?)");
    $stmt1->bind_param("i", $org_id);
    $stmt1->execute();

    // 2. Delete users from radcheck
    $stmt2 = $conn->prepare("DELETE FROM radcheck WHERE organization_id = ?");
    $stmt2->bind_param("i", $org_id);
    $stmt2->execute();

    // 3. Delete users from data_limits
    $stmt3 = $conn->prepare("DELETE FROM data_limits WHERE organization_id = ?");
    $stmt3->bind_param("i", $org_id);
    $stmt3->execute();

    // 4. Delete related admins
    $stmt4 = $conn->prepare("DELETE FROM admin_users WHERE organization_id = ?");
    $stmt4->bind_param("i", $org_id);
    $stmt4->execute();

    // 5. Finally delete the organization
    $stmt5 = $conn->prepare("DELETE FROM organizations WHERE id = ?");
    $stmt5->bind_param("i", $org_id);

    if ($stmt5->execute()) {
        $success = "✅ Organization, its admins, and users deleted successfully.";
        header("Location: view_organizations.php?deleted=1");
        exit;
    } else {
        $error = "❌ Error deleting organization.";
    }
}

$result = $conn->query("SELECT * FROM organizations ORDER BY created_at");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>View Organizations</title>
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
      max-width: 95%;
      margin: auto;
      background: rgba(0, 0, 0, 0.85);
      padding: 30px;
      border-radius: 16px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
    }

    h2 {
      text-align: center;
      color: rgba(250, 9, 78, 0.86);
      margin-bottom: 20px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    th, td {
      padding: 12px 16px;
      border-bottom: 1px solid #444;
      text-align: left;
    }

    th {
      background-color: #222;
      color: #f5395c;
    }

    tr:hover {
      background-color: rgba(255, 255, 255, 0.05);
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

    .edit-link {
      color: rgba(250, 9, 78, 0.86);
      text-decoration: none;
      font-weight: bold;
      cursor: pointer;
    }

    .edit-link:hover {
      text-decoration: underline;
    }

    .no-data {
      text-align: center;
      color: #bbb;
      margin-top: 20px;
    }

    .success {
      background: #2ecc71;
      color: black;
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 20px;
      text-align: center;
    }

    .error {
      background: #e74c3c;
      color: white;
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 20px;
      text-align: center;
    }

    form.delete-form {
      display: inline;
    }

    form.delete-form button {
      background: none;
      border: none;
      color: #f5395c;
      font-weight: bold;
      cursor: pointer;
    }

    form.delete-form button:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
<div class="container">
  <h2>All Registered Organizations</h2>

  <?php if (isset($_GET['deleted'])): ?>
    <div class="success">Organization deleted successfully.</div>
  <?php endif; ?>

  <?php if (!empty($success)): ?>
    <div class="success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($result && $result->num_rows > 0): ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Organization Name</th>
          <th>Address</th>
          <th>Contact Email</th>
          <th>Contact Number</th>
          <th>Created</th>
          <th>Updated</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php while($org = $result->fetch_assoc()): ?>
          <tr>
            <td><?= $org['id'] ?></td>
            <td><?= htmlspecialchars($org['org_name']) ?></td>
            <td><?= nl2br(htmlspecialchars($org['address'])) ?></td>
            <td><?= htmlspecialchars($org['contact_email']) ?></td>
            <td><?= htmlspecialchars($org['contact_number']) ?></td>
            <td><?= $org['created_at'] ?></td>
            <td><?= $org['updated_at'] ?></td>
            <td>
              <a href="view_admin.php?id=<?= $org['id'] ?>" class="edit-link">Admin</a> |
              <a href="edit_organizations.php?id=<?= $org['id'] ?>" class="edit-link">Edit</a> |
              <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this organization, its admins, and all its users?');">
                <input type="hidden" name="org_id" value="<?= $org['id'] ?>">
                <button type="submit" name="delete_org">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="no-data">No organizations found.</p>
  <?php endif; ?>

  <a href="super_dashboard.php" class="back">&larr; Back to Dashboard</a>
</div>
</body>
</html>
