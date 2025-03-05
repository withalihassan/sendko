<?php
// Turn on error reporting (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Database Connection ---
// Update with your actual database credentials.
include('db.php'); // This file must initialize your $pdo connection

// Include the AWS PHP SDK autoloader (adjust the path as needed)
require_once __DIR__ . '/aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

// Check if this is an AJAX request for a region cleanup.
if (isset($_GET['action']) && $_GET['action'] === 'delete_region' && isset($_GET['region']) && isset($_GET['ac_id'])) {
    header('Content-Type: application/json');

    $region = $_GET['region'];
    $ac_id  = intval($_GET['ac_id']);

    // Fetch AWS credentials for the provided account ID.
    $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
    $stmt->execute([$ac_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo json_encode(['error' => 'Account not found.']);
        exit;
    }

    $aws_key    = $account['aws_key'];
    $aws_secret = $account['aws_secret'];

    $deletedCount = 0;
    $skippedCount = 0;
    $messages = [];
    try {
        // Create an SNS client for the current region.
        $snsClient = new SnsClient([
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_key,
                'secret' => $aws_secret,
            ],
        ]);

        // List all sandbox phone numbers.
        $result = $snsClient->listSMSSandboxPhoneNumbers();

        // Check for both possible key names.
        if (isset($result['phoneNumbers'])) {
            $phoneNumbers = $result['phoneNumbers'];
        } elseif (isset($result['PhoneNumbers'])) {
            $phoneNumbers = $result['PhoneNumbers'];
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

            // Check if a creation timestamp is available.
            if (isset($number['CreatedTimestamp'])) {
                // Convert the timestamp to a Unix time.
                $createdTime = strtotime($number['CreatedTimestamp']);
                $ageSeconds = $currentTime - $createdTime;
                if ($ageSeconds < 24 * 3600) {
                    // Skip deletion if added less than 24 hours ago.
                    $skippedCount++;
                    $messages[] = "Skipped {$phone} (added less than 24 hours ago).";
                    continue;
                }
            }
            // If no CreatedTimestamp is provided or if it's older than 24 hours, attempt deletion.
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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
        <h1>Contact Cleanup</h1>
        <?php
        // Ensure the account ID is provided via query parameter.
        $ac_id = isset($_GET['ac_id']) ? intval($_GET['ac_id']) : 0;
        if ($ac_id === 0) {
            echo '<p class="error">No account ID provided.</p>';
            exit;
        }
        ?>
        <button id="startButton">Start Cleanup Process</button>
        <div id="log"></div>
    </div>

    <script>
        // Helper: sleep for a given number of milliseconds.
        function sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        // Define the AWS regions to process.
        const regions = ["us-east-1", "us-east-2", "us-west-1", "us-west-2", "ap-south-1", "ap-northeast-3", 
        "ap-southeast-1", "ap-southeast-2", "ap-northeast-1", "ca-central-1", "eu-central-1", "eu-west-1", "eu-west-2", "eu-west-3",
        "eu-north-1", "me-central-1", "sa-east-1", "af-south-1", "ap-southeast-3", "ap-southeast-4", "ca-west-1", "eu-south-1", 
        "eu-south-2", "eu-central-2", "me-south-1", "il-central-1",  "ap-south-2"];
        const ac_id = <?php echo json_encode($ac_id); ?>;
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
                    const response = await fetch(`?action=delete_region&ac_id=${ac_id}&region=${region}`);
                    const result = await response.json();
                    if (result.error) {
                        appendLog(`Region ${region} error: ${result.error}`, "error");
                    } else {
                        appendLog(`Region ${region}: Deleted <strong>${result.deleted}</strong> phone number(s).`, "success");
                        appendLog(`Region ${region}: Skipped <strong>${result.skipped}</strong> phone number(s) (added recently).`, "error");
                        if (result.messages && result.messages.length > 0) {
                            result.messages.forEach(msg => appendLog(`&nbsp;&nbsp;&nbsp;${msg}`));
                        }
                    }
                } catch (err) {
                    appendLog(`Fetch error for region ${region}: ${err.message}`, "error");
                }
                // Wait 2 seconds before processing the next region.
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