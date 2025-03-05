<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AWS Account Manager</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
</head>

<body>
    <div class="container mt-5">
        <h2>Parent AWS Account Manager</h2>

        <!-- Form to Add AWS Account -->
        <form id="awsForm">
            <div class="form-group">
                <label>AWS Key</label>
                <input type="text" class="form-control" id="aws_key" name="aws_key" required>
            </div>
            <div class="form-group">
                <label>AWS Secret</label>
                <input type="text" class="form-control" id="aws_secret" name="aws_secret" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <button type="submit" class="btn btn-primary">Add Account</button>
        </form>

        <div id="response" class="mt-3"></div>

        <!-- AWS Accounts Table -->
        <h3 class="mt-5">Stored AWS Accounts</h3>
        <table class="table table-bordered">
            <thead class="thead-dark">
                <tr>
                    <th>#</th>
                    <th>Email</th>
                    <th>AWS Account ID</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="accountsTable">
                <!-- Data will be loaded here using AJAX -->
            </tbody>
        </table>
    </div>

    <script>
        $(document).ready(function() {
            // Load accounts when page loads
            loadAccounts();

            // Submit form with AJAX
            $('#awsForm').submit(function(e) {
                e.preventDefault();
                $.ajax({
                    type: 'POST',
                    url: 'process.php',
                    data: $(this).serialize(),
                    beforeSend: function() {
                        $('#response').html('<div class="alert alert-info">Processing...</div>');
                    },
                    success: function(response) {
                        $('#response').html(response);
                        $('#awsForm')[0].reset();
                        loadAccounts(); // Refresh table after adding
                    }
                });
            });
            // Open new tab with account ID
            $(document).on('click', '.btn-open', function() {
                let accountId = $(this).data('id');
                window.open('account_details.php?id=' + accountId, '_blank');
            });

            // Check AWS account status
            $(document).on('click', '.btn-status', function() {
                let accountId = $(this).data('id');
                $.ajax({
                    type: 'POST',
                    url: 'check_status.php',
                    data: {
                        account_id: accountId
                    },
                    beforeSend: function() {
                        alert("Checking account status...");
                    },
                    success: function(response) {
                        alert(response);
                        loadAccounts(); // Reload table with updated status
                    }
                });
            });

            // Function to reload account list
            function loadAccounts() {
                $.ajax({
                    url: 'fetch_accounts.php',
                    method: 'GET',
                    success: function(data) {
                        $('#accountsTable').html(data);
                    }
                });
            }
        });
    </script>

</body>

</html>