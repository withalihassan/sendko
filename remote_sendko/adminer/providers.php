<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
include('../db.php');
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $name = trim($_POST['username']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $user_type = trim($_POST['type']);

    // Insert the new user (password stored as plain text per requirements)
    $stmt = $pdo->prepare("INSERT INTO users (name, username, password, type) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([$name, $username, $password, $user_type]);
        $message = "User added successfully!";
    } catch (PDOException $e) {
        $message = "Error adding user: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css">
    <style>
        .container {
            margin-top: 50px;
        }

        .btn-group .status-select {
            margin-right: 5px;
        }
    </style>
</head>

<body>
<?php include "./header.php"; ?>
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>
        <!-- Add New User Form -->
        <div class="card mb-4">
            <div class="card-header">Add New User</div>
            <div class="card-body">
                <form method="POST" action="providers.php">
                    <div class="form-group">
                        <label for="username">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Enter Provider Name" required>
                    </div>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password (Plain Text)</label>
                        <input type="text" name="password" class="form-control" placeholder="Enter password" required>
                    </div>
                    <div class="form-group">
                        <label for="account_status">Account Type</label>
                        <select name="type" class="form-control" required>
                            <option value="provider">Account Provider</option>
                            <option value="consumer"> Account consumer</option>
                        </select>
                    </div>
                    <button type="submit" name="add_user" class="btn btn-success">Add User</button>
                </form>
            </div>
        </div>

        <!-- Users List Table -->
        <div class="card">
            <div class="card-header">Users List</div>
            <div class="card-body">
                <table id="usersTable" class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Password</th>
                            <th>Current Status</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT * FROM users ORDER BY id DESC");
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            echo "<tr id='row-{$row['id']}'>
                        <td>{$row['id']}</td>
                        <td>" . htmlspecialchars($row['username']) . "</td>
                        <td>" . htmlspecialchars($row['password']) . "</td>
                        <td>{$row['account_status']}</td>
                        <td>{$row['created_at']}</td>
                        <td>
                          <button class='btn btn-danger btn-sm delete-user' data-id='{$row['id']}'>Delete</button>
                          <div class='btn-group ml-2' role='group'>
                              <select class='form-control status-select' data-id='{$row['id']}' style='width: auto; display: inline-block;'>
                                  <option value='active'" . ($row['account_status'] == 'active' ? ' selected' : '') . ">Active</option>
                                  <option value='hold'" . ($row['account_status'] == 'hold' ? ' selected' : '') . ">Hold</option>
                                  <option value='blocked'" . ($row['account_status'] == 'blocked' ? ' selected' : '') . ">Blocked</option>
                              </select>
                              <button class='btn btn-info btn-sm update-status' data-id='{$row['id']}'>Update</button>
                          </div>
                        </td>
                      </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- jQuery, Bootstrap JS, and DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#usersTable').DataTable();

            // Delete user with AJAX
            $('.delete-user').on('click', function() {
                if (confirm("Are you sure you want to delete this user?")) {
                    var userId = $(this).data('id');
                    var row = $('#row-' + userId);
                    $.ajax({
                        url: 'scripts/delete_user.php',
                        type: 'POST',
                        data: {
                            id: userId
                        },
                        success: function(response) {
                            if (response === 'success') {
                                row.fadeOut(500, function() {
                                    row.remove();
                                });
                            } else {
                                alert('Error deleting user.');
                            }
                        }
                    });
                }
            });

            // Update user status with AJAX
            $('.update-status').on('click', function() {
                var userId = $(this).data('id');
                var newStatus = $(this).siblings('.status-select').val();
                $.ajax({
                    url: 'scripts/update_status.php',
                    type: 'POST',
                    data: {
                        id: userId,
                        status: newStatus
                    },
                    success: function(response) {
                        if (response === 'success') {
                            alert('Status updated successfully.');
                            // Optionally update the "Current Status" cell in the table:
                            $('#row-' + userId + ' td:nth-child(4)').text(newStatus);
                        } else {
                            alert('Error updating status: ' + response);
                        }
                    }
                });
            });
        });
    </script>
</body>

</html>