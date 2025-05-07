<?php
// list_regions.php
header('Content-Type: application/json');
require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\Account\AccountClient;
use Aws\Exception\AwsException;

if (!isset($_POST['awsKey']) || !isset($_POST['awsSecret'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing AWS credentials.']);
    exit;
}

$awsKey = trim($_POST['awsKey']);
$awsSecret = trim($_POST['awsSecret']);

// Initialize AccountClient (global endpoint, using us-east-1)
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
    // Call ListRegions API
    $result = $accountClient->listRegions([]);
    $regions = isset($result['Regions']) ? $result['Regions'] : [];
    
    $enabledCount = 0;
    $disabledCount = 0;
    foreach ($regions as $r) {
        if (isset($r['RegionOptStatus'])) {
            if ($r['RegionOptStatus'] === 'ENABLED') {
                $enabledCount++;
            } elseif ($r['RegionOptStatus'] === 'DISABLED') {
                $disabledCount++;
            }
        }
    }
    echo json_encode([
        'status'   => 'success',
        'enabled'  => $enabledCount,
        'disabled' => $disabledCount,
        'regions'  => $regions
    ]);
} catch (AwsException $e) {
    $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
    echo json_encode(['status' => 'error', 'message' => 'Error listing regions: ' . $errorMsg]);
    exit;
}
?>
