<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../db.php';  // Include your database connection file (providing $pdo)
require '../aws/aws-autoloader.php';  // Include AWS SDK autoloader

use Aws\Sts\StsClient;
use Aws\Iam\IamClient;
use Aws\Exception\AwsException;

// --- API Endpoint: Create keys for one child account ---
if (isset($_GET['action']) && $_GET['action'] === 'create' && isset($_GET['child_id']) && isset($_GET['parent_id'])) {
    header('Content-Type: application/json');
    $parent_id = $_GET['parent_id'];
    $child_id = $_GET['child_id'];

    try {
        // Check if keys already exist for this child account
        $stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
        $stmt->execute([$child_id]);
        $childAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($childAccount && !empty($childAccount['aws_access_key']) && !empty($childAccount['aws_secret_key'])) {
            echo json_encode([
                "status" => "exists",
                "message" => "Keys already exist for account {$child_id}"
            ]);
            exit;
        }

        // Step 1: Fetch parent's AWS credentials from the database
        $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE account_id = ?");
        $stmt->execute([$parent_id]);
        $parentAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parentAccount) {
            echo json_encode([
                "status" => "error",
                "message" => "Parent account credentials not found."
            ]);
            exit;
        }

        // Step 2: Assume role in the child account
        $stsClient = new StsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key'    => $parentAccount['aws_key'],
                'secret' => $parentAccount['aws_secret'],
            ]
        ]);
        $roleArn = "arn:aws:iam::{$child_id}:role/OrganizationAccountAccessRole";
        $assumedRole = $stsClient->assumeRole([
            'RoleArn' => $roleArn,
            'RoleSessionName' => 'ChildAccountSession'
        ]);
        $tempCredentials = $assumedRole['Credentials'];

        // Step 3: Create an IAM client with temporary credentials and create an IAM user
        $iamClient = new IamClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key'    => $tempCredentials['AccessKeyId'],
                'secret' => $tempCredentials['SecretAccessKey'],
                'token'  => $tempCredentials['SessionToken'],
            ]
        ]);

        $userName = "child-root-user-{$child_id}";
        try {
            $iamClient->createUser([
                'UserName' => $userName
            ]);
        } catch (AwsException $e) {
            // If the user already exists, ignore the error
            if ($e->getAwsErrorCode() !== 'EntityAlreadyExists') {
                throw $e;
            }
        }

        // Step 4: Attach AdministratorAccess policy to the IAM user
        try {
            $iamClient->attachUserPolicy([
                'UserName'  => $userName,
                'PolicyArn' => 'arn:aws:iam::aws:policy/AdministratorAccess'
            ]);
        } catch (AwsException $e) {
            // Optionally log or handle error here
        }

        // Step 5: Create permanent access keys for the newly created IAM user
        $accessKeyResult = $iamClient->createAccessKey([
            'UserName' => $userName
        ]);
        $accessKey = $accessKeyResult['AccessKey']['AccessKeyId'];
        $secretKey = $accessKeyResult['AccessKey']['SecretAccessKey'];

        // Step 6: Store the permanent credentials in the database
        $updateStmt = $pdo->prepare("UPDATE child_accounts SET aws_access_key = ?, aws_secret_key = ? WHERE account_id = ?");
        $updateStmt->execute([$accessKey, $secretKey, $child_id]);

        echo json_encode([
            "status" => "success",
            "message" => "Keys created successfully for account {$child_id}",
            "accessKey" => $accessKey
        ]);
    } catch (AwsException $e) {
        echo json_encode([
            "status" => "error",
            "message" => "AWS Error: " . $e->getMessage()
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            "status" => "error",
            "message" => "Database Error: " . $e->getMessage()
        ]);
    }
    exit;
}

// --- Display HTML UI ---
if (!isset($_GET['parent_id'])) {
    die("Error: Missing parent_id parameter.");
}
$parent_id = $_GET['parent_id'];

// Fetch child accounts where parent_id equals the provided id
$stmt = $pdo->prepare("SELECT * FROM child_accounts WHERE parent_id = ?  AND account_id != '$parent_id'");
$stmt->execute([$parent_id]);
$childAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create AWS Keys for Child Accounts</title>
    <!-- Include Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .child-box { margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h1 class="mb-4">Create AWS Keys for Child Accounts</h1>
    <!-- Button to create keys for all child accounts with a 3-second delay between each -->
    <button id="createAll" class="btn btn-primary mb-4">Create Keys for All Accounts</button>
    <div id="childAccounts" class="row">
        <?php foreach ($childAccounts as $child): ?>
            <div class="col-md-4">
                <div class="card child-box" id="child_<?php echo $child['account_id']; ?>">
                    <div class="card-body">
                        <h5 class="card-title">Child Account: <?php echo htmlspecialchars($child['account_id']); ?></h5>
                        <?php if (!empty($child['aws_access_key']) && !empty($child['aws_secret_key'])): ?>
                            <p class="card-text text-success">Keys already created.</p>
                        <?php else: ?>
                            <p class="card-text text-warning">No keys created yet.</p>
                            <button class="btn btn-success createKeyBtn" data-child-id="<?php echo $child['account_id']; ?>">Create Keys</button>
                        <?php endif; ?>
                        <div class="result mt-2"></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Include jQuery and Bootstrap JS for functionality -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
    // Function to call the API endpoint and update the UI for a specific child account.
    function createKeys(parent_id, child_id, button) {
        button.disabled = true;
        var resultDiv = $('#child_' + child_id + ' .result');
        resultDiv.html('<span class="text-info">Creating keys...</span>');
        $.ajax({
            url: "?action=create&parent_id=" + parent_id + "&child_id=" + child_id,
            method: "GET",
            dataType: "json",
            success: function(response) {
                if (response.status === "success" || response.status === "exists") {
                    resultDiv.html('<span class="text-success">' + response.message + '</span>');
                    button.hide();
                    $('#child_' + child_id).addClass('border border-success');
                } else {
                    resultDiv.html('<span class="text-danger">' + response.message + '</span>');
                    button.prop('disabled', false);
                    $('#child_' + child_id).addClass('border border-danger');
                }
            },
            error: function() {
                resultDiv.html('<span class="text-danger">Error calling API.</span>');
                button.prop('disabled', false);
            }
        });
    }

    // Attach event listeners to each individual "Create Keys" button.
    $('.createKeyBtn').on('click', function() {
        var child_id = $(this).data('child-id');
        var parent_id = "<?php echo $parent_id; ?>";
        createKeys(parent_id, child_id, this);
    });

    // "Create Keys for All" button triggers creation for each account with a 3-second delay.
    $('#createAll').on('click', function() {
        var parent_id = "<?php echo $parent_id; ?>";
        var buttons = $('.createKeyBtn');
        var delay = 0;
        buttons.each(function() {
            var child_id = $(this).data('child-id');
            var button = this;
            setTimeout(function() {
                createKeys(parent_id, child_id, button);
            }, delay);
            delay += 3000;
        });
    });
</script>
</body>
</html>
