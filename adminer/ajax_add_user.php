<?php
session_start();
include "../db.php"; // Adjust the path if necessary

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Retrieve and trim form values
    $name     = trim($_POST['name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $type     = trim($_POST['type']);
    
    if(empty($name) || empty($username) || empty($password) || empty($type)) {
        echo "All fields are required.";
        exit;
    }
    
    try {
        // Insert the new user into the users table.
        // Here, 'account_status' is used to store the type (provider/consumer).
        $stmt = $pdo->prepare("INSERT INTO users (name, username, password, type, created_at) VALUES (:name, :username, :password, :type, NOW())");
        $stmt->execute([
            ':name'     => $name,
            ':username' => $username,
            ':password' => $password,
            ':type'     => $type
        ]);
        echo "User added successfully.";
    } catch(PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>
