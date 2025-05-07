<?php
require '../../db.php';
require '../../aws/aws-autoloader.php';  // Include AWS SDK

// Get input from POST request
$region = $_POST['region'] ?? '';
$aws_access_key = $_POST['aws_access_key'] ?? '';
$aws_secret_key = $_POST['aws_secret_key'] ?? '';

// Validate required parameters
if (empty($region) || empty($aws_access_key) || empty($aws_secret_key)) {
    die("Missing required parameters.");
}

use Aws\ServiceQuotas\ServiceQuotasClient;
use Aws\Exception\AwsException;

try {
    // Create ServiceQuotasClient with AWS credentials
    $client = new ServiceQuotasClient([
        'region'  => $region,
        'version' => 'latest',
        'credentials' => [
            'key'    => $aws_access_key,
            'secret' => $aws_secret_key,
        ],
    ]);

    // Check quota for EC2 Spot Instances (L-34B43A08)
    $quotaCode = 'L-34B43A08';  // Code for Spot Instance Request Limits
    $serviceCode = 'ec2';

    $result = $client->getServiceQuota([
        'ServiceCode' => $serviceCode,
        'QuotaCode'   => $quotaCode,
    ]);

    // Get quota value
    $spotQuota = $result['Quota']['Value'];

    echo "Spot Instance Quota for EC2 in region <strong>$region</strong>: <strong>$spotQuota</strong> instances allowed.";
} catch (AwsException $e) {
    echo "Error fetching quota: " . $e->getMessage();
}
?>
