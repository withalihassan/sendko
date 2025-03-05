<?php
require '../db.php';

if (!isset($_GET['ac_id'])) {
    die("Invalid account ID.");
}

$accountId = htmlspecialchars($_GET['ac_id']);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage AWS Nodes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>

<body>
    <div class="container mt-5">
        <h2>Manage Nodes for Parent ID: <?php echo $accountId; ?></h2>
        <?php if (isset($message)) : ?>
            <div class="alert alert-info">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <!-- Form to Add Child Account -->
        <div class="card mb-4">
            <div class="card-header">Add Mini Account</div>
            <div class="card-body">
                <form id="addChildAccountForm">
                    <div class="mb-3">
                        <label for="email" class="form-label">Mini Account Email</label>
                        <input type="email" class="form-control" id="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">Mini Account Name</label>
                        <input type="text" class="form-control" id="name" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Account</button>
                </form>
            </div>
        </div>

        <!-- Button to Fetch Existing Child Accounts -->
        <div class="card mt-4">
            <div class="card-body">
                <a target="_blank" href="./child/delete_all_child.php?parent_id=<?php echo $accountId; ?>"><button type="submit" name="delete_all" class="btn btn-danger">Delete All Mini Accounts</button></a>
                <button id="fetchExistingAccounts" class="btn btn-secondary">Fetch Existing Mini Accounts</button>
                <button id="refresh" class="btn btn-success">Refresh</button>
            </div>
        </div>

        <!-- Table to Display Existing Child Accounts -->
        <div class="card">
            <div class="card-header">Existing Child Accounts</div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>AWS Account ID</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="childAccountsTable">
                        <tr>
                            <td colspan="4" class="text-center">Loading accounts...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>

    <!-- Pass the PHP parent account ID to JS -->
    <script>
        var parentAccountId = "<?php echo $accountId; ?>";
        
        document.getElementById("refresh").addEventListener("click", function() {
            location.reload();
        });
    </script>

    <!-- Include the external JS file for AJAX -->
    <script src="child/scripts.js"></script>
    <script src="child/existac.js"></script>
</body>

</html>