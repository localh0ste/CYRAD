<?php
// Database connection details
$servername = "139.59.62.11";
$username = "radius"; // Replace if different
$password = "buggy";
$dbname = "radius";

// Connect to MySQL
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("❌ DB Connection failed: " . $conn->connect_error);
}

// Fetch all users with data limits
$limit_query = $conn->query("SELECT username, internet_limit FROM data_limits");
if (!$limit_query) {
    die("❌ Query failed: " . $conn->error);
}

while ($row = $limit_query->fetch_assoc()) {
    $username = $conn->real_escape_string($row['username']);
    $limit_bytes = (int)$row['internet_limit'];

    // Get total usage from radacct (in bytes)
    $usage_result = $conn->query("
        SELECT SUM(acctinputoctets + acctoutputoctets) AS total_usage
        FROM radacct
        WHERE username = '$username'
    ");

    if (!$usage_result) {
        echo "⚠️ Usage query failed for $username: " . $conn->error . "\n";
        continue;
    }

    $usage_data = $usage_result->fetch_assoc();
    $total_usage = (int)($usage_data['total_usage'] ?? 0);

    // Check if limit is exceeded
    if ($total_usage >= $limit_bytes) {
        // Check if user is already disabled
        $check = $conn->query("SELECT id, value FROM radcheck WHERE username = '$username' AND attribute = 'Cleartext-Password' LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $row = $check->fetch_assoc();
            if (strpos($row['value'], 'disabled_') !== 0) {
                // Disable user by modifying password value
                $new_pass = "disabled_" . $row['value'];
                $id = (int)$row['id'];

                $stmt = $conn->prepare("UPDATE radcheck SET value = ? WHERE id = ?");
                $stmt->bind_param("si", $new_pass, $id);
                $stmt->execute();
                $stmt->close();

                echo "🚫 User '$username' disabled due to exceeding limit.\n";
            }
        }
    }
}

$conn->close();
?>

