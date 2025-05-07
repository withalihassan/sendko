<?php
session_start();
// If user is not logged in, redirect to login page.
if (!isset($_SESSION['user_id'])) {
    header("Location: ./login.php");
    exit;
}
if($_SESSION['type'] !== 'consumer'){
    header("Location: ./provider/");
}

include('db.php');
$stmt = $pdo->prepare("SELECT username, account_status FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$session_id = $_SESSION['user_id'];
$username = $user['username'];
$account_status = $user['account_status'];
