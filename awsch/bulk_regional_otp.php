<?php
// bulk_regional_otp.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('../db.php'); // This file must initialize your $pdo connection

// --- STREAMING MODE (SSE) ---
// When stream=1 is passed along with an individual account id (ac_id) and set_id,
// the script runs the OTP process for that child account.
if (isset($_GET['stream'])) {
    if (!isset($_GET['ac_id'])) {
        echo "Account ID required.";
        exit;
    }
    if (!isset($_GET['set_id']) || intval($_GET['set_id']) <= 0) {
        echo "No set selected.";
        exit;
    }
    
    $ac_id = htmlspecialchars($_GET['ac_id']);
    $set_id = intval($_GET['set_id']);
    
    // Fetch AWS credentials for this child account based on account_id
    $stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key, name FROM child_accounts WHERE account_id = ?");
    $stmt->execute([$ac_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        echo "Account not found.";
        exit;
    }
    $accountName = $account['name'];
    $aws_key     = $account['aws_access_key'];
    $aws_secret  = $account['aws_secret_key'];
    
    date_default_timezone_set('Asia/Karachi');
    
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    while (ob_get_level()) { ob_end_flush(); }
    set_time_limit(0);
    ignore_user_abort(true);
    
    function sendSSE($type, $message) {
        echo "data:" . $type . "|" . str_replace("\n", "\\n", $message) . "\n\n";
        flush();
    }
    
    sendSSE("STATUS", "Starting OTP Process for Account: " . $accountName . " | Set ID: " . $set_id);
    
    // List of regions to process (adjust as needed)
    $regions = array(
      "us-east-1", "us-east-2", "us-west-1", "us-west-2",
      "ap-south-1", "ap-northeast-3", "ap-southeast-1", "ap-southeast-2",
      "ap-northeast-1", "ca-central-1", "eu-central-1", "eu-west-1",
      "eu-west-2", "eu-west-3", "eu-north-1", "me-central-1",
      "sa-east-1", "af-south-1", "ap-southeast-3", "ap-southeast-4",
      "ca-west-1", "eu-south-1", "eu-south-2", "eu-central-2",
      "me-south-1", "il-central-1", "ap-south-2"
    );
    $totalRegions = count($regions);
    $totalSuccess = 0;
    $usedRegions = 0;
    
    // Include the AJAX handler functions
    $internal_call = true;
    require_once('region_ajax_handler_global.php');
    
    foreach ($regions as $region) {
        $usedRegions++;
        sendSSE("STATUS", "Processing region: " . $region);
        sendSSE("COUNTERS", "Total OTP sent: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));
    
        // Fetch allowed numbers for the region and set.
        $numbersResult = fetch_numbers($region, $pdo, $set_id);
        if (isset($numbersResult['error'])) {
            sendSSE("STATUS", "Error fetching numbers for region " . $region . ": " . $numbersResult['error']);
            sleep(5);
            continue;
        }
        $allowedNumbers = $numbersResult['data'];
        if (empty($allowedNumbers)) {
            sendSSE("STATUS", "No allowed numbers found in region: " . $region);
            sleep(5);
            continue;
        }
    
        // Try to send OTPs – if a number has no attempts left, try the next available one.
        $desiredOtpCount = 10;
        $otpSentCount = 0;
        $index = 0;
        $otpSentInThisRegion = false;
        while ($otpSentCount < $desiredOtpCount && $index < count($allowedNumbers)) {
            $currentNumber = $allowedNumbers[$index];
            sendSSE("STATUS", "Attempting OTP for: " . $currentNumber['phone_number'] . " in region " . $region);
            
            // Delay before each AWS API request
            sleep(5);
            $sns = initSNS($aws_key, $aws_secret, $region);
            if (is_array($sns) && isset($sns['error'])) {
                sendSSE("ROW", $currentNumber['id'] . "|" . $currentNumber['phone_number'] . "|" . $region . "|OTP Failed: " . $sns['error']);
                $index++;
                sleep(5);
                continue;
            }
            $result = send_otp_single($currentNumber['id'], $currentNumber['phone_number'], $region, $aws_key, $aws_secret, $pdo, $sns);
            if ($result['status'] === 'success') {
                sendSSE("ROW", $currentNumber['id'] . "|" . $currentNumber['phone_number'] . "|" . $region . "|OTP Sent");
                $totalSuccess++;
                $otpSentCount++;
                $otpSentInThisRegion = true;
                sendSSE("COUNTERS", "Total OTP sent: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));
                // 2.5 second delay after a successful OTP send
                usleep(2500000);
            } else if ($result['status'] === 'error' && strpos($result['message'], "No remaining OTP attempts") !== false) {
                sendSSE("ROW", $currentNumber['id'] . "|" . $currentNumber['phone_number'] . "|" . $region . "|OTP Failed: " . $result['message'] . " - Switching to next number");
                $index++;
                sleep(5);
                continue;
            } else if ($result['status'] === 'error' && (
                        strpos($result['message'], "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false ||
                        strpos($result['message'], "The security token included in the request is invalid") !== false ||
                        strpos($result['message'], "Region Restricted") !== false
                    )) {
                sendSSE("ROW", $currentNumber['id'] . "|" . $currentNumber['phone_number'] . "|" . $region . "|OTP Failed: " . $result['message'] . " - Moving to next region");
                // Break out of the current region loop
                break;
            } else if ($result['status'] === 'skip') {
                sendSSE("ROW", $currentNumber['id'] . "|" . $currentNumber['phone_number'] . "|" . $region . "|OTP Skipped: " . $result['message']);
                $index++;
                sleep(5);
                continue;
            } else {
                sendSSE("ROW", $currentNumber['id'] . "|" . $currentNumber['phone_number'] . "|" . $region . "|OTP Failed: " . $result['message']);
                $index++;
                sleep(5);
                continue;
            }
        }
        if ($otpSentInThisRegion) {
            sendSSE("STATUS", "Completed OTP sending for region $region. Waiting 10 seconds...");
            sleep(10);
        } else {
            sendSSE("STATUS", "Completed OTP sending for region $region. Waiting 5 seconds...");
            sleep(5);
        }
    }
    
    $summary = "Final Summary:<br>Total OTP sent: $totalSuccess<br>Regions processed: $usedRegions<br>Remaining regions: " . ($totalRegions - $usedRegions);
    sendSSE("SUMMARY", $summary);
    sendSSE("STATUS", "Process Completed for account: " . $accountName);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Bulk Regional OTP Sending</title>
  <!-- Use container-fluid for full–width layout -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    body { background: #f7f7f7; }
    .container-fluid { max-width: 100%; margin: auto; }
    .card { margin-bottom: 20px; }
    .scroll-box { max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ccc; }
    .global-stats { margin-bottom: 20px; }
    /* Minimized font size for OTP responses and logs */
    .otp-log, .numbers-box, .otp-stats { font-size: 12px; }
  </style>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<div class="container-fluid">
  <h1 class="text-center">Bulk Regional OTP Sending</h1>
  
  <!-- Global Controls -->
  <div class="global-stats">
    <?php
    // Use the provided SQL for set selection
    $stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets Where status='fresh' ORDER BY 1 DESC");
    $sets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="row">
      <div class="col-md-4">
        <label for="global_set_id">Select Set:</label>
        <select id="global_set_id" class="form-control">
          <option value="">-- Select a Set --</option>
          <?php foreach ($sets as $set): ?>
            <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <button id="global_start_otp" class="btn btn-primary btn-block">Start OTP for All Accounts</button>
      </div>
      <div class="col-md-4">
        <h4>Total Stats:</h4>
        <div id="global_counters" class="alert alert-info p-2"></div>
      </div>
    </div>
  </div>
  
  <!-- Child Account Cards (3 per row) -->
  <div class="row" id="child_cards_container">
    <?php
    // Fetch child accounts for the given parent_id.
    // Ensure that we only fetch children (i.e. where account_id is not equal to the parent_id).
    if (!isset($_GET['parent_id'])) {
      echo "<div class='col-12'><div class='alert alert-warning'>No parent_id provided.</div></div>";
      exit;
    }
    $parent_id = htmlspecialchars($_GET['parent_id']);
    $stmtChild = $pdo->prepare("SELECT id, parent_id, email, account_id, name, aws_access_key, aws_secret_key FROM child_accounts WHERE parent_id = ? AND account_id <> ?");
    $stmtChild->execute([$parent_id, $parent_id]);
    $childAccounts = $stmtChild->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <?php if(count($childAccounts) > 0): ?>
      <?php foreach($childAccounts as $child): ?>
        <div class="col-md-4">
          <div class="card" data-account-id="<?php echo htmlspecialchars($child['account_id']); ?>">
            <div class="card-header">
              <h5><?php echo htmlspecialchars($child['name']); ?> (<?php echo htmlspecialchars($child['email']); ?>)</h5>
            </div>
            <div class="card-body">
              <p><strong>AWS Key:</strong> <?php echo htmlspecialchars($child['aws_access_key']); ?></p>
              <p><strong>AWS Secret:</strong> <?php echo htmlspecialchars($child['aws_secret_key']); ?></p>
              <!-- Individual Set Selection -->
              <label for="set_id_<?php echo $child['account_id']; ?>">Select Set:</label>
              <select id="set_id_<?php echo $child['account_id']; ?>" class="form-control individual-set">
                <option value="">-- Select a Set --</option>
                <?php foreach ($sets as $set): ?>
                  <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
                <?php endforeach; ?>
              </select>
              <!-- Allowed Numbers Display -->
              <label>Allowed Phone Numbers:</label>
              <div id="numbers_<?php echo $child['account_id']; ?>" class="scroll-box numbers-box"></div>
              <button class="btn btn-secondary btn-sm fetch-numbers" data-account="<?php echo $child['account_id']; ?>">Fetch Numbers</button>
              <hr>
              <!-- OTP Process Controls -->
              <button class="btn btn-primary start-otp" data-account="<?php echo $child['account_id']; ?>">Start OTP Process</button>
              <!-- OTP Event Log -->
              <label>OTP Events:</label>
              <div id="otp_log_<?php echo $child['account_id']; ?>" class="scroll-box otp-log"></div>
              <!-- OTP Stats for this account -->
              <div class="otp-stats alert alert-info p-2 mt-2" id="otp_stats_<?php echo $child['account_id']; ?>"></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="col-12">
        <div class="alert alert-warning">No child accounts found for parent id <?php echo $parent_id; ?>.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// Global counters to store overall stats
var globalCounters = {
  totalOtpSent: 0,
  totalRegionsProcessed: 0
};

function updateGlobalCountersDisplay() {
  $('#global_counters').html("Total OTP Sent: " + globalCounters.totalOtpSent + "<br>Regions Processed: " + globalCounters.totalRegionsProcessed);
}

$(document).ready(function(){
  // Fetch allowed numbers for an individual card when the "Fetch Numbers" button is clicked.
  $('.fetch-numbers').click(function(){
    var accountId = $(this).data('account');
    var setId = $('#set_id_' + accountId).val();
    if (!setId) {
      alert("Please select a set for account " + accountId);
      return;
    }
    $.ajax({
      url: 'region_ajax_handler_global.php',
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'fetch_numbers',
        region: 'dummy', // not used here
        set_id: setId
      },
      success: function(response) {
        var numbersBox = $('#numbers_' + accountId);
        if (response.status === 'success') {
          var displayText = "";
          response.data.forEach(function(item) {
            displayText += "ID: " + item.id + " | Phone: " + item.phone_number + " | ATM Left: " + item.atm_left + " | Date: " + item.formatted_date + "<br>";
          });
          numbersBox.html(displayText);
        } else {
          numbersBox.html('Error: ' + response.message);
        }
      },
      error: function(xhr, status, error) {
        $('#numbers_' + accountId).html('AJAX error: ' + error);
      }
    });
  });
  
  // Start OTP process for an individual account.
  $('.start-otp').click(function(){
    var accountId = $(this).data('account');
    var setId = $('#set_id_' + accountId).val();
    if (!setId) {
      alert("Please select a set for account " + accountId);
      return;
    }
    var otpLogBox = $('#otp_log_' + accountId);
    otpLogBox.html('');
    // Open SSE connection for this account.
    var evtSource = new EventSource("bulk_regional_otp.php?ac_id=" + accountId + "&set_id=" + setId + "&stream=1");
    evtSource.onmessage = function(e) {
      var data = e.data;
      var parts = data.split("|");
      var type = parts[0];
      if (type === "ROW") {
        // Format: ROW|ID|Phone|Region|Status
        var id = parts[1];
        var phone = parts[2];
        var region = parts[3];
        var status = parts.slice(4).join("|");
        otpLogBox.append("ID: " + id + " | Phone: " + phone + " | Region: " + region + " | " + status + "<br>");
      } else if (type === "STATUS") {
        otpLogBox.append("STATUS: " + parts.slice(1).join("|").replace(/\\n/g, "<br>") + "<br>");
      } else if (type === "COUNTERS") {
        otpLogBox.append("COUNTERS: " + parts.slice(1).join("|").replace(/\\n/g, "<br>") + "<br>");
        // Update global counters (simplified parsing)
        var counterText = parts.slice(1).join("|").replace(/\\n/g, "<br>");
        var otpSentMatch = counterText.match(/Total OTP sent: (\d+)/);
        var regionsProcessedMatch = counterText.match(/Regions processed: (\d+)/);
        if (otpSentMatch) {
          globalCounters.totalOtpSent = parseInt(otpSentMatch[1]);
        }
        if (regionsProcessedMatch) {
          globalCounters.totalRegionsProcessed = parseInt(regionsProcessedMatch[1]);
        }
        updateGlobalCountersDisplay();
        // Update individual OTP stats for this account.
        $('#otp_stats_' + accountId).html(counterText);
      } else if (type === "SUMMARY") {
        otpLogBox.append("SUMMARY: " + parts.slice(1).join("|").replace(/\\n/g, "<br>") + "<br>");
      }
    };
    evtSource.onerror = function() {
      otpLogBox.append("An error occurred with the SSE connection.<br>");
      evtSource.close();
    };
  });
  
  // Global start OTP process for all child accounts.
  $('#global_start_otp').click(function(){
    var globalSetId = $('#global_set_id').val();
    if (!globalSetId) {
      alert("Please select a global set.");
      return;
    }
    $('.start-otp').each(function(){
      $(this).trigger('click');
    });
  });
});
</script>
</body>
</html>
