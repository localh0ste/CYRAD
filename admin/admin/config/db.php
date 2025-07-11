<?php
$conn = new mysqli("localhost", "radius", "buggy", "radius"); // change if using cloud creds

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
