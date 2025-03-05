<?php
// bulk_send.php
// Make sure there's no whitespace before this tag.
include('db.php');

// Ensure an account ID is provided via GET
if (!isset($_GET['ac_id'])) {
  echo "No account ID provided.";
  exit;
}

$id = intval($_GET['ac_id']);
$user_id = intval($_GET['user_id']);

// Fetch the AWS key and secret key for the provided account ID
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
  echo "Account not found.";
  exit;
}
$accountId = intval($_GET['ac_id']);
// If an AJAX request is sent for updating the account:
if (isset($_POST['action']) && $_POST['action'] === 'update_account') {
  // Only proceed if a valid account id exists
  if ($accountId > 0) {
    // Prepare the update query:
    // - Set status to 'completed'
    // - Increment ac_score by 1 (assumes ac_score is a non-null numeric field)
    // - Update last_used with the current timestamp
    $stmt = $pdo->prepare("UPDATE accounts 
                               SET ac_score = ac_score + 1, 
                                   last_used = NOW() 
                               WHERE id = :id");
    try {
      $stmt->execute([':id' => $accountId]);
      echo json_encode(['success' => true, 'message' => 'Account updated successfully.']);
    } catch (PDOException $e) {
      echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $e->getMessage()]);
    }
  } else {
    echo json_encode(['success' => false, 'message' => 'Invalid account ID.']);
  }
  exit; // End the script for the AJAX request.
}
// ===================================
$aws_key    = htmlspecialchars($account['aws_key']);
$aws_secret = htmlspecialchars($account['aws_secret']);

// Fetch bulk sets for the dropdown
$stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets ORDER BY set_name ASC");
$sets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>CQ Accounnt ID <?php echo $accountId;?></title>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 20px;
      background: #f7f7f7;
    }

    .container {
      max-width: 700px;
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

    select,
    input,
    textarea,
    #start-bulk-otp {
      width: 100%;
      padding: 10px;
      margin: 10px 0;
      border-radius: 4px;
      border: 1px solid #ccc;
      box-sizing: border-box;
    }

    button {
      width: 40%;
      padding: 3px;
      margin: 10px 0;
      border-radius: 4px;
      border: 1px solid #ccc;
      background: #007bff;
      color: #fff;
      cursor: pointer;
      font-size: 16px;
    }

    textarea {
      resize: vertical;
      background-color: #e9ecef;
      color: #495057;
    }

    #start-bulk-otp {
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

    .timer {
      font-weight: bold;
      margin-top: 10px;
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
      padding: 3px;
      text-align: center;
    }

    th {
      background: #f4f4f4;
    }
  </style>
</head>

<body>
  <div class="container">
    <h1>Check Quality Accounnt ID <?php echo $accountId;?></h1>
    <button id="updateButton">Mark As Completed</button>
    <div id="result"></div>
    <form id="bulk-otp-form">
      <!-- Hidden input to hold the session_id -->
      <input type="hidden" id="session_id" value="<?php echo $user_id; ?>">

      <label for="region">Select Region:</label>
      <select id="region" name="region" required>
        <option value="">-- Select a Region --</option>
        <option value="us-east-1">Virginia (us-east-1)</option>
        <option value="us-east-2">Ohio (us-east-2)</option>
        <option value="us-west-1">California (us-west-1)</option>
        <option value="us-west-2">Oregon (us-west-2)</option>
        <option value="ap-south-1">Mumbai (ap-south-1)</option>
        <option value="ap-northeast-3">Osaka (ap-northeast-3)</option>
        <option value="ap-southeast-1">Singapore (ap-southeast-1)</option>
        <option value="ap-southeast-2">Sydney (ap-southeast-2)</option>
        <option value="ap-northeast-1">Tokyo (ap-northeast-1)</option>
        <option value="ca-central-1">Canada (ca-central-1)</option>
        <option value="eu-central-1">Frankfurt (eu-central-1)</option>
        <option value="eu-west-1">Ireland (eu-west-1)</option>
        <option value="eu-west-2">London (eu-west-2)</option>
        <option value="eu-west-3">Paris (eu-west-3)</option>
        <option value="eu-north-1">Stockholm (eu-north-1)</option>
        <option value="me-central-1">UAE (me-central-1)</option>
        <option value="sa-east-1">Sao Paulo (sa-east-1)</option>
        <option value="af-south-1">Africa (af-south-1)</option>
        <option value="ap-southeast-3">Jakarta (ap-southeast-3)</option>
        <option value="ap-southeast-4">Melbourne (ap-southeast-4)</option>
        <option value="ca-west-1">Calgary (ca-west-1)</option>
        <option value="eu-south-1">Milan (eu-south-1)</option>
        <option value="eu-south-2">Spain (eu-south-2)</option>
        <option value="eu-central-2">Zurich (eu-central-2)</option>
        <option value="me-south-1">Bahrain (me-south-1)</option>
        <option value="il-central-1">Tel Aviv (il-central-1)</option>
        <option value="ap-south-2">Hyderabad (ap-south-2)</option>
      </select>

      <!-- New Dropdown for selecting Set -->
      <label for="set_id">Select Set:</label>
      <select id="set_id" name="set_id" required>
        <option value="">-- Select a Set --</option>
        <?php foreach ($sets as $set): ?>
          <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
        <?php endforeach; ?>
      </select>

      <!-- AWS Credentials (pre-filled and disabled) -->
      <label for="awsKey">AWS Key:</label>
      <input type="text" id="awsKey" name="awsKey" value="<?php echo $aws_key; ?>" disabled>

      <label for="awsSecret">AWS Secret:</label>
      <input type="text" id="awsSecret" name="awsSecret" value="<?php echo $aws_secret; ?>" disabled>

      <label for="numbers">Allowed Phone Numbers (from database):</label>
      <textarea id="numbers" name="numbers" rows="5" readonly></textarea>

      <button type="button" id="start-bulk-otp">Start Bulk OTP Process</button>
    </form>

    <!-- Status / error messages -->
    <div id="process-status" class="message"></div>
    <div id="countdown" class="timer"></div>

    <!-- Table of numbers with OTP sent -->
    <h2>Numbers with OTP Sent</h2>
    <table id="sent-numbers-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Phone Number</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <!-- Rows will be added dynamically -->
      </tbody>
    </table>
  </div>

  <script>
    $(document).ready(function() {
      var allowedNumbers = []; // Array to store allowed numbers
      var timerInterval;
      var totalSuccess = 0; // Total successfully sent OTP count

      // Function to fetch numbers based on region and set
      function fetchNumbers() {
        var region = $('#region').val();
        var set_id = $('#set_id').val();
        var session_id = $('#session_id').val();
        if (region === '' || set_id === '') {
          $('#numbers').val('');
          return;
        }
        $.ajax({
          url: 'ajax_handler.php',
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'fetch_numbers',
            region: region,
            set_id: set_id,
            session_id: session_id
          },
          success: function(response) {
            if (response.status === 'success') {
              allowedNumbers = response.data;
              var displayText = "";
              allowedNumbers.forEach(function(item) {
                displayText += "ID: " + item.id + " | Phone: " + item.phone_number + " | Created At: " + item.created_at + "\n";
              });
              $('#numbers').val(displayText);
            } else {
              $('#numbers').val('Error fetching numbers: ' + response.message);
            }
          },
          error: function(xhr, status, error) {
            $('#numbers').val('AJAX error: ' + error);
          }
        });
      }

      // Bind change events to region and set dropdowns
      $('#region, #set_id').change(function() {
        fetchNumbers();
      });

      // Start bulk OTP process on button click
      $('#start-bulk-otp').click(function() {
        if (allowedNumbers.length === 0) {
          alert("No allowed numbers available. Please select a region and set first.");
          return;
        }

        // AWS credentials are taken from the disabled fields
        var awsKey = $('#awsKey').val();
        var awsSecret = $('#awsSecret').val();
        if (!awsKey || !awsSecret) {
          alert("AWS credentials missing.");
          return;
        }

        // Build OTP tasks:
        // • First allowed number gets 4 OTPs.
        // • Next 9 allowed numbers (if available) get 1 OTP each.
        var otpTasks = [];
        otpTasks.push({
          id: allowedNumbers[0].id,
          phone: allowedNumbers[0].phone_number
        });
        otpTasks.push({
          id: allowedNumbers[0].id,
          phone: allowedNumbers[0].phone_number
        });
        otpTasks.push({
          id: allowedNumbers[0].id,
          phone: allowedNumbers[0].phone_number
        });
        otpTasks.push({
          id: allowedNumbers[0].id,
          phone: allowedNumbers[0].phone_number
        });

        for (var i = 1; i < Math.min(allowedNumbers.length, 10); i++) {
          otpTasks.push({
            id: allowedNumbers[i].id,
            phone: allowedNumbers[i].phone_number
          });
        }

        totalSuccess = 0;
        $('#start-bulk-otp').prop('disabled', true);
        processNextTask(otpTasks, 0, awsKey, awsSecret);
      });

      // Recursive function to process OTP tasks one by one with a 5-second delay.
      function processNextTask(tasks, index, awsKey, awsSecret) {
        if (index >= tasks.length) {
          $('#process-status').removeClass('error').addClass('success')
            .text("All OTPs processed for region " + $('#region').val() + ". Total Successfully Sent OTPs: " + totalSuccess)
            .fadeIn();
          $('#countdown').fadeOut();
          $('#start-bulk-otp').prop('disabled', false);
          return;
        }

        var task = tasks[index];
        $('#process-status').removeClass('error').addClass('success')
          .text("Sending OTP to: " + task.phone)
          .fadeIn();

        $.ajax({
          url: 'ajax_handler.php',
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'send_otp_single',
            id: task.id,
            phone: task.phone,
            region: $('#region').val(),
            awsKey: awsKey,
            awsSecret: awsSecret,
            session_id: $('#session_id').val()
          },
          success: function(response) {
            if (response.status === 'success') {
              addSentNumberRow(task.id, task.phone, 'OTP Sent');
              totalSuccess++;
              var secondsLeft = 5;
              $('#countdown').fadeIn().text("Waiting " + secondsLeft + " seconds before next OTP... (Region: " + response.region + ")");
              timerInterval = setInterval(function() {
                secondsLeft--;
                if (secondsLeft > 0) {
                  $('#countdown').text("Waiting " + secondsLeft + " seconds before next OTP... (Region: " + response.region + ")");
                } else {
                  clearInterval(timerInterval);
                  processNextTask(tasks, index + 1, awsKey, awsSecret);
                }
              }, 1000);
            } else if (response.status === 'skip') {
              addSentNumberRow(task.id, task.phone, 'Skipped: Monthly Spend Limit Reached');
              processNextTask(tasks, index + 1, awsKey, awsSecret);
            } else {
              $('#process-status').removeClass('success').addClass('error')
                .text("Error sending OTP to " + task.phone + ": " + response.message)
                .fadeIn();
              $('#countdown').fadeOut();
              $('#start-bulk-otp').prop('disabled', false);
            }
          },
          error: function(xhr, status, error) {
            $('#process-status').removeClass('success').addClass('error')
              .text("AJAX error: " + error)
              .fadeIn();
            $('#countdown').fadeOut();
            $('#start-bulk-otp').prop('disabled', false);
          }
        });
      }

      // Add a row to the "Numbers with OTP Sent" table
      function addSentNumberRow(id, phone, status) {
        var row = '<tr id="row-' + id + '">' +
          '<td>' + id + '</td>' +
          '<td>' + phone + '</td>' +
          '<td class="row-status">' + status + '</td>' +
          '<td>' +
          '<button class="update-status" data-id="' + id + '" data-status="notverified">Not Verified</button> ' +
          '<button class="update-status" data-id="' + id + '" data-status="verified">Verified</button>' +
          '</td>' +
          '</tr>';
        $('#sent-numbers-table tbody').append(row);
      }

      // Update status for a given row
      $(document).on('click', '.update-status', function() {
        var id = $(this).data('id');
        var newStatus = $(this).data('status');
        $.ajax({
          url: 'ajax_handler.php',
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'update_status',
            id: id,
            new_status: newStatus,
            session_id: $('#session_id').val()
          },
          success: function(response) {
            if (response.status === 'success') {
              $('#row-' + id + ' .row-status').text(newStatus);
              $('#process-status').removeClass('error').addClass('success')
                .text('Status updated successfully: ' + response.message)
                .fadeIn().delay(3000).fadeOut();
            } else {
              $('#process-status').removeClass('success').addClass('error')
                .text('Error updating row: ' + response.message)
                .fadeIn().delay(3000).fadeOut();
            }
          },
          error: function(xhr, status, error) {
            $('#process-status').removeClass('success').addClass('error')
              .text('AJAX error while updating row: ' + error)
              .fadeIn().delay(3000).fadeOut();
          }
        });
      });
    });
  </script>
  <script>
    $(document).ready(function() {
      $("#updateButton").click(function() {
        // Use the full URL (including the GET id) to ensure the account id is passed along
        $.ajax({
          url: window.location.href, // current page URL, including ?id=...
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'update_account'
          },
          success: function(response) {
            if (response.success) {
              $("#result").html("<p style='color: green;'>" + response.message + "</p>");
            } else {
              $("#result").html("<p style='color: red;'>" + response.message + "</p>");
            }
          },
          error: function() {
            $("#result").html("<p style='color: red;'>An error occurred while updating the account.</p>");
          }
        });
      });
    });
  </script>
</body>

</html>