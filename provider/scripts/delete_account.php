<?php
// delete_account.php

include('../../db.php'); // Ensure this sets up a $pdo instance

if (!isset($_POST['id']) || empty($_POST['id'])) {
    echo "Invalid request. No account ID provided.";
    exit;
}

$id = intval($_POST['id']);

try {
    $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        echo "Account deleted successfully.";
    } else {
        echo "No account found with the provided ID.";
    }
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}
?>
