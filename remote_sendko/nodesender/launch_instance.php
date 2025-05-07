<?php
// launch_instance.php
require '../session.php';
require '../db.php';
require '../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

header('Content-Type: application/json');

// Check POST parameters
if (!isset($_POST['account_id'], $_POST['region'], $_POST['instance_type'])) {
    echo json_encode(['success' => false, 'message' => "Missing parameters."]);
    exit;
}

$account_id    = intval($_POST['account_id']);
$region        = $_POST['region'];
$instance_type = $_POST['instance_type'];

// Fetch AWS credentials from the accounts table
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo json_encode(['success' => false, 'message' => "AWS account not found."]);
    exit;
}
$aws_key    = $account['aws_key'];
$aws_secret = $account['aws_secret'];

// For this example, we support us-west-2 only (Ubuntu AMI)
$ami_id = '';
if ($region === 'us-west-2') {
    $ami_id = 'ami-00c257e12d6828491';
} else {
    echo json_encode(['success' => false, 'message' => "Region not supported."]);
    exit;
}

try {
    // Create EC2 client
    $ec2Client = new Ec2Client([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $aws_key,
            'secret' => $aws_secret,
        ],
    ]);

    // Check for (or create) a security group that allows all inbound IPv4 traffic.
    $groupName = 'all_inbound';
    $securityGroupId = null;
    $result = $ec2Client->describeSecurityGroups([
        'Filters' => [
            ['Name' => 'group-name', 'Values' => [$groupName]],
        ],
    ]);
    if (!empty($result['SecurityGroups'])) {
        $securityGroupId = $result['SecurityGroups'][0]['GroupId'];
    } else {
        // Create the security group if it does not exist.
        $vpcResult = $ec2Client->describeVpcs();
        $vpcId = $vpcResult['Vpcs'][0]['VpcId']; // Use the first VPC
        $createResult = $ec2Client->createSecurityGroup([
            'GroupName'   => $groupName,
            'Description' => 'Security group allowing all inbound traffic',
            'VpcId'       => $vpcId,
        ]);
        $securityGroupId = $createResult['GroupId'];
        // Add ingress rule: allow all inbound IPv4 traffic.
        $ec2Client->authorizeSecurityGroupIngress([
            'GroupId'      => $securityGroupId,
            'IpPermissions' => [
                [
                    'IpProtocol' => '-1', // all protocols
                    'IpRanges'   => [['CidrIp' => '0.0.0.0/0']],
                ],
            ],
        ]);
    }
    $userData = file_get_contents('setup.sh');

    if ($userData === false) {
        // Handle the error appropriately if the file cannot be read
        die('Error: Could not read setup.sh file');
    }

    // Encode the contents
    $userDataEncoded = base64_encode($userData);


    // Launch the instance
    $result = $ec2Client->runInstances([
        'ImageId'           => $ami_id,
        'InstanceType'      => $instance_type,
        'MinCount'          => 1,
        'MaxCount'          => 1,
        'SecurityGroupIds'  => [$securityGroupId],
        'UserData'          => $userDataEncoded,
    ]);
    $instance = $result['Instances'][0];
    $instanceId = $instance['InstanceId'];

    // Wait until the instance is running
    $ec2Client->waitUntil('InstanceRunning', [
        'InstanceIds' => [$instanceId],
    ]);

    // Retrieve instance details
    $desc = $ec2Client->describeInstances(['InstanceIds' => [$instanceId]]);
    $instanceDetails = $desc['Reservations'][0]['Instances'][0];
    $launchTime = $instanceDetails['LaunchTime']->format('Y-m-d H:i:s');
    $state      = $instanceDetails['State']['Name'];
    $publicIp   = isset($instanceDetails['PublicIpAddress']) ? $instanceDetails['PublicIpAddress'] : null;

    // Insert instance details into the database
    $stmt = $pdo->prepare("INSERT INTO instances (account_id, instance_id, region, instance_type, state, launch_time, public_ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$account_id, $instanceId, $region, $instance_type, $state, $launchTime, $publicIp]);

    echo json_encode(['success' => true, 'message' => "Instance launched successfully."]);
    exit;
} catch (AwsException $e) {
    echo json_encode(['success' => false, 'message' => "Error launching instance: " . $e->getMessage()]);
    exit;
}
