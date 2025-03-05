<?php
// region_opt.php
// This script uses the AWS Account API to enable or disable a region
header('Content-Type: application/json');
require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\Account\AccountClient;
use Aws\Exception\AwsException;

// Validate POST parameters
if (!isset($_POST['region']) || !isset($_POST['action']) || !isset($_POST['awsKey']) || !isset($_POST['awsSecret'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
}

$regionName = trim($_POST['region']);
$actionType = trim($_POST['action']); // Expected "enable" or "disable"
$awsKey     = trim($_POST['awsKey']);
$awsSecret  = trim($_POST['awsSecret']);

// The AWS Account API is global; use a fixed region such as us-east-1.
try {
    $accountClient = new AccountClient([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'credentials' => [
            'key'    => $awsKey,
            'secret' => $awsSecret,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error initializing Account client: ' . $e->getMessage()]);
    exit;
}

try {
    if ($actionType === 'enable') {
        // Call EnableRegion API
        $accountClient->enableRegion(['RegionName' => $regionName]);
        // Get current status
        $statusResult = $accountClient->getRegionOptStatus(['RegionName' => $regionName]);
        $status = isset($statusResult['RegionOptStatus']) ? $statusResult['RegionOptStatus'] : 'UNKNOWN';
        echo json_encode([
            'status'  => 'success',
            'message' => "EnableRegion request submitted for $regionName. Current status: $status."
        ]);
    } elseif ($actionType === 'disable') {
        // Call DisableRegion API
        $accountClient->disableRegion(['RegionName' => $regionName]);
        $statusResult = $accountClient->getRegionOptStatus(['RegionName' => $regionName]);
        $status = isset($statusResult['RegionOptStatus']) ? $statusResult['RegionOptStatus'] : 'UNKNOWN';
        echo json_encode([
            'status'  => 'success',
            'message' => "DisableRegion request submitted for $regionName. Current status: $status."
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
    }
} catch (AwsException $e) {
    $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
    echo json_encode(['status' => 'error', 'message' => "Error processing request for $regionName: " . $errorMsg]);
}
?>
