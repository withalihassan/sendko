<?php
// sendko_db.php

function openSendkkoConnection() {
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '13.220.207.140') {
        $host = 'localhost';
    } else {
        $host = 'database-1.ct22ws4u0c7g.me-central-1.rds.amazonaws.com';
    }

    $dbname   = 'sender';
    $username = 'admin';
    $password = 'sLoGMCVfEo4TpMGOEm18';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("Sendkko DB connection failed: " . $e->getMessage());
    }
}

function closeSendkkoConnection(&$pdo) {
    $pdo = null;
}