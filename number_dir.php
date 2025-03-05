<?php
include "./session.php";      // Your session file
include "./header.php";       // Your header file
include "db.php";             // Database connection

// Handle form submission to add a new set
if (isset($_POST['submit'])) {
    $set_name    = trim($_POST['set_name']);
    $description = trim($_POST['description']);

    if (empty($set_name)) {
        $message = "Set Name is required.";
    } else {
        // Get current timestamp in Pakistan timezone
        $created_at = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("INSERT INTO bulk_sets (set_name, description, created_at) VALUES (?, ?, ?)");
        if ($stmt->execute([$set_name, $description, $created_at])) {
            $message = "New set added successfully!";
        } else {
            $message = "Error adding set.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Bulk Sets</title>
    <!-- Bootstrap CSS for styling -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <!-- DataTables CSS for pagination -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.js"></script>
</head>
<body>
<div class="container-fluid" style="padding: 4%;">
    <h2>Bulk Sets Management</h2>
    <?php 
    if (isset($message)) {
        echo '<div class="alert alert-info">' . $message . '</div>';
    }
    ?>
    
    <!-- Inline Form to Add New Set -->
    <form action="" method="post" class="form-inline mb-4">
        <div class="form-group mr-2">
            <label for="set_name" class="mr-2">Set Name:</label>
            <input type="text" name="set_name" id="set_name" class="form-control" placeholder="Set Name">
        </div>
        <div class="form-group mr-2">
            <label for="description" class="mr-2">Description:</label>
            <input type="text" name="description" id="description" class="form-control" placeholder="Description">
        </div>
        <button type="submit" name="submit" class="btn btn-primary">Add Set</button>
    </form>
    
    <!-- Table to Display Bulk Sets -->
    <table id="setsTable" class="display table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Set Name</th>
                <th>Description</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Fetch all sets from the database
            $stmt = $pdo->query("SELECT * FROM bulk_sets ORDER BY id DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo '<tr>';
                echo '<td>' . $row['id'] . '</td>';
                echo '<td>' . htmlspecialchars($row['set_name']) . '</td>';
                echo '<td>' . htmlspecialchars($row['description']) . '</td>';
                echo '<td>';
                // Open button directs to a new page (set_numbers.php) to display numbers attached to this set
                echo '<a href="my_numbers.php?id=' . $row['id'] . '" class="btn btn-info btn-sm">Open</a> ';
                // Delete button (optional AJAX delete functionality can be implemented later)
                echo '<button class="btn btn-danger btn-sm delete-btn" data-id="' . $row['id'] . '">Delete</button>';
                echo '</td>';
                echo '</tr>';
            }
            ?>
        </tbody>
    </table>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable for better record display
    $('#setsTable').DataTable();

    // Optional Delete functionality using AJAX
    $('.delete-btn').click(function() {
        if (confirm('Are you sure you want to delete this set?')) {
            var setId = $(this).data('id');
            $.ajax({
                url: './scripts/delete_set.php',
                type: 'POST',
                data: { id: setId },
                success: function(response) {
                    alert(response);
                    location.reload();
                },
                error: function() {
                    alert('Error deleting set.');
                }
            });
        }
    });
});
</script>
</body>
</html>
