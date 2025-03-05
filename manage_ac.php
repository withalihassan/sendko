<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'db.php';      // Your database connection file
include 'session.php'; // Your session/initialization file

// Ensure an account ID is provided
if (!isset($_GET['ac_id'])) {
  echo "Account ID is required.";
  exit;
}

$ac_id = intval($_GET['ac_id']);

// Fetch AWS credentials for the provided account ID
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$ac_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
  echo "Account not found.";
  exit;
}

$aws_key    = htmlspecialchars($account['aws_key']);
$aws_secret = htmlspecialchars($account['aws_secret']);

// Try to fetch organization and child account records from your DB (if tables exist)
$stmtOrg = $pdo->prepare("SELECT * FROM organizations WHERE account_id = ?");
$stmtOrg->execute([$ac_id]);
$org = $stmtOrg->fetch(PDO::FETCH_ASSOC);

$stmtChild = $pdo->prepare("SELECT * FROM child_accounts WHERE parent_id = ?");
$stmtChild->execute([$ac_id]);
$childAccounts = $stmtChild->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Noder & Mini Accounts</title>
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    .action-buttons .btn { margin: 0 5px; }
  </style>
</head>
<body>
  <?php include 'header.php'; // Your header file ?>
  <div class="container mt-3">
    <h1>Noder & Mini Accounts</h1>
    <!-- Hidden field to store the account ID -->
    <input type="hidden" id="accountId" value="<?php echo $ac_id; ?>">
    
    <!-- Organization Section -->
    <div id="orgSection" class="mb-4">
      <?php if($org): ?>
        <div class="alert alert-success">Noder already exists.</div>
      <?php else: ?>
        <button id="createOrgBtn" class="btn btn-primary">Create Noder</button>
      <?php endif; ?>
    </div>
    
    <hr>
    <hr>
    <!-- Div to display AJAX responses -->
    <div id="ajaxResponse"></div>
  </div>

  <!-- jQuery and Bootstrap JS dependencies -->
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script>
    $(document).ready(function(){
      // Create Organization
      $('#createOrgBtn').click(function(){
        var accountId = $('#accountId').val();
        $.ajax({
          url: 'account_queries/create_organization.php',
          type: 'POST',
          data: { ac_id: accountId },
          dataType: 'json',
          success: function(response){
            $('#ajaxResponse').html('<pre>' + JSON.stringify(response, null, 2) + '</pre>');
            if(response.success){
              $('#orgSection').html('<div class="alert alert-success">Organization created successfully.</div>');
            } else {
              $('#orgSection').html('<div class="alert alert-warning">'+response.message+'</div>');
            }
          },
          error: function(xhr, status, error){
            $('#ajaxResponse').html('Error: ' + error);
          }
        });
      });
      
      // Fetch Existing Accounts (only when button is clicked)
      $('#fetchExistingAccountsBtn').click(function(){
        var accountId = $('#accountId').val();
        $.ajax({
          url: 'account_queries/fetch_existing_accounts.php',
          type: 'POST',
          data: { parent_id: accountId },
          dataType: 'json',
          success: function(response){
            $('#ajaxResponse').html('<pre>' + JSON.stringify(response, null, 2) + '</pre>');
            if(response.success){
              // Optionally reload the page to update the child accounts list
              location.reload();
            }
          },
          error: function(xhr, status, error){
            $('#ajaxResponse').html('Error: ' + error);
          }
        });
      });
      
      // Create Child Account
      $('#childAccountForm').submit(function(e){
        e.preventDefault();
        var accountId = $('#accountId').val();
        var childEmail = $('#childEmail').val();
        var childAccountName = $('#childAccountName').val();
        $.ajax({
          url: 'account_queries/create_child_account.php',
          type: 'POST',
          data: { 
            ac_id: accountId,
            email: childEmail,
            account_name: childAccountName
          },
          dataType: 'json',
          success: function(response){
            $('#ajaxResponse').html('<pre>' + JSON.stringify(response, null, 2) + '</pre>');
            if(response.success){
              // Refresh the page to show updated child accounts list
              location.reload();
            }
          },
          error: function(xhr, status, error){
            $('#ajaxResponse').html('Error: ' + error);
          }
        });
      });
      
      // Setup Child Account (placeholder for future logic)
      $('.setupChildBtn').click(function(){
        var childId = $(this).data('child-id');
        $.ajax({
          url: 'account_queries/setup_child_account.php',
          type: 'POST',
          data: { child_id: childId },
          dataType: 'json',
          success: function(response){
            $('#ajaxResponse').html('<pre>' + JSON.stringify(response, null, 2) + '</pre>');
          },
          error: function(xhr, status, error){
            $('#ajaxResponse').html('Error: ' + error);
          }
        });
      });
    });
  </script>
</body>
</html>
