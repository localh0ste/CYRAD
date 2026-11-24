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

// Handle Delete (single + bulk; also purge group/profile rows; CSRF-protected)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !empty($_POST['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

    $targets = [];
    if (isset($_POST['delete_user']) && is_string($_POST['delete_user'])) {
        $targets = [ $_POST['delete_user'] ];
    } elseif (isset($_POST['bulk_delete']) && !empty($_POST['users']) && is_array($_POST['users'])) {
        $targets = array_values(array_unique(array_filter(array_map('strval', $_POST['users']), fn($x) => $x !== '')));
    }

    if ($targets) {
        $org_id = (int)($_POST['organization_id'] ?? 0);
        $deleted = 0;
        $conn->begin_transaction();
        try {
            $del_rc = $conn->prepare("DELETE FROM radcheck      WHERE username = ? AND organization_id = ?");
            $del_dl = $conn->prepare("DELETE FROM data_limits  WHERE username = ? AND organization_id = ?");
            $del_rg = $conn->prepare("DELETE FROM radusergroup WHERE username = ?");
            $del_up = $conn->prepare("DELETE FROM user_profiles WHERE username = ?");
            foreach ($targets as $uname) {
                if (!preg_match('/^[a-zA-Z0-9_\\-\\.]{1,32}$/', $uname)) continue;
                $del_rc->bind_param("si", $uname, $org_id); $del_rc->execute();
                $del_dl->bind_param("si", $uname, $org_id); $del_dl->execute();
                $del_rg->bind_param("s",  $uname);          $del_rg->execute();
                $del_up->bind_param("s",  $uname);          $del_up->execute();
                if ($del_rc->affected_rows > 0) $deleted++;
            }
            $conn->commit();
            $success = $deleted . " user(s) deleted.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Delete failed: " . $e->getMessage();
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: view_org_users.php?view=all&organization_id=" . $org_id);
        exit;
    }
}

// Handle Disable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_user'])) {
    $username = $_POST['disable_user'];
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

    $stmt = $conn->prepare("SELECT r.username, r.value as password, d.internet_type, d.internet_limit,
        (SELECT 1 FROM radacct a WHERE a.username = r.username AND (a.acctstoptime IS NULL OR a.acctstoptime = '') ORDER BY a.acctstarttime DESC LIMIT 1) as online
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
        (SELECT acctstarttime FROM radacct a WHERE a.username = r.username ORDER BY acctstarttime DESC LIMIT 1) as acctstarttime,
        (SELECT 1 FROM radacct a WHERE a.username = r.username AND (a.acctstoptime IS NULL OR a.acctstoptime = '') ORDER BY a.acctstarttime DESC LIMIT 1) as online
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
        $row['online'] = $row['online'] ? 'Online' : 'Offline';
        $users[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Super Admin - View Users</title>
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
    <header class="topbar">
      <div><strong><?= $view === 'search' ? 'Search User' : 'All Users' ?></strong></div>
    </header>
    <div class="content">

      <?php if (!empty($success)): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="card glass">
        <form method="POST">
          <label>Select Organization</label>
          <select name="organization_id" required onchange="this.form.submit()">
            <option value="">-- Choose --</option>
            <?php mysqli_data_seek($org_result, 0); while ($org = mysqli_fetch_assoc($org_result)): ?>
              <option value="<?= $org['id'] ?>" <?= $selected_org_id == $org['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($org['org_name']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </form>
      </div>

      <?php if ($view === 'search' && $selected_org_id): ?>
        <div class="card glass">
          <h3><i class="fas fa-search" style="color:var(--cyan)"></i> Search User</h3>
          <form method="POST">
            <input type="hidden" name="organization_id" value="<?= htmlspecialchars($selected_org_id) ?>">
            <input type="text" name="search_username" placeholder="Enter Username" required>
            <p><button class="btn" type="submit"><i class="fas fa-search"></i> Search</button></p>
          </form>

          <?php if ($user): ?>
            <table>
              <tbody>
                <tr><th>Username</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
                <tr><th>Internet</th><td><?= htmlspecialchars($user['internet_type']) ?></td></tr>
                <tr><th>Limit</th><td><?= htmlspecialchars($user['internet_limit']) ?></td></tr>
                <tr><th>NAS IP</th><td><?= htmlspecialchars($user['nasipaddress']) ?></td></tr>
                <tr><th>Start Time</th><td><?= htmlspecialchars($user['acctstarttime']) ?></td></tr>
                <tr><th>Status</th><td><span class="pill <?= !empty($user['online']) ? 'allow' : 'off' ?>"><?= !empty($user['online']) ? 'Online' : 'Offline' ?></span></td></tr>
              </tbody>
            </table>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
              <a class="btn sm" href="super_edit_user.php?username=<?= urlencode($user['username']) ?>&organization_id=<?= htmlspecialchars($selected_org_id) ?>"><i class="fas fa-edit"></i> Edit</a>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="organization_id" value="<?= htmlspecialchars($selected_org_id) ?>">
                <button class="btn sm danger" name="delete_user" value="<?= htmlspecialchars($user['username']) ?>" type="submit" onclick="return confirm('Delete this user?')"><i class="fas fa-trash"></i> Delete</button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="organization_id" value="<?= htmlspecialchars($selected_org_id) ?>">
                <button class="btn sm" name="disable_user" value="<?= htmlspecialchars($user['username']) ?>" type="submit"><i class="fas fa-ban"></i> Disable</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php elseif ($view === 'all' && $selected_org_id): ?>
        <div class="card glass">
          <h3><i class="fas fa-list" style="color:var(--cyan)"></i> All Users</h3>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="organization_id" value="<?= htmlspecialchars($selected_org_id) ?>">
            <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <button class="btn sm danger" type="submit" name="bulk_delete"
                      onclick="return confirm('Delete the selected user(s)? This cannot be undone.')">
                <i class="fas fa-trash"></i> Delete Selected
              </button>
              <span id="selCountSuper" style="color:var(--muted)"></span>
            </div>
            <table>
              <thead>
                <tr>
                  <th><input type="checkbox" id="selAllSuper" title="Select all"></th>
                  <th>Username</th>
                  <th>Internet</th>
                  <th>Limit</th>
                  <th>NAS IP</th>
                  <th>Start Time</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td><input type="checkbox" class="rowSelSuper" name="users[]" value="<?= htmlspecialchars($u['username']) ?>"></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['internet_type']) ?></td>
                    <td><?= htmlspecialchars($u['internet_limit']) ?></td>
                    <td><?= htmlspecialchars($u['nasipaddress']) ?></td>
                    <td><?= htmlspecialchars($u['acctstarttime']) ?></td>
                    <td><span class="pill <?= $u['online'] === 'Online' ? 'allow' : 'off' ?>"><?= htmlspecialchars($u['online']) ?></span></td>
                    <td>
                      <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <a class="btn sm" href="super_edit_user.php?username=<?= urlencode($u['username']) ?>&organization_id=<?= htmlspecialchars($selected_org_id) ?>"><i class="fas fa-edit"></i></a>
                        <button class="btn sm danger" type="submit" name="delete_user" value="<?= htmlspecialchars($u['username']) ?>"
                                onclick="return confirm('Delete user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? This cannot be undone.')">
                          <i class="fas fa-trash"></i>
                        </button>
                        <button class="btn sm" type="submit" name="disable_user" value="<?= htmlspecialchars($u['username']) ?>">
                          <i class="fas fa-ban"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </form>
          <script>
            const selAllS = document.getElementById('selAllSuper');
            const rowSelS = document.querySelectorAll('.rowSelSuper');
            const selCountS = document.getElementById('selCountSuper');
            function updateCountS() {
              const n = document.querySelectorAll('.rowSelSuper:checked').length;
              selCountS.textContent = n ? n + ' selected' : '';
            }
            selAllS.addEventListener('change', () => {
              rowSelS.forEach(c => { c.checked = selAllS.checked; });
              updateCountS();
            });
            rowSelS.forEach(c => c.addEventListener('change', () => {
              selAllS.checked = document.querySelectorAll('.rowSelSuper:checked').length === rowSelS.length;
              updateCountS();
            }));
            updateCountS();
          </script>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
