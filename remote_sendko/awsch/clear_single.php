<?php
// Turn on error reporting (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Database Connection ---
// Ensure your db.php file initializes the $pdo connection
include('../db.php');

// Include the AWS PHP SDK autoloader (adjust the path as needed)
require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

// Check if this is an AJAX request for a region cleanup.
if (isset($_GET['action']) && $_GET['action'] === 'delete_region' && isset($_GET['region'])) {
    header('Content-Type: application/json');

    // Use the values as strings to preserve formatting (e.g. leading zeros)
    $region    = $_GET['region'];
    $ac_id     = isset($_GET['ac_id']) ? $_GET['ac_id'] : '';
    $parent_id = isset($_GET['parrent_id']) ? $_GET['parrent_id'] : '';

    // Ensure both parameters are provided
    if ($ac_id === '' || $parent_id === '') {
        echo json_encode(['error' => 'Both ac_id and parrent_id must be provided.']);
        exit;
    }

    // Fetch the account from child_accounts where parent_id and account_id match the URL parameters.
    $stmt = $pdo->prepare("SELECT `id`, `parent_id`, `email`, `account_id`, `status`, `created_at`, `name`, `aws_access_key`, `aws_secret_key` FROM `child_accounts` WHERE parent_id = ? AND account_id = ?");
    $stmt->execute([$parent_id, $ac_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo json_encode(['error' => 'Account not found for the provided parent_id and ac_id.']);
        exit;
    }

    // Retrieve AWS credentials from the fetched row.
    $aws_access_key = $account['aws_access_key'];
    $aws_secret_key = $account['aws_secret_key'];

    $deletedCount = 0;
    $skippedCount = 0;
    $messages = [];

    try {
        // Create an SNS client for the specified region using the account's AWS credentials.
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

        // Check for both possible key names.
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
            // Ensure we have a phone number string.
            if (!isset($number['PhoneNumber'])) {
                continue;
            }
            sleep(2);
            $phone = $number['PhoneNumber'];

            // If a creation timestamp exists, skip deletion if the phone was added less than 24 hours ago.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contacts Cleanup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: #333;
        }
        #startButton {
            display: block;
            margin: 20px auto;
            background: #007BFF;
            color: #fff;
            border: none;
            padding: 12px 20px;
            font-size: 1rem;
            border-radius: 5px;
            cursor: pointer;
        }
        #startButton:disabled {
            background: #aaa;
            cursor: not-allowed;
        }
        #log {
            margin-top: 20px;
            padding: 10px;
            border: 1px solid #ddd;
            background: #fafafa;
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
            font-size: 0.95rem;
        }
        .log-entry {
            margin-bottom: 10px;
        }
        .success {
            color: green;
        }
        .error {
            color: red;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php
            // Debug display of the parent id (as provided)
            echo "Provided Parent ID: " . htmlspecialchars($_GET['parrent_id']);
        ?>
        <h1>Contact Cleanup</h1>
        <?php
        // Ensure both ac_id and parrent_id are provided via URL.
        $ac_id = isset($_GET['ac_id']) ? $_GET['ac_id'] : '';
        $parent_id = isset($_GET['parrent_id']) ? $_GET['parrent_id'] : '';
        if ($ac_id === '' || $parent_id === '') {
            echo '<p class="error">Both ac_id and parrent_id must be provided.</p>';
            exit;
        }
        ?>
        <button id="startButton">Start Cleanup Process</button>
        <div id="log"></div>
    </div>

    <script>
        // Helper function: sleep for a given number of milliseconds.
        function sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        // List of AWS regions to process.
        const regions = [
            "us-east-1", "us-east-2", "us-west-1", "us-west-2", "ap-south-1", "ap-northeast-3", 
            "ap-southeast-1", "ap-southeast-2", "ap-northeast-1", "ca-central-1", "eu-central-1", 
            "eu-west-1", "eu-west-2", "eu-west-3", "eu-north-1", "me-central-1", "sa-east-1", 
            "af-south-1", "ap-southeast-3", "ap-southeast-4", "ca-west-1", "eu-south-1", 
            "eu-south-2", "eu-central-2", "me-south-1", "il-central-1", "ap-south-2"
        ];
        const ac_id = <?php echo json_encode($ac_id); ?>;
        const parent_id = <?php echo json_encode($parent_id); ?>;
        const log = document.getElementById("log");
        const startButton = document.getElementById("startButton");

        function appendLog(message, className = "") {
            const p = document.createElement("p");
            p.className = "log-entry " + className;
            p.innerHTML = message;
            log.appendChild(p);
            log.scrollTop = log.scrollHeight;
        }

        async function processRegions() {
            for (let region of regions) {
                appendLog(`Processing region: <strong>${region}</strong>...`);
                try {
                    const response = await fetch(`?action=delete_region&ac_id=${ac_id}&parrent_id=${parent_id}&region=${region}`);
                    const result = await response.json();
                    if (result.error) {
                        appendLog(`Region ${region} error: ${result.error}`, "error");
                    } else {
                        appendLog(`Region ${region}: Deleted <strong>${result.deleted}</strong> phone number(s).`, "success");
                        appendLog(`Region ${region}: Skipped <strong>${result.skipped}</strong> phone number(s) (added recently).`, "error");
                        if (result.messages && Array.isArray(result.messages)) {
                            result.messages.forEach(msg => appendLog(`&nbsp;&nbsp;&nbsp;${msg}`));
                        }
                    }
                } catch (err) {
                    appendLog(`Fetch error for region ${region}: ${err.message}`, "error");
                }
                await sleep(2000);
            }
            appendLog("Cleanup process complete.");
            startButton.disabled = false;
        }

        startButton.addEventListener("click", function() {
            log.innerHTML = "";
            startButton.disabled = true;
            processRegions();
        });
    </script>
</body>
</html>
