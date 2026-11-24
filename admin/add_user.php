<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://fonts.googleapis.com 'unsafe-inline'; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_user']) || !isset($_SESSION['organization_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/lib/ad_config.php';
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

function sanitize_input($data, $is_password = false) {
    return $is_password ? trim($data) : strip_tags(htmlspecialchars(trim($data), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function validate_username($username) {
    return preg_match('/^[a-zA-Z0-9_\-\.]{4,32}$/', $username);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token.";
        header("Location: add_user.php");
        exit;
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // regenerate

    $username = strtolower(sanitize_input($_POST['username'] ?? ''));
    $password = sanitize_input($_POST['password'] ?? '', true);
    $auth_source = in_array($_POST['auth_source'] ?? '', ['local', 'ad']) ? $_POST['auth_source'] : 'local';
    $internet_type = sanitize_input($_POST['internet_type'] ?? '');
    $internet_limit_mb = ($internet_type === 'limited') ? ($_POST['internet_limit'] ?? '') : null;
    $organization_id = $_SESSION['organization_id'];
    $user_info = trim(strip_tags($_POST['user_info'] ?? ''));

    if ($auth_source === 'ad') {
        // Saving the domain-controller details is the only thing an AD submit
        // does here. If the operator just pressed "Save domain controller",
        // store the typed DC values; otherwise this was a plain Add User click,
        // and AD users are never added by hand.
        if (isset($_POST['save_ad'])) {
            $c = [
                'ad_domain' => $_POST['ad_domain'] ?? '',
                'dc_host'   => $_POST['dc_host'] ?? '',
                'dc_ip'     => $_POST['dc_ip'] ?? '',
                'dc_port'   => $_POST['dc_port'] ?? 389,
                'use_tls'   => isset($_POST['use_tls']) ? 1 : 0,
                'base_dn'   => $_POST['base_dn'] ?? '',
                'bind_dn'   => $_POST['bind_dn'] ?? '',
                'bind_pass' => $_POST['bind_pass'] ?? '',
                'group_dn'  => $_POST['group_dn'] ?? '',
            ];
            $err = ad_config_validate($c);
            if ($err !== '') {
                $_SESSION['error'] = 'Domain controller: ' . $err;
            } else {
                $save_err = ad_config_save($conn, $c);
                if ($save_err !== '') {
                    $_SESSION['error'] = $save_err;
                } else {
                    $saved = ad_config_load($conn, false);
                    $_SESSION['success'] = 'Domain controller saved: ' . ad_config_uri($saved)
                        . ' (base DN ' . $saved['base_dn'] . '). The server now reads these'
                        . ' values by itself — run  sudo bash '
                        . ad_config_apply_script() . '  over SSH to hand them to FreeRADIUS.';
                }
            }
            header("Location: add_user.php");
            exit;
        }

        // Plain Add User click with AD selected: no manual user row is created.
        // The person's AD login is authenticated by the domain controller
        // (LDAP/winbind) when they connect to WiFi and auto-registered with an
        // Unlimited plan by FreeRADIUS post-auth.
        $_SESSION['success'] = "Active Directory needs no manual add. Anyone with valid domain credentials is authenticated automatically via LDAP and auto-registered with an Unlimited plan on their first WiFi login.";
        header("Location: add_user.php");
        exit;
    }

    if (!validate_username($username)) {
        $_SESSION['error'] = "Username must be 4–32 characters: only letters, numbers, underscore, hyphen, dot.";
        header("Location: add_user.php");
        exit;
    }

    if (empty($password) || strlen($password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters.";
        header("Location: add_user.php");
        exit;
    }

    if (!in_array($internet_type, ['unlimited', 'limited'])) {
        $_SESSION['error'] = "Invalid internet type selected.";
        header("Location: add_user.php");
        exit;
    }

    if ($internet_type === 'limited') {
        if (empty($internet_limit_mb) || !is_numeric($internet_limit_mb) || $internet_limit_mb < 100) {
            $_SESSION['error'] = "Internet limit must be at least 100MB.";
            header("Location: add_user.php");
            exit;
        }
        $internet_limit_bytes = intval($internet_limit_mb) * 1024 * 1024;
    } else {
        // Unlimited means no cap at all: store 0, the same convention as
        // edit_user and the AD auto-registration (never an unset variable).
        $internet_limit_bytes = 0;
    }

    $vlan_tag = null;

    require_once __DIR__ . '/config/db.php';
    if ($conn->connect_error) {
        $_SESSION['error'] = "Database connection failed: " . $conn->connect_error;
        header("Location: add_user.php");
        exit;
    }

    $check = $conn->prepare("SELECT username FROM radcheck WHERE username = ? UNION SELECT username FROM user_profiles WHERE username = ?");
    $check->bind_param("ss", $username, $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $_SESSION['error'] = "Username already exists.";
        $check->close();
        $conn->close();
        header("Location: add_user.php");
        exit;
    }

    $check->close();

    $conn->begin_transaction();
    try {
        if ($auth_source === 'local') {
            $stmt1 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value, organization_id) VALUES (?, 'Cleartext-Password', ':=', ?, ?)");
            $stmt1->bind_param("ssi", $username, $password, $organization_id);
            $stmt1->execute();
            $stmt1->close();
        }

        $up = $conn->prepare("INSERT INTO user_profiles (username, organization_id, user_info, auth_source) VALUES (?, ?, ?, ?)");
        $up->bind_param("siss", $username, $organization_id, $user_info, $auth_source);
        $up->execute();
        $up->close();

        if ($internet_type === 'limited') {
            $stmt2 = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit, organization_id, usage_reset_ts) VALUES (?, ?, ?, ?, NOW())");
            $stmt2->bind_param("ssii", $username, $internet_type, $internet_limit_bytes, $organization_id);
            $stmt2->execute();
            $stmt2->close();
        }

        if ($vlan_tag !== null) {
            // VLAN pin removed
        }

        $conn->commit();
        $_SESSION['success'] = "User added successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Add user failed: " . $e->getMessage());
        $_SESSION['error'] = "Failed to add user. Error: " . $e->getMessage();
    }

    $conn->close();
    header("Location: add_user.php");
    exit;
}

$vlan_options = [];

// --- AD / domain controller settings shown when 'Active Directory' is selected ---
// Hand-typed values from the form below (ad_config table, db/v12); blanks fall
// back to the CYRAD_AD_* constants in config.php.
$adc = ad_config_load($conn);
$ad = [
    'domain'  => ad_config_const('CYRAD_AD_DOMAIN'),
    'host'    => (string)$adc['dc_host'],
    'ip'      => ad_config_resolve_ip($adc),
    'port'    => (int)$adc['dc_port'],
    'tls'     => (int)$adc['use_tls'],
    'base'    => (string)$adc['base_dn'],
    'bind'    => (string)$adc['bind_dn'],
    'group'   => (string)$adc['group_dn'],
    'pass'    => trim((string)$adc['bind_pass']) !== '',
    'uri'     => ad_config_uri($adc),
    'ready'   => ad_config_ready($adc),
    'command' => ad_config_apply_command($adc),
    'script'  => ad_config_apply_script(),
];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add User</title>
  <link rel="icon" href="data:,">
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
      <div><strong>Add User</strong></div>
      <div class="org"><?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?> · Org #<?= (int)($_SESSION['organization_id'] ?? 0) ?></div>
    </header>
    <div class="content">
      <div class="card glass">
        <h3><i class="fas fa-user-plus" style="color:var(--cyan)"></i> Add Wi-Fi User</h3>

        <?php if (!empty($error)): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

          <label for="auth_source">Authentication Source *</label>
          <select name="auth_source" id="auth_source" required>
            <option value="local" <?= (isset($_POST['auth_source']) && $_POST['auth_source'] === 'local') ? 'selected' : '' ?>>Local (RADIUS database)</option>
            <option value="ad" <?= (isset($_POST['auth_source']) && $_POST['auth_source'] === 'ad') ? 'selected' : '' ?>>Active Directory (auto LDAP)</option>
          </select>
          <p class="muted-sm" id="adNote" style="display:none">
            No user is added here &mdash; Active Directory users appear by themselves on
            first WiFi login. Below you point the panel at your <strong>domain controller</strong>:
            type the DC host/IP, base DN and read-only service account yourself, then save.
          </p>

          <div id="adDcBox" style="display:none;margin:10px 0;padding:12px 14px;border-left:3px solid var(--cyan);background:rgba(34,211,238,.06);border-radius:6px">
            <div style="font-weight:700;margin-bottom:8px"><i class="fas fa-server"></i> Domain Controller (LDAP) &mdash; enter your own details</div>

            <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
              <div>
                <label for="ad_domain">AD domain <span class="muted-sm">(used to join the host)</span></label>
                <input type="text" name="ad_domain" id="ad_domain" value="<?= htmlspecialchars($adc['ad_domain']) ?>" placeholder="corp.local" autocomplete="off">
                <p class="muted-sm" style="margin-top:4px">Optional if already set in <code>config.php</code>.</p>
              </div>
              <div>
                <label for="dc_host">DC host *</label>
                <input type="text" name="dc_host" id="dc_host" value="<?= htmlspecialchars($ad['host']) ?>" placeholder="dc01.corp.local" autocomplete="off">
                <p class="muted-sm" style="margin-top:4px">Hostname of your domain controller.</p>
              </div>
            </div>

            <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
              <div>
                <label for="dc_ip">DC IP</label>
                <input type="text" name="dc_ip" id="dc_ip" value="<?= htmlspecialchars($adc['dc_ip']) ?>" placeholder="10.0.0.10" autocomplete="off">
                <p class="muted-sm" style="margin-top:4px">Optional. Used as-is instead of resolving the host name.</p>
              </div>
              <div>
                <label for="dc_port">LDAP port</label>
                <input type="number" name="dc_port" id="dc_port" min="1" max="65535" value="<?= (int)$adc['dc_port'] ?: 389 ?>">
              </div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;margin:-4px 0 12px">
              <input type="checkbox" name="use_tls" id="use_tls" value="1" <?= $ad['tls'] ? 'checked' : '' ?> style="width:auto">
              <label for="use_tls" style="margin:0">Use LDAPS (TLS) &mdash; switches the port to 636</label>
            </div>

            <label for="base_dn">Base DN *</label>
            <input type="text" name="base_dn" id="base_dn" value="<?= htmlspecialchars($adc['base_dn']) ?>" placeholder="DC=corp,DC=local" autocomplete="off">

            <label for="bind_dn">Bind DN * <span class="muted-sm">(read-only service account)</span></label>
            <input type="text" name="bind_dn" id="bind_dn" value="<?= htmlspecialchars($adc['bind_dn']) ?>" placeholder="CN=radius,OU=svc,DC=corp,DC=local" autocomplete="off">

            <label for="bind_pass">Bind password</label>
            <input type="password" name="bind_pass" id="bind_pass" placeholder="<?= $ad['pass'] ? 'Stored &mdash; leave blank to keep it' : 'Service account password' ?>" autocomplete="new-password">

            <label for="group_dn">Restrict to AD group <span class="muted-sm">(optional)</span></label>
            <input type="text" name="group_dn" id="group_dn" value="<?= htmlspecialchars($adc['group_dn']) ?>" placeholder="CN=WiFi-Users,OU=Groups,DC=corp,DC=local" autocomplete="off">
            <p class="muted-sm" style="margin-top:4px">Leave empty to allow every account in the domain.</p>

            <input type="hidden" name="save_ad" value="1">
            <div style="margin-top:12px">
              <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save domain controller</button>
            </div>

            <?php if ($ad['ready']): ?>
              <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);font-size:.85rem">
                <div style="color:var(--muted);margin-bottom:4px">Saved &mdash; LDAP URI <strong><?= htmlspecialchars($ad['uri']) ?></strong><?= $ad['tls'] ? ' <span class="pill allow">StartTLS</span>' : '' ?></div>
                <div style="color:var(--muted)">Apply to FreeRADIUS (domain join, mschap/ntlm_auth, AD-first authorize, auto-register) by running this over SSH — it picks the saved values up on its own:</div>
                <code style="display:block;margin-top:6px;word-break:break-all;white-space:pre-wrap">sudo bash <?= htmlspecialchars($ad['script']) ?></code>
                <div style="color:var(--muted);margin-top:10px">Check what the server would use, without changing anything: <code style="display:inline">sudo bash <?= htmlspecialchars($ad['script']) ?> --print-effective</code></div>
                <details style="margin-top:10px">
                  <summary style="cursor:pointer;color:var(--muted);font-size:.8rem">Run it with the values spelled out instead (CI, one-shot)</summary>
                  <code style="display:block;margin-top:6px;word-break:break-all;white-space:pre-wrap"><?= htmlspecialchars($ad['command']) ?></code>
                </details>
              </div>
            <?php else: ?>
              <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);font-size:.85rem;color:var(--muted)">
                Not configured yet. Fill in the fields above and save, then run <code style="display:inline">sudo bash <?= htmlspecialchars($ad['script']) ?></code> over SSH to hand the settings to FreeRADIUS.
              </div>
            <?php endif; ?>
          </div>

          <div id="localFields">
            <div id="usernameGroup">
              <label for="username">Username *</label>
              <input type="text" name="username" id="username" required value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
            </div>

            <div id="passwordGroup">
              <label for="password">Password *</label>
              <input type="password" name="password" id="password" required>
            </div>

            <label for="internet_type">Internet Type *</label>
            <select name="internet_type" id="internet_type" required>
              <option value="">-- Select --</option>
              <option value="unlimited" <?= (isset($_POST['internet_type']) && $_POST['internet_type'] === 'unlimited') ? 'selected' : '' ?>>Unlimited</option>
              <option value="limited" <?= (isset($_POST['internet_type']) && $_POST['internet_type'] === 'limited') ? 'selected' : '' ?>>Limited</option>
            </select>

            <div id="mb-input-container" style="display:none">
              <label for="internet_limit">Internet Limit (in MB) *</label>
              <input type="number" name="internet_limit" id="internet_limit" min="100" value="<?= isset($_POST['internet_limit']) ? htmlspecialchars($_POST['internet_limit']) : '' ?>">
            </div>

            <!-- VLAN functionality removed -->

            <label for="user_info">User Info <small style="color:var(--muted)">(notes shown to admins &amp; Telegram alerts)</small></label>
            <textarea name="user_info" id="user_info" rows="3" placeholder="e.g. Room 204, Hostel Block B, contact 9xxxx"><?= isset($_POST['user_info']) ? htmlspecialchars($_POST['user_info']) : '' ?></textarea>

            <button class="btn" type="submit"><i class="fas fa-user-plus"></i> Add User</button>
          </div>
        </form>

        <a class="btn ghost" href="users.php" style="margin-top:14px"><i class="fas fa-arrow-left"></i> Back to Users</a>
      </div>
    </div>
  </div>
</div>
<script>
  function toggleMBInput(value) {
    const mbContainer = document.getElementById('mb-input-container');
    const mbInput = document.getElementById('internet_limit');
    if (value === 'limited') {
      mbContainer.style.display = 'block';
      mbInput.required = true;
    } else {
      mbContainer.style.display = 'none';
      mbInput.required = false;
      mbInput.value = '';
    }
  }

  function toggleAuthSource(value) {
    const localFields = document.getElementById('localFields');
    const note = document.getElementById('adNote');
    const dcbox = document.getElementById('adDcBox');
    const isAd = value === 'ad';
    // AD mode: the only fields in play are the domain-controller ones in
    // adDcBox. localFields is hidden, so its required inputs must stop being
    // required — otherwise the browser refuses to submit "Save domain
    // controller" and never sends the DC values.
    localFields.style.display = isAd ? 'none' : 'block';
    note.style.display = isAd ? 'block' : 'none';
    dcbox.style.display = isAd ? 'block' : 'none';
    ['username', 'password', 'internet_type'].forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.required = !isAd;
    });
  }

  document.addEventListener("DOMContentLoaded", function() {
    const internetTypeSelect = document.getElementById('internet_type');
    toggleMBInput(internetTypeSelect.value);
    internetTypeSelect.addEventListener('change', function() {
      toggleMBInput(this.value);
    });
    const authSourceSelect = document.getElementById('auth_source');
    toggleAuthSource(authSourceSelect.value);
    authSourceSelect.addEventListener('change', function() {
      toggleAuthSource(this.value);
    });
  });
</script>
</body>
</html>
