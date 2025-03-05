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
    $stsClient = new StsClient([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'credentials' => [
            'key'    => $aws_key,
            'secret' => $aws_secret,
        ]
    ]);
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

if ($status === "suspended") {
    $suspended_date = date("Y-m-d H:i:s");
    $updateStmt = $pdo->prepare("UPDATE accounts SET status = ?, suspended_date = ? WHERE id = ?");
    $updateStmt->execute([$status, $suspended_date, $id]);
} else {
    $updateStmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ?");
    $updateStmt->execute([$status, $id]);
}

echo $message;
?>
