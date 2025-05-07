<?php
// account_queries/fetch_existing_accounts.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the AWS PHP SDK autoloader
require_once __DIR__ . '/../aws/aws-autoloader.php';
use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['parent_id'])) {
    $parent_id = intval($_POST['parent_id']);
    $newAccountsInserted = 0;
    $accountsUpdated = 0;

    include __DIR__ . '/../db.php';

    // Fetch AWS credentials for the parent account
    $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
    $stmt->execute([$parent_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($account) {
        $aws_key = $account['aws_key'];
        $aws_secret = $account['aws_secret'];

        $orgClient = new OrganizationsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key' => $aws_key,
                'secret' => $aws_secret
            ]
        ]);

        try {
            $result = $orgClient->listAccounts();
            $accountsList = $result['Accounts'];

            if (empty($accountsList)) {
                echo json_encode(['success' => false, 'message' => 'No child accounts found.']);
                exit;
            }

            // Prepare statements for insertion and updating (using "aws_account_id" as unique identifier)
            $insertStmt = $pdo->prepare("INSERT INTO child_accounts (parent_account_id, email, account_name, aws_account_id, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $updateStmt = $pdo->prepare("UPDATE child_accounts SET status = ? WHERE aws_account_id = ?");

            foreach ($accountsList as $childAccount) {
                $email = isset($childAccount['Email']) ? $childAccount['Email'] : 'No email';
                $name = isset($childAccount['Name']) ? $childAccount['Name'] : 'No name';
                $aws_account_id = isset($childAccount['Id']) ? $childAccount['Id'] : 'No account ID';
                $status = isset($childAccount['Status']) ? $childAccount['Status'] : 'Running';

                // Check if account already exists in local DB
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM child_accounts WHERE aws_account_id = ?");
                $checkStmt->execute([$aws_account_id]);
                $existingAccount = $checkStmt->fetchColumn();

                if ($existingAccount > 0) {
                    // Update the status
                    $updateStmt->execute([$status, $aws_account_id]);
                    $accountsUpdated++;
                } else {
                    // Insert new record
                    $insertStmt->execute([$parent_id, $email, $name, $aws_account_id, $status]);
                    $newAccountsInserted++;
                }
            }

            $message = "";
            if ($newAccountsInserted > 0) {
                $message .= "$newAccountsInserted new child account(s) inserted. ";
            }
            if ($accountsUpdated > 0) {
                $message .= "$accountsUpdated existing child account(s) updated.";
            }
            if ($message == "") {
                $message = "No changes made.";
            }
            echo json_encode(['success' => true, 'message' => $message]);
        } catch (AwsException $e) {
            echo json_encode(['success' => false, 'message' => 'AWS Error: ' . $e->getAwsErrorMessage()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Parent account not found or missing AWS credentials.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Parent ID is missing.']);
}
