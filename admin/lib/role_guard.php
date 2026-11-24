<?php
/**
 * Regular admin-page guard.
 *
 * Super-admins are normally restricted to the super console
 * (superUserFilesContainer/). A few operational side-menu pages are shared
 * with them so every sidebar link actually works: Users, Add User, CSV Upload,
 * DNS Rules, WiFi Profiles, Guest Tickets, MAC Blacklist, IP Map, Active
 * Sessions and Password. Without this allowlist a super-admin clicking any of
 * those links is bounced back to the super dashboard (and Guest Tickets used
 * to fail there instead of opening). Must be required AFTER session_start()
 * and the admin_logged_in check.
 */
if (($_SESSION['admin_role'] ?? '') === 'super_admin') {
    $__cyrad_page = basename($_SERVER['PHP_SELF'] ?? '');
    $__cyrad_shared = [
        'dashboard.php', 'users.php', 'add_user.php', 'upload_users.php',
        'dns_rules.php', 'wifi_profiles.php', 'guest_tickets.php',
        'mac_blacklist.php', 'ipmap.php', 'active_sessions.php',
        'change_password.php',
    ];
    if (!in_array($__cyrad_page, $__cyrad_shared, true)) {
        header('Location: superUserFilesContainer/super_dashboard.php');
        exit;
    }
}
