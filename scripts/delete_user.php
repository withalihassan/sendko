<?php
include('../db.php');

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    try {
        $stmt->execute([$id]);
        echo 'success';
    } catch (PDOException $e) {
        echo 'error';
    }
}
?>
