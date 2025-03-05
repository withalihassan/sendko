<?php
include "../session.php"; // Ensure the session is started and user is authenticated (if needed)
include "../db.php";        // Database connection

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method.');
}

// Check if the set ID is provided
if (!isset($_POST['id'])) {
    die('Set ID is required.');
}

$set_id = $_POST['id'];

// Prepare and execute the DELETE statement
$stmt = $pdo->prepare("DELETE FROM bulk_sets WHERE id = ?");
if ($stmt->execute([$set_id])) {
    echo "Set deleted successfully.";
} else {
    echo "Failed to delete set.";
}
?>
