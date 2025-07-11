<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || !isset($_SESSION['organization_id'])) {
    header("Location: login.php");
    exit;
}

$org_id = $_SESSION['organization_id'];

require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$users = [];
$query = "
    SELECT 
        d.username,
        d.internet_limit,
        d.internet_type,
        COALESCE(SUM(r.acctinputoctets + r.acctoutputoctets), 0) AS total_used,
        (d.internet_limit - COALESCE(SUM(r.acctinputoctets + r.acctoutputoctets), 0)) AS remaining_bytes
    FROM 
        data_limits d
    LEFT JOIN 
        radacct r ON d.username = r.username
    WHERE 
        d.organization_id = ?
    GROUP BY 
        d.username, d.internet_limit, d.internet_type
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['remaining_mb'] = round($row['remaining_bytes'] / (1024 * 1024), 2);
        $row['internet_limit_mb'] = round($row['internet_limit'] / (1024 * 1024), 2);
        $row['used_mb'] = round($row['total_used'] / (1024 * 1024), 2);
        $users[] = $row;
    }
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Limited Users Data Left</title>
    <link rel="icon" href="images/internet_usage.PNG">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: url("images/dashboard.jpg");
            background-size: cover;
            color: white;
            padding: 40px;
        }
        table {
            width: 80%;
            margin: auto;
            border-collapse: collapse;
        }
        th, td { padding: 12px; text-align: center; border-bottom: 1px solid #555; }
        
        th { background-color: rgba(250, 9, 78, 0.86); }
        tr:hover { background-color: rgba(255,255,255,0.05); }
        h2 {
            text-align: center;
            color: rgba(250, 9, 78, 0.86);
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <h2>📊 Data Left for Limited Users</h2>
    <table>
        <tr>
            <th>Username</th>
            <th>Internet Type</th>
            <th>Limit (MB)</th>
            <th>Used (MB)</th>
            <th>Remaining (MB)</th>
        </tr>
        <?php if (!empty($users)): ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['internet_type']) ?></td>
                    <td><?= $u['internet_limit_mb'] ?></td>
                    <td><?= $u['used_mb'] ?></td>
                    <td><?= $u['remaining_mb'] ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5">No limited users found for your organization.</td>
            </tr>
        <?php endif; ?>
    </table>
</body>
</html>
