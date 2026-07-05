<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "wa_saas";
$port = 3307;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!defined("WHATSAPP_TOKEN")) {
    define("WHATSAPP_TOKEN", getenv("WHATSAPP_TOKEN") ?: "EAAZBGtwMbSu0BR45Cvl2BYZCkupVr6DgwytZAt7sXfuBdyQy8bmlyLfKOuVmERuGXZCjtmt7wkgRKRcPUWLajhIrhi6ZCSU50SBzfjUsEw1aIZCSqwbhYIkdTEDd2WA0OZBDH4mYjoaaoQgxjTZCAy0y9akeZAZAwQgcUxkYxuaE2k3uCflKVz6wmZB33Twk5VoBzwaY8kkaBStt8iX5ZA1BCV9XZBb1G1ip7RXMZCp7dE6ZC2v8MvL3rxK6ZCoHtQi6njFGHRrV98rC4s4vwc9OP2m1nbDl");
}
