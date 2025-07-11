<?php
session_start();

// Get username from session (must be set during login)
$username = $_SESSION['username'] ?? null;

// Optional: Set NAS details
$nas_ip = '139.59.62.11'; // 🔁 Replace with your NAS IP
$nas_secret = 'buggy'; // 🔁 Replace with your NAS shared secret
$coa_port = 3799; // Default CoA port

// Optional: Send Disconnect packet using radclient
if ($username) {
    $command = "echo 'User-Name = \"$username\"' | radclient -x $nas_ip:$coa_port disconnect $nas_secret";
    shell_exec($command); // ⚠ Ensure PHP has permission to run shell commands
}

// Destroy session
session_unset();
session_destroy();
?>

<!DOCTYPE html>
<html>
<head>
  <title>Logged Out</title>
  <meta http-equiv="refresh" content="3;url=login.php" />
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #f7f7f7;
      text-align: center;
      padding-top: 100px;
    }

    h1 {
      color: #28a745;
    }

    p {
      color: #333;
      font-size: 1rem;
    }
  </style>
</head>
<body>
  <h1>Logged out successfully!</h1>
  <p>You will be redirected to the login page shortly...</p>
</body>
</html>
