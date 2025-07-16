<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../db.php';
$accountId = $_GET['parent_id'];

// Prepare and execute the DELETE query based on parent_account_id
$query = "DELETE FROM child_accounts WHERE parent_id = '$accountId' AND is_in_org='No'";

if ($pdo->query($query) === TRUE) {
    echo "
       <script>
        setTimeout(function() { window.location.href = 'new_page.php'; }, 10000); 
        </script>
       ";
} else {
    $message = "Error deleting child accounts: ";
}
