<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$error = "";
$success = "";

function is_valid_input($input) {
    return preg_match('/^[a-zA-Z0-9_\-@.]+$/', $input);
}

function sanitize_csv_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

if (isset($_POST["upload"])) {
    if (isset($_FILES["file"]) && $_FILES["file"]["size"] > 0) {
        $fileType = pathinfo($_FILES["file"]["name"], PATHINFO_EXTENSION);
        if (strtolower($fileType) !== 'csv') {
            $error = "Only CSV files are allowed.";
        } else {
            require_once __DIR__ . '/config/db.php';
            if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

            if (!isset($_SESSION['organization_id'])) {
                $error = "Organization ID not found in session.";
            } else {
                $organization_id = $_SESSION['organization_id'];

                $file = fopen($_FILES["file"]["tmp_name"], "r");
                fgetcsv($file); // Skip header

                $inserted = 0;
                $skipped = 0;
                $skipped_data = [];

                while (($data = fgetcsv($file, 10000, ",")) !== FALSE) {
                    $username = strtolower(sanitize_csv_input($data[0] ?? ""));
                    $raw_password = sanitize_csv_input($data[1] ?? "");
                    $internet_type = strtolower(sanitize_csv_input($data[2] ?? ""));
                    $internet_limit_mb = sanitize_csv_input($data[3] ?? "");

                    if (
                        empty($username) || empty($raw_password) ||
                        !in_array($internet_type, ['limited', 'unlimited']) ||
                        !is_valid_input($username) || !is_valid_input($raw_password)
                    ) {
                        $skipped++;
                        $skipped_data[] = [$username, $raw_password, $internet_type, $internet_limit_mb, 'Invalid or dangerous input'];
                        continue;
                    }

                    if (strlen($raw_password) < 8) {
                        $skipped++;
                        $skipped_data[] = [$username, $raw_password, $internet_type, $internet_limit_mb, 'Password must be at least 8 characters'];
                        continue;
                    }

                    $limit_bytes = null;
                    if ($internet_type === 'limited') {
                        if (is_numeric($internet_limit_mb)) {
                            $limit_bytes = (int)$internet_limit_mb * 1024 * 1024;
                        } else {
                            $skipped++;
                            $skipped_data[] = [$username, $raw_password, $internet_type, $internet_limit_mb, 'Missing or invalid MB value for limited type'];
                            continue;
                        }
                    }

                    $check = $conn->prepare("SELECT username FROM radcheck WHERE username = ?");
                    if (!$check) {
                        $error = "Prepare failed: " . $conn->error;
                        break;
                    }

                    $check->bind_param("s", $username);
                    $check->execute();
                    $check->store_result();

                    if ($check->num_rows == 0) {
                        $stmt1 = $conn->prepare("INSERT INTO radcheck (username, attribute, op, value, organization_id) VALUES (?, 'Cleartext-Password', ':=', ?, ?)");
                        if (!$stmt1) {
                            $error = "Prepare failed (radcheck): " . $conn->error;
                            break;
                        }

                        $stmt1->bind_param("ssi", $username, $raw_password, $organization_id);

                        $stmt2 = null;
                        if ($internet_type === 'limited') {
                            $stmt2 = $conn->prepare("INSERT INTO data_limits (username, internet_type, internet_limit, organization_id) VALUES (?, ?, ?, ?)");
                            if (!$stmt2) {
                                $error = "Prepare failed (data_limits): " . $conn->error;
                                break;
                            }
                            $stmt2->bind_param("ssii", $username, $internet_type, $limit_bytes, $organization_id);
                        }

                        if ($stmt1->execute() && ($stmt2 === null || $stmt2->execute())) {
                            $inserted++;
                        } else {
                            $skipped++;
                            $skipped_data[] = [$username, $raw_password, $internet_type, $internet_limit_mb, 'Database insert error'];
                        }

                        $stmt1->close();
                        if ($stmt2) $stmt2->close();
                    } else {
                        $skipped++;
                        $skipped_data[] = [$username, $raw_password, $internet_type, $internet_limit_mb, 'Duplicate username'];
                    }
                    $check->close();
                }

                fclose($file);
                $conn->close();

                if ($skipped > 0) {
                    $skipped_dir = __DIR__ . "/skipped_csv";
                    if (!file_exists($skipped_dir)) {
                        mkdir($skipped_dir, 0775, true);
                    }

                    $skippedFile = fopen($skipped_dir . "/skipped_users.csv", "w");
                    fputcsv($skippedFile, ['Username', 'Password', 'Internet Type', 'Internet Limit', 'Reason']);
                    foreach ($skipped_data as $row) {
                        fputcsv($skippedFile, $row);
                    }
                    fclose($skippedFile);
                }

                $success = "$inserted user(s) added successfully.";
                if ($skipped > 0) {
                    $success .= " Skipped $skipped record(s). <a href='skipped_csv/skipped_users.csv' download>Download Skipped Users</a>";
                }
            }
        }
    } else {
        $error = "Please upload a valid CSV file.";
    }
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>Upload Users via CSV</title>
    <link rel="icon" href="images/users_icon.PNG">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: url("images/CYNDIA.jpg");
            background-size: cover;
            color: #f1f1f1;
            padding: 40px;
        }
        .box {
            background: #1e1e1e;
            padding: 30px;
            border-radius: 12px;
            max-width: 700px;
            margin: auto;
        }
        .guideline {
            background: #2c2c2c;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #444;
        }
        .guideline pre, .guideline code {
            background-color: #1a1a1a;
            padding: 6px 10px;
            display: inline-block;
            border-radius: 6px;
            color: rgba(250, 9, 78, 0.86);
        }
        input[type="file"] {
            padding: 10px;
            border: none;
            background: #333;
            color: #fff;
            margin-bottom: 15px;
        }
        button {
            padding: 10px 20px;
            background: rgba(250, 9, 78, 0.86);
            color: white;
            border: none;
            font-size: 15px;
            border-radius: 6px;
            cursor: pointer;
        }
        button:hover {
            background: rgba(164, 4, 50, 0.86);
        }
        .back {
            margin-top: 20px;
            display: inline-block;
            color: #ccc;
            text-decoration: none;
        }
        .back:hover {
            color: white;
        }
        .message {
            margin-top: 10px;
            color: rgba(250, 9, 78, 0.86);
        }
        .error {
            margin-top: 10px;
            color: #ff4c4c;
        }
    </style>
</head>
<body>
<div class="box">
    <h2>To add users - Upload Users via CSV</h2>

    <div class="guideline">
        <h3>📄 CSV Format Guidelines</h3>
        <p>Make sure your CSV file has this format:</p>
        <pre><code>USERNAME,PASSWORD,INTERNET_TYPE,INTERNET_LIMIT</code></pre>
        <ul>
            <li><strong>USERNAME</strong>: Unique username</li>
            <li><strong>PASSWORD</strong>: Plain text password (stored directly)</li>
            <li><strong>INTERNET_TYPE</strong>: Either <code>unlimited</code> or <code>limited</code></li>
            <li><strong>INTERNET_LIMIT</strong>: MB value if limited, leave blank if unlimited</li>
        </ul>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="file" accept=".csv" required><br>
        <button type="submit" name="upload">Upload</button>
    </form>

    <?php if (!empty($success)): ?>
        <p class="message"><?= $success ?></p>
    <?php elseif (!empty($error)): ?>
        <p class="error"><?= $error ?></p>
    <?php endif; ?>

    <a class="back" href="users.php">&larr; Back</a>
</div>
</body>
</html>
