<?php
$host     = 'database-1.cjiuwqmaw256.ap-south-1.rds.amazonaws.com';
$port     = 3306;
$db       = 'mysql';
$user     = 'admin';
$password = '3CFz8no5NSxCXiDOMz8g';

// Simple connection (suitable for quick testing)
$mysqli = mysqli_connect($host, $user, $password, $db, $port);

if (!$mysqli) {
    die('Connection error: ' . mysqli_connect_error() . PHP_EOL);
}

$result = mysqli_query($mysqli, 'SELECT VERSION() AS ver');

if ($result) {
    $row = mysqli_fetch_assoc($result);
    echo "MySQL version: " . $row['ver'] . PHP_EOL;
    mysqli_free_result($result);
} else {
    echo 'Query error: ' . mysqli_error($mysqli) . PHP_EOL;
}

mysqli_close($mysqli);