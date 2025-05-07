<?php
require '../db_connect.php';
require '../aws/aws-autoloader.php';  // Include AWS SDK

// Get AWS credentials from the POST request
$aws_access_key = $_POST['aws_access_key'] ?? '';
$aws_secret_key = $_POST['aws_secret_key'] ?? '';

// Validate inputs
if (empty($aws_access_key) || empty($aws_secret_key)) {
    die("Missing AWS credentials.");
}

use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

try {
    // Create STS client to verify credentials
    $stsClient = new StsClient([
        'region'      => 'us-east-1',  // STS works globally, but choose a default region
        'version'     => 'latest',
        'credentials' => [
            'key'    => $aws_access_key,
            'secret' => $aws_secret_key,
        ],
    ]);

    // Call AWS STS to verify credentials
    $result = $stsClient->getCallerIdentity();
    $accountId = $result['Account'];

    echo "Account is <b style='color:green'>Active.</b> AWS Account ID: <strong>$accountId</strong>";
} catch (AwsException $e) {
    echo "Account is <b style='color:red'>Suspended.</b> Error: " . $e->getAwsErrorMessage();
}
?>
