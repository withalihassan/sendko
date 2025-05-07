<?php
// chk_quality.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('../db.php'); // This file must initialize your $pdo connection

// ============================================================
// Handle worth_type update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_worth') {
    if (!isset($_GET['ac_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Account ID missing']);
        exit;
    }
    $ac_id = htmlspecialchars($_GET['ac_id']);
    $worth_type = $_POST['worth_type'] ?? '';
    if (!in_array($worth_type, ['full', 'half'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid worth type']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE child_accounts SET worth_type = ? WHERE account_id = ?");
    $stmt->execute([$worth_type, $ac_id]);
    echo json_encode(['status' => 'success', 'message' => "Child account marked as $worth_type"]);
    exit;
}

// ============================================================
// Ensure account ID is provided via GET
if (!isset($_GET['ac_id'])) {
    echo "Account ID required.";
    exit;
}
$id = htmlspecialchars($_GET['ac_id']);

// Fetch AWS credentials for the provided account ID from child_accounts table
$stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    echo "Account not found.";
    exit;
}
$accountId = $id; // using provided account id

// Set the timezone to Asia/Karachi
date_default_timezone_set('Asia/Karachi');
$currentTimestamp = date('Y-m-d H:i:s');

// Retrieve AWS keys from child_accounts
$aws_key    = $account['aws_access_key'];
$aws_secret = $account['aws_secret_key'];

// ============================================================
// STREAMING MODE: Run the SSE loop if stream=1 is provided.
if (isset($_GET['stream'])) {
    if (!isset($_GET['set_id']) || intval($_GET['set_id']) <= 0) {
        echo "No set selected.";
        exit;
    }
    if (!isset($_GET['region']) || !in_array($_GET['region'], ['us-east-1', 'us-east-2'])) {
        echo "No valid region selected.";
        exit;
    }
    $set_id = intval($_GET['set_id']);
    $selectedRegion = $_GET['region'];
    // Retrieve language from GET; default to "es-419" if not provided.
    $language = isset($_GET['language']) ? trim($_GET['language']) : "es-419";

    // Turn off output buffering and enable implicit flush.
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_implicit_flush(true);
    
    // Send SSE headers.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    
    function sendSSE($type, $message) {
        echo "data:" . $type . "|" . str_replace("\n", "\\n", $message) . "\n\n";
        @flush();
    }
    
    sendSSE("STATUS", "Starting Bulk Regional Patch Process for Set ID: " . $set_id . " in region: " . $selectedRegion);
    $region = $selectedRegion;
    sendSSE("STATUS", "Processing region: " . $region);
    sendSSE("COUNTERS", "Processing region: " . $region);
    
    $otpSentInThisRegion = false;
    $verifDestError = false;
    
    $internal_call = true;
    require_once('region_ajax_handler_chk.php');
    
    // Fetch phone numbers based solely on the set_id for the selected region.
    $numbersResult = fetch_numbers($region, $pdo, $set_id);
    if (isset($numbersResult['error'])) {
        sendSSE("STATUS", "Error fetching numbers for region " . $region . ": " . $numbersResult['error']);
        sleep(5);
        exit;
    }
    $allowedNumbers = $numbersResult['data'];
    if (empty($allowedNumbers)) {
        sendSSE("STATUS", "No allowed numbers found in region: " . $region);
        sleep(5);
        exit;
    }
    
    // Build OTP tasks.
    // If six or more numbers exist, add the first five once and the sixth number twice (7 tasks total).
    if (count($allowedNumbers) >= 6) {
        $otpTasks = array();
        for ($i = 0; $i < 5; $i++) {
            $otpTasks[] = array('id' => $allowedNumbers[$i]['id'], 'phone' => $allowedNumbers[$i]['phone_number']);
        }
        $otpTasks[] = array('id' => $allowedNumbers[5]['id'], 'phone' => $allowedNumbers[5]['phone_number']);
        $otpTasks[] = array('id' => $allowedNumbers[5]['id'], 'phone' => $allowedNumbers[5]['phone_number']);
    } else {
        // Otherwise, add each allowed number once.
        $otpTasks = array();
        foreach ($allowedNumbers as $number) {
            $otpTasks[] = array('id' => $number['id'], 'phone' => $number['phone_number']);
        }
    }
    
    // Process OTP tasks.
    foreach ($otpTasks as $task) {
        sendSSE("STATUS", "[$region] Sending Patch...");
        $sns = initSNS($aws_key, $aws_secret, $region);
        if (is_array($sns) && isset($sns['error'])) {
            sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Failed: " . $sns['error']);
            continue;
        }
        // Pass the language parameter to send_otp_single.
        $result = send_otp_single($task['id'], $task['phone'], $region, $aws_key, $aws_secret, $pdo, $sns, $language);
        if ($result['status'] === 'success') {
            sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Sent");
            $otpSentInThisRegion = true;
            sendSSE("COUNTERS", "Patch sent in region: " . $region);
            usleep(2500000);
        } else if ($result['status'] === 'skip') {
            sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Skipped: " . $result['message']);
        } else if ($result['status'] === 'error') {
            sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Failed: " . $result['message']);
            if (strpos($result['message'], "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
                $verifDestError = true;
                sendSSE("STATUS", "[$region] VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error encountered. Skipping region.");
                break;
            } else if (
                strpos($result['message'], "Access Denied") !== false ||
                strpos($result['message'], "Region Restricted") !== false
            ) {
                sendSSE("STATUS", "[$region] Critical error (" . $result['message'] . "). Skipping region.");
                break;
            } else {
                sleep(3);
            }
        }
    }
    if ($verifDestError) {
        sendSSE("STATUS", "Region $region encountered an error. Waiting 5 seconds...");
        sleep(3);
    } else if ($otpSentInThisRegion) {
        sendSSE("STATUS", "Completed Patch sending for region $region. Waiting 15 seconds...");
        sleep(15);
    } else {
        sendSSE("STATUS", "Completed Patch sending for region $region. Waiting 5 seconds...");
        sleep(3);
    }
    
    $summary = "Final Summary:<br>Patch sent in region: $region<br>";
    sendSSE("SUMMARY", $summary);
    sendSSE("STATUS", "Process Completed.");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?php echo $id; ?> | Bulk Regional Patch Sending</title>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f7f7f7; }
    .container { max-width: 800px; margin: auto; background: #fff; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); border-radius: 5px; }
    h1, h2 { text-align: center; color: #333; }
    label { font-weight: bold; margin-top: 10px; display: block; }
    input, textarea, select, button { width: 100%; padding: 10px; margin: 10px 0; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; }
    button { background: #007bff; color: #fff; border: none; cursor: pointer; font-size: 16px; }
    button:disabled { background: #6c757d; cursor: not-allowed; }
    .message { padding: 10px; border-radius: 5px; margin: 10px 0; display: none; }
    .success { background: #d4edda; color: #155724; }
    .error { background: #f8d7da; color: #721c24; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    table, th, td { border: 1px solid #ccc; }
    th, td { padding: 5px; text-align: center; }
    th { background: #f4f4f4; }
    #counters { background: #eee; color: #333; padding: 5px 10px; margin: 10px 0; font-weight: bold; text-align: center; font-size: 14px; border: 1px solid #ccc; border-radius: 3px; display: inline-block; width: auto; }
    .inline-group {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .inline-group > div {
      flex: 1;
      min-width: 200px;
    }
    .inline-buttons { text-align: center; margin-bottom: 20px; }
    .inline-buttons button { width: auto; margin: 0 10px; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Bulk Regional Patch Sending</h1>
    <div class="inline-buttons">
      <button id="mark-full">Mark it Full</button>
      <button id="mark-half">Mark it Half</button>
    </div>
    <!-- Display response for worth_type update -->
    <div id="worth-response" class="message"></div>
    <?php
    // Fetch available sets from bulk_sets.
    $stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets WHERE status = 'fresh' ORDER BY set_name ASC");
    $sets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <form id="bulk-regional-otp-form">
      <!-- Inline group for Set, Region, and Language -->
      <div class="inline-group">
        <div>
          <label for="set_id">Select Set:</label>
          <select id="set_id" name="set_id" required>
            <option value="">-- Select a Set --</option>
            <?php foreach ($sets as $set): ?>
              <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="region">Select Region:</label>
          <select id="region" name="region" required>
            <option value="us-east-1" selected>us-east-1</option>
            <option value="us-east-2">us-east-2</option>
          </select>
        </div>
        <div>
          <label for="language">Select Language:</label>
          <select id="language" name="language" required>
            <option value="en-US">English (US)</option>
            <option value="es-419" selected>Spanish (Latin America)</option>
            <!-- Add more languages if needed -->
          </select>
        </div>
      </div>
      
      <!-- Inline group for AWS Credentials -->
      <div class="inline-group">
        <div>
          <label for="awsKey">AWS Key:</label>
          <input type="text" id="awsKey" name="awsKey" value="<?php echo $aws_key; ?>" disabled>
        </div>
        <div>
          <label for="awsSecret">AWS Secret:</label>
          <input type="text" id="awsSecret" name="awsSecret" value="<?php echo $aws_secret; ?>" disabled>
        </div>
      </div>
      
      <button type="button" id="start-bulk-regional-otp">Start Bulk Patch Process for Selected Set</button>
    </form>

    <!-- Display area for allowed phone numbers (read-only) -->
    <label for="numbers">Allowed Phone Numbers (from database):</label>
    <textarea id="numbers" name="numbers" rows="10" readonly></textarea>

    <!-- Status messages -->
    <div id="process-status" class="message"></div>

    <!-- Live Counters -->
    <h2>Live Counters</h2>
    <div id="counters"></div>

    <!-- Table of Patch events -->
    <h2>Patch Events</h2>
    <table id="sent-numbers-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Phone Number</th>
          <th>Region</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <!-- Final Summary -->
    <h2>Final Summary</h2>
    <div id="summary"></div>
  </div>

  <script>
    $(document).ready(function() {
      var acId = "<?php echo $id; ?>";
      
      // Fetch allowed numbers when set or region changes.
      $('#set_id, #region, #language').change(function() {
        var set_id = $('#set_id').val();
        var region = $('#region').val();
        var language = $('#language').val();
        if (!set_id) {
          $('#numbers').val('');
          return;
        }
        $.ajax({
          url: 'region_ajax_handler_chk.php',
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'fetch_numbers',
            region: region,
            set_id: set_id,
            language: language
          },
          success: function(response) {
            if (response.status === 'success') {
              var displayText = "";
              response.data.forEach(function(item) {
                displayText += "ID: " + item.id + " | Phone: " + item.phone_number + " | ATM Left: " + item.atm_left + " | Date: " + item.formatted_date + "\n";
              });
              $('#numbers').val(displayText);
            } else {
              $('#numbers').val('Error: ' + response.message);
            }
          },
          error: function(xhr, status, error) {
            $('#numbers').val('AJAX error: ' + error);
          }
        });
      });
      
      $('#start-bulk-regional-otp').click(function() {
        var set_id = $('#set_id').val();
        var region = $('#region').val();
        var language = $('#language').val();
        if (!set_id) {
          alert("Please select a set.");
          return;
        }
        $(this).prop('disabled', true);
        $('#process-status').removeClass('error').removeClass('success').text('');
        $('#numbers').val('');
        $('#sent-numbers-table tbody').html('');
        $('#summary').html('');
        $('#counters').html('');
        
        // Start SSE connection; pass language as GET parameter.
        var sseUrl = "chk_quality.php?ac_id=" + acId + "&set_id=" + set_id + "&region=" + region + "&language=" + language + "&stream=1";
        var evtSource = new EventSource(sseUrl);
        evtSource.onmessage = function(e) {
          var data = e.data;
          var parts = data.split("|");
          var type = parts[0];
          if (type === "ROW") {
            var id = parts[1];
            var phone = parts[2];
            var region = parts[3];
            var status = parts.slice(4).join("|");
            var row = '<tr><td>' + id + '</td><td>' + phone + '</td><td>' + region + '</td><td>' + status + '</td></tr>';
            $('#sent-numbers-table tbody').append(row);
          } else {
            var content = parts.slice(1).join("|").replace(/\\n/g, "<br>");
            if (type === "STATUS") {
              $('#process-status').text(content).show();
            } else if (type === "COUNTERS") {
              $('#counters').html(content);
            } else if (type === "SUMMARY") {
              $('#summary').html(content);
            }
          }
        };
        evtSource.onerror = function() {
          $('#process-status').text("An error occurred with the SSE connection.").addClass('error').show();
          evtSource.close();
        };
      });
      
      // Handle worth_type update buttons.
      $('#mark-full').click(function() {
        $.ajax({
          url: "chk_quality.php?ac_id=" + acId,
          type: "POST",
          dataType: "json",
          data: { action: "update_worth", worth_type: "full" },
          success: function(response) {
            if (response.status === "success") {
              $('#worth-response').removeClass('error').addClass('success').text(response.message).show();
            } else {
              $('#worth-response').removeClass('success').addClass('error').text(response.message).show();
            }
          },
          error: function(xhr, status, error) {
            $('#worth-response').removeClass('success').addClass('error').text("AJAX error: " + error).show();
          }
        });
      });
      
      $('#mark-half').click(function() {
        $.ajax({
          url: "chk_quality.php?ac_id=" + acId,
          type: "POST",
          dataType: "json",
          data: { action: "update_worth", worth_type: "half" },
          success: function(response) {
            if (response.status === "success") {
              $('#worth-response').removeClass('error').addClass('success').text(response.message).show();
            } else {
              $('#worth-response').removeClass('success').addClass('error').text(response.message).show();
            }
          },
          error: function(xhr, status, error) {
            $('#worth-response').removeClass('success').addClass('error').text("AJAX error: " + error).show();
          }
        });
      });
    });
  </script>
</body>
</html>
