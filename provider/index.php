<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ./login.php");
    exit;
}
if ($_SESSION['type'] !== 'provider') {
    header("Location: ../");
}

include('../db.php');

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

require '../aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Iam\IamClient;
use Aws\Exception\AwsException;

$message = "";
$childMessage = "";
$session_id = $_SESSION['user_id'];

// Process first form: add an AWS account using provided keys
if (isset($_POST['submit'])) {
    $aws_key    = trim($_POST['aws_key']);
    $aws_secret = trim($_POST['aws_secret']);
    $ac_worth   = trim($_POST['ac_worth']);
    $assign_to  = trim($_POST['assign_to']);

    if (empty($aws_key) || empty($aws_secret) || empty($assign_to)) {
        $message = "AWS Key, AWS Secret, and Consumer assignment cannot be empty.";
    } else {
        try {
            // Create an STS client using provided credentials
            $stsClient = new StsClient([
                'version'     => 'latest',
                'region'      => 'us-east-1', // adjust as needed
                'credentials' => [
                    'key'    => $aws_key,
                    'secret' => $aws_secret,
                ]
            ]);
            $result     = $stsClient->getCallerIdentity();
            $account_id = $result->get('Account');

            // Check if this account already exists
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_id = ?");
            $checkStmt->execute([$account_id]);
            $count = $checkStmt->fetchColumn();

            if ($count > 0) {
                $message = "Error: Duplicate Account or this account already exists.";
            } else {
                // Set current time in Pakistan timezone
                $added_date = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d H:i:s');
                $stmt = $pdo->prepare("INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, ac_state, ac_score, ac_age, cr_offset, added_date, ac_worth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$assign_to, $aws_key, $aws_secret, $account_id, 'active', 'orphan', '0', '0', '0', $added_date, $ac_worth])) {
                    $message = "Account added successfully. AWS Account ID: " . htmlspecialchars($account_id);
                } else {
                    $message = "Failed to insert account into the database.";
                }
            }
        } catch (AwsException $e) {
            $message = "AWS Error: " . $e->getAwsErrorMessage();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Process second form: use root keys to fetch AWS account id, then create child IAM user and its keys
if (isset($_POST['submit_child'])) {
    $root_key         = trim($_POST['root_key']);
    $root_secret      = trim($_POST['root_secret']);
    $ac_worth_child   = trim($_POST['ac_worth_child']);
    $assign_to_child  = trim($_POST['assign_to_child']);

    if (empty($root_key) || empty($root_secret) || empty($assign_to_child)) {
        $childMessage = "AWS Key, AWS Secret, and Consumer assignment cannot be empty.";
    } else {
        try {
            // Use the root credentials to get the AWS Account ID
            $rootStsClient = new StsClient([
                'version'     => 'latest',
                'region'      => 'us-east-1',
                'credentials' => [
                    'key'    => $root_key,
                    'secret' => $root_secret,
                ]
            ]);
            $rootIdentity = $rootStsClient->getCallerIdentity();
            $account_id   = $rootIdentity->get('Account');

            // Wait 1 second to ensure proper processing
            sleep(1);

            // Now, using the same root credentials, create a child IAM user
            $iamClient = new IamClient([
                'version'     => 'latest',
                'region'      => 'us-east-1',
                'credentials' => [
                    'key'    => $root_key,
                    'secret' => $root_secret,
                ]
            ]);
            
            // Generate a unique IAM user name for the child account
            $childUsername = "child-" . time();
            
            // Create the IAM user (child)
            $iamClient->createUser([
                'UserName' => $childUsername,
            ]);
            
            // Attach the AdministratorAccess policy to the new user
            $iamClient->attachUserPolicy([
                'UserName'  => $childUsername,
                'PolicyArn' => 'arn:aws:iam::aws:policy/AdministratorAccess',
            ]);
            
            // Create access keys for the new IAM user
            $accessKeyResult = $iamClient->createAccessKey([
                'UserName' => $childUsername,
            ]);
            $childAccessKey       = $accessKeyResult->get('AccessKey');
            $childAccessKeyId     = $childAccessKey['AccessKeyId'];
            $childSecretAccessKey = $childAccessKey['SecretAccessKey'];
            
            // Set added date using Pakistan timezone
            $added_date_child = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d H:i:s');
            
            // Insert the child account keys and the AWS Account ID into the database.
            $stmt = $pdo->prepare("INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, ac_state, ac_score, ac_age, cr_offset, added_date, ac_worth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$assign_to_child, $childAccessKeyId, $childSecretAccessKey, $account_id, 'active', 'child', '0', '0', '0', $added_date_child, $ac_worth_child])) {
                $childMessage = "Child account created successfully. AWS Account ID: " . htmlspecialchars($account_id);
            } else {
                $childMessage = "Failed to insert child account into the database.";
            }
        } catch (AwsException $e) {
            $childMessage = "AWS Error: " . $e->getAwsErrorMessage();
        } catch (Exception $e) {
            $childMessage = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Home- Add AWS Accounts</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <!-- DataTables JS -->
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.js"></script>
    <style>
        .navbar-custom {
            background-color: #343a40;
        }
        .navbar-custom .navbar-brand,
        .navbar-custom .nav-link {
            color: #ffffff;
        }
        .navbar-custom .nav-link:hover {
            color: #dcdcdc;
        }
        .navbar-toggler {
            border-color: rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>
    <?php include "./header.php"; ?>
    <div class="container-fluid" style="padding: 4%;">
        <h1>Welcome <?php echo ucfirst($user['name']); ?>!</h1>
        <div class="row">
            <!-- Left Column: Original Form -->
            <div class="col-md-6">
                <h2 class="mt-4">Add AWS Account</h2>
                <?php if (!empty($message)) {
                    echo '<div class="alert alert-info">' . $message . '</div>';
                } ?>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="aws_key">AWS Key:</label>
                        <input type="text" name="aws_key" id="aws_key" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="aws_secret">AWS Secret Key:</label>
                        <input type="text" name="aws_secret" id="aws_secret" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="ac_worth">Select an Account Worth</label>
                        <select class="form-control" id="ac_worth" name="ac_worth">
                            <option value="special">Special</option>
                            <option value="normal">Normal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="assign_to">Assign Account To (Consumer):</label>
                        <select class="form-control" id="assign_to" name="assign_to" required>
                            <option value="">Select a Consumer</option>
                            <?php
                            $consumersStmt = $pdo->query("SELECT id, name FROM users WHERE type = 'consumer'");
                            while ($consumer = $consumersStmt->fetch(PDO::FETCH_ASSOC)) {
                                echo "<option value='" . htmlspecialchars($consumer['id']) . "'>" . htmlspecialchars($consumer['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <button type="submit" name="submit" class="btn btn-primary">Add Account</button>
                </form>
            </div>
            <!-- Right Column: New Form for Child IAM Account -->
            <div class="col-md-6">
                <h2 class="mt-4">Create Child IAM Account</h2>
                <?php if (!empty($childMessage)) {
                    echo '<div class="alert alert-info">' . $childMessage . '</div>';
                } ?>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="root_key">Root AWS Key:</label>
                        <input type="text" name="root_key" id="root_key" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="root_secret">Root AWS Secret Key:</label>
                        <input type="text" name="root_secret" id="root_secret" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="ac_worth_child">Select an Account Worth</label>
                        <select class="form-control" id="ac_worth_child" name="ac_worth_child">
                            <option value="special">Special</option>
                            <option value="normal">Normal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="assign_to_child">Assign Account To (Consumer):</label>
                        <select class="form-control" id="assign_to_child" name="assign_to_child" required>
                            <option value="">Select a Consumer</option>
                            <?php
                            $consumersStmtChild = $pdo->query("SELECT id, name FROM users WHERE type = 'consumer'");
                            while ($consumer = $consumersStmtChild->fetch(PDO::FETCH_ASSOC)) {
                                echo "<option value='" . htmlspecialchars($consumer['id']) . "'>" . htmlspecialchars($consumer['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <button type="submit" name="submit_child" class="btn btn-primary">Create Child Account</button>
                </form>
            </div>
        </div>
        <hr>
        <h2>Today Accounts List</h2>
        <table id="accountsTable" class="display">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Account ID</th>
                    <th>AWS Key</th>
                    <th>Status</th>
                    <th>State</th>
                    <th>Account Score</th>
                    <th>Account Age</th>
                    <th>Added Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM accounts WHERE by_user = '$session_id' AND DATE(added_date) = CURDATE()");
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
                    } else if ($row['ac_state'] == 'child') {
                        echo "<td><span class='badge badge-info'>Child</span></td>";
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
                    echo "<td>" . (new DateTime($row['added_date']))->format('d M') . "</td>";
                    echo "<td>
                            <button class='btn btn-danger btn-sm delete-btn' data-id='" . $row['id'] . "'>Delete</button>
                            <button class='btn btn-info btn-sm check-status-btn' data-id='" . $row['id'] . "'>Check Status</button>
                          </td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    <script>
        $(document).ready(function() {
            $('#accountsTable').DataTable();
            $('.delete-btn').click(function() {
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
            $('.check-status-btn').click(function() {
                var id = $(this).data('id');
                $.ajax({
                    url: './scripts/check_status.php',
                    type: 'POST',
                    data: { id: id },
                    success: function(response) {
                        alert(response);
                        location.reload();
                    },
                    error: function() {
                        alert("An error occurred while checking the account status.");
                    }
                });
            });
        });
    </script>
</body>
</html>
