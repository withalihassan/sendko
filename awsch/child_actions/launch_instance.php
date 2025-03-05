<?php
require '../db_connect.php';
require '../aws/aws-autoloader.php';

// Get input values
$aws_access_key = $_POST['aws_access_key'] ?? '';
$aws_secret_key = $_POST['aws_secret_key'] ?? '';
$instance_type  = $_POST['instance_type'] ?? '';
$market_type    = $_POST['market_type'] ?? '';
$region         = $_POST['region'] ?? '';

if (empty($aws_access_key) || empty($aws_secret_key) || empty($instance_type) || empty($market_type)) {
    die("Missing required fields.");
}

// AMI mappings
$amiMap = [
    'us-east-1' => 'ami-0e2c8caa4b6378d8c',
    'us-east-2' => 'ami-036841078a4b68e14',
    'us-west-1' => 'ami-0657605d763ac72a8',
    'us-west-2' => 'ami-05d38da78ce859165',

    'ap-south-1' => 'ami-00bb6a80f01f03502',
    'ap-northeast-3' => 'ami-053e5b2b49d1b2a82',
    'ap-northeast-2' => 'ami-024ea438ab0376a47',
    'ap-southeast-1' => 'ami-0672fd5b9210aa093',
    'ap-southeast-2' => 'ami-09e143e99e8fa74f9',
    'ap-northeast-1' => 'ami-0a290015b99140cd1',

    'ca-central-1' => 'ami-055943271915205db',

    'eu-central-1' => 'ami-07eef52105e8a2059',
    'eu-west-1' => 'ami-03fd334507439f4d1',
    'eu-west-2' => 'ami-091f18e98bc129c4e',
    'eu-west-3' => 'ami-06e02ae7bdac6b938',
    'eu-north-1' => 'ami-09a9858973b288bdd',

    'sa-east-1' => 'ami-04d88e4b4e0a5db46'
];

$shFileUrl = 'https://s3.eu-north-1.amazonaws.com/insoftstudio.com/auto-start-process.sh';

$regionsToLaunch = ($region === "all") ? array_keys($amiMap) : [$region];

use Aws\Ec2\Ec2Client;
use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

$successCount = 0;
$failedCount = 0;

foreach ($regionsToLaunch as $launchRegion) {
    try {
        // Get the account ID
        $stsClient = new StsClient([
            'region'      => $launchRegion,
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_access_key,
                'secret' => $aws_secret_key,
            ],
        ]);
        
        $accountInfo = $stsClient->getCallerIdentity([]);
        $accountId = $accountInfo['Account'];

        $ec2Client = new Ec2Client([
            'region'      => $launchRegion,
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_access_key,
                'secret' => $aws_secret_key,
            ],
        ]);

        $amiId = $amiMap[$launchRegion] ?? null;
        if (!$amiId) {
            echo "AMI not found for region $launchRegion.<br>";
            continue;
        }

        // Prepare launch parameters
        $launchOptions = [
            'ImageId'        => $amiId,
            'InstanceType'   => $instance_type,
            'MinCount'       => 1,
            'MaxCount'       => 1,
            'UserData'       => base64_encode("#!/bin/bash\nwget -O /tmp/script.sh $shFileUrl && bash /tmp/script.sh"),
        ];

        if ($market_type == 'spot') {
            $launchOptions['InstanceMarketOptions'] = [
                'MarketType' => 'spot',
                'SpotOptions' => [
                    'SpotInstanceType' => 'one-time',
                    'InstanceInterruptionBehavior' => 'terminate'
                ]
            ];
        }

        $result = $ec2Client->runInstances($launchOptions);

        $instanceId = $result['Instances'][0]['InstanceId'];
        $successCount++;

        // Save instance details to the database
        $stmt = $conn->prepare("INSERT INTO launched_instances (account_id, instance_id, region, instance_type, launch_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$accountId, $instanceId, $launchRegion, $instance_type, $market_type]);

        echo "Instance <b>$instanceId</b> launched successfully in region <b>$launchRegion</b>.<br>";

    } catch (AwsException $e) {
        $failedCount++;
        echo "Error launching instance in $launchRegion: " . $e->getAwsErrorMessage() . "<br>";
    }
}

echo "<br>Success: $successCount, Failed: $failedCount";
?>
