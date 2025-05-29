<?php
// Set session timeout to 2 hours (7200 seconds)
ini_set('session.gc_maxlifetime', 7200);
ini_set('session.cookie_lifetime', 7200);

// Make sure the session cookie respects the lifetime setting
session_set_cookie_params([
    'lifetime' => 7200,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']), // true if using HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// If user is not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header("Location: ./login.php");
    exit;
}

// Restrict access to consumers only
if ($_SESSION['type'] !== 'consumer') {
    header("Location: ./provider/");
    exit;
}

include('db.php');

// Fetch user info
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
