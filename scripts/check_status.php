<?php
// check_status.php

include('../db.php');
require '../aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

if (!isset($_POST['id'])) {
    echo "Invalid request.";
    exit;
}

$id = $_POST['id'];

// Retrieve account details from the database
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "Account not found.";
    exit;
}

$aws_key    = $account['aws_key'];
$aws_secret = $account['aws_secret'];

try {
    // Create an STS client using the stored credentials
    $stsClient = new StsClient([
        'version'     => 'latest',
        'region'      => 'us-east-1', // adjust as needed
        'credentials' => [
            'key'    => $aws_key,
            'secret' => $aws_secret,
        ]
    ]);

    // Attempt to get the caller identity to verify credentials
    $result = $stsClient->getCallerIdentity();
    $status = "active";
    $message = "Account is active.";
} catch (AwsException $e) {
    // If the AWS SDK throws an exception, mark as suspended
    $status = "suspended";
    $message = "Account suspended: " . $e->getAwsErrorMessage();
} catch (Exception $e) {
    $status = "suspended";
    $message = "Account suspended: " . $e->getMessage();
}

// Update the account status in the database
$updateStmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ?");
$updateStmt->execute([$status, $id]);

echo $message;
