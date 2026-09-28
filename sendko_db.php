<?php
// sendko_db.php

function openSendkkoConnection()
{
    $host = 'database-1.cj8e4u0u2aoh.ap-south-1.rds.amazonaws.com';
    $dbname   = 'sender';
    $username = 'admin';
    $password = 'iRPIhAfKKsAIedz3UiRw';

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
