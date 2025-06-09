<?php
// PHP (child_actions/leave_organization.php)
// This file receives AWS keys, calls LeaveOrganization, and returns JSON success/failure.

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// 1) Grab posted data
$accessKey = $_POST['aws_access_key'] ?? '';
$secretKey = $_POST['aws_secret_key'] ?? '';

if (!$accessKey || !$secretKey) {
    echo json_encode(['error' => 'Missing AWS credentials.']);
    exit;
}

// 2) Load AWS SDK
require '../../aws/aws-autoloader.php';

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

try {
    // 3) Instantiate OrganizationsClient using the member account’s credentials
    $orgClient = new OrganizationsClient([
        'version'     => 'latest',
        'region'      => 'us-east-1', // org APIs are global, but region still required
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey
        ]
    ]);

    // 4) Call LeaveOrganization (no parameters needed)
    $orgClient->leaveOrganization();

    // 5) Return success message
    echo json_encode([
        'message' => 'Script Successfully Executed Congratulations!.'
    ]);
} catch (AwsException $e) {
    // Capture AWS SDK errors (e.g., not part of an Org, invalid creds, etc.)
    $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
    echo json_encode(['error' => 'Error Operation Not Completed']);
}
