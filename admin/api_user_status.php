<?php
/**
 * Live user status endpoint.
 * Authenticated via the admin session cookie (PHPSESSID) — the browser's
 * fetch() includes it automatically (same-origin), which is what scopes the
 * result to the logged-in admin's organization. For 802.1X / WPA2-Enterprise
 * WiFi there is no web cookie per WiFi user; the authoritative "online" state
 * is the RADIUS accounting session in `radacct` (an open session = acctstoptime
 * IS NULL). This returns that real state.
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) { http_response_code(500); exit; }

$org_id  = (int)($_SESSION['organization_id'] ?? 0);
$isSuper = ($_SESSION['admin_role'] ?? 'admin') === 'super_admin';
$orgCond = $isSuper ? "1=1" : "u.oid = $org_id";

$out = [];
$res = $conn->query(
    "SELECT u.username, u.src, COALESCE(d.internet_type,'limited') AS type
     FROM (
         SELECT username, 'local' AS src, organization_id AS oid FROM radcheck
         UNION
         SELECT username, auth_source, organization_id FROM user_profiles
           WHERE auth_source <> 'local'
     ) u LEFT JOIN data_limits d ON d.username = u.username AND d.organization_id = u.oid
     WHERE $orgCond ORDER BY u.username"
);
while ($u = $res->fetch_assoc()) {
    $un = $conn->real_escape_string($u['username']);
    $s = $conn->query(
        "SELECT acctstarttime, acctstoptime,
                COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0) AS octets
         FROM radacct WHERE username='$un' ORDER BY acctstarttime DESC LIMIT 1"
    );
    $row = $s ? $s->fetch_assoc() : null;
    $stop   = $row['acctstoptime'] ?? null;
    $online = $row && ($stop === null || $stop === '' || $stop === '0000-00-00 00:00:00');
    $out[] = [
        'username' => $u['username'],
        'type'     => $u['type'],
        'online'   => (bool)$online,
        'started'  => $row['acctstarttime'] ?? null,
        'octets'   => (int)($row['octets'] ?? 0),
    ];
}
header('Content-Type: application/json');
echo json_encode(['users' => $out, 'ts' => time()]);
