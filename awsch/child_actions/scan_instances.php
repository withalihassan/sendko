<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../db.php';
require '../../aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

// Capture inputs
$aws_access_key = $_POST['aws_access_key'] ?? '';
$aws_secret_key = $_POST['aws_secret_key'] ?? '';
$region         = $_POST['region'] ?? '';

if (empty($aws_access_key) || empty($aws_secret_key) || empty($region)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters.']);
    exit;
}

try {
    // 1) Retrieve caller account ID via STS
    $sts = new StsClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => [
            'key'    => $aws_access_key,
            'secret' => $aws_secret_key
        ],
    ]);
    $accountId = $sts->getCallerIdentity()['Account'];

    // 2) Describe all instances in the region
    $ec2 = new Ec2Client([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => [
            'key'    => $aws_access_key,
            'secret' => $aws_secret_key
        ],
    ]);
    $result = $ec2->describeInstances();

    $found = [];
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach ($result['Reservations'] as $reservation) {
        foreach ($reservation['Instances'] as $instance) {
            $instanceId = $instance['InstanceId'];
            $state      = $instance['State']['Name'];

            // Check if already recorded
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM launched_instances WHERE instance_id = ?"
            );
            $stmt->execute([$instanceId]);

            if ($stmt->fetchColumn() == 0) {
                // Insert new record
                $ins = $pdo->prepare(
                    "INSERT INTO launched_instances
                     (account_id, instance_id, region, instance_type, launch_type, launched_at)
                     VALUES (?, ?, ?, ?, ?, NOW())"
                );
                $ins->execute([
                    $accountId,
                    $instanceId,
                    $region,
                    $instance['InstanceType'],
                    $instance['InstanceLifecycle'] ?? 'on-demand'
                ]);
            }

            $found[] = [
                'InstanceId' => $instanceId,
                'State'      => $state
            ];
        }
    }

    // Return JSON response
    echo json_encode(['instances' => $found]);

} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'AWS SDK error: ' . $e->getAwsErrorMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>