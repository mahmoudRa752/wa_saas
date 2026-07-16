<?php
session_start();
require_once('config/db.php');

$email = 'test1@porto.com';
$password = '12345678';

$stmt = $conn->prepare('SELECT * FROM companies WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $company = $result->fetch_assoc();
    echo 'company_found=1' . PHP_EOL;
    echo 'stored_hash=' . $company['password'] . PHP_EOL;
    echo 'verify=' . (password_verify($password, $company['password']) ? 'true' : 'false') . PHP_EOL;
    if (password_verify($password, $company['password'])) {
        $_SESSION['company_id'] = $company['id'];
        $_SESSION['company_name'] = $company['name'];
        $_SESSION['role'] = 'admin';
        echo 'session_company_id=' . ($_SESSION['company_id'] ?? 'none') . PHP_EOL;
        echo 'session_role=' . ($_SESSION['role'] ?? 'none') . PHP_EOL;
        header('Location: /dashboard/index.php');
        exit;
    }
}

echo 'login_failed' . PHP_EOL;
