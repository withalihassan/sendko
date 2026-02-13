<?php
// launch_instance_minimal.php
// Minimal, working launcher: creates keypair, security group, launches Windows instance, stores record (if db.php provides $pdo).
// PUT this file in your actions/ folder. Adjust paths to aws-autoloader and db.php if needed.

header('Content-Type: application/json; charset=utf-8');

// load DB if available (user's db.php should define $pdo as PDO)
$dbPath = __DIR__ . '/../../db.php';
if (file_exists($dbPath)) {
    include $dbPath; // safe include; db.php should set $pdo
}

require_once __DIR__ . '/../../aws/aws-autoloader.php';
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

function jsonExit($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

$ak = trim((string)($_POST['aws_access_key'] ?? ''));
$sk = trim((string)($_POST['aws_secret_key'] ?? ''));
$region = trim((string)($_POST['region'] ?? ''));
$instanceType = trim((string)($_POST['instance_type'] ?? ''));
$parentId = trim((string)($_POST['parent_id'] ?? '')) ?: null;

if ($ak === '' || $sk === '' || $region === '' || $instanceType === '') {
    jsonExit(['status'=>'error','message'=>'Missing aws_access_key, aws_secret_key, region or instance_type'],400);
}

// minimal Windows AMI map - extend as needed
$amiMap = [
    'us-east-1'       => 'ami-06b5375e3af24939c', // US East (N. Virginia)
    'us-east-2'       => 'ami-0c84451959d149095', // US East (Ohio)
    'us-west-1'       => 'ami-072fcf26b3b4a134a', // US West (N. California)
    'us-west-2'       => 'ami-07a73e7966fb8ae9d', // US West (Oregon)
    'af-south-1'      => 'ami-0ff39bdcfefaddfc8', // Africa (Cape Town)
    'ap-east-1'       => 'ami-01f61a2422c5c042d', // Asia Pacific (Hong Kong)
    'ap-south-2'      => 'ami-01f61a2422c5c042d', // Asia Pacific (Hyderabad)
    'ap-southeast-3'  => 'ami-0bd6f939d550f0910', // Asia Pacific (Jakarta)
    'ap-southeast-5'  => 'ami-0d189ff67430cfdb5', // Asia Pacific (Malaysia)
    'ap-southeast-4'  => 'ami-09b12166d0231eda1', // Asia Pacific (Melbourne)
    'ap-south-1'      => 'ami-0bca660a856fc8c72', // Asia Pacific (Mumbai)
    'ap-southeast-6'  => 'ami-0102cad94e0404f5b', // Asia Pacific (New Zealand)
    'ap-northeast-3'  => 'ami-076d7a04780bf6cfa', // Asia Pacific (Osaka)
    'ap-northeast-2'  => 'ami-08997c3a95d3123eb', // Asia Pacific (Seoul)
    'ap-southeast-1'  => 'ami-0b2c9f548a02f03c5', // Asia Pacific (Singapore)
    'ap-southeast-2'  => 'ami-0a3ccc6176b961ece', // Asia Pacific (Sydney)
    'ap-east-2'       => 'ami-06c10b7ddb9e84aa2', // Asia Pacific (Taipei)
    'ap-southeast-7'  => 'ami-0086e40e6c9f17dc3', // Asia Pacific (Thailand)
    'ap-northeast-1'  => 'ami-0ae6d043070f16d1a', // Asia Pacific (Tokyo)
    'ca-central-1'    => 'ami-024bf1f86e73d89e5', // Canada (Central)
    'ca-west-1'       => 'ami-0684569f3d862e122', // Canada West (Calgary)
    'eu-central-1'    => 'ami-00cb3eed37fa7b06c', // Europe (Frankfurt)
    'eu-west-1'       => 'ami-0bacfb07f6a0fb80a', // Europe (Ireland)
    'eu-west-2'       => 'ami-05d5ef92e8653e623', // Europe (London)
    'eu-south-1'      => 'ami-0e5c30a04af12489e', // Europe (Milan)
    'eu-west-3'       => 'ami-0b8e7b1d2a65785b7', // Europe (Paris)
    'eu-south-2'      => 'ami-024a33b6126126966', // Europe (Spain)
    'eu-north-1'      => 'ami-0a9856f989025b2f0', // Europe (Stockholm)
    'eu-central-2'    => 'ami-07e64e46c7460c716', // Europe (Zurich)
    'mx-central-1'    => 'ami-08899bc8da6335256', // Mexico (Central)
    'me-south-1'      => 'ami-0e15597ac9d07aae1', // Middle East (Bahrain)
    'me-central-1'    => 'ami-0e3ee148731f57b2b', // Middle East (UAE)
    'il-central-1'    => 'ami-0268729eb9948e2b1', // Israel (Tel Aviv)
    'sa-east-1'       => 'ami-0c0d47e26a00217ab', // South America (São Paulo)
];

if (!isset($amiMap[$region])) jsonExit(['status'=>'error','message'=>"No AMI configured for region {$region}"],400);
$amiId = $amiMap[$region];

try {
    $ec2 = new Ec2Client([
        'region' => $region,
        'version' => 'latest',
        'credentials' => [ 'key' => $ak, 'secret' => $sk ]
    ]);
} catch (Throwable $e) {
    jsonExit(['status'=>'error','message'=>'Failed to create EC2 client: '.$e->getMessage()],500);
}

// key name: letters/numbers/hyphen only
$keyName = 'desk-key-' . bin2hex(random_bytes(4)) . '-' . time();
$newKeyNAmee=$keyName.".pem";

try {
    // create key pair
    $createKey = $ec2->createKeyPair(['KeyName' => $keyName]);
    if (empty($createKey['KeyMaterial'])) throw new Exception('No KeyMaterial returned');
    $keyMaterial = $createKey['KeyMaterial'];

    // save pem
    $dir = __DIR__ . '/keys';
    // if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new Exception('Failed to create keys dir');
    $pemPath = $dir . '/' . $keyName . '.pem';
    // if (file_put_contents($pemPath, $keyMaterial) === false) throw new Exception('Failed to write PEM');

    // create security group - IMPORTANT: name must NOT start with 'sg-'
    $sgName = 'desk-' . substr($keyName, -8);
    $sgDesc = 'Temporary SG for '.$keyName.' (open - testing only)';
    $createSg = $ec2->createSecurityGroup(['GroupName' => $sgName, 'Description' => $sgDesc]);
    $sgId = $createSg['GroupId'] ?? null;
    if (!$sgId) throw new Exception('Failed to create security group');

    // authorize ingress (open to all) - be careful in production!
    $ec2->authorizeSecurityGroupIngress([
        'GroupId' => $sgId,
        'IpPermissions' => [
            [
                'IpProtocol' => '-1',
                'IpRanges' => [['CidrIp' => '0.0.0.0/0']],
                'Ipv6Ranges' => [['CidrIpv6' => '::/0']]
            ]
        ]
    ]);

    // run instance
    $tagName = 'Desk-' . substr($keyName, -6);

    // set root device name and force root EBS volume to 100 GB
    $rootDevice = '/dev/sda1';
    $blockDeviceMappings = [
        [
            'DeviceName' => $rootDevice,
            'Ebs' => [
                'VolumeSize' => 100,
                'VolumeType' => 'gp3',
                'DeleteOnTermination' => true
            ]
        ]
    ];

    $run = $ec2->runInstances([
        'ImageId' => $amiId,
        'InstanceType' => $instanceType,
        'MinCount' => 1,
        'MaxCount' => 1,
        'KeyName' => $keyName,
        'SecurityGroupIds' => [$sgId],
        'BlockDeviceMappings' => $blockDeviceMappings,
        'TagSpecifications' => [[
            'ResourceType' => 'instance',
            'Tags' => [
                ['Key' => 'Name', 'Value' => $tagName],
                ['Key' => 'CreatedBy', 'Value' => 'web-ui']
            ]
        ]]
    ]);

    $instanceId = $run['Instances'][0]['InstanceId'] ?? null;
    if (!$instanceId) throw new Exception('No InstanceId returned');

    // wait until running (may take some time)
    try {
        $ec2->waitUntil('InstanceRunning', ['InstanceIds' => [$instanceId]]);
    } catch (AwsException $we) {
        // continue and fetch whatever info we can
    }

    // describe instance
    $desc = $ec2->describeInstances(['InstanceIds' => [$instanceId]]);
    $inst = $desc['Reservations'][0]['Instances'][0] ?? null;
    $publicIp = $inst['PublicIpAddress'] ?? null;
    $state = $inst['State']['Name'] ?? 'unknown';
    $launchedAt = date('Y-m-d H:i:s');

    // store to DB if $pdo exists and is PDO
    $dbMsg = 'DB skipped';
    if (isset($pdo) && $pdo instanceof PDO) {
        // create table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS launched_desks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            key_name VARCHAR(255),
            key_material LONGTEXT,
            parent_id VARCHAR(255),
            instance_id VARCHAR(100),
            region_name VARCHAR(64),
            type VARCHAR(64),
            state VARCHAR(64),
            public_ip VARCHAR(45),
            launched_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare("INSERT INTO launched_desks
            (key_name, key_material, parent_id, instance_id, region_name, type, state, public_ip, launched_at)
            VALUES (:key_name, :key_material, :parent_id, :instance_id, :region_name, :type, :state, :public_ip, :launched_at)");
        $stmt->execute([
            ':key_name'=>$newKeyNAmee,
            ':key_material'=>$keyMaterial,
            ':parent_id'=>$parentId,
            ':instance_id'=>$instanceId,
            ':region_name'=>$region,
            ':type'=>$instanceType,
            ':state'=>$state,
            ':public_ip'=>$publicIp,
            ':launched_at'=>$launchedAt
        ]);
        $dbMsg = 'DB insert OK, id='.$pdo->lastInsertId();
    }

    $response = [
        'status'=>'ok',
        'message'=>'Instance launched',
        'instance_id'=>$instanceId,
        'instance_state'=>$state,
        'public_ip'=>$publicIp,
        'key_name'=>$keyName,
        'pem_path'=>$pemPath,
        'security_group_id'=>$sgId,
        'region'=>$region,
        'instance_type'=>$instanceType,
        'launched_at'=>$launchedAt,
        'db_message'=>$dbMsg
    ];

    jsonExit($response,200);

} catch (AwsException $ae) {
    $msg = $ae->getAwsErrorMessage() ?: $ae->getMessage();
    jsonExit(['status'=>'error','message'=>$msg],500);
} catch (Throwable $e) {
    jsonExit(['status'=>'error','message'=>$e->getMessage()],500);
}
