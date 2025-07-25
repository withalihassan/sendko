<?php
include "./session.php";      // Your session file
include "./header.php";       // Your header file
include "db.php";             // Database connection

// Handle status toggle if requested via GET parameters
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // Get the current status of the set
    $stmt = $pdo->prepare("SELECT status FROM bulk_sets WHERE id = ?");
    $stmt->execute([$id]);
    $current_status = $stmt->fetchColumn();
    if ($current_status !== false) {
        // Toggle the status: if 'fresh' then mark as 'used', otherwise mark as 'fresh'
        $new_status = ($current_status === 'fresh') ? 'used' : 'fresh';
        $stmt2 = $pdo->prepare("UPDATE bulk_sets SET status = ? WHERE id = ?");
        if ($stmt2->execute([$new_status, $id])) {
            $message = "Status updated successfully.";
        } else {
            $message = "Status update failed.";
        }
    }
}

// Handle form submission to add a new set
if (isset($_POST['submit'])) {
    $set_name    = trim($_POST['set_name']);
    $description = trim($_POST['description']);

    if (empty($set_name)) {
        $message = "Set Name is required.";
    } else {
        // Get current timestamp in Pakistan timezone
        $created_at = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d H:i:s');

        // Insert new set with default status "fresh"
        $stmt = $pdo->prepare("INSERT INTO bulk_sets (set_name, description, created_at, status) VALUES (?, ?, ?, 'fresh')");
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
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Fetch all sets from the database with the latest ones on top
            $stmt = $pdo->query("SELECT * FROM bulk_sets ORDER BY id ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo '<tr>';
                echo '<td>' . $row['id'] . '</td>';
                echo '<td>' . htmlspecialchars($row['set_name']) . '</td>';
                echo '<td>' . htmlspecialchars($row['description']) . '</td>';
                echo '<td>' . htmlspecialchars($row['status']) . '</td>';
                echo '<td>';
                // Open button directs to a new page (my_numbers.php) to display numbers attached to this set
                echo '<a href="my_numbers.php?id=' . $row['id'] . '" class="btn btn-info btn-sm mr-1">Open</a> ';
                // Toggle Status button changes label based on current status
                if ($row['status'] === 'fresh') {
                    echo '<a href="?toggle_status=1&id=' . $row['id'] . '" class="btn btn-warning btn-sm mr-1">Mark as used</a> ';
                } else {
                    echo '<a href="?toggle_status=1&id=' . $row['id'] . '" class="btn btn-success btn-sm mr-1">Mark as fresh</a> ';
                }
                // Delete button (AJAX delete functionality)
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
    var table = $('#setsTable').DataTable();

    // Delegate delete click so it works on all pages
    $(document).on('click', '.delete-btn', function() {
        if (!confirm('Are you sure you want to delete this set?')) {
            return;
        }
        var setId = $(this).data('id');
        $.ajax({
            url: './scripts/delete_set.php',
            type: 'POST',
            data: { id: setId },
            success: function(response) {
                alert(response);
                // Remove the row from DataTable and redraw
                table
                    .row( $('button.delete-btn[data-id="' + setId + '"]').parents('tr') )
                    .remove()
                    .draw();
            },
            error: function() {
                alert('Error deleting set.');
            }
        });
    });
});
</script>
</body>
</html>
