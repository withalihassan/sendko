<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../../db.php';                // provides $pdo
require '../../aws/aws-autoloader.php'; // AWS SDK

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['parent_id'])) {
    $parent_id = $_GET['parent_id'];
    $newAccountsInserted = 0;

    try {
        // 1) Load AWS credentials for this parent
        $stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE account_id = ?");
        $stmt->execute([$parent_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $account) {
            exit('Parent account not found or missing AWS credentials.');
        }
        $aws_key    = $account['aws_key'];
        $aws_secret = $account['aws_secret'];

        // 2) Initialize AWS Organizations client
        $orgClient = new OrganizationsClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => [
                'key'    => $aws_key,
                'secret' => $aws_secret,
            ],
        ]);

        // 3) Fetch all AWS child accounts
        $result   = $orgClient->listAccounts();
        $accounts = $result['Accounts'] ?? [];

        // 4) Mark all existing DB children for this parent as not in org
        $pdo->prepare("
            UPDATE child_accounts
               SET is_in_org = 'No'
             WHERE parent_id = ?
        ")->execute([$parent_id]);

        // 5) Prepare our INSERT / UPDATE statements
        $insertStmt = $pdo->prepare("
            INSERT INTO child_accounts
                (parent_id, email, name, account_id, status, added_date, is_in_org)
            VALUES (?, ?, ?, ?, ?, ?, 'Yes')
        ");
        $updateStmt = $pdo->prepare("
            UPDATE child_accounts
               SET status     = ?,
                   added_date = ?,
                   is_in_org  = 'Yes'
             WHERE account_id = ?
        ");
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM child_accounts WHERE account_id = ?");

        // 6) Loop through AWS accounts
        foreach ($accounts as $acc) {
            $acctId   = $acc['Id']      ?? null;
            if (! $acctId) {
                continue; // skip malformed
            }
            $emailObj  = $acc['Email']           ?? null;
            $nameObj   = $acc['Name']            ?? null;
            $status    = $acc['Status']          ?? 'UNKNOWN';
            $joinedTs  = $acc['JoinedTimestamp'] ?? null;
            $joinedAt = ($joinedTs instanceof \DateTimeInterface)
                ? $joinedTs->format('Y-m-d H:i:s')
                : null;

            // ---- FIXED: split prepare/execute/fetch ----
            $checkStmt->execute([$acctId]);
            $existingCount = (int) $checkStmt->fetchColumn();

            if ($existingCount > 0) {
                // Already in DB → update
                $updateStmt->execute([
                    $status,
                    $joinedAt,
                    $acctId,
                ]);
            } else {
                // New → insert
                $insertStmt->execute([
                    $parent_id,
                    $emailObj,
                    $nameObj,
                    $acctId,
                    $status,
                    $joinedAt,
                ]);
                $newAccountsInserted++;
            }
        }

        // 7) Feedback
        if ($newAccountsInserted > 0) {
            echo "$newAccountsInserted new child account(s) inserted successfully!<br>";
        }
        echo 'Sync complete. Any DB-only children are now marked is_in_org = No.';
    }
    catch (AwsException $e) {
        exit('Error fetching child accounts: ' . $e->getMessage());
    }
    catch (PDOException $e) {
        exit('Database error: ' . $e->getMessage());
    }
} else {
    exit('Parent ID is missing.');
}
