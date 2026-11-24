<?php
/**
 * CYRAD — blocked-MAC list for the router enforcer and FreeRADIUS-style checks.
 *
 * Optional router tooling can poll this endpoint; the active CYRAD disconnect
 * path uses the WireGuard tunnel + /usr/bin/cyrad-kick (ubus del_client).
 * disassociates any station whose MAC is listed, optionally dropping it in the
 * firewall. FreeRADIUS itself rejects these MACs at authorize time via the
 * patched SQL query (freeradius/auth-limit-check.sh).
 *
 * Auth: CYRAD_API_TOKEN as ?t=… or POST token (empty token => open LAN mode).
 * Formats:
 *   default / ?format=json : {"macs":[{"mac","reason","username","expires_at"}],"ts"}
 *   ?format=text           : one MAC per line (what the router shell parses)
 * Optional: ?org=<id> limits to one organization.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/router_policy.php';

$expected = defined('CYRAD_API_TOKEN') ? CYRAD_API_TOKEN : '';
$token = $_GET['t'] ?? ($_POST['token'] ?? '');
if ($expected !== '' && !hash_equals((string)$expected, (string)$token)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'forbidden']);
    exit;
}

try {
    $conn = cyrad_connect();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'db']);
    exit;
}

// Opportunistic cleanup so the list never serves stale entries.
cyrad_blacklist_prune($conn);

$org = isset($_GET['org']) ? (int)$_GET['org'] : 0;
$sql = "SELECT mac, reason, username, expires_at FROM mac_blacklist
        WHERE (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())" . ($org > 0 ? " AND organization_id = $org" : "") . "
        ORDER BY created_at DESC";
$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

if (($_GET['format'] ?? '') === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
    foreach ($rows as $r) echo $r['mac'] . "\n";
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    'macs' => $rows,
    'count' => count($rows),
    'ts' => time(),
]);
