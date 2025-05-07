<?php
declare(strict_types=1);

// Show all errors for debugging (disable in production)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Adjust these paths as needed
require __DIR__ . '/../../db.php';
require __DIR__ . '/../../aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

// Always return JSON
header('Content-Type: application/json; charset=utf-8');

// Get POST inputs
$awsAccessKey = trim((string) ($_POST['aws_access_key'] ?? ''));
$awsSecretKey = trim((string) ($_POST['aws_secret_key'] ?? ''));
$region       = trim((string) ($_POST['region'] ?? ''));

// Validate inputs
if ($awsAccessKey === '' || $awsSecretKey === '' || $region === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameter(s): aws_access_key, aws_secret_key and region are required.']);
    exit;
}

try {
    // 1) Get the AWS account ID of the caller
    $sts = new StsClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => [
            'key'    => $awsAccessKey,
            'secret' => $awsSecretKey,
        ],
    ]);
    $caller   = $sts->getCallerIdentity();
    $accountId = $caller['Account'] ?? '';

    // 2) Describe all EC2 instances in the region
    $ec2 = new Ec2Client([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => [
            'key'    => $awsAccessKey,
            'secret' => $awsSecretKey,
        ],
    ]);
    $result = $ec2->describeInstances();

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $instancesOutput = [];

    foreach ($result['Reservations'] as $reservation) {
        foreach ($reservation['Instances'] as $instance) {
            $instanceId   = $instance['InstanceId'] ?? '';
            $state        = $instance['State']['Name'] ?? 'unknown';
            $instanceType = $instance['InstanceType'] ?? '';
            $launchType   = $instance['InstanceLifecycle'] ?? 'on-demand';

            // Check if already recorded
            $checkStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM launched_instances WHERE instance_id = :instance_id'
            );
            $checkStmt->execute([':instance_id' => $instanceId]);
            $exists = (int) $checkStmt->fetchColumn() > 0;

            if (! $exists) {
                // Insert new record with state
                $insertStmt = $pdo->prepare(
                    'INSERT INTO launched_instances
                        (account_id, instance_id, region, instance_type, launch_type, state, launched_at)
                     VALUES
                        (:account_id, :instance_id, :region, :instance_type, :launch_type, :state, NOW())'
                );
                $insertStmt->execute([
                    ':account_id'    => $accountId,
                    ':instance_id'   => $instanceId,
                    ':region'        => $region,
                    ':instance_type' => $instanceType,
                    ':launch_type'   => $launchType,
                    ':state'         => $state,
                ]);
            }

            // Build output entry
            $instancesOutput[] = [
                'InstanceId'   => $instanceId,
                'State'        => $state,
                'InstanceType' => $instanceType,
                'LaunchType'   => $launchType,
            ];
        }
    }

    // Send back JSON
    echo json_encode(['instances' => $instancesOutput], JSON_PRETTY_PRINT);

} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'AWS SDK error: ' . $e->getAwsErrorMessage(),
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'General error: ' . $e->getMessage(),
    ]);
}
