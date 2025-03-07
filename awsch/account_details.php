<?php
// account_details.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$query = "SELECT email FROM child_accounts WHERE parent_id = :accountId AND account_id !='$accountId' ORDER BY id ASC LIMIT 1";
$stmt  = $pdo->prepare($query);
$stmt->execute(['accountId' => $accountId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $childEmail = $row['email'];
} else {
    $childEmail = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $accountId; ?> | Manage AWS Nodes</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
  <h2>Manage Nodes for Parent ID: <?php echo $accountId; ?></h2>
  <?php if (isset($message)) : ?>
    <div class="alert alert-info"><?php echo $message; ?></div>
  <?php endif; ?>

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
        <button type="button" class="btn btn-danger">Delete All Mini Accounts</button>
      </a>
      <button id="fetchExistingAccounts" class="btn btn-secondary">Fetch Existing Mini Accounts</button>
      <button id="refresh" class="btn btn-success">Refresh</button>
      <!-- Existing Create Organization functionality -->
      <button id="createOrg" class="btn btn-primary">Create Organization</button>
      <!-- Button for auto-creating additional child accounts -->
      <button id="autoCreate" class="btn btn-info">Auto Create Mini Accounts</button>
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

  // Create Organization button functionality (existing)
  $(document).on('click', '#createOrg', function(e) {
    e.preventDefault();
    $("#orgResponse").html("<span class='text-info'>Creating organization...</span>");
    $.ajax({
      url: 'child/create_org.php',
      type: 'GET',
      data: { ac_id: parentAccountId },
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
          url: '',  // Current file.
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

  // Manual form submission for adding one account with a 4-second delay between submissions.
  $("#addChildAccountForm").submit(function(e) {
    e.preventDefault();
    var email = $("#email").val();
    var name = $("#name").val();
    var submitButton = $("#manualSubmitBtn");
    submitButton.prop("disabled", true); // Disable to prevent duplicate submissions.
    $.ajax({
      url: '', // Current file.
      type: 'POST',
      data: {
        action: 'create_account',
        email: email,
        name: name,
        parent_id: parentAccountId
      },
      success: function(response) {
        alert("Response: " + response);
      },
      error: function() {
        alert("Error creating account.");
      },
      complete: function() {
        // Re-enable the submit button after 4 seconds.
        setTimeout(function(){
          submitButton.prop("disabled", false);
        }, 4000);
      }
    });
  });
</script>
<!-- Include external JS files if needed -->
<script src="child/scripts.js"></script>
<script src="child/existac.js"></script>
</body>
</html>
