<?php
// account_details.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// date_default_timezone_set('Asia/Karachi');
require '../db.php';
require '../aws/aws-autoloader.php';

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

// Check for required GET parameter (parent account id)
if (!isset($_GET['ac_id'])) {
  die("Invalid account ID.");
}

$accountId = htmlspecialchars($_GET['ac_id']);

// Handle AJAX POST request for creating a child account.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_account') {
  $parentId = $_POST['parent_id'] ?? '';
  $email    = $_POST['email'] ?? '';
  $name     = $_POST['name'] ?? '';

  if (!$parentId || !$email || !$name) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
  }

  // Fetch parent's AWS credentials from the "accounts" table.
  $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE account_id = ?");
  $stmt->execute([$parentId]);
  $parentAccount = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($parentAccount) {
    $awsKey    = $parentAccount['aws_key'];
    $awsSecret = $parentAccount['aws_secret'];

    try {
      // Initialize AWS Organizations Client using the parent's credentials.
      $orgClient = new OrganizationsClient([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'credentials' => [
          'key'    => $awsKey,
          'secret' => $awsSecret
        ]
      ]);

      // Call AWS Organizations createAccount API.
      $result = $orgClient->createAccount([
        'AccountName' => $name,
        'Email'       => $email
      ]);

      // Capture the AccountId if available. Otherwise, use a placeholder.
      $childAccountId = isset($result['CreateAccountStatus']['AccountId']) ? $result['CreateAccountStatus']['AccountId'] : null;
      if ($childAccountId === null) {
        $childAccountId = "pending";
      }

      // Return the API response without storing anything in the database.
      if ($childAccountId !== "pending") {
        echo json_encode(['status' => 'success', 'message' => 'Child account created successfully! AccountId: ' . $childAccountId]);
      } else {
        echo json_encode(['status' => 'success', 'message' => 'Child account creation initiated, AccountId pending.']);
      }
    } catch (AwsException $e) {
      echo json_encode(['status' => 'error', 'message' => $e->getAwsErrorMessage()]);
    }
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Parent account not found.']);
  }
  exit;
}

// For GET requests, fetch one child account email (if available) associated with this parent.
$query = "SELECT email FROM child_accounts WHERE parent_id = :accountId AND account_id != '$accountId' ORDER BY id ASC LIMIT 1";
$stmt  = $pdo->prepare($query);
$stmt->execute(['accountId' => $accountId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
  $childEmail = $row['email'];
} else {
  $childEmail = null;
}

if (isset($_POST['action']) && $_POST['action'] === 'update_account') {
  if ($accountId > 0) {
    // Create a DateTime object for the current Pakistan time
    $currentDateTime = new DateTime('now', new DateTimeZone('Asia/Karachi'));
    $currentTimestamp = $currentDateTime->format('Y-m-d H:i:s');
    $currentDay = $currentDateTime->format('Y-m-d'); // Only the date part

    // Retrieve the last_used value from the database
    $stmt = $pdo->prepare("SELECT last_used FROM accounts WHERE account_id = :id");
    $stmt->execute([':id' => $accountId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && !empty($result['last_used'])) {
      // Convert last_used to Pakistan time and get its date part
      $lastUsedDateTime = new DateTime($result['last_used'], new DateTimeZone('Asia/Karachi'));
      $lastUsedDay = $lastUsedDateTime->format('Y-m-d');

      if ($lastUsedDay === $currentDay) {
        echo json_encode([
          'success' => false,
          'message' => 'This account has already been marked as complete for today. You can only mark it complete after 24 hours.'
        ]);
        exit;
      }
    }

    // Proceed with the update if last_used is not today
    $stmt = $pdo->prepare("UPDATE accounts SET ac_score = ac_score + 1, last_used = :last_used WHERE account_id = :id");
    try {
      $stmt->execute([':last_used' => $currentTimestamp, ':id' => $accountId]);
      echo json_encode([
        'success' => true,
        'message' => 'Account updated successfully.',
        'time' => $currentTimestamp
      ]);
    } catch (PDOException $e) {
      echo json_encode([
        'success' => false,
        'message' => 'Database update failed: ' . $e->getMessage()
      ]);
    }
  } else {
    echo json_encode(['success' => false, 'message' => 'Invalid account ID.']);
  }
  exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'quarantine_account') {
  if ($accountId > 0) {
    // Get the current Pakistan time
    $currentTimestamp = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d H:i:s');

    // Update the account with Pakistan time
    $stmt = $pdo->prepare("UPDATE accounts SET last_used = :last_used , ac_worth='quarantined' WHERE account_id = :id");
    try {
      $stmt->execute([':last_used' => $currentTimestamp, ':id' => $accountId]);
      echo json_encode(['success' => true, 'message' => 'Account Quarantine successfully.', 'time' => $currentTimestamp]);
    } catch (PDOException $e) {
      echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $e->getMessage()]);
    }
  } else {
    echo json_encode(['success' => false, 'message' => 'Invalid account ID.']);
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $accountId; ?> | Manage AWS Nodes</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
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
  </style>
  <script>
    // Set a global variable for the parent account ID.
    window.parentAccountId = "<?php echo $accountId; ?>";
  </script>
</head>

<body>
  <div class="container mt-5">
    <h2>Manage Nodes for Parent ID: <?php echo $accountId; ?></h2>
    <?php if (isset($message)) : ?>
      <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>
    <div class="d-flex align-items-center gap-2">
      <button id="updateButton" class="btn btn-success">Mark as Completed</button>
      <div id="result" class="text-success fw-bold"></div>
      <button id="quarantineButton" class="btn btn-warning">Quarantine For 7 Days</button>
      <div id="quarantineresult" class="text-warning fw-bold"></div>
    </div>

    <!-- Display base child account email or a message if none available -->
    <div class="alert alert-info">
      <?php
      if ($childEmail) {
        echo "Child Account Email: " . htmlspecialchars($childEmail);
      } else {
        echo "No child available.";
      }
      ?>
    </div>

    <!-- Manual form to add a child account -->
    <div class="card mb-4">
      <div class="card-header">Add Mini Account</div>
      <div class="card-body">
        <form id="addChildAccountForm">
          <div class="mb-3">
            <label for="email" class="form-label">Mini Account Email</label>
            <input type="email" class="form-control" id="email" required>
          </div>
          <div class="mb-3">
            <label for="name" class="form-label">Mini Account Name</label>
            <input type="text" class="form-control" id="name" required>
          </div>
          <button type="submit" class="btn btn-primary" id="manualSubmitBtn">Add Account</button>
        </form>
      </div>
    </div>

    <!-- Action buttons -->
    <div class="card mt-4">
      <div class="card-body">
        <a target="_blank" href="./child/delete_all_child.php?parent_id=<?php echo $accountId; ?>">
          <button type="button" class="btn btn-danger">Del All</button>
        </a>
        <button id="fetchExistingAccounts" class="btn btn-secondary">Fetch All</button>
        <button id="refresh" class="btn btn-success">Refresh</button>
        <!-- Existing Create Organization functionality -->
        <button id="createOrg" class="btn btn-primary">Create Org</button>
        <!-- Button for auto-creating additional child accounts -->
        <button id="autoCreate" class="btn btn-info">Auto Create Mini Accounts</button>
        <!-- Button for All Regions Enabled  -->
        <a href="enable_all_regions.php?parent_id=<?php echo $accountId; ?>" target="_blank">
          <button class="btn btn-success">E-R All</button>
        </a>
        <!-- Button for All Regions Clearance  -->
        <a href="clear_all_regions.php?parent_id=<?php echo $accountId; ?>" target="_blank">
          <button class="btn btn-danger">Clear All Regions</button>
        </a>
        <a href="bulk_regional_otp.php?parent_id=<?php echo $accountId; ?>" target="_blank">
          <button class="btn btn-primary">Global Send</button>
        </a>
        <a href="setup_all.php?parent_id=<?php echo $accountId; ?>" target="_blank">
          <button class="btn btn-primary">Setup all</button>
        </a>
        <div id="orgResponse" class="mt-2"></div>
        <!-- Log area for auto-creation status -->
        <div id="autoCreateLog" class="mt-3 border p-2" style="max-height:300px; overflow:auto;"></div>
      </div>
    </div>

    <!-- Table to display existing child accounts -->
    <div class="card">
      <div class="card-header">Existing Child Accounts</div>
      <div class="card-body">
        <table class="table table-bordered">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Email</th>
              <th>Status</th>
              <th>Type</th>
              <th>AWS Account ID</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="childAccountsTable">
            <tr>
              <td colspan="6" class="text-center">Loading accounts...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- jQuery CDN -->
  <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
  <script>
    var parentAccountId = "<?php echo $accountId; ?>";
    // Use the initially fetched child email as the base for auto-generated emails.
    var originalEmail = <?php echo json_encode($childEmail); ?>;

    document.getElementById("refresh").addEventListener("click", function() {
      location.reload();
    });

    // Create Organization button functionality
    $(document).on('click', '#createOrg', function(e) {
      e.preventDefault();
      $("#orgResponse").html("<span class='text-info'>Creating organization...</span>");
      $.ajax({
        url: 'child/create_org.php',
        type: 'GET',
        data: {
          ac_id: parentAccountId
        },
        success: function(response) {
          $("#orgResponse").html("<span class='text-success'>" + response + "</span>");
        },
        error: function() {
          $("#orgResponse").html("<span class='text-danger'>An error occurred while creating the organization.</span>");
        }
      });
    });

    // Generate a random but meaningful name.
    function generateRandomName() {
      var adjectives = ["Brave", "Clever", "Mighty", "Swift", "Sly", "Happy", "Gentle", "Fierce"];
      var nouns = ["Lion", "Tiger", "Eagle", "Falcon", "Shark", "Wolf", "Panther", "Dragon"];
      var adjective = adjectives[Math.floor(Math.random() * adjectives.length)];
      var noun = nouns[Math.floor(Math.random() * nouns.length)];
      return adjective + " " + noun;
    }

    // Auto-create additional child accounts with a 5-second delay between each.
    function autoCreateAccounts() {
      if (!originalEmail) {
        alert("No child available. Please create a child account manually first.");
        return;
      }
      var emailParts = originalEmail.split('@');
      var emailPrefix = emailParts[0];
      var emailDomain = emailParts[1];
      var totalAutoAccounts = 8; // Total additional accounts to create.
      var counter = 1;

      function createAccount() {
        if (counter <= totalAutoAccounts) {
          var newEmail = emailPrefix + "+" + counter + "@" + emailDomain;
          var newName = generateRandomName();
          $("#autoCreateLog").append("<div>Creating account " + counter + ": " + newEmail + " with name: " + newName + "</div>");

          $.ajax({
            url: '', // Current file.
            type: 'POST',
            data: {
              action: 'create_account',
              email: newEmail,
              name: newName,
              parent_id: parentAccountId
            },
            success: function(response) {
              $("#autoCreateLog").append("<div>Response for account " + counter + ": " + response + "</div>");
            },
            error: function() {
              $("#autoCreateLog").append("<div>Error creating account " + counter + "</div>");
            }
          });
          counter++;
          setTimeout(createAccount, 5000);
        } else {
          $("#autoCreateLog").append("<div><strong>All " + totalAutoAccounts + " accounts created.</strong></div>");
          alert(totalAutoAccounts + " accounts created.");
        }
      }
      createAccount();
    }

    // Attach event handler to the Auto Create button.
    $("#autoCreate").click(function(e) {
      e.preventDefault();
      autoCreateAccounts();
    });

    // The manual form submission for adding a child account is now handled exclusively by child/scripts.js.
  </script>
  <!-- Include external JS files -->
  <script src="child/scripts.js"></script>
  <script src="child/existac.js"></script>
  <script>
    // Update account button handler.
    $(document).ready(function() {
      $("#updateButton").click(function() {
        $.ajax({
          url: window.location.href,
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'update_account'
          },
          success: function(response) {
            $("#result").html("<p style='color: " + (response.success ? 'green' : 'red') + ";'>" + response.message + "</p>");
          },
          error: function() {
            $("#result").html("<p style='color: red;'>An error occurred while updating the account.</p>");
          }
        });
      });
    });

    // Quarantine account button handler.
    $(document).ready(function() {
      $("#quarantineButton").click(function() {
        $.ajax({
          url: window.location.href,
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'quarantine_account'
          },
          success: function(response) {
            $("#quarantineresult").html("<p style='color: " + (response.success ? 'green' : 'red') + ";'>" + response.message + "</p>");
          },
          error: function() {
            $("#quarantineresult").html("<p style='color: red;'>An error occurred while updating the account.</p>");
          }
        });
      });
    });
  </script>
</body>

</html>
