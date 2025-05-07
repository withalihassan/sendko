<?php
$host = "localhost";
$db_name = "aws_management";
$username = "root";  // Change this as per your DB credentials
$password = "";      // Set your DB password

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
