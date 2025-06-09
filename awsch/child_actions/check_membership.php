<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../aws/aws-autoloader.php';
use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

// pull from POST
$accessKey = $_POST['aws_access_key']  ?? '';
$secretKey = $_POST['aws_secret_key']  ?? '';
$region    = $_POST['region']          ?? 'us-east-1';

if (!$accessKey || !$secretKey) {
    echo "<div class='alert alert-danger'>Missing AWS credentials.</div>";
    exit;
}

try {
    // init Organizations client
    $org = new OrganizationsClient([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey
        ]
    ]);

    // Describe the organization
    $result = $org->describeOrganization();
    $masterEmail = $result['Organization']['MasterAccountEmail'] ?? '';

    if ($masterEmail) {
        echo "<div class='alert alert-danger'>
                This account is not ready.<br>
                Script Error Re-run the Script.
              </div>";
    } else {
        // unlikely, but just in case
        echo "<div class='alert alert-warning'>
                AWS Organization exists, but no master email found.
              </div>";
    }
} catch (AwsException $e) {
    $code = $e->getAwsErrorCode();
    // if there is no organization or you’re not in one, AWS throws AWSOrganizationsNotInUseException
    if ($code === 'AWSOrganizationsNotInUseException' || 
        strpos($e->getMessage(), 'not in organization') !== false) {
        echo "<div class='alert alert-success'>
                This account is Ready.
              </div>";
    } else {
        // any other error
        echo "<div class='alert alert-danger'>
                Error checking organization membership:<br>"
             . htmlspecialchars($e->getMessage()) .
             "</div>";
    }
}
