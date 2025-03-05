<?php
// account_queries/create_organization.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the AWS PHP SDK autoloader
require_once __DIR__ . '/../aws/aws-autoloader.php';
use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

if (!isset($_POST['ac_id'])) {
    echo json_encode(['success' => false, 'message' => 'Account ID missing.']);
    exit;
}
$ac_id = intval($_POST['ac_id']);

include __DIR__ . '/../db.php';

// Check if an organization already exists
$stmt = $pdo->prepare("SELECT * FROM organizations WHERE account_id = ?");
$stmt->execute([$ac_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);
if ($org) {
    echo json_encode(['success' => false, 'message' => 'Organization already exists.']);
    exit;
}

// Fetch AWS credentials for the account
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
    'region'  => 'us-east-1', // Organizations API requires us-east-1
    'credentials' => [
         'key'    => $aws_key,
         'secret' => $aws_secret,
    ],
]);

try {
    $result = $client->createOrganization([
        'FeatureSet' => 'ALL',
    ]);
    // Save organization details to your DB
    $stmtInsert = $pdo->prepare("INSERT INTO organizations (account_id, org_id, created_at) VALUES (?, ?, NOW())");
    $stmtInsert->execute([$ac_id, $result['Organization']['Id']]);
    echo json_encode(['success' => true, 'message' => 'Organization created successfully.', 'data' => $result->toArray()]);
} catch (AwsException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getAwsErrorMessage()]);
}
