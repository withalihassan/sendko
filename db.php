<?php
// db.php

// Check if the server's IP address is 47.251.28.20.
// If so, use 'localhost' as the host; otherwise, use '47.251.28.20'.
if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] == '47.251.28.20') {
   echo  $host = 'localhost';
} else {
   echo  $host = '47.251.28.20';
}

$dbname   = 'sender';
$username = 'sender';
$password = 'Tech@#009';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>

