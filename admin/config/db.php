<?php
require_once __DIR__ . '/../../config.php';

$conn = new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
