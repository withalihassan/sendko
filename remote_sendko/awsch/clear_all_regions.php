<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database connection and AWS SDK autoloader
include('../db.php');
require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

// ----- AJAX Endpoint: Delete SNS Sandbox Phone Numbers for a Specific Region -----
if (isset($_GET['action']) && $_GET['action'] === 'delete_region' && isset($_GET['region'])) {
    header('Content-Type: application/json');

    $region    = $_GET['region'];
    $ac_id     = isset($_GET['ac_id']) ? $_GET['ac_id'] : '';
    $parent_id = isset($_GET['parrent_id']) ? $_GET['parrent_id'] : '';

    if ($ac_id === '' || $parent_id === '') {
        echo json_encode(['error' => 'Both ac_id and parrent_id must be provided.']);
        exit;
    }

    // Fetch the child account record matching the parent and child IDs.
    $stmt = $pdo->prepare("SELECT `id`, `parent_id`, `email`, `account_id`, `status`, `created_at`, `name`, `aws_access_key`, `aws_secret_key` FROM `child_accounts` WHERE parent_id = ? AND account_id = ?");
    $stmt->execute([$parent_id, $ac_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo json_encode(['error' => 'Account not found for the provided parent_id and ac_id.']);
        exit;
    }

    $aws_access_key = $account['aws_access_key'];
    $aws_secret_key = $account['aws_secret_key'];

    $deletedCount = 0;
    $skippedCount = 0;
    $messages = [];

    try {
        // Create an SNS client using the account's AWS credentials for the specified region.
        $snsClient = new SnsClient([
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_access_key,
                'secret' => $aws_secret_key,
            ],
        ]);

        // List all SNS sandbox phone numbers.
        $resultSNS = $snsClient->listSMSSandboxPhoneNumbers();

        // Some responses use different keys.
        if (isset($resultSNS['phoneNumbers'])) {
            $phoneNumbers = $resultSNS['phoneNumbers'];
        } elseif (isset($resultSNS['PhoneNumbers'])) {
            $phoneNumbers = $resultSNS['PhoneNumbers'];
        } else {
            $phoneNumbers = [];
        }

        $messages[] = "Found " . count($phoneNumbers) . " phone number(s) in region {$region}.";
        $currentTime = time();

        // Process each phone number.
        foreach ($phoneNumbers as $number) {
            if (!isset($number['PhoneNumber'])) {
                continue;
            }
            // Pause for 2 seconds between deletions.
            sleep(2);
            $phone = $number['PhoneNumber'];

            // Skip deletion if created less than 24 hours ago.
            if (isset($number['CreatedTimestamp'])) {
                $createdTime = strtotime($number['CreatedTimestamp']);
                if (($currentTime - $createdTime) < 24 * 3600) {
                    $skippedCount++;
                    $messages[] = "Skipped {$phone} (added less than 24 hours ago).";
                    continue;
                }
            }
            // Attempt deletion.
            try {
                $snsClient->deleteSMSSandboxPhoneNumber([
                    'PhoneNumber' => $phone,
                ]);
                $deletedCount++;
                $messages[] = "Deleted {$phone}.";
            } catch (AwsException $ex) {
                $messages[] = "Error deleting {$phone}: " . $ex->getAwsErrorMessage();
            }
        }
    } catch (AwsException $e) {
        echo json_encode(['error' => "Error processing region {$region}: " . $e->getAwsErrorMessage()]);
        exit;
    }

    echo json_encode([
        'region'   => $region,
        'deleted'  => $deletedCount,
        'skipped'  => $skippedCount,
        'messages' => $messages,
    ]);
    exit;
}

// ----- HTML User Interface for Clearing Regions for All Child Accounts -----
if (!isset($_GET['parent_id'])) {
    die("Error: Missing parent_id parameter.");
}
$parent_id = $_GET['parent_id'];

// Fetch all child accounts belonging to the given parent.
$stmt = $pdo->prepare("SELECT * FROM child_accounts WHERE parent_id = ? AND account_id!='$parent_id' AND  status!='pending'");
$stmt->execute([$parent_id]);
$childAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clear Regions for Child Accounts</title>
    <!-- Include Bootstrap CSS from a CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .card {
            margin-bottom: 20px;
        }
        .log-box {
            max-height: 300px;
            overflow-y: auto;
            background: #fafafa;
            border: 1px solid #ddd;
            padding: 10px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="container-fluid p-5">
    <h1 class="mb-4">Clear Regions for Child Accounts</h1>
    <p>Parent ID: <?php echo htmlspecialchars($parent_id); ?></p>
    <!-- Global Button to start cleanup for all child accounts -->
    <button id="startAll" class="btn btn-primary mb-4">Start Cleanup For All Child Accounts</button>
    <div id="childCards" class="row">
        <?php foreach ($childAccounts as $child): ?>
            <div class="col-md-4">
                <div class="card" id="child_<?php echo $child['account_id']; ?>">
                    <div class="card-body">
                        <h5 class="card-title">Child Account: <?php echo htmlspecialchars($child['account_id']); ?></h5>
                        <p class="card-text">Name: <?php echo htmlspecialchars($child['name'] ?? 'N/A'); ?></p>
                        <button class="btn btn-success startCleanup" data-child-id="<?php echo $child['account_id']; ?>">Start Cleanup</button>
                        <div class="mt-3 log-box" id="log_<?php echo $child['account_id']; ?>"></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Include jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
// List of AWS regions to process.
const regions = [
    "us-east-1", "us-east-2", "us-west-1", "us-west-2", "ap-south-1", "ap-northeast-3", 
    "ap-southeast-1", "ap-southeast-2", "ap-northeast-1", "ca-central-1", "eu-central-1", 
    "eu-west-1", "eu-west-2", "eu-west-3", "eu-north-1", "me-central-1", "sa-east-1", 
    "af-south-1", "ap-southeast-3", "ap-southeast-4", "ca-west-1", "eu-south-1", 
    "eu-south-2", "eu-central-2", "me-south-1", "il-central-1", "ap-south-2"
];

// Helper function to pause execution for a specified duration (in milliseconds)
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// Function to append a message to a specific log box
function appendLog(child_id, message, className = '') {
    let logBox = $("#log_" + child_id);
    logBox.append(`<p class="${className}">${message}</p>`);
    logBox.scrollTop(logBox[0].scrollHeight);
}

// Function to process region cleanup for a given child account
async function processRegionsForChild(child_id, parent_id) {
    for (let region of regions) {
        appendLog(child_id, `Processing region: <strong>${region}</strong>...`);
        try {
            let response = await fetch(`?action=delete_region&ac_id=${child_id}&parrent_id=${parent_id}&region=${region}`);
            let result = await response.json();
            if (result.error) {
                appendLog(child_id, `Region ${region} error: ${result.error}`, 'text-danger');
            } else {
                appendLog(child_id, `Region ${region}: Deleted <strong>${result.deleted}</strong> phone number(s).`, 'text-success');
                appendLog(child_id, `Region ${region}: Skipped <strong>${result.skipped}</strong> phone number(s).`, 'text-warning');
                if (result.messages && Array.isArray(result.messages)) {
                    result.messages.forEach(msg => appendLog(child_id, `&nbsp;&nbsp;&nbsp;${msg}`));
                }
            }
        } catch (err) {
            appendLog(child_id, `Fetch error for region ${region}: ${err.message}`, 'text-danger');
        }
        // Wait 2 seconds before processing the next region.
        await sleep(2000);
    }
    appendLog(child_id, "Cleanup process complete.", 'font-weight-bold');
}

// Handler for the individual "Start Cleanup" button on each child card.
$(".startCleanup").on("click", function() {
    let child_id = $(this).data("child-id");
    let parent_id = <?php echo json_encode($parent_id); ?>;
    // Disable the button once clicked.
    $(this).prop("disabled", true);
    processRegionsForChild(child_id, parent_id);
});

// Handler for the global "Start Cleanup For All Child Accounts" button.
$("#startAll").on("click", function() {
    let parent_id = <?php echo json_encode($parent_id); ?>;
    // Disable the global button while processing.
    $(this).prop("disabled", true);
    // For each child account, start the cleanup process with a delay between each.
    let delay = 0;
    $(".startCleanup").each(function() {
        let child_id = $(this).data("child-id");
        let button = $(this);
        setTimeout(function() {
            button.prop("disabled", true);
            processRegionsForChild(child_id, parent_id);
        }, delay);
        delay += regions.length * 2100; // delay for all regions (2.1 sec per region)
    });
});
</script>
</body>
</html>
