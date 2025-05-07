<?php
require '../db_connect.php';
$accountId = $_GET['parent_id'];

// Prepare and execute the DELETE query based on parent_account_id
$query = "DELETE  FROM child_accounts WHERE parent_id = '$accountId'";

if ($conn->query($query) === TRUE) {
    echo "
       <script>
        setTimeout(function() { window.location.href = 'new_page.php'; }, 1000); 
        </script>
       ";
} else {
    $message = "Error deleting child accounts: " . $conn->error;
}
