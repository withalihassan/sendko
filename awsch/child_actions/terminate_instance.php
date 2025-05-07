<?php
declare(strict_types=1);

// Enable error reporting (disable in production)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Return JSON responses
header('Content-Type: application/json; charset=utf-8');

// Include dependencies
require __DIR__ . '/../../db.php';          // Database connection (expects $pdo)
require __DIR__ . '/../../aws/aws-autoloader.php'; // AWS SDK autoloader

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

// Retrieve and validate POST parameters
$instanceId = trim((string)($_POST['instance_id']  ?? ''));
$recordId   = trim((string)($_POST['record_id']    ?? ''));
$region     = trim((string)($_POST['region']       ?? ''));
$accessKey  = trim((string)($_POST['access_key']   ?? ''));
$secretKey  = trim((string)($_POST['secret_key']   ?? ''));

if ($instanceId === '' || $recordId === '' || $region === '' || $accessKey === '' || $secretKey === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters: instance_id, record_id, region, access_key, and secret_key are all required.'
    ]);
    exit;
}

try {
    // Initialize EC2 client
    $ec2Client = new Ec2Client([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey,
        ],
    ]);

    // Terminate the instance
    $result = $ec2Client->terminateInstances([
        'InstanceIds' => [$instanceId],
    ]);

    // Get the resulting state
    $state = $result['TerminatingInstances'][0]['CurrentState']['Name'] ?? '';

    // If termination has begun or completed, delete the DB record
    if (in_array($state, ['shutting-down', 'terminated'], true)) {
        $deleteStmt = $pdo->prepare('DELETE FROM launched_instances WHERE id = :id');
        $deleteStmt->execute([':id' => $recordId]);

        echo json_encode([
            'success' => true,
            'instance_id' => $instanceId,
            'state'   => $state
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => "Unexpected instance state: {$state}."
        ]);
    }

} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'AWS SDK error: ' . $e->getAwsErrorMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
