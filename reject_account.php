<?php
include('db.php');
include "./session.php";

if (isset($_POST['id'])) {
    $id = $_POST['id'];
    
    // Update the account's state to 'rejected'
    $stmt = $pdo->prepare("UPDATE accounts SET ac_state = 'rejected' WHERE id = ?");
    if ($stmt->execute([$id])) {
        echo "Account has been rejected.";
    } else {
        echo "Failed to update account status.";
    }
} else {
    echo "Invalid request.";
}
?>
