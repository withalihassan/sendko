<?php
// db.php

// Determine the database host based on the URL used to access the site.
if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '13.220.207.140') {
    // When accessed via http://13.220.207.140, use 'localhost' for the DB connection.
    $host = 'localhost';
} else {
    // Otherwise, use the remote IP address.
    $host = 'database-1.ct22ws4u0c7g.me-central-1.rds.amazonaws.com';
}

$dbname   = 'sender';
$username = 'admin';
$password = 'sLoGMCVfEo4TpMGOEm18';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
