<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

include "./session.php";
// index.php
include "./header.php";
// Include your local database connection file
include('db.php');

// Include the AWS PHP SDK autoloader (ensure the path is correct)
require './aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

// Initialize message variable for response messages
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
            $stsClient = new StsClient([
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
        } catch (AwsException $e) {
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
    <title>Home- Claim and Start Process</title>
    <!-- Bootstrap CSS for styling -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <!-- DataTables CSS for pagination -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <!-- DataTables JS -->
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.js"></script>
    <!-- <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js"></script> -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</head>

<body>
    <div class="container-fluid" style="padding: 4%;">
        <h2>Accounts Lists</h2>
        <!-- Sync Button to fetch new records from remote DB -->
        <button id="syncBtn" class="btn btn-primary mb-3">Sync Remote Records</button>
        <div id="syncResult" class="mb-3"></div>
        <table id="myaccountsTable" class="display">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Account ID</th>
                    <th>AWS Key</th>
                    <th>Status</th>
                    <th>State</th>
                    <th>Account Score</th>
                    <th>Account Age</th>
                    <th>Next Atm</th>
                    <th>Type</th>
                    <th>Added Date</th>
                    <th>Actions</th>
                    <th>Last Used</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch already claimed accounts by the current user
                $stmt = $pdo->query("SELECT * FROM accounts WHERE ac_state = 'claimed' AND status='active' ORDER by 1 DESC");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    echo "<tr>";
                    echo "<td>" . $row['id'] . "</td>";
                    echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['aws_key']) . "</td>";
                    
                    if ($row['status'] == 'active') {
                        echo "<td><span class='badge badge-success'>Active</span></td>";
                    } else {
                        echo "<td><span class='badge badge-danger'>Suspended</span></td>";
                    }
                    
                    if ($row['ac_state'] == 'orphan') {
                        echo "<td><span class='badge badge-warning'>Orphan</span></td>";
                    } else if ($row['ac_state'] == 'claimed') {
                        echo "<td><span class='badge badge-success'>Claimed</span></td>";
                    } else {
                        echo "<td><span class='badge badge-danger'>Rejected</span></td>";
                    }
                    
                    echo "<td>" . htmlspecialchars($row['ac_score']) . "</td>";
                    
                    if ($row['status'] == 'active') {
                        $td_Added_date = new DateTime($row['added_date']);
                        $td_current_date = new DateTime();
                        $diff = $td_Added_date->diff($td_current_date);
                        echo "<td>" . $diff->format('%a days') . "</td>";
                    } else {
                        $td_Added_date = new DateTime($row['added_date']);
                        $td_current_date = new DateTime($row['suspended_date']);
                        $diff = $td_Added_date->diff($td_current_date);
                        echo "<td>" . $diff->format('%a days') . "</td>";
                    }
                    
                    if (empty($row['last_used'])) {
                        echo "<td><span class='badge badge-warning'>No date provided</span></td>";
                    } else {
                        $initial = DateTime::createFromFormat('Y-m-d H:i:s', $row['last_used'], new DateTimeZone('Asia/Karachi'));
                        if (!$initial) {
                            echo "<td><span class='badge badge-danger'>Invalid date format</span></td>";
                        } else {
                            $expiry = clone $initial;
                            $expiry->modify('+1 day');
                            $now = new DateTime();
                            $diff = $now->diff($expiry);
                            if ($diff->invert) {
                                echo "<td><span class='badge badge-success'>Ready to go</span></td>";
                            } else {
                                $hours = ($diff->days * 24) + $diff->h;
                                echo "<td><span class='badge badge-secondary'>{$hours}H {$diff->i}m left</span></td>";
                            }
                        }
                    }
                    
                    echo "<td>" . htmlspecialchars($row['worth_type']) . "</td>";
                    echo "<td>" . (new DateTime($row['added_date']))->format('d M g:i a') . "</td>";
                    
                    echo "<td>
                            <div class='dropdown'>
                                <button class='btn btn-info btn-sm dropdown-toggle' type='button' id='actionDropdown{$row['id']}' data-toggle='dropdown' aria-haspopup='true' aria-expanded='false'>
                                Actions
                                </button>
                                <div class='dropdown-menu' aria-labelledby='actionDropdown{$row['id']}'>
                                    <a class='dropdown-item' href='awsch/account_details.php?ac_id=" . $row['account_id'] . "&user_id=" . $session_id . "' target='_blank'>Manage Account</a>
                                    <a class='dropdown-item check-status-btn' href='#' data-id='" . $row['id'] . "'>Check Status</a>
                                    <a class='dropdown-item' href='bulk_send.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank'>Bulk Send</a>
                                    <a class='dropdown-item' href='bulk_regional_send.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank'>Bulk Regional Send</a>
                                    <a class='dropdown-item' href='brs.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank'>BRS</a>
                                    <a class='dropdown-item' href='aws_account.php?id=" . $row['id'] . "' target='_blank'>EnableReg</a>
                                    <a class='dropdown-item' href='nodesender/sender.php?id=" . $row['id'] . "' target='_blank'>NodeSender</a>
                                    <a class='dropdown-item' href='clear_region.php?ac_id=" . $row['id'] . "' target='_blank'>Clear</a>
                                    <!-- New options to update claim type -->
                                    <a class='dropdown-item mark-full-btn' href='#' data-id='" . $row['id'] . "'>Mark-Full</a>
                                    <a class='dropdown-item mark-half-btn' href='#' data-id='" . $row['id'] . "'>Mark-Half</a>
                                </div>
                            </div>
                          </td>";
                    echo "<td>" . (new DateTime($row['last_used']))->format('d M g:i a') . "</td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <script>
        $(document).ready(function() {
            $('#myaccountsTable').DataTable();
        });

        // Sync Button Event Handler
        $(document).on('click', '#syncBtn', function(e) {
            e.preventDefault();
            $('#syncResult').html("<span class='text-info'>Syncing records...</span>");
            $.ajax({
                url: 'sync_remote.php',
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    if(response.success) {
                        $('#syncResult').html("<span class='text-success'>"+response.new_records+" new record(s) fetched.</span>");
                        // Optionally, reload the table or page here
                    } else {
                        $('#syncResult').html("<span class='text-danger'>"+response.message+"</span>");
                    }
                },
                error: function() {
                    $('#syncResult').html("<span class='text-danger'>An error occurred while syncing records.</span>");
                }
            });
        });

        $(document).on('click', '.check-status-btn', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            $.ajax({
                url: './provider/scripts/check_status.php',
                type: 'POST',
                data: { id: id },
                success: function(response) {
                    alert(response);
                },
                error: function() {
                    alert("An error occurred while checking the account status.");
                }
            });
        });

        $(document).on('click', '.claim-btn', function() {
            var accountId = $(this).data("id");
            var claimType = prompt("Enter claim type (full or half)", "full");
            if (claimType === null) {
                return;
            }
            claimType = claimType.trim().toLowerCase();
            if (claimType !== "full" && claimType !== "half") {
                alert("Invalid claim type. Please enter 'full' or 'half'.");
                return;
            }
            $.ajax({
                url: "claim_account.php",
                type: "POST",
                data: { id: accountId, claim_type: claimType },
                success: function(response) {
                    alert(response);
                    location.reload();
                },
                error: function() {
                    alert("An error occurred while claiming the account.");
                }
            });
        });

        $(document).on('click', '.mark-full-btn', function(e) {
            e.preventDefault();
            var accountId = $(this).data("id");
            $.ajax({
                url: "claim_account.php",
                type: "POST",
                data: { id: accountId, claim_type: 'full' },
                success: function(response) {
                    alert(response);
                    location.reload();
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
                data: { id: accountId, claim_type: 'half' },
                success: function(response) {
                    alert(response);
                    location.reload();
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
                    data: { id: id },
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
                    data: { id: accountId },
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
