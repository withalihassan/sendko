<?php
// account_queries/create_child_account.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../aws/aws-autoloader.php';
use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

if (!isset($_POST['ac_id'], $_POST['email'], $_POST['account_name'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
    exit;
}

$ac_id = intval($_POST['ac_id']);
$email = $_POST['email'];
$account_name = $_POST['account_name'];

include __DIR__ . '/../db.php';

// Fetch AWS credentials
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$ac_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

$aws_key    = $account['aws_key'];
$aws_secret = $account['aws_secret'];

$client = new OrganizationsClient([
    'version' => 'latest',
    'region'  => 'us-east-1',
    'credentials' => [
         'key'    => $aws_key,
         'secret' => $aws_secret,
    ],
]);

try {
    $result = $client->createAccount([
      'Email' => $email,
      'AccountName' => $account_name,
      // Additional parameters as needed.
    ]);
    // Save the child account record to your DB
    $stmtInsert = $pdo->prepare("INSERT INTO child_accounts (parent_account_id, email, account_name, aws_account_id, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    // Here, we assume the AWS API returns the child account ID in ['CreateAccountStatus']['AccountId']
    $aws_account_id = isset($result['CreateAccountStatus']['AccountId']) ? $result['CreateAccountStatus']['AccountId'] : 'Pending';
    $status = 'Pending';
    $stmtInsert->execute([$ac_id, $email, $account_name, $aws_account_id, $status]);
    echo json_encode(['success' => true, 'message' => 'Child account creation initiated.', 'data' => $result->toArray()]);
} catch (AwsException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getAwsErrorMessage()]);
}
