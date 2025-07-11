<?php
require_once __DIR__ . '/config/db.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$limit_query = $conn->query("SELECT username, internet_limit FROM data_limits");

if (!$limit_query) {
    die("Query failed: " . $conn->error);
}

while ($row = $limit_query->fetch_assoc()) {
    $username = $conn->real_escape_string($row['username']);
    $limit_bytes = (int)$row['internet_limit'];

    // Calculate total data usage
    $usage_query = $conn->query("
        SELECT SUM(acctinputoctets + acctoutputoctets) AS total_usage 
        FROM radacct 
        WHERE username = '$username'
    ");

    if (!$usage_query) {
        echo "Failed to fetch usage for $username: " . $conn->error . "\n";
        continue;
    }

    $usage_result = $usage_query->fetch_assoc();
    $total_usage = (int)($usage_result['total_usage'] ?? 0);

    if ($total_usage >= $limit_bytes) {
        echo "⛔ Disabling $username (Used: $total_usage / $limit_bytes bytes)\n";

        // Disable only if not already disabled
        $check_stmt = $conn->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password' LIMIT 1");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_stmt->bind_result($current_value);
        $check_stmt->fetch();
        $check_stmt->close();

        if (strpos($current_value, 'disabled_') !== 0) {
            $disable_stmt = $conn->prepare("UPDATE radcheck SET value = CONCAT('disabled_', ?) WHERE username = ? AND attribute = 'Cleartext-Password'");
            $disable_stmt->bind_param("ss", $username, $username);
            $disable_stmt->execute();
            $disable_stmt->close();
        } else {
            echo "   Already disabled.\n";
        }
    } else {
        echo "✅ $username is under limit: $total_usage / $limit_bytes bytes\n";
    }
}

$conn->close();
?>
