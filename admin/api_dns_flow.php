<?php
/**
 * CYRAD — NAT flow ingest endpoint (called by the OpenWrt flowmon).
 * Receives (org, token, flows=[{ip,mac,dst_ip,dst_port,proto},...]) and:
 *   1. resolves each MAC -> RADIUS username (radacct.callingstationid)
 *   2. inserts a connection_logs row per flow
 *
 * SECURITY: if CYRAD_API_TOKEN is defined in config.php, the posted token
 * must match. Deploy under the admin/ web root.
 */
require_once __DIR__ . '/config/db.php';

$token    = $_POST['token'] ?? '';
$expected = defined('CYRAD_API_TOKEN') ? CYRAD_API_TOKEN : '';
if ($expected !== '' && !hash_equals((string)$expected, (string)$token)) {
    http_response_code(403);
    echo "forbidden";
    exit;
}

$org_in = isset($_POST['org']) ? (int)$_POST['org'] : 0;
$org    = $org_in > 0 ? $org_in : (defined('CYRAD_DEFAULT_ORG') ? (int)CYRAD_DEFAULT_ORG : 0);

$flows = json_decode($_POST['flows'] ?? '[]', true);
if (!is_array($flows)) {
    http_response_code(400);
    echo "bad";
    exit;
}

// Self-healing schema.
$conn->query(
    "CREATE TABLE IF NOT EXISTS connection_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL,
        username VARCHAR(64) NOT NULL DEFAULT 'unknown',
        client_ip VARCHAR(45),
        mac VARCHAR(32),
        dst_ip VARCHAR(45),
        dst_port INT,
        proto VARCHAR(8),
        ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (organization_id, ts)
    )"
);

$ins = $conn->prepare(
    "INSERT INTO connection_logs (organization_id, username, client_ip, mac, dst_ip, dst_port, proto)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

foreach ($flows as $f) {
    $mac = strtolower(preg_replace('/[^a-f0-9]/', ':', (string)($f['mac'] ?? '')));
    $username = 'unknown';
    $s = $conn->prepare(
        "SELECT username FROM radacct
         WHERE LOWER(REPLACE(REPLACE(callingstationid,'-',':'),' ','')) = ?
           AND acctstoptime IS NULL
         ORDER BY acctstarttime DESC LIMIT 1"
    );
    $s->bind_param('s', $mac);
    $s->execute();
    $s->bind_result($u);
    if ($s->fetch()) {
        $username = $u;
    }
    $s->close();

    $ip   = (string)($f['ip'] ?? '');
    $dip  = (string)($f['dst_ip'] ?? '');
    $port = (int)($f['dst_port'] ?? 0);
    $proto = (string)($f['proto'] ?? '');
    $ins->bind_param('isssssi', $org, $username, $ip, $mac, $dip, $port, $proto);
    $ins->execute();
}

echo "ok";
