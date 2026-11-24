<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';

$success = $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $org_name = trim($_POST['org_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');

    if (empty($org_name)) {
        $error = "Organization name is required.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM organizations WHERE org_name = ?");
        $stmt->bind_param("s", $org_name);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Organization already exists.";
        } else {
            $stmt->close();
            $insert = $conn->prepare(
                "INSERT INTO organizations (org_name, address, contact_email, contact_number)
                VALUES (?, ?, ?, ?)
            ");
            $insert->bind_param("ssss", $org_name, $address, $contact_email, $contact_number);
        
            if ($insert->execute()) {
                $success = "Organization created successfully.";
                // Redirect after successful creation
                echo "<script>window.location.href='view_organizations.php';</script>";
                exit;
            } else {
                $error = "Error creating organization: " . $conn->error;
            }
            $insert->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Organization</title>
  <link rel="icon" href="data:,">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/theme.css">
</head>
  </style>
</head>
<body>
  <div class="form-container">
    <h2>Create Organization</h2>

    <?php if ($success): ?>
      <div class="msg success"><?= htmlspecialchars($success) ?></div>
    <?php elseif ($error): ?>
      <div class="msg error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="text" name="org_name" placeholder="Organization Name *" required>
      <textarea name="address" placeholder="Address (optional)" rows="3"></textarea>
      <input type="email" name="contact_email" placeholder="Contact Email (optional)">
      <input type="tel" name="contact_number" placeholder="Contact Number (optional)" pattern="[0-9]{10}" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '')">

      <button type="submit" class="btn">Create</button>
      <a href="super_dashboard.php" class="back-link">← Back to Dashboard</a>
    </form>
  </div>
</body>
</html>
