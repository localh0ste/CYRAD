<?php
// Database connection settings
$servername = "139.59.62.11";
$username = "radius";          // Change if different
$password = "buggy";       // Change if different
$dbname = "radius";

// Connect to the database
$conn = new mysqli($servername, $username, $password, $dbname);

// Check DB connection
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
    echo "✅ Interim usage simulated: +5MB in / +2MB out.\n";
} else {
    echo "❌ Error updating radacct: " . $conn->error;
}

// Close connection
$conn->close();
?>
