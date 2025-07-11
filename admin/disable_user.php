<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || !isset($_SESSION['organization_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username'])) {
    $username = $_POST['username'];
    $org_id = $_SESSION['organization_id'];

    // Step 1: Fetch the current password only if the user belongs to the same organization
    $stmt = $conn->prepare("
        SELECT r.value 
        FROM radcheck r 
        JOIN data_limits d ON r.username = d.username 
        WHERE r.username = ? 
          AND r.attribute = 'Cleartext-Password' 
          AND d.organization_id = ?
    ");
    $stmt->bind_param("si", $username, $org_id);
    $stmt->execute();
    $stmt->bind_result($currentPassword);
    $stmt->fetch();
    $stmt->close();

    if ($currentPassword) {
        if (str_starts_with($currentPassword, 'disabled_')) {
            $_SESSION['status_msg'] = "User is already disabled.";
        } else {
            $newPassword = "disabled_" . $currentPassword;
            $updateStmt = $conn->prepare("
                UPDATE radcheck 
                SET value = ? 
                WHERE username = ? 
                  AND attribute = 'Cleartext-Password'
            ");
            $updateStmt->bind_param("ss", $newPassword, $username);

            if ($updateStmt->execute()) {
                $_SESSION['status_msg'] = "User disabled successfully.";
            } else {
                $_SESSION['status_msg'] = "Error disabling user.";
            }
            $updateStmt->close();
        }
    } else {
        $_SESSION['status_msg'] = "User not found or doesn't belong to your organization.";
    }

    $conn->close();

    // Redirect to view page
    $redirectView = $_GET['view'] ?? 'search';
    if ($redirectView === 'all') {
        header("Location: view_users.php?view=all");
    } else {
        header("Location: view_users.php?view=search&search_username=" . urlencode($username));
    }
    exit;
}
?>
