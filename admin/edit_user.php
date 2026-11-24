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

require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/router_policy.php';

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

    $stmt = $conn->prepare("SELECT username, value, organization_id FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
    $stmt->bind_param("s", $username_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $radcheck = $result->fetch_assoc();
    $stmt->close();

    if ($radcheck) {
        if ((int)($radcheck['organization_id'] ?? -1) !== $org_id) {
            $error = "User not found.";
            $radcheck = null;
        }
    }

    if ($radcheck) {
        $stmt = $conn->prepare("SELECT internet_type, internet_limit FROM data_limits WHERE username = ? AND organization_id = ?");
        $stmt->bind_param("si", $username_param, $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $datalimit = $result->fetch_assoc();
        $stmt->close();

        $user = [
            'username' => $radcheck['username'],
            'auth_source' => 'local',
            'internet_type' => $datalimit ? $datalimit['internet_type'] : 'unlimited',
            'internet_limit' => $datalimit ? intval($datalimit['internet_limit'] / (1024 * 1024)) : ''
        ];
    } else {
        // No local radcheck row: an Active Directory shadow (billing-only) —
        // credentials live in the domain, verified over LDAP/winbind by RADIUS.
        $up = $conn->prepare("SELECT username, organization_id, auth_source FROM user_profiles WHERE username = ? AND organization_id = ?");
        $up->bind_param("si", $username_param, $org_id);
        $up->execute();
        $ures = $up->get_result();
        $ushadow = $ures->fetch_assoc();
        $up->close();

        if ($ushadow && $ushadow['auth_source'] === 'ad') {
            $dlim = $conn->prepare("SELECT internet_type, internet_limit FROM data_limits WHERE username = ? AND organization_id = ?");
            $dlim->bind_param("si", $username_param, $org_id);
            $dlim->execute();
            $dresult = $dlim->get_result();
            $datalimit = $dresult->fetch_assoc();
            $dlim->close();

            $user = [
                'username' => $ushadow['username'],
                'auth_source' => 'ad',
                'internet_type' => $datalimit ? $datalimit['internet_type'] : 'unlimited',
                'internet_limit' => $datalimit ? intval($datalimit['internet_limit'] / (1024 * 1024)) : ''
            ];
        } else {
            $error = "User not found or not an Active Directory shadow.";
        }
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

    // Is this an Active Directory shadow? (billing-only; credentials live in the domain)
    $is_ad = false;
    if (!empty($original_username)) {
        $upc = $conn->prepare("SELECT username FROM user_profiles WHERE username = ? AND organization_id = ? AND auth_source = 'ad'");
        $upc->bind_param("si", $original_username, $org_id);
        $upc->execute();
        $upc->store_result();
        $is_ad = $upc->num_rows > 0;
        $upc->close();
    }

    if ($is_ad) {
        // AD users: plan only — the password is verified against the domain controller.
        if ($internet_type !== 'unlimited' && $internet_type !== 'limited') {
            $error = "Please choose a valid internet type.";
        } elseif ($internet_type === 'limited' && (empty($internet_limit_mb) || $internet_limit_mb <= 0)) {
            $error = "Internet limit must be greater than 0 MB.";
        } else {
            $limit_bytes = ($internet_type === 'limited') ? $internet_limit_mb * 1024 * 1024 : 0;
            $upd = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit, organization_id, usage_reset_ts)
                                   VALUES (?, ?, ?, ?, NOW())
                                   ON DUPLICATE KEY UPDATE internet_type = VALUES(internet_type), internet_limit = VALUES(internet_limit), usage_reset_ts = NOW()");
            $upd->bind_param("sii", $original_username, $internet_type, $limit_bytes, $org_id);
            $upd->execute();
            $upd->close();
            header("Location: edit_user.php?username=" . urlencode($original_username) . "&updated=1");
            exit;
        }
    } elseif (
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
                    $stmt = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit, organization_id, usage_reset_ts) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->bind_param("ssii", $new_username, $internet_type, $limit_bytes, $org_id);
                }
                $stmt->execute();
                $stmt->close();
                // Forgive already-used data so an increased limit gives a fresh full allowance
                $rstmt = $conn->prepare("UPDATE data_limits SET usage_reset_ts = NOW() WHERE username = ? AND organization_id = ?");
                $rstmt->bind_param("si", $new_username, $org_id);
                $rstmt->execute();
                $rstmt->close();
            } else {
                if ($exists) {
                    $stmt = $conn->prepare("DELETE FROM data_limits WHERE username = ? AND organization_id = ?");
                    $stmt->bind_param("si", $original_username, $org_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // Re-enable the user so they can reconnect after a limit change / increase
            $reen = $conn->prepare("UPDATE radcheck SET value = SUBSTRING(value, 10) WHERE username = ? AND attribute = 'Cleartext-Password' AND LEFT(value, 9) = 'disabled_'");
            $reen->bind_param("s", $new_username);
            $reen->execute();
            $reen->close();
            // Auto-unban: raising/deleting the limit clears any active 24h block
            $ub = $conn->prepare("UPDATE data_limits SET banned_until = NULL WHERE username = ? AND organization_id = ?");
            $ub->bind_param("si", $new_username, $org_id);
            $ub->execute();
            $ub->close();
            // Lift MAC blocks so a raised limit lets the device rejoin right away
            cyrad_blacklist_forget_user($conn, $original_username);
            if ($new_username !== $original_username) cyrad_blacklist_forget_user($conn, $new_username);

            header("Location: edit_user.php?username=" . urlencode($new_username) . "&updated=1");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="data:,">
  <title>Edit User</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>CONTROL PANEL</small></div></div>
    <?php require __DIR__ . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar">
      <div><strong>Edit User</strong></div>
      <div class="org"><?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?> · Org #<?= (int)($_SESSION['organization_id'] ?? 0) ?></div>
    </header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-user-edit" style="color:var(--cyan)"></i> Edit Wi-Fi User</h3>

        <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <?php if ($user): ?>
        <form method="POST">
          <input type="hidden" name="original_username" value="<?= htmlspecialchars($user['username']) ?>">

          <?php if (($user['auth_source'] ?? 'local') === 'ad'): ?>
            <label>Username <small>(Active Directory account)</small></label>
            <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled>
            <input type="hidden" name="username" value="<?= htmlspecialchars($user['username']) ?>">
            <label>Authentication Source</label>
            <div><span class="pill block">Active Directory</span></div>
            <p class="muted-sm">The password is validated by the domain controller (LDAP/winbind)
              — it is never stored in the panel; only the billing plan is kept here.</p>
          <?php else: ?>
            <label>Username</label>
            <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>

            <label>Password (leave blank to keep unchanged)</label>
            <input type="password" name="password" minlength="8">
          <?php endif; ?>

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
            <a href="users.php" class="btn ghost"><i class="fas fa-arrow-left"></i> Back</a>
          </div>
        </form>
        <?php endif; ?>
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
