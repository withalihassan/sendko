<?php
// scan_instances.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// require '../session.php';
require '../db.php';
require '../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

header('Content-Type: application/json');

// Validate POST parameters
if (!isset($_POST['account_id'], $_POST['region'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
    exit;
}

$account_id = intval($_POST['account_id']);
$region     = $_POST['region'];

// Fetch AWS credentials from the accounts table
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    echo json_encode(['success' => false, 'message' => 'AWS account not found.']);
    exit;
}

$aws_key    = $account['aws_key'];
$aws_secret = $account['aws_secret'];

// Create an EC2 client
$ec2Client = new Ec2Client([
    'version'     => 'latest',
    'region'      => $region,
    'credentials' => [
        'key'    => $aws_key,
        'secret' => $aws_secret,
    ],
    // Uncomment the line below for detailed debugging output if needed.
    // 'debug' => true,
]);

try {
    // Retrieve all instances in the region
    $result = $ec2Client->describeInstances();
    $insertedCount = 0;
    
    if (isset($result['Reservations']) && is_array($result['Reservations'])) {
        foreach ($result['Reservations'] as $reservation) {
            if (isset($reservation['Instances']) && is_array($reservation['Instances'])) {
                foreach ($reservation['Instances'] as $instance) {
                    $instanceId = $instance['InstanceId'];
                    
                    // Check if this instance already exists in the database
                    $stmt = $pdo->prepare("SELECT id FROM instances WHERE instance_id = ? AND account_id = ?");
                    $stmt->execute([$instanceId, $account_id]);
                    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                        // Prepare instance details for insertion
                        $instanceType = $instance['InstanceType'];
                        $state        = $instance['State']['Name'];
                        $launchTime   = (isset($instance['LaunchTime']) && $instance['LaunchTime'] instanceof \DateTimeInterface)
                                        ? $instance['LaunchTime']->format('Y-m-d H:i:s')
                                        : date('Y-m-d H:i:s');
                        $publicIp     = isset($instance['PublicIpAddress']) ? $instance['PublicIpAddress'] : null;
                        
                        $insertStmt = $pdo->prepare("INSERT INTO instances (account_id, instance_id, region, instance_type, state, launch_time, public_ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $insertStmt->execute([$account_id, $instanceId, $region, $instanceType, $state, $launchTime, $publicIp]);
                        $insertedCount++;
                    }
                }
            }
        }
    }
    
    echo json_encode(['success' => true, 'message' => "Scan complete. $insertedCount new instance(s) inserted."]);
    exit;
    
} catch (AwsException $e) {
    // Log the error message for debugging purposes
    error_log("AWS Exception in scan_instances.php: " . $e->getMessage());
    
    // Provide a detailed error message if available
    $awsError = $e->getAwsErrorMessage();
    $message = $awsError ? $awsError : $e->getMessage();
    
    echo json_encode(['success' => false, 'message' => "Error scanning instances: " . $message]);
    exit;
}
