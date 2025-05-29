<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../db.php';  // Include the database connection file (provides $pdo)
require '../aws/aws-autoloader.php';  // Include the AWS SDK autoloader

use Aws\Sts\StsClient;
use Aws\Iam\IamClient;
use Aws\Exception\AwsException;

// Get parent_id and child_id from URL parameters
if (isset($_GET['parent_id']) && isset($_GET['child_id'])) {
    $parent_id = $_GET['parent_id'];
    $child_id = $_GET['child_id'];

    try {
        // Step 1: Fetch parent account AWS credentials from the database
        $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE account_id = ?");
        $stmt->execute([$parent_id]);
        $parentAccount = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parentAccount) {
            die("Error: Parent account credentials not found.");
        }

        echo "Step 1: Fetched AWS Credentials<br>";
        echo "AWS Key: " . htmlspecialchars($parentAccount['aws_key']) . "<br>";
        echo "AWS Secret: " . substr($parentAccount['aws_secret'], 0, 5) . "***********<br><br>";

        // Step 2: Assume role in the child account
        $stsClient = new StsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key'    => $parentAccount['aws_key'],
                'secret' => $parentAccount['aws_secret'],
            ]
        ]);

        $roleArn = "arn:aws:iam::{$child_id}:role/OrganizationAccountAccessRole";

        $assumedRole = $stsClient->assumeRole([
            'RoleArn' => $roleArn,
            'RoleSessionName' => 'ChildAccountSession'
        ]);

        $tempCredentials = $assumedRole['Credentials'];

        echo "Step 2: Assumed Role Successfully<br>";
        echo "Temporary Access Key: " . htmlspecialchars($tempCredentials['AccessKeyId']) . "<br>";
        echo "Temporary Secret Key: " . substr($tempCredentials['SecretAccessKey'], 0, 5) . "***********<br>";
        echo "Session Token: " . substr($tempCredentials['SessionToken'], 0, 10) . "***********<br>";
        echo "Expiration: " . htmlspecialchars($tempCredentials['Expiration']) . "<br><br>";

        // Step 3: Use temporary credentials to create an IAM user
        $iamClient = new IamClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key'    => $tempCredentials['AccessKeyId'],
                'secret' => $tempCredentials['SecretAccessKey'],
                'token'  => $tempCredentials['SessionToken'],
            ]
        ]);

        $userName = "child-manual-user-{$child_id}";

        try {
            $userResult = $iamClient->createUser([
                'UserName' => $userName
            ]);
            echo "Step 3: IAM User '$userName' Created Successfully<br>";
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'EntityAlreadyExists') {
                echo "Step 3: IAM User '$userName' already exists.<br>";
            } else {
                throw $e;
            }
        }

        // Step 4: Attach AdministratorAccess policy to the IAM user
        try {
            $iamClient->attachUserPolicy([
                'UserName'  => $userName,
                'PolicyArn' => 'arn:aws:iam::aws:policy/AdministratorAccess'
            ]);
            echo "Step 4: AdministratorAccess policy attached to user '$userName'<br>";
        } catch (AwsException $e) {
            echo "Step 4 Error: Failed to attach policy - " . $e->getMessage() . "<br>";
        }

        // Step 5: Create permanent access keys for the newly created IAM user
        $accessKeyResult = $iamClient->createAccessKey([
            'UserName' => $userName
        ]);

        $accessKey = $accessKeyResult['AccessKey']['AccessKeyId'];
        $secretKey = $accessKeyResult['AccessKey']['SecretAccessKey'];

        echo "Step 5: Created Permanent Access Keys<br>";
        echo "Permanent Access Key: " . htmlspecialchars($accessKey) . "<br>";
        echo "Permanent Secret Key: " . substr($secretKey, 0, 5) . "***********<br><br>";

        // Step 6: Store the permanent credentials in the database
        $updateStmt = $pdo->prepare("UPDATE child_accounts SET aws_access_key = ?, aws_secret_key = ? WHERE account_id = ?");
        $updateStmt->execute([$accessKey, $secretKey, $child_id]);

        echo "Step 6: Permanent keys stored in the database successfully!";

    } catch (AwsException $e) {
        echo "AWS Error: " . $e->getMessage();
    } catch (PDOException $e) {
        echo "Database Error: " . $e->getMessage();
    }
} else {
    echo "Error: Missing required parameters.";
}
?>
