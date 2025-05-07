<?php
include('../db.php');

if (isset($_POST['id']) && isset($_POST['status'])) {
    $id = intval($_POST['id']);
    $status = $_POST['status'];
    if (!in_array($status, ['active', 'hold', 'blocked'])) {
        echo 'Invalid status';
        exit;
    }
    $stmt = $pdo->prepare("UPDATE users SET account_status = ? WHERE id = ?");
    try {
        $stmt->execute([$status, $id]);
        echo 'success';
    } catch (PDOException $e) {
        echo 'error: ' . $e->getMessage();
    }
}
?>
