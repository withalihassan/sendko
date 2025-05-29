<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../db_connect.php';
require './aws/aws-autoloader.php'; // Include the AWS SDK autoloader

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

// Check if parent_id is provided
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['parent_id'])) {
    $parent_id = $_GET['parent_id'];

    try {
        // Fetch the AWS credentials from the database for the given parent account ID
        $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM aws_accounts WHERE account_id = ?");
        $stmt->execute([$parent_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            // Extract AWS key and secret
            $aws_key = $account['aws_key'];
            $aws_secret = $account['aws_secret'];

            // AWS Organizations client using the fetched credentials
            $orgClient = new OrganizationsClient([
                'region' => 'us-east-1', // Set your AWS region
                'version' => 'latest',
                'credentials' => [
                    'key'    => $aws_key,  // Use the fetched AWS key
                    'secret' => $aws_secret // Use the fetched AWS secret
                ]
            ]);

            // Fetch child accounts under the given parent account
            $result = $orgClient->listAccounts();
            $accounts = $result['Accounts'];

            if (empty($accounts)) {
                echo 'No child accounts found.';
                exit;
            }

            // Store the fetched accounts in the database
            $stmt = $pdo->prepare("INSERT INTO child_accounts (parent_id, email, name, account_id, status) VALUES (?, ?, ?, ?, ?)");

            foreach ($accounts as $account) {
                $email = $account['Email'];
                $name = $account['Name'];
                $account_id = $account['Id'];
                $status = 'Running'; // Default status
                $stmt->execute([$parent_id, $email, $name, $account_id, $status]);
            }

            echo 'Child accounts fetched and stored successfully!';
        } else {
            echo 'Parent account not found or missing AWS credentials.';
        }

    } catch (AwsException $e) {
        echo 'Error fetching child accounts: ' . $e->getMessage();
    } catch (PDOException $e) {
        echo 'Database error: ' . $e->getMessage();
    }
} else {
    echo 'Parent ID is missing.';
}
