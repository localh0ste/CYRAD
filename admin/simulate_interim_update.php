<?php
// Security: CLI/cron script — never run over HTTP.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit('Forbidden');
}
// Database connection (central config: config.php)
require_once __DIR__ . '/../config.php';
$conn = new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Amount to simulate per update (in bytes)
$simulated_input = 5 * 1024 * 1024;  // 5 MB
$simulated_output = 2 * 1024 * 1024; // 2 MB

// Update all active sessions (no stop time yet)
$sql = "UPDATE radacct
        SET acctinputoctets = acctinputoctets + $simulated_input,
            acctoutputoctets = acctoutputoctets + $simulated_output,
            acctupdatetime = NOW()
        WHERE acctstoptime IS NULL";

if ($conn->query($sql) === TRUE) {
    echo "Interim usage simulated: +5MB in / +2MB out.\n";
} else {
    echo "Error updating radacct: " . $conn->error;
}

$conn->close();
?>
