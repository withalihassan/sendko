<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require './aws/aws-autoloader.php'; // Include the AWS SDK autoloader

// Database connection
require './db_connect.php';

// Fetch child_id and parent_id from the URL
$child_id = isset($_GET['child_id']) ? $_GET['child_id'] : null;
$parent_id = isset($_GET['parent_id']) ? $_GET['parent_id'] : null;

// Ensure both child_id and parent_id are present
if ($child_id && $parent_id) {
    // Prepare the SQL query to fetch account keys
    $sql = "SELECT `aws_access_key`, `aws_secret_key`
            FROM `child_accounts`
            WHERE `account_id` = :child_id AND `parent_id` = :parent_id";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':child_id', $child_id, PDO::PARAM_INT);
    $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);

    // Execute the query
    $stmt->execute();

    // Fetch the result
    $accountData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if data exists
    if ($accountData) {
        // Check if the AWS access and secret keys are available
        if (!empty($accountData['aws_access_key']) && !empty($accountData['aws_secret_key'])) {
            // Assign the keys to the variables
            $awsAccessKey = $accountData['aws_access_key'];
            $awsSecretKey = $accountData['aws_secret_key'];
            echo '<div class="response success">Child Account is ready to use</div>';
        } else {
            // Keys are not available
            echo '<div class="response error">You must first set up the account and open it</div>';
            $awsAccessKey = NULL;
            $awsSecretKey = NULL;
        }
    } else {
        echo "No account found for the given child_id and parent_id.";
    }
} else {
    echo "Child ID and Parent ID are required.";
}

// AWS Access and Secret Key
$regions = [
    'us-east-1' => 'ami-0e2c8caa4b6378d8c',
    'us-east-2' => 'ami-036841078a4b68e14',
    'us-west-1' => 'ami-0657605d763ac72a8',
    'us-west-2' => 'ami-05d38da78ce859165',
    'ap-south-1' => 'ami-053b12d3152c0cc71',
    'ap-northeast-2' => 'ami-0dc44556af6f78a7b',
    'ap-southeast-1' => 'ami-06650ca7ed78ff6fa',
    'ap-southeast-2' => 'ami-003f5a76758516d1e',
    'ca-central-1' => 'ami-0bee12a638c7a8942',
    'eu-central-1' => 'ami-0a628e1e89aaedf80',
    'eu-west-1' => 'ami-0e9085e60087ce171',
    'eu-west-2' => 'ami-05c172c7f0d3aed00',
    'sa-east-1' => 'ami-015f3596bb2ef1aaa',
];

$credentials = new Aws\Credentials\Credentials($awsAccessKey, $awsSecretKey);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'launch_one' || $action === 'launch_all') {
        $instanceType = $_POST['instance_type'];
        $launchType = $_POST['launch_type'];
        $region = $_POST['region'];
        $amiId = $regions[$region];

        if ($action === 'launch_one') {
            launchInstance($region, $amiId, $instanceType, $launchType);
        } else {
            foreach ($regions as $region => $amiId) {
                launchInstance($region, $amiId, $instanceType, $launchType);
            }
        }
    } elseif ($action === 'terminate') {
        $instanceId = $_POST['instance_id'];
        terminateInstance($instanceId);
    } elseif ($action === 'check_status') {
        $instanceId = $_POST['instance_id'];
        checkInstanceStatus($instanceId);
    }
}

function launchInstance($region, $amiId, $instanceType, $launchType)
{
    global $credentials, $conn;

    try {
        $ec2Client = new Aws\Ec2\Ec2Client([
            'region' => $region,
            'version' => 'latest',
            'credentials' => $credentials,
        ]);

        $params = [
            'ImageId' => $amiId,
            'InstanceType' => $instanceType,
            'MinCount' => 1,
            'MaxCount' => 1,
            'TagSpecifications' => [
                [
                    'ResourceType' => 'instance',
                    'Tags' => [
                        [
                            'Key' => 'Name',
                            'Value' => 'MyUbuntuInstance-' . $region,
                        ],
                    ],
                ],
            ],
        ];

        if ($launchType === 'spot') {
            $params['InstanceMarketOptions'] = [
                'MarketType' => 'spot',
            ];
        }

        $result = $ec2Client->runInstances($params);
        $instanceId = $result['Instances'][0]['InstanceId'];

        // Start the shell script after launch
        $shFileUrl = 'https://s3.eu-north-1.amazonaws.com/insoftstudio.com/auto-start-process.sh';
        startShellScript($instanceId, $shFileUrl);

        // Insert instance data into the database
        $stmt = $conn->prepare("INSERT INTO launched_instances (instance_id, region, instance_type, launch_type, state) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$instanceId, $region, $instanceType, $launchType, 'pending']);

        echo '<div class="response success">Instance launched successfully!</div>';
    } catch (Aws\Exception\AwsException $e) {
        echo "Error launching instance: " . $e->getMessage() . "<br>";
    }
}

function startShellScript($instanceId, $shFileUrl)
{
    global $credentials;
    try {
        $ec2Client = new Aws\Ec2\Ec2Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => $credentials,
        ]);
        
        // Execute the shell script via EC2 instance user data
        $commands = [
            "#!/bin/bash",
            "curl -O $shFileUrl",
            "chmod +x auto-start-process.sh",
            "./auto-start-process.sh"
        ];

        $userData = base64_encode(implode("\n", $commands));

        $ec2Client->modifyInstanceAttribute([
            'InstanceId' => $instanceId,
            'UserData' => [
                'Value' => $userData
            ]
        ]);
    } catch (Aws\Exception\AwsException $e) {
        echo "Error starting shell script: " . $e->getMessage() . "<br>";
    }
}

function terminateInstance($instanceId)
{
    global $credentials, $conn;

    try {
        $ec2Client = new Aws\Ec2\Ec2Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => $credentials,
        ]);

        $ec2Client->terminateInstances([
            'InstanceIds' => [$instanceId],
        ]);

        // Update the state of the instance in the database
        $stmt = $conn->prepare("UPDATE launched_instances SET state = 'terminated' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);

        echo '<div class="response success">Instance terminated successfully!</div>';
    } catch (Aws\Exception\AwsException $e) {
        echo "Error terminating instance: $instanceId<br>";
        echo "Error message: " . $e->getMessage() . "<br>";
    }
}

function checkInstanceStatus($instanceId)
{
    global $credentials;

    try {
        $ec2Client = new Aws\Ec2\Ec2Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => $credentials,
        ]);

        $result = $ec2Client->describeInstances([
            'InstanceIds' => [$instanceId],
        ]);

        $state = $result['Reservations'][0]['Instances'][0]['State']['Name'];
        echo "<div class='response info'>Instance $instanceId is currently $state.</div>";
    } catch (Aws\Exception\AwsException $e) {
        echo "Error checking instance status: " . $e->getMessage() . "<br>";
    }
}

// Fetch running instances from the database
$stmt = $conn->query("SELECT * FROM launched_instances WHERE state IN ('pending', 'running')");
$runningInstances = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage EC2 Instances</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            background-color: #f4f4f9;
            padding: 20px;
        }

        select,
        button,
        input {
            padding: 10px;
            font-size: 16px;
            margin: 10px;
        }

        table {
            width: 90%;
            margin: 20px auto;
            border-collapse: collapse;
        }

        table,
        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
        }

        th {
            background-color: #4CAF50;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        button {
            cursor: pointer;
            background-color: #4CAF50;
            color: white;
            border: none;
        }

        button:hover {
            background-color: #45a049;
        }

        .response {
            font-weight: bold;
            padding: 15px 25px;
            border-radius: 25px;
            margin: 10px auto;
            max-width: 400px;
            text-align: center;
            box-shadow: 4px 4px 10px rgba(0, 0, 0, 0.15), -4px -4px 10px rgba(255, 255, 255, 0.2);
        }

        .response.success {
            background-color: #28a745;
            color: white;
        }

        .response.error {
            background-color: #dc3545;
            color: white;
        }

        .response.info {
            background-color: #17a2b8;
            color: white;
        }
    </style>
</head>

<body>
    <h1>Manage EC2 Instances</h1>

    <form method="post">
        <label for="instance_type">Choose Instance Type:</label>
        <select name="instance_type" id="instance_type">
            <option value="t2.micro">t2.micro</option>
            <option value="t2.small">t2.small</option>
        </select><br>

        <label for="launch_type">Choose Launch Type:</label>
        <select name="launch_type" id="launch_type">
            <option value="on-demand">On-demand</option>
            <option value="spot">Spot</option>
        </select><br>

        <label for="region">Choose Region:</label>
        <select name="region" id="region">
            <?php foreach ($regions as $key => $value) {
                echo "<option value='$key'>$key</option>";
            } ?>
        </select><br>

        <input type="hidden" name="action" value="launch_one">
        <button type="submit">Launch Instance</button>
    </form>

    <h2>Running Instances</h2>
    <table>
        <thead>
            <tr>
                <th>Instance ID</th>
                <th>Region</th>
                <th>Instance Type</th>
                <th>Launch Type</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($runningInstances as $instance) { ?>
                <tr>
                    <td><?php echo $instance['instance_id']; ?></td>
                    <td><?php echo $instance['region']; ?></td>
                    <td><?php echo $instance['instance_type']; ?></td>
                    <td><?php echo $instance['launch_type']; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="instance_id" value="<?php echo $instance['instance_id']; ?>">
                            <input type="hidden" name="action" value="terminate">
                            <button type="submit">Terminate</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="instance_id" value="<?php echo $instance['instance_id']; ?>">
                            <input type="hidden" name="action" value="check_status">
                            <button type="submit">Check Status</button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

</body>

</html>
