<?php
ob_start();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="users_export.csv"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || !isset($_SESSION['organization_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$org_id = $_SESSION['organization_id'];

ob_clean();
flush();

$output = fopen("php://output", "w");
fputcsv($output, ['Username', 'Password', 'Internet Type', 'Internet Limit (MB)']);

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
    WHERE 
        r.organization_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $internet_type = $row['internet_type'] ?? 'unlimited';
    $internet_limit_mb = ($internet_type === 'limited' && $row['internet_limit'] !== null)
        ? round($row['internet_limit'] / 1024 / 1024)
        : '';

    fputcsv($output, [
        $row['username'],
        $row['password'],
        $internet_type,
        $internet_limit_mb
    ]);
}

fclose($output);
$conn->close();
exit;
?>
