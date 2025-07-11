<?php
// db.php or replace with your DB creds
require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get all users with data limits
$limit_query = $conn->query("SELECT username, internet_limit FROM data_limits");

while ($row = $limit_query->fetch_assoc()) {
    $username = $row['username'];
    $limit_bytes = (int)$row['internet_limit']; // already stored in bytes

    // Calculate total usage (input + output octets)
    $usage_query = $conn->query("
        SELECT SUM(acctinputoctets + acctoutputoctets) AS total_usage 
        FROM radacct 
        WHERE username = '$username'
    ");
    $usage_result = $usage_query->fetch_assoc();
    $total_usage = (int)($usage_result['total_usage'] ?? 0);

    // If usage exceeds limit
    if ($total_usage >= $limit_bytes) {
        echo "Disabling $username (used $total_usage / $limit_bytes bytes)\n";

        // Disable user by modifying their password to invalid string
        $disable_stmt = $conn->prepare("UPDATE radcheck SET value = CONCAT('disabled_', ?) WHERE username = ?");
        $disable_stmt->bind_param("ss", $username, $username);
        $disable_stmt->execute();
        $disable_stmt->close();
    } else {
        echo "$username is under limit: $total_usage / $limit_bytes bytes\n";
    }
}

$conn->close();
