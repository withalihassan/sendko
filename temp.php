below i have a file that create a child account in aws organization and then by using button of fetch accounnts am fetch and insert in database Now i want to automate the child account creation process the total child accoun that. i wantt to create is 9 and first. account will be crreated manually and rest of 8 accounts will be created automatically 
forr account creation prrocess we need a two vallues namee and email so you have to generate random but. Meangfull name and use it as child account name 
and the accoun that. am creaed manuall use email of that account and use it using below. method 
Orriginall email used to create first manual child account
miwec97077@lassora.com	(just forr example yyou have to fetch it from database)
Remaning Emails will be created in below way 
miwec97077+1@lassora.com	, miwec97077+2@lassora.com, miwec97077+3@lassora.com	upto 
miwec97077+@lassora.com	

and add. onnebutton. when it is pressed starrt. creation of accounts and make sure to add 5 second delay between each account creation 
and makesure that already. available functionality should remain workinng proper 
Below is my files 
URL: http://localhost/sendko/awsch/account_details.php?ac_id=585768178888&user_id=12

account_details.php
<?php
require '../db.php';

if (!isset($_GET['ac_id'])) {
    die("Invalid account ID.");
}

$accountId = htmlspecialchars($_GET['ac_id']);
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
            <div class="alert alert-info">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <!-- Form to Add Child Account -->
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
                    <button type="submit" class="btn btn-primary">Add Account</button>
                </form>
            </div>
        </div>

        <!-- Buttons for various actions -->
        <div class="card mt-4">
            <div class="card-body">
                <a target="_blank" href="./child/delete_all_child.php?parent_id=<?php echo $accountId; ?>">
                    <button type="button" class="btn btn-danger">Delete All Mini Accounts</button>
                </a>
                <button id="fetchExistingAccounts" class="btn btn-secondary">Fetch Existing Mini Accounts</button>
                <button id="refresh" class="btn btn-success">Refresh</button>
                <!-- New button for creating an organization in AWS parent account -->
                <button id="createOrg" class="btn btn-primary">Create Organization</button>
                <!-- Div to display responses from Create Organization -->
                <div id="orgResponse" class="mt-2"></div>
            </div>
        </div>

        <!-- Table to Display Existing Child Accounts -->
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
                            <td colspan="4" class="text-center">Loading accounts...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- jQuery CDN -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>

    <!-- Pass the PHP parent account ID to JS -->
    <script>
        var parentAccountId = "<?php echo $accountId; ?>";

        document.getElementById("refresh").addEventListener("click", function() {
            location.reload();
        });

        // Event handler for Create Organization button
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
    </script>

    <!-- Include the external JS files for AJAX actions -->
    <script src="child/scripts.js"></script>
    <script src="child/existac.js"></script>
</body>

</html>

Scripts.js
$(document).ready(function () {
    // Use the parentAccountId from the PHP variable passed to JS
    var parentAccountId = window.parentAccountId;

    // Load existing child accounts
    function loadChildAccounts() {
        $.ajax({
            url: 'child/fetch_child_accounts.php',
            type: 'GET',
            data: { parent_id: parentAccountId },
            success: function (response) {
                $('#childAccountsTable').html(response);
            },
            error: function () {
                alert('Failed to fetch child accounts.');
            }
        });
    }

    loadChildAccounts(); // Initial load

    // Add Child Account Form Submission
    $('#addChildAccountForm').submit(function (e) {
        e.preventDefault();

        let email = $('#email').val();
        let name = $('#name').val();

        $.ajax({
            url: 'child/add_child_account.php',
            type: 'POST',
            data: { parent_id: parentAccountId, email: email, name: name },
            success: function (response) {
                alert(response);
                $('#addChildAccountForm')[0].reset();
                loadChildAccounts();
            },
            error: function () {
                alert('Failed to add child account.');
            }
        });
    });
});

add_child_account.php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../../db.php';  // Include the database connection file (provides $pdo)
require '../../aws/aws-autoloader.php'; // Include the AWS SDK autoloader

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

if (isset($_POST['parent_id'], $_POST['email'], $_POST['name'])) {
    $parentId = $_POST['parent_id'];
    $email = $_POST['email'];
    $name = $_POST['name'];

    // Get parent account credentials from the "accounts" table using $pdo.
    $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE account_id = ?");
    $stmt->execute([$parentId]);
    $parentAccount = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($parentAccount) {
        $awsKey = $parentAccount['aws_key'];
        $awsSecret = $parentAccount['aws_secret'];

        try {
            // Initialize AWS Organizations Client using the fetched credentials.
            $orgClient = new OrganizationsClient([
                'version' => 'latest',
                'region'  => 'us-east-1',
                'credentials' => [
                    'key'    => $awsKey,
                    'secret' => $awsSecret
                ]
            ]);

            // Create AWS child account.
            $result = $orgClient->createAccount([
                'AccountName' => $name,
                'Email'       => $email
            ]);

            // The AWS response is asynchronous. If available, capture the AccountId from CreateAccountStatus.
            $accountId = isset($result['CreateAccountStatus']['AccountId']) ? $result['CreateAccountStatus']['AccountId'] : null;
            $status = 'Pending';

            if ($accountId) {
                // Insert the child account record into the database.
                $insert = $pdo->prepare("INSERT INTO child_accounts (parent_id, email, account_id, status) VALUES (?, ?, ?, ?)");
                $insert->execute([$parentId, $email, $accountId, $status]);
                echo "Child account created successfully!";
            } else {
                echo "Child account creation initiated, but AccountId not available yet.";
            }
        } catch (AwsException $e) {
            echo "Error: " . $e->getAwsErrorMessage();
        }
    } else {
        echo "Parent account not found.";
    }
} else {
    echo "Missing parameters.";
}
?>

Note: makesure. to rewrite me complete file again and also display the above functionality i need is mustt display ajax rresponse of child account creation prrocess