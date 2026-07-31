<?php
// sendko_db.php

function openSendkkoConnection()
{
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '54.151.244.24') {
        $host = 'localhost';
    } else {
        $host = '54.151.244.24';
    }

    $dbname   = 'sender';
    $username = 'admin';
    $password = '3CFz8no5NSxCXiDOMz8g';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Sendkko DB connection failed: " . $e->getMessage());
    }
}

function closeSendkkoConnection(&$pdo)
{
    $pdo = null;
}
