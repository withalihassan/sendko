<?php
// add_in_cur_user.php
header('Content-Type: application/json; charset=utf-8');

// include DB connection (adjust path if needed)
require_once __DIR__ . '/../db.php';

// AWS SDK
require_once __DIR__ . '/../aws/aws-autoloader.php';
use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

// accept either names your JS will send
$aws_key    = isset($data['access_key_id']) ? trim($data['access_key_id']) : (isset($data['aws_key']) ? trim($data['aws_key']) : '');
$aws_secret = isset($data['secret_access_key']) ? trim($data['secret_access_key']) : (isset($data['aws_secret']) ? trim($data['aws_secret']) : '');
$assign_to  = isset($data['assign_to']) ? (int)$data['assign_to'] : 0; // will be used as `by_user` per your existing insert
$ac_worth   = isset($data['ac_worth']) ? trim($data['ac_worth']) : '0';

if (empty($aws_key) || empty($aws_secret) || empty($assign_to)) {
    echo json_encode(['success' => false, 'message' => 'access_key_id, secret_access_key and assign_to (user_id) are required.']);
    exit;
}

try {
    // Create STS client using provided temporary credentials
    $stsClient = new StsClient([
        'version'     => 'latest',
        'region'      => 'us-east-1', // change if you prefer another region
        'credentials' => [
            'key'    => $aws_key,
            'secret' => $aws_secret,
        ],
        'http' => ['timeout' => 10, 'connect_timeout' => 5],
    ]);

    $result = $stsClient->getCallerIdentity();
    $account_id = $result->get('Account');

    if (empty($account_id)) {
        echo json_encode(['success' => false, 'message' => 'Unable to determine AWS account id.']);
        exit;
    }

    // check duplicate
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_id = ?");
    $checkStmt->execute([$account_id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        echo json_encode(['success' => false, 'message' => 'Duplicate Account: this AWS account already exists in DB.']);
        exit;
    }

    // Insert into accounts table (keeps same column order as your original code)
    $added_date = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d H:i:s');
    $insertSql = "INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, ac_state, ac_score, ac_age, cr_offset, added_date, ac_worth)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($insertSql);
    $executed = $stmt->execute([
        $assign_to,
        $aws_key,
        $aws_secret,
        $account_id,
        'active',
        'orphan',
        '0',
        '0',
        '0',
        $added_date,
        $ac_worth
    ]);

    if ($executed) {
        echo json_encode(['success' => true, 'message' => "Account added successfully. AWS Account ID: " . htmlspecialchars($account_id)]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to insert account into database.']);
        exit;
    }
} catch (AwsException $e) {
    // AWS SDK specific errors
    $awsMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
    echo json_encode(['success' => false, 'message' => 'AWS Error: ' . $awsMsg]);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}
