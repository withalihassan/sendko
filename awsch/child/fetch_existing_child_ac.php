<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../../db.php';  // Include the database connection file (provides $pdo)
require '../../aws/aws-autoloader.php';  // Include the AWS SDK autoloader

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

// Check if parent_id is provided via GET
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['parent_id'])) {
    $parent_id = $_GET['parent_id'];
    $newAccountsInserted = 0;  // Counter for newly inserted accounts

    try {
        // Fetch the AWS credentials for the given parent account ID
        $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE account_id = ?");
        $stmt->execute([$parent_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            // Extract AWS key and secret
            $aws_key = $account['aws_key'];
            $aws_secret = $account['aws_secret'];

            // Initialize AWS Organizations client using the fetched credentials
            $orgClient = new OrganizationsClient([
                'region' => 'us-east-1',  // Set your AWS region
                'version' => 'latest',
                'credentials' => [
                    'key'    => $aws_key,
                    'secret' => $aws_secret
                ]
            ]);

            // Fetch child accounts under the given parent account
            $result = $orgClient->listAccounts();
            $accounts = $result['Accounts'];

            if (empty($accounts)) {
                echo 'No child accounts found.';
                exit;
            }

            // Prepare the statements for insertion or updating using $pdo
            $insertStmt = $pdo->prepare("INSERT INTO child_accounts (parent_id, email, name, account_id, status) VALUES (?, ?, ?, ?, ?)");
            $updateStmt = $pdo->prepare("UPDATE child_accounts SET status = ? WHERE account_id = ?");

            // Iterate through the returned accounts
            foreach ($accounts as $acc) {
                // Ensure keys exist before accessing them
                $email = isset($acc['Email']) ? $acc['Email'] : 'No email';
                $name = isset($acc['Name']) ? $acc['Name'] : 'No name';
                $account_id = isset($acc['Id']) ? $acc['Id'] : 'No account ID';
                $status = isset($acc['Status']) ? $acc['Status'] : 'Running';

                // Check if account already exists in the local DB
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM child_accounts WHERE account_id = ?");
                $checkStmt->execute([$account_id]);
                $existingAccount = $checkStmt->fetchColumn();

                if ($existingAccount > 0) {
                    // Account exists, update its status
                    $updateStmt->execute([$status, $account_id]);
                } else {
                    // Account does not exist, insert a new record
                    $insertStmt->execute([$parent_id, $email, $name, $account_id, $status]);
                    $newAccountsInserted++;
                }
            }
            if ($newAccountsInserted > 0) {
                echo "$newAccountsInserted new child account(s) inserted successfully!";
            } else {
                echo 'No new child accounts inserted. All accounts are already in the database.';
            }
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
?>
