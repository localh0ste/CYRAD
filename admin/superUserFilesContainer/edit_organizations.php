<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/smtp_settings_ui.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$org_id = intval($_GET['id'] ?? 0);
$error = $success = "";

if ($org_id <= 0) {
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><link rel='stylesheet' href='../assets/theme.css'></head><body><div class='app'><div class='main'><div class='content'><div class='card glass'><div class='msg err'>Invalid organization ID.</div><a class='btn ghost' href='view_organizations.php'><i class='fas fa-arrow-left'></i> Back</a></div></div></div></div></body></html>";
    exit;
}

// Fetch organization info
$stmt = $conn->prepare("SELECT * FROM organizations WHERE id = ?");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><link rel='stylesheet' href='../assets/theme.css'></head><body><div class='app'><div class='main'><div class='content'><div class='card glass'><div class='msg err'>Organization not found.</div><a class='btn ghost' href='view_organizations.php'><i class='fas fa-arrow-left'></i> Back</a></div></div></div></div></body></html>";
    exit;
}
$org = $result->fetch_assoc();
$smtp = guest_mail_load($conn, $org_id);
$smtp_csrf_ok = is_string($_POST['csrf_token'] ?? null)
    && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smtp_action'])) {
    [$smtp, , $smtp_msg] = smtp_settings_handle($conn, $org_id, $smtp, $smtp_csrf_ok, (string)$org['org_name']);
    if ($smtp_msg !== '') {
        if (str_contains($smtp_msg, "class='msg err'")) $error = trim(strip_tags($smtp_msg));
        else $success = trim(strip_tags($smtp_msg));
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['smtp_action'])) {
    $org_name = trim($_POST['org_name']);
    $address = trim($_POST['address']);
    $contact_email = trim($_POST['contact_email']);
    $contact_number = trim($_POST['contact_number']);
    $telegram_bot_token = trim($_POST['telegram_bot_token'] ?? '');
    $telegram_admin_uids = trim($_POST['telegram_admin_uids'] ?? '');

    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid form token. Reload and try again.';
    } elseif (empty($org_name)) {
        $error = "Organization name is required.";
    } else {
        $stmt = $conn->prepare("UPDATE organizations SET org_name = ?, address = ?, contact_email = ?, contact_number = ?, telegram_bot_token = ?, telegram_admin_uids = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $org_name, $address, $contact_email, $contact_number, $telegram_bot_token, $telegram_admin_uids, $org_id);
        if ($stmt->execute()) {
            $success = "Organization updated successfully.";
            $org = [
                'org_name' => $org_name,
                'address' => $address,
                'contact_email' => $contact_email,
                'contact_number' => $contact_number,
                'telegram_bot_token' => $telegram_bot_token,
                'telegram_admin_uids' => $telegram_admin_uids,
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Organization</title>
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
    <header class="topbar"><div><strong>Edit Organization</strong></div></header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-building" style="color:var(--cyan)"></i> Edit Organization</h3>

        <?php if (!empty($error)): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <label>Organization Name *</label>
          <input type="text" name="org_name" value="<?= htmlspecialchars($org['org_name']) ?>" required>

          <label>Address</label>
          <textarea name="address" rows="3"><?= htmlspecialchars($org['address']) ?></textarea>

          <label>Contact Email</label>
          <input type="email" name="contact_email" value="<?= htmlspecialchars($org['contact_email']) ?>">

          <label>Contact Number</label>
          <input type="text" name="contact_number" value="<?= htmlspecialchars($org['contact_number']) ?>">

          <hr style="margin:22px 0;border-color:#3a2b33">
          <label>Telegram Bot Token <small style="color:var(--muted)">(alerts + super-admin OTP)</small></label>
          <input type="text" name="telegram_bot_token" value="<?= htmlspecialchars($org['telegram_bot_token'] ?? '') ?>" placeholder="Bot token">

          <label>Telegram Admin Chat IDs <small style="color:var(--muted)">(comma-separated)</small></label>
          <input type="text" name="telegram_admin_uids" value="<?= htmlspecialchars($org['telegram_admin_uids'] ?? '') ?>" placeholder="123456789,987654321">

          <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Update</button>
        </form>

        <hr style="margin:24px 0">
        <?= smtp_settings_form($smtp, (string)$org['org_name'], (string)$_SESSION['csrf_token']) ?>

        <a href="view_organizations.php" class="btn ghost" style="margin-top:14px"><i class="fas fa-arrow-left"></i> Back to Organizations</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
