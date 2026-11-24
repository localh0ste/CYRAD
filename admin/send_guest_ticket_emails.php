<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (in_array('--help', $argv, true)) {
    echo "Usage: php admin/send_guest_ticket_emails.php\nProcess up to 50 due guest emails; retries at 5 and 10 minutes, maximum 3 attempts.\n";
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/guest_mail.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// Environment wins over config.php so ops can test against a copy before cutover.
// An explicitly-set empty value (for example CYRAD_DB_PASS='') is honoured.
function cyrad_worker_db(string $key, string $default): string {
    $env = getenv($key);
    if ($env !== false) return $env;
    return defined($key) ? (string)constant($key) : $default;
}
try {
    $db = new mysqli(cyrad_worker_db('CYRAD_DB_HOST', 'localhost'), cyrad_worker_db('CYRAD_DB_USER', 'radius'),
        cyrad_worker_db('CYRAD_DB_PASS', ''), cyrad_worker_db('CYRAD_DB_NAME', 'radius'));
    $db->set_charset('utf8mb4'); $db->query("SET time_zone='+00:00'");
    echo date('c') . ' processed=' . guest_mail_drain($db) . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, "Guest mail worker failed; check database, migration and key permissions.\n"); exit(1); }
