<?php
include('../../db.php');
require '../../aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

if (!isset($_POST['id'])) {
    echo "Invalid request.";
    exit;
}

$id = $_POST['id'];

// Fetch the account by id
$stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "Account not found.";
    exit;
}

$aws_key    = $account['aws_key'];
$aws_secret = $account['aws_secret'];

try {
    // Create the STS client using provided credentials
    $stsClient = new StsClient([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'credentials' => [
            'key'    => $aws_key,
            'secret' => $aws_secret,
        ]
    ]);
    // Attempt to get caller identity
    $stsClient->getCallerIdentity();
    $status = "active";
    $message = "Account is active.";
} catch (AwsException $e) {
    $status = "suspended";
    $message = "Account suspended: " . $e->getAwsErrorMessage();
} catch (Exception $e) {
    $status = "suspended";
    $message = "Account suspended: " . $e->getMessage();
}

// Update the database with the new status
if ($status === "suspended") {
    $suspended_date = date("Y-m-d H:i:s");
    $suspend_mode = "auto";
    $acc_wasted = 'yes';
    $updateStmt = $pdo->prepare("UPDATE accounts SET status = ?, suspended_date = ?, suspend_mode = ?, wasted = ? WHERE id = ?");
    $updateStmt->execute([$status, $suspended_date, $suspend_mode, $acc_wasted, $id]);
} else {
    $updateStmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ?");
    $updateStmt->execute([$status, $id]);
}

echo $message;
?>
