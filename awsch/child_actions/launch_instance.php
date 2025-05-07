<?php
// launch.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../db.php';
require '../../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

// === 1. Capture and validate input ===
$aws_access_key = $_POST['aws_access_key'] ?? '';
$aws_secret_key = $_POST['aws_secret_key'] ?? '';
$instance_type  = $_POST['instance_type'] ?? '';
$market_type    = $_POST['market_type'] ?? '';
$region         = $_POST['region'] ?? '';

if (empty($aws_access_key) || empty($aws_secret_key) || empty($instance_type) || empty($market_type) || empty($region)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Missing one or more required fields.'
    ]);
    exit;
}

// === 2. AMI mapping per region ===
$amiMap = [
    'us-east-1'      => 'ami-084568db4383264d4',
    'us-east-2'      => 'ami-036841078a4b68e14',
    'us-west-1'      => 'ami-0657605d763ac72a8',
    'us-west-2'      => 'ami-05d38da78ce859165',
    'ap-south-1'     => 'ami-00bb6a80f01f03502',
    'ap-northeast-3' => 'ami-053e5b2b49d1b2a82',
    'ap-northeast-2' => 'ami-024ea438ab0376a47',
    'ap-southeast-1' => 'ami-0672fd5b9210aa093',
    'ap-southeast-2' => 'ami-09e143e99e8fa74f9',
    'ap-northeast-1' => 'ami-0a290015b99140cd1',
    'ca-central-1'   => 'ami-055943271915205db',
    'eu-central-1'   => 'ami-07eef52105e8a2059',
    'eu-west-1'      => 'ami-03fd334507439f4d1',
    'eu-west-2'      => 'ami-091f18e98bc129c4e',
    'eu-west-3'      => 'ami-06e02ae7bdac6b938',
    'eu-north-1'     => 'ami-09a9858973b288bdd',
    'sa-east-1'      => 'ami-04d88e4b4e0a5db46'
];

// Determine which regions to launch in
$regionsToLaunch = ($region === 'all')
    ? array_keys($amiMap)
    : [$region];

// Prepare response accumulator
$response = [
    'success'    => 0,
    'failed'     => 0,
    'details'    => []  // per-region messages
];

// Make PDO throw exceptions on error
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach ($regionsToLaunch as $launchRegion) {
    try {
        // 3a. Get AWS Account ID via STS
        $sts = new StsClient([
            'region'      => $launchRegion,
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_access_key,
                'secret' => $aws_secret_key,
            ],
        ]);
        $caller = $sts->getCallerIdentity();
        $accountId = $caller['Account'];

        // 3b. Launch the EC2 instance
        $ec2 = new Ec2Client([
            'region'      => $launchRegion,
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_access_key,
                'secret' => $aws_secret_key,
            ],
        ]);

        if (!isset($amiMap[$launchRegion])) {
            throw new Exception("No AMI mapping found for region “{$launchRegion}”.");
        }
        $amiId = $amiMap[$launchRegion];

        $launchOpts = [
            'ImageId'      => $amiId,
            'InstanceType' => $instance_type,
            'MinCount'     => 1,
            'MaxCount'     => 1,
        ];

        if ($market_type === 'spot') {
            $launchOpts['InstanceMarketOptions'] = [
                'MarketType'  => 'spot',
                'SpotOptions' => [
                    'SpotInstanceType'             => 'one-time',
                    'InstanceInterruptionBehavior' => 'terminate'
                ]
            ];
        }

        $instResult = $ec2->runInstances($launchOpts);
        $instanceId = $instResult['Instances'][0]['InstanceId'];

        // 4. Insert into your database (this is blocking until complete)
        $stmt = $pdo->prepare("
            INSERT INTO launched_instances 
                (account_id, instance_id, region, instance_type, launch_type, launched_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $accountId,
            $instanceId,
            $launchRegion,
            $instance_type,
            $market_type
        ]);

        // 5a. Record success
        $response['success']++;
        $response['details'][] = [
            'region'   => $launchRegion,
            'status'   => 'success',
            'instance' => $instanceId,
            'message'  => "Launched and recorded Instance <b>{$instanceId}</b> in <b>{$launchRegion}</b>."
        ];

    } catch (AwsException $awsEx) {
        // AWS SDK errors
        $response['failed']++;
        $response['details'][] = [
            'region'  => $launchRegion,
            'status'  => 'error',
            'message' => "AWS error in {$launchRegion}: " . $awsEx->getAwsErrorMessage()
        ];

    } catch (Exception $ex) {
        // DB errors or general exceptions
        $response['failed']++;
        $response['details'][] = [
            'region'  => $launchRegion,
            'status'  => 'error',
            'message' => "Error in {$launchRegion}: " . $ex->getMessage()
        ];
    }
}

// 6. Send back JSON to your front‐end response box
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
