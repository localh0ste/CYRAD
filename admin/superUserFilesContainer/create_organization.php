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
            $insert = $conn->prepare("
                INSERT INTO organizations (org_name, address, contact_email, contact_number)
                VALUES (?, ?, ?, ?)
            ");
            $insert->bind_param("ssss", $org_name, $address, $contact_email, $contact_number);
            if ($insert->execute()) {
                $success = "Organization created successfully.";
            } else {
                $error = "Error creating organization.";
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
  <title>Create Organization</title>
  <link rel="icon" href="../images/orgIcon.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      font-family: 'Roboto', sans-serif;
      background: url("../images/background.jpg");
      background-size: cover;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .form-container {
      background-color: rgba(0, 0, 0, 0.85);
      padding: 30px 40px;
      border-radius: 12px;
      max-width: 600px;
      width: 100%;
      color: #fff;
      box-shadow: 0 0 25px rgba(0,0,0,0.5);
    }

    h2 {
      text-align: center;
      color: #ff0055;
      margin-bottom: 20px;
    }

    form {
      display: flex;
      flex-direction: column;
    }

    input, textarea {
      background-color: #1f1f1f;
      color: white;
      padding: 10px;
      margin-bottom: 15px;
      border: none;
      border-radius: 8px;
      font-size: 15px;
    }

    input::placeholder, textarea::placeholder {
      color: #aaa;
    }

    .btn {
      padding: 12px;
      background-color: rgba(250, 9, 78, 0.86);
      border: none;
      border-radius: 8px;
      color: white;
      font-weight: bold;
      cursor: pointer;
      font-size: 16px;
      margin-top: 10px;
    }

    .btn:hover {
      background-color: rgba(218, 6, 66, 0.92);
    }
    .back-btn {
  display: inline-block;
  text-align: center;
  background-color: transparent;
  border: 2px solid #ccc;
  color: #ccc;
  text-decoration: none;
  padding: 10px;
  margin-top: 10px;
  border-radius: 8px;
  font-size: 15px;
  transition: all 0.3s ease;
}

.back-btn:hover {
  background-color: rgba(255, 255, 255, 0.1);
  color: white;
  border-color: white;
}


    .msg {
      padding: 12px;
      border-radius: 8px;
      text-align: center;
      margin-bottom: 15px;
      font-weight: bold;
    }
    .back-link {
      color: #ccc;
      padding-top:10px;
      text-decoration: none;
    }

    .back-link:hover {
      color: white;
    }

    .success {
      background-color: rgba(0, 255, 0, 0.15);
      color: #2ecc71;
    }

    .error {
      background-color: rgba(255, 0, 0, 0.15);
      color: #ff6b6b;
    }

    @media (max-width: 600px) {
      .form-container {
        padding: 20px;
        margin: 10px;
      }
    }
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
