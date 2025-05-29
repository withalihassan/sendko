<?php
// Parent's AWS credentials (Use your actual Parent IAM keys here)
$accessKey = 'YOUR_PARENT_ACCESS_KEY';
$secretKey = 'YOUR_PARENT_SECRET_KEY';

// Role ARN (Role in Child Account to Assume)
$roleArn = 'arn:aws:iam::682033460740:role/OrganizationAccountAccessRole';  // Replace with your role ARN

// Session Name for the Assume Role request
$sessionName = 'MySessionName';

// Construct the AWS CLI command for assuming the role
$command = "aws sts assume-role --role-arn $roleArn --role-session-name $ --region us-east-1";

// Execute the shell command and capture the output
$output = shell_exec($command);

// Decode the JSON output from AWS CLI to extract the temporary credentials
$credentials = json_decode($output, true);

// Check if the response contains temporary credentials
if (isset($credentials['Credentials'])) {
    // Extract the temporary credentials
    $tempAccessKey = $credentials['Credentials']['AccessKeyId'];
    $tempSecretKey = $credentials['Credentials']['SecretAccessKey'];
    $tempSessionToken = $credentials['Credentials']['SessionToken'];
    $expiration = $credentials['Credentials']['Expiration'];

    // Display the temporary credentials and expiration time
    echo "Temporary Access Key: $tempAccessKey<br>";
    echo "Temporary Secret Key: $tempSecretKey<br>";
    echo "Temporary Session Token: $tempSessionToken<br>";
    echo "Credentials Expiration: $expiration<br>";
} else {
    echo "Error: Unable to assume role and retrieve temporary credentials.<br>";
    echo "Output: $output";
}
?>
