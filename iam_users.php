<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// session.php, header.php, db.php and aws-autoloader.php should be in your include paths
include "./session.php";
include "./header.php";
include('db.php');
require './aws/aws-autoloader.php';

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
    <title>Home - Accounts Manage</title>
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
    <div class="container-fluid" style="padding: 1% 4% 4% 4%;">
        <!-- Table Section 1: Accounts List -->
        <div class="table-section mb-5">
            <h2>IAM Accounts List</h2>
            <!-- Div for check status messages -->
            <div class="status-message-iam mb-2"></div>
            <table id="accountsTable3" class="display table table-bordered">
                <thead>
                    <tr>
                        <th>UID</th>
                        <th>Child ID</th>
                        <th>Key</th>
                        <th>Secret Key</th>
                        <th>Added Date</th>
                        <th>Status</th>
                        <th>Clean</th>
                        <th>Quick Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt_iam = $pdo->query("SELECT * FROM iam_users WHERE by_user='$session_id' ORDER BY created_at DESC");
                    while ($row_iam_users = $stmt_iam->fetch(PDO::FETCH_ASSOC)) {
                        // Update by_user for this iam_users record
                        // $iam_user_id = $row_iam_users['id'];
                        // $pdo->query("UPDATE iam_users SET by_user = '$session_id' WHERE id = '$iam_user_id'");
                        $parent_id = $pdo->query("SELECT parent_id FROM child_accounts WHERE account_id = '{$row_iam_users['child_account_id']}' LIMIT 1")->fetchColumn();
                        echo "<tr>";
                        echo "<td>" . $row_iam_users['by_user'] . "</td>";
                        echo "<td>" . htmlspecialchars($row_iam_users['child_account_id']) . "</td>";
                        echo "<td>" . htmlspecialchars($row_iam_users['access_key_id']) . "</td>";
                        echo "<td>" . htmlspecialchars($row_iam_users['secret_access_key']) . "</td>";
                        echo "<td>" . (new DateTime($row_iam_users['created_at']))->format('d M g:i a') . "</td>";

                        if ($row_iam_users['status'] == 'Delivered') {
                            echo "<td><span class='badge badge-success'>Delivered</span></td>";
                        } else if ($row_iam_users['status'] == 'Canceled') {
                            echo "<td><span class='badge badge-danger'>Canceled</span></td>";
                        } else if ($row_iam_users['status'] == 'Pending') {
                            echo "<td><span class='badge badge-warning'>Pending</span></td>";
                        } else {
                            echo "<td><span class='badge badge-primary'>" . $row_iam_users['status'] . "</span></td>";
                        }
                        echo "<td>" . $row_iam_users['cleanup_status'] . "</td>";


                        // Quick Actions inline buttons
                        echo "<td>
                                        <div class='d-inline-flex align-items-center'>
                                        <!-- inline status buttons -->
                                            <div class='btn-group'>
                                            <button type='button' class='btn btn-info btn-sm dropdown-toggle' data-toggle='dropdown'>
                                                Status
                                            </button>
                                            <div class='dropdown-menu'>
                                                <a href='#' class='dropdown-item update-status-btn-iam' data-id='{$row_iam_users['id']}' data-status='Delivered'>Delivered</a>
                                                <a href='#' class='dropdown-item update-status-btn-iam' data-id='{$row_iam_users['id']}' data-status='Pending'>Pending</a>
                                                <a href='#' class='dropdown-item update-status-btn-iam' data-id='{$row_iam_users['id']}' data-status='Canceled'>Canceled</a>
                                                <a href='#' class='dropdown-item update-status-btn-iam' data-id='{$row_iam_users['id']}' data-status='Recheck'>Recheck</a>
                                            </div>
                                            </div>
                                            <a href='./iam_clear.php?ac_id={$row_iam_users['id']}' target='_blank'>
                                            <button class='btn btn-danger btn-sm mr-1'>Clear</button>
                                            </a>
                                            <a href='./awsch/child_actions.php?ac_id=" . urlencode($row_iam_users['child_account_id']) .
                            "&parent_id=" . urlencode($parent_id) .
                            "' target='_blank'>
                                            <button class='btn btn-success btn-sm mr-1'>Open</button>
                                            </a>

                                            
                                        </div>
                                        </td>";

                        echo "</tr>";
                    }

                    // <a href='bulk_regional_send.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank'><button class='btn btn-danger btn-sm'>Start sending</button></a>
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
        $(function() {
            $('body').on('click', '.update-status-btn-iam', function(e) {
                e.preventDefault();
                var $btn = $(this),
                    id = $btn.data('id'),
                    status = $btn.data('status');

                $.ajax({
                        url: 'iam_users_status_ajax.php', // point to your handler
                        method: 'POST',
                        // temporarily comment out dataType so you can see raw response:
                        // dataType: 'json',
                        data: {
                            update_status: 1,
                            id: id,
                            status: status
                        }
                    })
                    .done(function(res, textStatus, xhr) {
                        console.log('RAW response:', xhr.responseText);
                        try {
                            res = JSON.parse(xhr.responseText);
                        } catch (e) {
                            $('.status-message-iam').addClass('alert alert-danger').text('Invalid JSON returned');
                            return;
                        }
                        var $msg = $('.status-message-iam').removeClass('alert-success alert-danger');
                        if (res.success) {
                            $msg.addClass('alert alert-success').text(res.msg);
                            $('.current-status-' + id).text(status);
                        } else {
                            $msg.addClass('alert alert-danger').text(res.msg);
                        }
                    })
                    .fail(function(xhr) {
                        console.error('AJAX error:', xhr.status, xhr.responseText);
                        $('.status-message-iam')
                            .addClass('alert alert-danger')
                            .text('AJAX request failed (see console for details)');
                    });
            });
        });
    </script>


    <script>
        $(document).ready(function() {
            $('#accountsTable1').DataTable();
            $('#accountsTable2').DataTable();
            $('#accountsTable3').DataTable();
            $('#manualSuspendedTable').DataTable();
        });

        // Global Sync Remote Records handler
        $(document).on('click', '#syncBtn', function(e) {
            e.preventDefault();
            $('#syncResult').html("<span class='text-info'>Syncing records...</span>");
            $.ajax({
                url: 'sync_remote.php',
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#syncResult').html("<span class='text-success'>" + response.new_records + " new record(s) fetched.</span>");
                        // Optionally, you could update your tables dynamically here.
                    } else {
                        $('#syncResult').html("<span class='text-danger'>" + response.message + "</span>");
                    }
                },
                error: function() {
                    $('#syncResult').html("<span class='text-danger'>An error occurred while syncing records.</span>");
                }
            });
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
                url: './provider/scripts/check_status.php',
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

        // Additional event handlers (mark-full, mark-half, delete, reject) can remain similar to your original code:
        $(document).on('click', '.mark-full-btn', function(e) {
            e.preventDefault();
            var accountId = $(this).data("id");
            $.ajax({
                url: "claim_account.php",
                type: "POST",
                data: {
                    id: accountId,
                    claim_type: 'full'
                },
                success: function(response) {
                    // You can display the response in the table section status div if needed.
                    alert(response);
                },
                error: function() {
                    alert("An error occurred while marking the account as full.");
                }
            });
        });

        $(document).on('click', '.mark-half-btn', function(e) {
            e.preventDefault();
            var accountId = $(this).data("id");
            $.ajax({
                url: "claim_account.php",
                type: "POST",
                data: {
                    id: accountId,
                    claim_type: 'half'
                },
                success: function(response) {
                    alert(response);
                },
                error: function() {
                    alert("An error occurred while marking the account as half.");
                }
            });
        });

        $(document).on('click', '.delete-btn', function() {
            if (confirm("Are you sure you want to delete this account?")) {
                var id = $(this).data('id');
                $.ajax({
                    url: './scripts/delete_account.php',
                    type: 'POST',
                    data: {
                        id: id
                    },
                    success: function(response) {
                        alert(response);
                        location.reload();
                    },
                    error: function() {
                        alert("An error occurred while deleting the account.");
                    }
                });
            }
        });

        $(document).on('click', '.reject-btn', function(e) {
            e.preventDefault();
            if (confirm("Are you sure you want to reject this account?")) {
                var accountId = $(this).data('id');
                $.ajax({
                    url: 'reject_account.php',
                    type: 'POST',
                    data: {
                        id: accountId
                    },
                    success: function(response) {
                        alert(response);
                        location.reload();
                    },
                    error: function() {
                        alert("An error occurred while rejecting the account.");
                    }
                });
            }
        });
    </script>
</body>

</html>