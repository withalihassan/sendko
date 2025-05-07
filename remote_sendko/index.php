<?php
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
    <title>Home - Claim and Start Process</title>
    <!-- Bootstrap CSS for styling -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <!-- DataTables CSS for pagination -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
    <style>
        /* Optional: Adjust spacing for inline quick action buttons */
        .d-inline-flex > * + * {
            margin-left: 0.5rem;
        }
    </style>
</head>
<body>

<?php
    // session_start();
    // include 'db.php'; // Your existing connection file

    // Get current user's ID from the session.
    $user_id = $_SESSION['user_id'];

    // Prepare and execute the query using PDO.
    $sql = "SELECT i.public_ip, i.elastic_ip 
        FROM instances i
        JOIN accounts a ON a.id = i.account_id
        WHERE a.by_user = :user_id
        ORDER BY i.launch_time DESC 
        LIMIT 12";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $user_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="container-fluid" style="padding: 1% 4% 0 4%;">
        <h2>
            Try Alternate IP Defense
            <span class="badge bg-success bg-opacity-75 ms-2 px-2 py-1 small rounded-pill text-white">Secure</span>
        </h2>
        <!-- Inline buttons with small spacing -->
        <div class="btn-group" role="group" aria-label="IP Buttons">
            <?php foreach ($results as $row):
                // Use elastic_ip if available, otherwise fall back to public_ip
                $ip = !empty($row['elastic_ip']) ? $row['elastic_ip'] : $row['public_ip'];
                if ($ip): ?>
                    <a href="http://<?php echo htmlspecialchars($ip); ?>" target="_blank" class="btn btn-primary btn-sm mx-1">
                        <?php echo htmlspecialchars($ip); ?>
                    </a>
            <?php endif;
            endforeach; ?>
        </div>
    </div>
    <div class="container-fluid" style="padding: 1% 4% 4% 4%;">
        <!-- Table Section 1: Accounts List -->
        <div class="table-section mb-5">
            <h2>Special Accounts List</h2>
            <!-- Div for check status messages -->
            <div class="status-message mb-2"></div>
            <table id="accountsTable1" class="display table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Account ID</th>
                        <!-- <th>AWS Key</th> -->
                        <th>Status</th>
                        <th>Account Score</th>
                        <th>Account Age</th>
                        <th>Next Atm</th>
                        <!-- <th>Type</th> -->
                        <th>Added Date</th>
                        <th>Actions</th>
                        <th>Quick Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Table 1 query: Accounts List
                    $stmt = $pdo->query("SELECT * FROM accounts WHERE status='active' AND ac_worth='special' AND  by_user='$session_id' ORDER BY 1 DESC");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<tr>";
                        echo "<td>" . $row['id'] . "</td>";
                        echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
                        // echo "<td>" . htmlspecialchars($row['aws_key']) . "</td>";
                        if ($row['status'] == 'active') {
                            echo "<td><span class='badge badge-success'>Active</span></td>";
                        } else {
                            echo "<td><span class='badge badge-danger'>Suspended</span></td>";
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
                        // echo "<td>" . htmlspecialchars($row['worth_type']) . "</td>";
                        echo "<td>" . (new DateTime($row['added_date']))->format('d M g:i a') . "</td>";
                        // Actions dropdown
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
                                    <a class='dropdown-item mark-full-btn' href='#' data-id='" . $row['id'] . "'>Mark-Full</a>
                                    <a class='dropdown-item mark-half-btn' href='#' data-id='" . $row['id'] . "'>Mark-Half</a>
                                </div>
                            </div>
                          </td>";
                        // Quick Actions inline buttons
                        echo "<td>
                            <div class='d-inline-flex'>
                                <button class='btn btn-primary btn-sm check-status-btn' data-id='" . $row['id'] . "'>Chk Status</button>
                                <a href='clear_region.php?ac_id=" . $row['id'] . "' target='_blank'><button class='btn btn-danger btn-sm' >Clear</button></a>
                                <a href='awsch/account_details.php?ac_id=" . $row['account_id'] . "&user_id=" . $session_id . "' target='_blank'><button class='btn btn-secondary btn-sm'>Manage Childs</button></a>
                                <a href='nodesender/sender.php?id=" . $row['id'] . "' target='_blank'><button class='btn btn-success btn-sm'>Get New IP</button></a>
                                <a href='manage_account.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank'><button class='btn btn-success btn-sm'>Manage Account</button></a>
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
    <div class="container-fluid" style="padding: 1% 4% 4% 4%;">
        <!-- Table Section 1: Accounts List -->
        <div class="table-section mb-5">
            <h2>Normal Accounts List</h2>
            <!-- Div for check status messages -->
            <div class="status-message mb-2"></div>
            <table id="accountsTable2" class="display table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Account ID</th>
                        <!-- <th>AWS Key</th> -->
                        <th>Status</th>
                        <th>Account Score</th>
                        <th>Account Age</th>
                        <th>Next Atm</th>
                        <!-- <th>Type</th> -->
                        <th>Added Date</th>
                        <th>Actions</th>
                        <th>Quick Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Table 1 query: Accounts List
                    $stmt = $pdo->query("SELECT * FROM accounts WHERE status='active' AND ac_worth='normal' AND  by_user='$session_id' ORDER BY 1 DESC");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<tr>";
                        echo "<td>" . $row['id'] . "</td>";
                        echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
                        // echo "<td>" . htmlspecialchars($row['aws_key']) . "</td>";
                        if ($row['status'] == 'active') {
                            echo "<td><span class='badge badge-success'>Active</span></td>";
                        } else {
                            echo "<td><span class='badge badge-danger'>Suspended</span></td>";
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
                        // echo "<td>" . htmlspecialchars($row['worth_type']) . "</td>";
                        echo "<td>" . (new DateTime($row['added_date']))->format('d M g:i a') . "</td>";
                        // Actions dropdown
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
                                    <a class='dropdown-item mark-full-btn' href='#' data-id='" . $row['id'] . "'>Mark-Full</a>
                                    <a class='dropdown-item mark-half-btn' href='#' data-id='" . $row['id'] . "'>Mark-Half</a>
                                </div>
                            </div>
                          </td>";
                        // Quick Actions inline buttons
                        echo "<td>
                            <div class='d-inline-flex'>
                                <button class='btn btn-primary btn-sm check-status-btn' data-id='" . $row['id'] . "'>Chk Status</button>
                                <a href='clear_region.php?ac_id=" . $row['id'] . "' target='_blank'><button class='btn btn-danger btn-sm' >Clear</button></a>
                                <a href='awsch/account_details.php?ac_id=" . $row['account_id'] . "&user_id=" . $session_id . "' target='_blank'><button class='btn btn-secondary btn-sm'>Manage Childs</button></a>
                                <a href='nodesender/sender.php?id=" . $row['id'] . "' target='_blank'><button class='btn btn-success btn-sm'>Get New IP</button></a>
                                <a href='manage_account.php?ac_id=" . $row['id'] . "&user_id=" . $session_id . "' target='_blank'><button class='btn btn-success btn-sm'>Manage Account</button></a>
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
$(document).ready(function() {
    $('#accountsTable1').DataTable();
    $('#accountsTable2').DataTable();
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
            if(response.success) {
                $('#syncResult').html("<span class='text-success'>"+response.new_records+" new record(s) fetched.</span>");
                // Optionally, you could update your tables dynamically here.
            } else {
                $('#syncResult').html("<span class='text-danger'>"+response.message+"</span>");
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
        data: { id: accountId },
        success: function(response) {
            statusDiv.html("<span class='text-success'>" + response + "</span>");
            // Update the status cell (4th column) based on response content
            if(response.toLowerCase().indexOf("active") !== -1) {
                row.find('td:eq(3)').html("<span class='badge badge-success'>Active</span>");
            } else if(response.toLowerCase().indexOf("suspended") !== -1) {
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
        data: { id: accountId, claim_type: 'full' },
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
        data: { id: accountId, claim_type: 'half' },
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
