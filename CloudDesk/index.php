<?php
// session.php, header.php, db.php and aws-autoloader.php should be in your include paths
include "../session.php";
// include "../header.php";
include('../db.php');
require '../aws/aws-autoloader.php';

// Initialize message variable for response messages (if needed)
$message = "";

if (isset($_POST['submit'])) {
    // Trim and fetch the AWS credentials from the form
    $aws_key    = trim($_POST['aws_key']);
    $aws_secret = trim($_POST['aws_secret']);

    // Check if fields are not empty
    if (empty($aws_key) || empty($aws_secret)) {
        $message = "AWS Key and Secret cannot be empty.";
    } else {
        try {
            // Create an STS client with the provided credentials
            $stsClient = new Aws\Sts\StsClient([
                'version'     => 'latest',
                'region'      => 'us-east-1', // change to your desired region
                'credentials' => [
                    'key'    => $aws_key,
                    'secret' => $aws_secret,
                ]
            ]);

            // Call getCallerIdentity to fetch the AWS Account ID
            $result     = $stsClient->getCallerIdentity();
            $account_id = $result->get('Account');

            // Use current timestamp for the added_date field
            $added_date = date("Y-m-d H:i:s");

            // Insert the account into the database with default status "active"
            $stmt = $pdo->prepare("INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, added_date) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$session_id, $aws_key, $aws_secret, $account_id, 'active', $added_date])) {
                $message = "Account added successfully. AWS Account ID: " . htmlspecialchars($account_id);
            } else {
                $message = "Failed to insert account into the database.";
            }
        } catch (Aws\Exception\AwsException $e) {
            // Catch errors thrown by AWS SDK
            $message = "AWS Error: " . $e->getAwsErrorMessage();
        } catch (Exception $e) {
            // Catch any other errors
            $message = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Home - Special Panel</title>
    <!-- Bootstrap CSS for styling -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <!-- DataTables CSS for pagination -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
    <style>
        /* Optional: Adjust spacing for inline quick action buttons */
        .d-inline-flex>*+* {
            margin-left: 0.5rem;
        }
    </style>
</head>

<body>
    <script>
        function runDeploy() {
            fetch('deploy.php', {
                    method: 'POST'
                })
                .then(response => response.text())
                .then(data => alert(data))
                .catch(error => alert("Error: " + error));
        }
    </script>
    <div class="container-fluid" style="padding: 1% 4% 4% 4%;">
        <!-- Table Section 1: Accounts List -->
        <div class="table-section mb-5">
            <h2>Nodes List</h2>
            <!-- Div for check status messages -->
            <div class="status-message mb-2"></div>
            <table id="accountsTable1" class="display table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Node ID</th>
                        <th></th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Table 1 query: Accounts List
                    $stmt = $pdo->query("SELECT * FROM accounts WHERE status='active' AND ( ac_worth='special' OR ac_worth='normal' ) AND  by_user='$session_id'  ORDER BY 1 DESC");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<tr>";
                        echo "<td>" . $row['id'] . "</td>";
                        echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
                        echo "<td></td>";
                        echo "<td></td>";
                        // if ($row['status'] == 'active') {
                        //     echo "<td><span class='badge badge-success'>Active</span></td>";
                        // } else {
                        //     echo "<td><span class='badge badge-danger'>Suspended</span></td>";
                        // }
                       // Quick Actions inline buttons
                        echo "<td>
                            <div class='d-inline-flex'>
                                <button class='btn btn-primary btn-sm check-status-btn' data-id='" . $row['id'] . "'>Chk Status</button>
                                <a href='create.php?parent_id=" . $row['account_id'] . "' target='_blank'><button class='btn btn-success btn-sm'>Create Desk</button></a>
                            </div>
                          </td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- jQuery, DataTables, Popper.js and Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#accountsTable1').DataTable();
        });

        // Check Status button handler for all tables
        $(document).on('click', '.check-status-btn', function(e) {
            e.preventDefault();
            var btn = $(this);
            var accountId = btn.data('id');
            var row = btn.closest('tr');
            var tableSection = btn.closest('.table-section');
            var statusDiv = tableSection.find('.status-message');
            statusDiv.html("<span class='text-info'>Checking status...</span>");
            $.ajax({
                url: '../provider/scripts/check_status.php',
                type: 'POST',
                data: {
                    id: accountId
                },
                success: function(response) {
                    statusDiv.html("<span class='text-success'>" + response + "</span>");
                    // Update the status cell (4th column) based on response content
                    if (response.toLowerCase().indexOf("active") !== -1) {
                        row.find('td:eq(3)').html("<span class='badge badge-success'>Active</span>");
                    } else if (response.toLowerCase().indexOf("suspended") !== -1) {
                        row.find('td:eq(3)').html("<span class='badge badge-danger'>Suspended</span>");
                    }
                },
                error: function() {
                    statusDiv.html("<span class='text-danger'>An error occurred while checking the account status.</span>");
                }
            });
        });
    </script>
</body>

</html>