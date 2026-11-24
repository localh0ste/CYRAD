<?php
/**
 * Returns the list of currently-disabled WiFi usernames.
 *
 * Optional router tooling may poll this; the active CYRAD disconnect path is
 * different: the backend sends the kick over the WireGuard tunnel and calls
 * /usr/bin/cyrad-kick (ubus del_client) directly on the router. No inbound
 * port-forward is needed — it works even when the router is behind CGNAT.
 *
 * Protected by CYRAD_API_TOKEN (empty token => open, trusted LAN only).
 */
require_once __DIR__ . '/../config.php';

if (defined('CYRAD_API_TOKEN') && CYRAD_API_TOKEN !== '') {
    if (($_GET['t'] ?? '') !== CYRAD_API_TOKEN) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

$conn = new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'db']);
    exit;
}

$disabled = [];
// Disabling works by rewriting the radcheck password to "disabled_<pw>" — the
// actual username row is unchanged, so recover usernames from the radcheck row
// itself (SELECT username), never from the value.
//
// Active Directory users are deliberately NOT listed just because AD is on: they
// have no radcheck row of their own (the domain controller holds their
// credential), so matching on auth_source='ad' would report every domain user in
// the building and the router observer would kick all of them off the WiFi. An AD
// user only shows up here once an operator disables them explicitly, which
// writes the same disabled_<pw> marker.
$res = $conn->query("SELECT username FROM radcheck WHERE attribute='Cleartext-Password' AND value LIKE 'disabled_%'");
while ($r = $res->fetch_assoc()) {
    $disabled[] = $r['username'];
}
$disabled = array_values(array_unique($disabled));

header('Content-Type: application/json');
echo json_encode(['disabled' => $disabled, 'ts' => time()]);
