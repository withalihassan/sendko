<?php
// instance_action.php
require '../session.php';
require '../db.php';
require '../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

header('Content-Type: application/json');

// Check required POST parameters
if (!isset($_POST['account_id'], $_POST['instance_id'], $_POST['region'], $_POST['action'])) {
    echo json_encode(['success' => false, 'message' => "Missing parameters."]);
    exit;
}

$account_id = intval($_POST['account_id']);
$instanceId = $_POST['instance_id'];
$region     = $_POST['region'];
$action     = $_POST['action'];

// Fetch AWS credentials for this account
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    echo json_encode(['success' => false, 'message' => "AWS account not found."]);
    exit;
}
$aws_key    = $account['aws_key'];
$aws_secret = $account['aws_secret'];

// Create EC2 client
$ec2Client = new Ec2Client([
    'version'     => 'latest',
    'region'      => $region,
    'credentials' => [
        'key'    => $aws_key,
        'secret' => $aws_secret,
    ],
]);

if ($action === 'terminate') {
    try {
        // Terminate the instance
        $ec2Client->terminateInstances(['InstanceIds' => [$instanceId]]);
        $ec2Client->waitUntil('InstanceTerminated', ['InstanceIds' => [$instanceId]]);
        // Remove the record from the database
        $stmt = $pdo->prepare("DELETE FROM instances WHERE instance_id = ? AND account_id = ?");
        $stmt->execute([$instanceId, $account_id]);
        echo json_encode(['success' => true, 'message' => "Instance terminated successfully."]);
        exit;
    } catch (AwsException $e) {
        echo json_encode(['success' => false, 'message' => "Error terminating instance: " . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'update') {
    try {
        // Get the latest instance details
        $desc = $ec2Client->describeInstances(['InstanceIds' => [$instanceId]]);
        $instanceDetails = $desc['Reservations'][0]['Instances'][0];
        $newPublicIp = isset($instanceDetails['PublicIpAddress']) ? $instanceDetails['PublicIpAddress'] : null;
        $state       = $instanceDetails['State']['Name'];
    
        // Get current details from the database
        $stmt = $pdo->prepare("SELECT public_ip, ip_history FROM instances WHERE instance_id = ? AND account_id = ?");
        $stmt->execute([$instanceId, $account_id]);
        $dbInstance = $stmt->fetch(PDO::FETCH_ASSOC);
        $ip_history = $dbInstance['ip_history'];
        if ($dbInstance['public_ip'] && $newPublicIp && $dbInstance['public_ip'] !== $newPublicIp) {
            // Append old IP to ip_history (comma separated)
            $ip_history = $ip_history ? $ip_history . ", " . $dbInstance['public_ip'] : $dbInstance['public_ip'];
        }
    
        // Update the database record
        $stmt = $pdo->prepare("UPDATE instances SET public_ip = ?, state = ?, ip_history = ? WHERE instance_id = ? AND account_id = ?");
        $stmt->execute([$newPublicIp, $state, $ip_history, $instanceId, $account_id]);
    
        echo json_encode(['success' => true, 'message' => "Instance updated successfully."]);
        exit;
    } catch (AwsException $e) {
        echo json_encode(['success' => false, 'message' => "Error updating instance: " . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'change_ip') {
    try {
        // Allocate a new Elastic IP (for VPC)
        $allocation = $ec2Client->allocateAddress(['Domain' => 'vpc']);
        $allocationId = $allocation['AllocationId'];
        $elasticIp  = $allocation['PublicIp'];
    
        // Associate the Elastic IP with the instance
        $ec2Client->associateAddress([
            'InstanceId'   => $instanceId,
            'AllocationId' => $allocationId,
        ]);
    
        // Update the instance record with the new Elastic IP
        $stmt = $pdo->prepare("UPDATE instances SET elastic_ip = ? WHERE instance_id = ? AND account_id = ?");
        $stmt->execute([$elasticIp, $instanceId, $account_id]);
    
        // Check for unassociated Elastic IP addresses
        $addresses = $ec2Client->describeAddresses();
        $unassociated = [];
        if (isset($addresses['Addresses'])) {
            foreach ($addresses['Addresses'] as $addr) {
                // Exclude addresses that are associated
                if (!isset($addr['AssociationId'])) {
                    $unassociated[] = $addr;
                }
            }
        }
        
        $deletedCount = 0;
        if (count($unassociated) > 2) {
            // Release all unassociated Elastic IP addresses
            foreach ($unassociated as $addr) {
                $ec2Client->releaseAddress([
                    'AllocationId' => $addr['AllocationId']
                ]);
                $deletedCount++;
            }
        }
    
        $msg = "Elastic IP associated successfully. New IP assigned: $elasticIp.";
        if ($deletedCount > 0) {
            $msg .= " Additionally, $deletedCount unused Elastic IP(s) were released.";
        }
    
        echo json_encode(['success' => true, 'message' => $msg]);
        exit;
    } catch (AwsException $e) {
        echo json_encode(['success' => false, 'message' => "Error changing IP: " . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'start') {
    try {
        // Start the instance
        $ec2Client->startInstances(['InstanceIds' => [$instanceId]]);
        $ec2Client->waitUntil('InstanceRunning', ['InstanceIds' => [$instanceId]]);
        // Update the database record to reflect the running state
        $stmt = $pdo->prepare("UPDATE instances SET state = ? WHERE instance_id = ? AND account_id = ?");
        $stmt->execute(['running', $instanceId, $account_id]);
        echo json_encode(['success' => true, 'message' => "Instance started successfully."]);
        exit;
    } catch (AwsException $e) {
        echo json_encode(['success' => false, 'message' => "Error starting instance: " . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'stop') {
    try {
        // Stop the instance
        $ec2Client->stopInstances(['InstanceIds' => [$instanceId]]);
        $ec2Client->waitUntil('InstanceStopped', ['InstanceIds' => [$instanceId]]);
        // Update the database record to reflect the stopped state
        $stmt = $pdo->prepare("UPDATE instances SET state = ? WHERE instance_id = ? AND account_id = ?");
        $stmt->execute(['stopped', $instanceId, $account_id]);
        echo json_encode(['success' => true, 'message' => "Instance stopped successfully."]);
        exit;
    } catch (AwsException $e) {
        echo json_encode(['success' => false, 'message' => "Error stopping instance: " . $e->getMessage()]);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => "Unknown action."]);
    exit;
}
