<?php
require 'db_connect.php';

require './aws/aws-autoloader.php'; // Include the AWS SDK autoloader
use Aws\Sts\StsClient;
use Aws\Exception\AwsException;


if (isset($_POST['account_id'])) {
    $accountId = $_POST['account_id'];

    // Fetch AWS credentials from database
    $stmt = $conn->prepare("SELECT aws_key, aws_secret FROM aws_accounts WHERE id = ?");
    $stmt->execute([$accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($account) {
        $awsKey = $account['aws_key'];
        $awsSecret = $account['aws_secret'];

        try {
            // Initialize STS client with provided credentials
            $stsClient = new StsClient([
                'version' => 'latest',
                'region'  => 'us-east-1',
                'credentials' => [
                    'key'    => $awsKey,
                    'secret' => $awsSecret
                ]
            ]);

            // Make a call to verify credentials
            $result = $stsClient->getCallerIdentity();
            echo "Account is Active";

            // Update status to Active
            $updateStmt = $conn->prepare("UPDATE aws_accounts SET status = 'Active' WHERE id = ?");
            $updateStmt->execute([$accountId]);

        } catch (AwsException $e) {
            echo "Account Suspended";

            // Update status to Suspended in the database
            $updateStmt = $conn->prepare("UPDATE aws_accounts SET status = 'Suspended' WHERE id = ?");
            $updateStmt->execute([$accountId]);
        }
    } else {
        echo "Account not found.";
    }
}
?>
