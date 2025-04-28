<?php
// brs.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('../db.php'); // This file must initialize your $pdo connection

// Ensure account ID is provided via GET
if (!isset($_GET['ac_id'])) {
  echo "Account ID required.";
  exit;
}

$id = htmlspecialchars($_GET['ac_id']);
$parent_id = htmlspecialchars($_GET['parrent_id']);

// Fetch AWS credentials for the provided account ID from child_accounts table
$stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
  echo "Account not found.";
  exit;
}

$accountId = $id; // using provided account id

// Set the timezone to Asia/Karachi and define current timestamp
date_default_timezone_set('Asia/Karachi');
$currentTimestamp = date('Y-m-d H:i:s');

// Retrieve AWS keys from child_accounts
$aws_key    = $account['aws_access_key'];
$aws_secret = $account['aws_secret_key'];

// STREAMING MODE: If stream=1 is present, run the SSE loop.
if (isset($_GET['stream'])) {
  if (!isset($_GET['set_id']) || intval($_GET['set_id']) <= 0) {
    echo "No set selected.";
    exit;
  }
  $set_id = intval($_GET['set_id']);

  // Retrieve language parameter from GET (default to "es-419")
  $language = isset($_GET['language']) ? trim($_GET['language']) : "es-419";

  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  while (ob_get_level()) {
    ob_end_flush();
  }
  set_time_limit(0);
  ignore_user_abort(true);

  function sendSSE($type, $message)
  {
    echo "data:" . $type . "|" . str_replace("\n", "\\n", $message) . "\n\n";
    flush();
  }

  sendSSE("STATUS", "Starting Bulk Regional Patching Process for Set ID: " . $set_id);

  // List of regions for BRS processing
  $regions = array(
    "me-central-1",
    "ap-southeast-3",
    "ap-southeast-4",
    "eu-south-2",
    "eu-central-2",
    "ap-south-2"
  );
  $totalRegions = count($regions);
  $totalSuccess = 0;
  $usedRegions = 0;

  $internal_call = true;
  require_once('region_ajax_handler_brs.php');

  foreach ($regions as $region) {
    $usedRegions++;
    sendSSE("STATUS", "Moving to region: " . $region);
    sendSSE("COUNTERS", "Total Patch Done: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));

    // Fetch allowed phone numbers based solely on the set_id
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

    // Build patch tasks:
    // If six or more numbers: add first five once and sixth twice (7 tasks total)
    $otpTasks = array();
    if (count($allowedNumbers) >= 6) {
      for ($i = 0; $i < 5; $i++) {
        $otpTasks[] = array('id' => $allowedNumbers[$i]['id'], 'phone' => $allowedNumbers[$i]['phone_number']);
      }
      // Add the 6th number twice.
      $otpTasks[] = array('id' => $allowedNumbers[5]['id'], 'phone' => $allowedNumbers[5]['phone_number']);
      $otpTasks[] = array('id' => $allowedNumbers[5]['id'], 'phone' => $allowedNumbers[5]['phone_number']);
    } else {
      // For fewer than 6 numbers, add all numbers once.
      foreach ($allowedNumbers as $number) {
        $otpTasks[] = array('id' => $number['id'], 'phone' => $number['phone_number']);
      }
    }

    $otpSentInThisRegion = false;
    $verifDestError = false;

    foreach ($otpTasks as $task) {
      sendSSE("STATUS", "[$region] Sending Patching...");
      $sns = initSNS($aws_key, $aws_secret, $region);
      if (is_array($sns) && isset($sns['error'])) {
        sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Failed: " . $sns['error']);
        continue;
      }
      // Pass the language parameter to send_otp_single
      $result = send_otp_single($task['id'], $task['phone'], $region, $aws_key, $aws_secret, $pdo, $sns, $language);
      if ($result['status'] === 'success') {
        sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Sent");
        $totalSuccess++;
        $otpSentInThisRegion = true;
        sendSSE("COUNTERS", "Total Patch Done: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));
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
      sleep(5);
    } else if ($otpSentInThisRegion) {
      sendSSE("STATUS", "Completed Patch sending for region $region. Waiting 15 seconds...");
      sleep(15);
    } else {
      sendSSE("STATUS", "Completed Patch sending for region $region. Waiting 5 seconds...");
      sleep(5);
    }
  }

  $summary = "Final Summary:<br>Total Patch sent: $totalSuccess<br>Regions processed: $usedRegions<br>Remaining regions: " . ($totalRegions - $usedRegions);
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 20px;
      background: #f7f7f7;
    }

    .container {
      max-width: auto;
      margin: auto;
      background: #fff;
      padding: 20px;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
      border-radius: 5px;
    }

    h1,
    h2 {
      text-align: center;
      color: #333;
    }

    label {
      font-weight: bold;
      margin-top: 10px;
      display: block;
    }

    input,
    textarea,
    select,
    button {
      width: 100%;
      padding: 10px;
      margin: 10px 0;
      border-radius: 4px;
      border: 1px solid #ccc;
      box-sizing: border-box;
    }

    button {
      background: #007bff;
      color: #fff;
      border: none;
      cursor: pointer;
      font-size: 16px;
    }

    button:disabled {
      background: #6c757d;
      cursor: not-allowed;
    }

    .message {
      padding: 10px;
      border-radius: 5px;
      margin: 10px 0;
      display: none;
    }

    .success {
      background: #d4edda;
      color: #155724;
    }

    .error {
      background: #f8d7da;
      color: #721c24;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    table,
    th,
    td {
      border: 1px solid #ccc;
    }

    th,
    td {
      padding: 5px;
      text-align: center;
    }

    th {
      background: #f4f4f4;
    }

    #counters {
      background: #eee;
      color: #333;
      padding: 5px 10px;
      margin: 10px 0;
      font-weight: bold;
      text-align: center;
      font-size: 14px;
      border: 1px solid #ccc;
      border-radius: 3px;
      display: inline-block;
      width: auto;
    }

    /* Layout rows for grouped fields */
    .row {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
    }

    .row .column {
      flex: 1;
      min-width: 200px;
    }
  </style>
</head>

<body>
  <div class="container-fluid">
    <div class="row">
      <div class="col-md-4">
        <div class="container">
          <h1>Region Enable Box</h1>
          <button id="enableRegionsButton" class="btn btn-primary mb-3">
            Enable All Opt‑In Regions
          </button>

          <table id="regions-status-table" class="table table-bordered">
            <thead>
              <tr>
                <th>Region</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>

        </div>
      </div>
      <div class="col-md-7">
        <div class="container">
          <h1>Bulk Regional Patch Sending</h1>
          <?php
          // Fetch available sets from bulk_sets table (only fresh sets)
          $stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets WHERE status = 'fresh' ORDER BY set_name ASC");
          $sets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <form id="bulk-regional-otp-form">
            <!-- First row: Select Set and Select Language -->
            <div class="row">
              <div class="column">
                <label for="set_id">Select Set:</label>
                <select id="set_id" name="set_id" required>
                  <option value="">-- Select a Set --</option>
                  <?php foreach ($sets as $set): ?>
                    <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="column">
                <label for="language_select">Select Language:</label>
                <select id="language_select" name="language_select">
                  <option value="es-419" selected>Spanish Latin America</option>
                  <option value="en-US">English (US)</option>
                  <!-- Add additional languages as needed -->
                </select>
              </div>
            </div>
            <!-- Second row: AWS Key and AWS Secret -->
            <div class="row">
              <div class="column">
                <label for="awsKey">AWS Key:</label>
                <input type="text" id="awsKey" name="awsKey" value="<?php echo $aws_key; ?>" disabled>
              </div>
              <div class="column">
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
      </div>
    </div>
  </div>
  <script>
    $(document).ready(function() {
      // Output the account ID as a string to preserve any leading zeros.
      var acId = "<?php echo $id; ?>";
      var evtSource;

      // When a set is selected, fetch allowed numbers for that set via AJAX.
      $('#set_id').change(function() {
        var set_id = $(this).val();
        if (!set_id) {
          $('#numbers').val('');
          return;
        }
        $.ajax({
          url: 'region_ajax_handler_brs.php',
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'fetch_numbers',
            region: 'dummy',
            set_id: set_id
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

        // Build SSE URL with selected set_id and language
        var language = $('#language_select').val();
        var sseUrl = "brs.php?ac_id=" + acId + "&set_id=" + set_id + "&stream=1&language=" + language;
        evtSource = new EventSource(sseUrl);
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
    });
  </script>

  <script>
    $(function() {
      const acId = <?php echo $id; ?>;
      const userId = <?php echo $parent_id; ?>;
      const regions = [
        "me-central-1",
        "ap-southeast-3", "ap-southeast-4",
        "eu-south-2", "eu-central-2",
        "ap-south-2"
      ];
      const maxConcurrent = 6;
      const delayMs = 2000; // 2 seconds
      const pollIntervals = {};
      let queue = [];
      let activeCount = 0;

      $('#enableRegionsButton').on('click', () => {
        const $tbody = $('#regions-status-table tbody').empty();
        queue = regions.slice(); // clone
        activeCount = 0;

        // Kick off the loop
        scheduleNext($tbody);
      });

      /**
       * Tries to start _one_ region; then always re‑schedules itself after delayMs.
       * Stops only when both the queue is empty AND there are no active polls.
       */
      function scheduleNext($tbody) {
        // If we have capacity and work to do, start one
        if (activeCount < maxConcurrent && queue.length > 0) {
          const region = queue.shift();
          checkAndSubmit(region, $tbody);
        }

        // Continue looping until completely done
        if (queue.length > 0 || activeCount > 0) {
          setTimeout(() => scheduleNext($tbody), delayMs);
        }
      }

      function checkAndSubmit(region, $tbody) {
        let $row = $tbody.find(`tr[data-region="${region}"]`);
        if (!$row.length) {
          $tbody.append(`
        <tr data-region="${region}">
          <td>${region}</td>
          <td class="status">Checking…</td>
        </tr>
      `);
          $row = $tbody.find(`tr[data-region="${region}"]`);
        }
        const $status = $row.find('.status');

        // 1️⃣ Check if already enabled
        $.post(
            `region_enable_handler.php?ac_id=${acId}&user_id=${userId}`, {
              action: 'check_region_status',
              region
            },
            'json'
          )
          .done(data => {
            if (data.success && data.status === 'ENABLED') {
              $status.text('Already Enabled');
              // No slot consumed, next will fire in scheduleNext()
            } else {
              // 2️⃣ Submit enable request
              $status.text('Submitted, Waiting…');
              $.post(
                  `region_enable_handler.php?ac_id=${acId}&user_id=${userId}`, {
                    action: 'enable_region',
                    region
                  },
                  'json'
                )
                .done(() => {
                  // Consume a slot for polling
                  activeCount++;
                  startPolling(region, $status, $tbody);
                })
                .fail(() => {
                  $status.text('Enable Error');
                  // slot never used; we'll get next in the scheduleNext loop
                });
            }
          })
          .fail(() => {
            $status.text('Check Error');
            // on error we simply let scheduleNext() fire next time
          });
      }

      /**
       * Polls every 40 s until status == ENABLED, then frees up a slot.
       */
      function startPolling(region, $status, $tbody) {
        if (pollIntervals[region]) {
          clearInterval(pollIntervals[region]);
        }
        pollIntervals[region] = setInterval(() => {
          $.post(
              `region_enable_handler.php?ac_id=${acId}&user_id=${userId}`, {
                action: 'check_region_status',
                region
              },
              'json'
            )
            .done(data => {
              if (data.success && data.status === 'ENABLED') {
                clearInterval(pollIntervals[region]);
                $status.text('Enabled Successfully');
                activeCount--;
                // Next slot opens; next scheduleNext() (if pending) will pick it up
              } else {
                $status.text(`Still Enabling…(${data.status})`);
              }
            })
            .fail(() => {
              $status.text('Poll Error');
            });
        }, 40000);
      }
    });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js" integrity="sha384-k6d4wzSIapyDyv1kpU366/PK5hCdSbCRGRCMv+eplOQJWyd1fbcAu9OCUj5zNLiq" crossorigin="anonymous"></script>

</body>

</html>