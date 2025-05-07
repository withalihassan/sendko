<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db_connect.php';
require './aws/aws-autoloader.php'; // Include the AWS SDK autoloader
use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $aws_key = $_POST['aws_key'];
    $aws_secret = $_POST['aws_secret'];
    $email = $_POST['email'];

    if (empty($aws_key) || empty($aws_secret) || empty($email)) {
        echo '<div class="alert alert-danger">All fields are required.</div>';
        exit;
    }

    try {
        // Initialize AWS STS Client to fetch AWS account ID
        $stsClient = new StsClient([
            'region'      => 'us-east-1',  // Change to your preferred AWS region
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_key,
                'secret' => $aws_secret,
            ],
        ]);

        // Get AWS Account ID
        $result = $stsClient->getCallerIdentity([]);
        $account_id = $result['Account'];
        $display = "yes";

        // Insert data into database
        $stmt = $conn->prepare("INSERT INTO aws_accounts (aws_key, aws_secret, email, account_id, display) VALUES (:aws_key, :aws_secret, :email, :account_id, :display)");
        $stmt->execute([
            ':aws_key'    => $aws_key,
            ':aws_secret' => $aws_secret,
            ':email'      => $email,
            ':account_id' => $account_id,
            ':display' => $display
        ]);

        echo '<div class="alert alert-success">AWS Account added successfully! Account ID: ' . $account_id . '</div>';
    } catch (AwsException $e) {
        echo '<div class="alert alert-danger">Error: ' . $e->getAwsErrorMessage() . '</div>';
    }
}
?>
