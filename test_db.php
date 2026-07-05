<?php

$host = "localhost";
$user = "root";
$pass = "";
$db   = "wa_saas";
$port = 3307;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("❌ Failed: " . $conn->connect_error);
} else {
    echo "✅ Database Connected Successfully!";
}
?>