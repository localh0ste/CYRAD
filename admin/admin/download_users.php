<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename=users_export.csv');

$output = fopen("php://output", "w");
fputcsv($output, ['Username', 'Hashed Password Value', 'Internet Type', 'Internet Limit (MB)']);

$query = "
    SELECT 
        r.username, 
        r.value AS password, 
        d.internet_type, 
        d.internet_limit 
    FROM 
        radcheck r 
    LEFT JOIN 
        data_limits d ON r.username = d.username
";

$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $internet_limit_mb = ($row['internet_type'] ?? 'unlimited') === 'limited' && $row['internet_limit'] !== null
        ? round($row['internet_limit'] / 1024 / 1024)
        : '';

    fputcsv($output, [
        $row['username'],
        $row['password'],
        $row['internet_type'] ?? 'unlimited',
        $internet_limit_mb
    ]);
}

fclose($output);
$conn->close();
exit;
?>
