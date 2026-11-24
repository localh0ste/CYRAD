<?php
/**
 * CYRAD — NAT table ingest endpoint (called by the router's cyrad-natsync).
 *
 * The router (OpenWrt or any gateway) shares its DHCP/NAT board: every private
 * client IP it hands out, the client's MAC (and optional hostname / iface /
 * VLAN the router observed). The DNS monitor uses this table to attribute a
 * NAT'd DNS query (client public IP behind NAT = the router's LAN IP appears
 * as the query source) back to a specific user:
 *
 *   query source IP -> nat_mappings.client_ip -> mac -> radacct.callingstationid
 *     -> username -> radcheck.organization_id (+ VLAN via Called-Station-Id)
 *
 * Request (POST form-urlencoded):
 *   token=<CYRAD_API_TOKEN>
 *   org=<organization_id>
 *   entries=<urlencoded JSON array>  [{"ip":"192.168.1.50","mac":"aa:bb:cc:dd:ee:ff","hostname":"macbook","iface":"br-lan"}, ...]
 *
 * The router should re-post every 1-5 minutes. Rows older than 20 minutes are
 * pruned automatically so the mapping never contains stale entries.
 */
require_once __DIR__ . '/config/db.php';

$token    = $_POST['token'] ?? '';
$expected = defined('CYRAD_API_TOKEN') ? CYRAD_API_TOKEN : '';
if ($expected !== '' && !hash_equals((string)$expected, (string)$token)) {
    http_response_code(403);
    exit;
}

$org = isset($_POST['org']) ? (int)$_POST['org'] : (defined('CYRAD_DEFAULT_ORG') ? (int)CYRAD_DEFAULT_ORG : 0);
if ($org <= 0) { http_response_code(400); exit; }

$json_raw = $_POST['entries'] ?? '';
$entries = json_decode($json_raw, true);
if (!is_array($entries)) { http_response_code(400); exit; }

function ab_lower_mac($m) {
    $m = strtolower(trim($m));
    $m = preg_replace('/[^0-9a-f]/', '', $m);
    if (strlen($m) !== 12) return 'unknown';
    return implode(':', str_split($m, 2));
}

$n = 0;
$conn->begin_transaction();
try {
    $ins = $conn->prepare(
        "INSERT INTO nat_mappings (organization_id, client_ip, mac, hostname, iface, updated_at)
         VALUES (?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE mac=VALUES(mac), hostname=VALUES(hostname),
                                 iface=VALUES(iface), updated_at=NOW()"
    );
    foreach ($entries as $e) {
        $ip = trim((string)($e['ip'] ?? ''));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) continue;
        $mac  = ab_lower_mac((string)($e['mac'] ?? ''));
        $host = substr(trim((string)($e['hostname'] ?? '')), 0, 128);
        $iface= substr(trim((string)($e['iface'] ?? '')), 0, 32);
        $ins->bind_param("issss", $org, $ip, $mac, $host, $iface);
        $ins->execute();
        $n++;
    }
    $conn->query("DELETE FROM nat_mappings WHERE updated_at < NOW() - INTERVAL 20 MINUTE");
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "ok $n";