<?php
// Shared sidebar. Renders the full admin navigation on every page so all
// sections (Users, WiFi Profiles, Guest Tickets, DNS Rules, IP Map)
// stay visible regardless of the current page. Works from both admin/ and
// admin/superUserFilesContainer/ by computing the correct relative prefix.
$__page   = basename($_SERVER['PHP_SELF'] ?? '');
$__inSuper = (basename(dirname($_SERVER['PHP_SELF'] ?? '')) === 'superUserFilesContainer');
$__up     = $__inSuper ? '../' : '';
$__sup    = $__inSuper ? '' : 'superUserFilesContainer/';
$__isSuper = (($_SESSION['admin_role'] ?? '') === 'super_admin');

$__items = [
    ['dashboard.php',          'fa-gauge',          'Dashboard',   'dashboard.php'],
    ['users.php',              'fa-users',          'Users',       'users.php'],
    ['add_user.php',           'fa-user-plus',      'Add User',    'add_user.php'],
    ['upload_users.php',       'fa-file-csv',       'CSV Upload',  'upload_users.php'],
    ['dns_rules.php',          'fa-shield-halved',  'DNS Rules',   'dns_rules.php'],
    ['wifi_profiles.php',      'fa-wifi',           'WiFi Profiles','wifi_profiles.php'],
    ['guest_tickets.php',      'fa-ticket',         'Guest Tickets','guest_tickets.php'],
    ['mac_blacklist.php',      'fa-ban',            'MAC Blacklist','mac_blacklist.php'],
    ['ipmap.php',              'fa-map-location-dot','IP Map',     'ipmap.php'],
    ['active_sessions.php',    'fa-signal',         'Active Sessions','active_sessions.php'],
    ['change_password.php',    'fa-key',            'Password',    'change_password.php'],
    ['logout.php',             'fa-right-from-bracket','Logout',   'logout.php'],
];
?>
    <nav class="nav">
<?php foreach ($__items as $__i): ?>
      <a href="<?= $__up . $__i[0] ?>"<?= ($__page === $__i[3]) ? ' class="active"' : '' ?>><i class="fas <?= $__i[1] ?>"></i> <?= $__i[2] ?></a>
<?php endforeach; ?>
<?php if ($__isSuper): ?>
      <div style="margin-top:14px;padding-top:10px;border-top:1px solid rgba(255,255,255,.08);font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;color:#8a8f98">Super Admin</div>
      <a href="<?= $__sup ?>super_dashboard.php"<?= ($__page === 'super_dashboard.php') ? ' class="active"' : '' ?>><i class="fas fa-database"></i> Super Dashboard</a>
      <a href="<?= $__sup ?>create_organization.php"<?= ($__page === 'create_organization.php') ? ' class="active"' : '' ?>><i class="fas fa-building"></i> Create Organization</a>
      <a href="<?= $__sup ?>assign_admin.php"<?= ($__page === 'assign_admin.php') ? ' class="active"' : '' ?>><i class="fas fa-user-shield"></i> Assign Admin</a>
      <a href="<?= $__sup ?>view_organizations.php"<?= ($__page === 'view_organizations.php') ? ' class="active"' : '' ?>><i class="fas fa-sitemap"></i> View Organizations</a>
      <a href="<?= $__sup ?>view_org_users.php"<?= ($__page === 'view_org_users.php') ? ' class="active"' : '' ?>><i class="fas fa-users"></i> View Org Users</a>
<?php endif; ?>
    </nav>