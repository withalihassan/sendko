<?php
// actions/launch_instance.php
header('Content-Type: application/json; charset=utf-8');

require '../../db.php';                // keep if you use DB; harmless if not used
require '../../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

function jsonExit($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// Read and validate inputs
$ak = trim((string)($_POST['aws_access_key'] ?? ''));
$sk = trim((string)($_POST['aws_secret_key'] ?? ''));
$region = trim((string)($_POST['region'] ?? ''));
$instanceType = trim((string)($_POST['instance_type'] ?? ''));

if ($ak === '' || $sk === '' || $region === '' || $instanceType === '') {
    jsonExit(['status'=>'error','message'=>'Missing aws keys, region or instance_type'],400);
}

// AMI mapping (Windows) — only 2 regions requested
$amiMap = [
    'us-east-1' => 'ami-0e3c2921641a4a215',
    'us-east-2' => 'ami-0c8eb251138004df2'
];

if (!isset($amiMap[$region])) {
    jsonExit(['status'=>'error','message'=>"No Windows AMI configured for region: {$region}"],400);
}

$amiId = $amiMap[$region];

// Prepare client
try {
    $ec2 = new Ec2Client([
        'region' => $region,
        'version' => 'latest',
        'credentials' => [
            'key'    => $ak,
            'secret' => $sk
        ]
    ]);
} catch (Throwable $e) {
    jsonExit(['status'=>'error','message'=>'Failed to create EC2 client: '.$e->getMessage()],500);
}

$keyName = 'keypair-' . preg_replace('/[^a-z0-9-_]/i','', substr($region,0,6)) . '-' . time();

try {
    // 1) Create Key Pair
    $createKey = $ec2->createKeyPair(['KeyName' => $keyName]);
    if (!isset($createKey['KeyMaterial'])) {
        throw new Exception('No KeyMaterial returned while creating keypair');
    }
    $keyMaterial = $createKey['KeyMaterial'];

    // Save PEM to actions/keys directory
    $dir = __DIR__ . '/keys';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true)) {
            throw new Exception("Failed to create keys directory: {$dir}");
        }
    }

    $pemPath = $dir . '/' . $keyName . '.pem';
    if (file_put_contents($pemPath, $keyMaterial) === false) {
        throw new Exception("Failed to write PEM to {$pemPath}");
    }
    chmod($pemPath, 0600);

    // 2) Launch Windows instance and attach KeyName
    $tagName = 'Desk-' . substr($keyName, -6);
    $run = $ec2->runInstances([
        'ImageId' => $amiId,
        'InstanceType' => $instanceType,
        'MinCount' => 1,
        'MaxCount' => 1,
        'KeyName' => $keyName,
        'TagSpecifications' => [
            [
                'ResourceType' => 'instance',
                'Tags' => [
                    ['Key' => 'Name', 'Value' => $tagName],
                    ['Key' => 'CreatedBy', 'Value' => 'web-ui']
                ]
            ]
        ],
        // Optional: enable public IP on default subnet (if using default VPC/subnet this usually works)
        // If you use a custom subnet that does not auto-assign public IP, you'll have to set NetworkInterfaces explicitly.
    ]);

    if (empty($run['Instances'][0]['InstanceId'])) {
        throw new Exception('No InstanceId returned from runInstances');
    }

    $instanceId = $run['Instances'][0]['InstanceId'];

    // 3) Wait until running
    // This uses the SDK waiter. It will poll until state is running or fail/timeout.
    try {
        $ec2->waitUntil('InstanceRunning', ['InstanceIds' => [$instanceId]]);
    } catch (AwsException $we) {
        // proceed — we'll still attempt to describe instance; include waiter warning in response
        $waiterError = $we->getMessage();
    } catch (Throwable $we) {
        $waiterError = $we->getMessage();
    }

    // 4) Describe to fetch public IP and state
    $desc = $ec2->describeInstances(['InstanceIds' => [$instanceId]]);
    $inst = $desc['Reservations'][0]['Instances'][0] ?? null;
    if (!$inst) throw new Exception('Failed to describe instance after launch');

    $publicIp = $inst['PublicIpAddress'] ?? null;
    $state = $inst['State']['Name'] ?? 'unknown';

    $response = [
        'status' => 'ok',
        'message' => 'Desk Launched Successfully',
        'key_name' => $keyName,
        'pem_path' => $pemPath,
        'instance_id' => $instanceId,
        'instance_state' => $state,
        'public_ip' => $publicIp,
        'region' => $region,
        'instance_type' => $instanceType,
        'ami' => $amiId,
        'created_at' => date('c')
    ];

    if (isset($waiterError)) $response['waiter_warning'] = $waiterError;

    jsonExit($response, 200);

} catch (AwsException $ae) {
    $msg = $ae->getAwsErrorMessage() ?: $ae->getMessage();
    jsonExit(['status'=>'error','message'=>$msg],500);
} catch (Throwable $e) {
    jsonExit(['status'=>'error','message'=>$e->getMessage()],500);
}
