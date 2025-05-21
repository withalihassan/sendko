<?php
// Turn on error reporting (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Database Connection ---
include 'db.php';              // must initialize $pdo
require_once __DIR__ . '/aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

// 1) Complete cleanup handler
if (
    isset($_GET['action'])
 && $_GET['action'] === 'complete_cleanup'
 && isset($_GET['ac_id'])
) {
    header('Content-Type: application/json');
    $ac_id = intval($_GET['ac_id']);
    $stmt = $pdo->prepare("
        UPDATE iam_users
           SET cleanup_status = 'Yes'
         WHERE id = ?
    ");
    $stmt->execute([$ac_id]);
    echo json_encode(['success' => $stmt->rowCount() > 0]);
    exit;
}

// 2) Delete-region handler
if (
    isset($_GET['action'])
 && $_GET['action'] === 'delete_region'
 && isset($_GET['region'])
 && isset($_GET['ac_id'])
) {
    header('Content-Type: application/json');

    $region = $_GET['region'];
    $ac_id  = intval($_GET['ac_id']);

    // fetch AWS creds
    $stmt = $pdo->prepare("SELECT access_key_id, secret_access_key FROM iam_users WHERE id = ?");
    $stmt->execute([$ac_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        echo json_encode(['error' => 'Account not found.']);
        exit;
    }

    $sns = new SnsClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => [
            'key'    => $account['access_key_id'],
            'secret' => $account['secret_access_key'],
        ],
    ]);

    $deleted = $skipped = 0;
    $messages = [];
    try {
        $res = $sns->listSMSSandboxPhoneNumbers();
        $phones = $res['phoneNumbers'] ?? $res['PhoneNumbers'] ?? [];
        $messages[] = "Found " . count($phones) . " number(s) in {$region}.";

        $now = time();
        foreach ($phones as $num) {
            if (empty($num['PhoneNumber'])) continue;
            $phone = $num['PhoneNumber'];
            // skip recent
            if (!empty($num['CreatedTimestamp'])) {
                $age = $now - strtotime($num['CreatedTimestamp']);
                if ($age < 86400) {
                    $skipped++;
                    $messages[] = "Skipped {$phone} (<24h old).";
                    continue;
                }
            }
            try {
                $sns->deleteSMSSandboxPhoneNumber(['PhoneNumber' => $phone]);
                $deleted++;
                $messages[] = "Deleted {$phone}.";
            } catch (AwsException $ex) {
                $messages[] = "Error deleting {$phone}: " . $ex->getAwsErrorMessage();
            }
            sleep(2);
        }
    } catch (AwsException $e) {
        echo json_encode([
            'error' => "Error in {$region}: " . $e->getAwsErrorMessage()
        ]);
        exit;
    }

    echo json_encode([
        'region'   => $region,
        'deleted'  => $deleted,
        'skipped'  => $skipped,
        'messages' => $messages,
    ]);
    exit;
}

// --- HTML/UI ---
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Contacts Cleanup</title>
  <style>
    body {font-family: Arial, sans-serif; background:#f5f5f5; margin:0; padding:20px;}
    .container {max-width:800px; margin:auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);}
    h1 {text-align:center;color:#333;}
    #startButton {display:block; margin:20px auto; background:#007BFF; color:#fff; border:none; padding:12px 20px; font-size:1rem; border-radius:5px; cursor:pointer;}
    #startButton:disabled {background:#aaa; cursor:not-allowed;}
    #log {margin-top:20px; padding:10px; border:1px solid #ddd; background:#fafafa; border-radius:4px; max-height:400px; overflow-y:auto; font-size:0.95rem;}
    .log-entry {margin-bottom:10px;}
    .success {color:green;}
    .error {color:red;}
  </style>
</head>
<body>
  <div class="container">
    <h1>Contact Cleanup</h1>
    <?php
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
    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
    const regions = [
      "us-east-1","us-east-2","us-west-1","us-west-2","ap-south-1","ap-northeast-3",
      "ap-southeast-1","ap-southeast-2","ap-northeast-1","ca-central-1","eu-central-1",
      "eu-west-1","eu-west-2","eu-west-3","eu-north-1","me-central-1","sa-east-1",
      "af-south-1","ap-southeast-3","ap-southeast-4","ca-west-1","eu-south-1","eu-south-2",
      "eu-central-2","me-south-1","il-central-1","ap-south-2"
    ];
    const ac_id = <?php echo $ac_id; ?>;
    const log = document.getElementById("log");
    const btn = document.getElementById("startButton");

    function appendLog(msg, cls="") {
      const p = document.createElement("p");
      p.className = "log-entry " + cls;
      p.innerHTML = msg;
      log.appendChild(p);
      log.scrollTop = log.scrollHeight;
    }

    async function processRegions() {
      for (let region of regions) {
        appendLog(`Processing region: <strong>${region}</strong>...`);
        try {
          let resp = await fetch(`?action=delete_region&ac_id=${ac_id}&region=${region}`);
          let data = await resp.json();
          if (data.error) {
            appendLog(`Region ${region} error: ${data.error}`, "error");
          } else {
            appendLog(`Region ${region}: Deleted <strong>${data.deleted}</strong> number(s).`, "success");
            appendLog(`Region ${region}: Skipped <strong>${data.skipped}</strong> number(s).`, "error");
            data.messages.forEach(m => appendLog(`&nbsp;&nbsp;${m}`));
          }
        } catch (e) {
          appendLog(`Fetch error for ${region}: ${e.message}`, "error");
        }
        await sleep(2000);
      }
      appendLog("Cleanup process complete.");

      // Now mark cleanup_status = 'Yes'
      try {
        let resp = await fetch(`?action=complete_cleanup&ac_id=${ac_id}`);
        let j = await resp.json();
        if (j.success) {
          appendLog("Cleanup process completed and Updated", "success");
        } else {
          appendLog("Failed to update cleanup_status", "error");
        }
      } catch (e) {
        appendLog("Error updating cleanup_status: " + e.message, "error");
      }

      btn.disabled = false;
    }

    btn.addEventListener("click", () => {
      log.innerHTML = "";
      btn.disabled = true;
      processRegions();
    });
  </script>
</body>
</html>
