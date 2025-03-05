<?php
require '../db_connect.php'; // Database connection
require '../aws/aws-autoloader.php';  // Include AWS SDK

use Aws\Ec2\Ec2Client;

// Get instance_id, record_id, region, access_key, and secret_key from the POST request
echo $instance_id = $_POST['instance_id'];
$record_id = $_POST['record_id'];
$region = $_POST['region'];
$access_key = $_POST['access_key'];
$secret_key = $_POST['secret_key'];
try {
    // Create AWS EC2 client using the provided access key and secret key
    $ec2Client = new Ec2Client([
        'region' => $region,
        'version' => 'latest',
        'credentials' => [
            'key'    => $access_key, // Use the provided access key
            'secret' => $secret_key, // Use the provided secret key
        ]
    ]);

    // Terminate the instance
    $result = $ec2Client->terminateInstances([
        'InstanceIds' => [$instance_id],
    ]);

    // Check if the termination was successful
    if ($result['TerminatingInstances'][0]['CurrentState']['Name'] == 'shutting-down' || $result['TerminatingInstances'][0]['CurrentState']['Name'] == 'terminated') {
        // Delete the record from the database
        $sql = "DELETE FROM `launched_instances` WHERE `id` = :record_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':record_id', $record_id, PDO::PARAM_INT);
        $stmt->execute();

        // Send success response
        echo json_encode(['success' => true]);
    } else {
        throw new Exception("Failed to terminate the instance.");
    }
} catch (Exception $e) {
    // Send error response
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
