<?php
/**
 * CYRAD — DNS blocklist export endpoint (called by the OpenWrt blocklist sync).
 * Returns one blocked domain_pattern per line for the given org.
 * The OpenWrt sync script turns each line into a dnsmasq "address=/domain/" rule.
 *
 * SECURITY: if CYRAD_API_TOKEN is defined in config.php, the token must match.
 */
require_once __DIR__ . '/config/db.php';

$token    = $_GET['token'] ?? '';
$expected = defined('CYRAD_API_TOKEN') ? CYRAD_API_TOKEN : '';
if ($expected !== '' && !hash_equals((string)$expected, (string)$token)) {
    http_response_code(403);
    exit;
}

$org = isset($_GET['org']) ? (int)$_GET['org'] : (defined('CYRAD_DEFAULT_ORG') ? (int)CYRAD_DEFAULT_ORG : 0);

header('Content-Type: text/plain; charset=utf-8');

// org <= 0 means "all enabled block rules". This keeps a single-router /
// single-tenant deployment working even when CYRAD_DEFAULT_ORG is 0 (the
// panel still scopes rules per admin organization on the DNS Rules page).
if ($org > 0) {
    $stmt = $conn->prepare(
        "SELECT domain_pattern FROM dns_rules
         WHERE organization_id = ? AND action = 'block' AND enabled = 1
         ORDER BY domain_pattern"
    );
    $stmt->bind_param('i', $org);
} else {
    $stmt = $conn->prepare(
        "SELECT domain_pattern FROM dns_rules
         WHERE action = 'block' AND enabled = 1
         ORDER BY domain_pattern"
    );
}
$stmt->execute();
$stmt->bind_result($domain);
while ($stmt->fetch()) {
    echo $domain . "\n";
}
$stmt->close();
