<?php
// db.php

// Determine the database host based on the URL used to access the site.
if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '47.251.28.20') {
    // When accessed via http://47.251.28.20, use 'localhost' for the DB connection.
   echo $host = 'localhost';
} else {
    // Otherwise, use the remote IP address.
   echo $host = '47.251.28.20';
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
