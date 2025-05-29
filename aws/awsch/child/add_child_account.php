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
                // $insert = $pdo->prepare("INSERT INTO child_accounts (parent_id, email, account_id, status) VALUES (?, ?, ?, ?)");
                // $insert->execute([$parentId, $email, $accountId, $status]);
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
