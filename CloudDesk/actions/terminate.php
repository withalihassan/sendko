<?php
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST ?? null;
if (!$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'no input received']); exit; }

$awsAccessKey = $input['awsAccessKey'] ?? null;
$awsSecretKey = $input['awsSecretKey'] ?? null;
$instanceId   = $input['instance_id'] ?? null;
$region       = $input['region'] ?? null;
$parent_id    = $input['parent_id'] ?? null;
$id           = $input['id'] ?? null;

if (!$awsAccessKey || !$awsSecretKey || !$instanceId || !$region) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'missing required parameters (awsAccessKey/awsSecretKey/instance_id/region)']);
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../aws/aws-autoloader.php';
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

// Helper: find DB connection object (PDO or mysqli)
function getDbHandle() {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return ['type'=>'pdo','handle'=>$GLOBALS['pdo']];
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return ['type'=>'pdo','handle'=>$GLOBALS['db']];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['conn']];
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['mysqli']];
    if (isset($GLOBALS['link']) && $GLOBALS['link'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['link']];
    return null;
}

$dbHandleInfo = getDbHandle();
if (!$dbHandleInfo) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'database connection not found. Ensure db.php defines $pdo (PDO) or $conn/$mysqli (mysqli).']);
    exit;
}

try {
    $ec2 = new Ec2Client([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $awsAccessKey,
            'secret' => $awsSecretKey,
        ],
        'http' => ['verify' => false]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'failed to create EC2 client','error'=>$e->getMessage()]);
    exit;
}

// 1) Terminate instance
$awsState = null;
try {
    $resp = $ec2->terminateInstances(['InstanceIds' => [$instanceId]]);
    $term = $resp->get('TerminatingInstances') ?? [];
    if (!empty($term) && is_array($term)) {
        $first = $term[0];
        if (isset($first['CurrentState']['Name'])) {
            $awsState = $first['CurrentState']['Name'];
        } elseif (isset($first['PreviousState']['Name'])) {
            $awsState = $first['PreviousState']['Name'];
        }
    }
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'AWS TerminateInstances failed','aws_error'=>$e->getAwsErrorMessage() ?? $e->getMessage()]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'AWS call failed','error'=>$e->getMessage()]);
    exit;
}

// 2) Delete DB row (matching instance_id OR id OR parent_id)
$deletedRows = 0;
try {
    if ($dbHandleInfo['type'] === 'pdo') {
        $pdo = $dbHandleInfo['handle'];
        $sql = "DELETE FROM `launched_desks` WHERE instance_id = :instance_id OR id = :id OR parent_id = :parent_id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':instance_id' => $instanceId, ':id' => $id, ':parent_id' => $parent_id]);
        $deletedRows = $stmt->rowCount();
    } else {
        $mysqli = $dbHandleInfo['handle'];
        $stmt = $mysqli->prepare("DELETE FROM `launched_desks` WHERE instance_id = ? OR id = ? OR parent_id = ? LIMIT 1");
        if ($stmt === false) throw new Exception('mysqli prepare failed: ' . $mysqli->error);
        $idForDelete = $id ?? '';
        $parentForDelete = $parent_id ?? '';
        $stmt->bind_param('sss', $instanceId, $idForDelete, $parentForDelete);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();
    }
} catch (Exception $e) {
    // Non-fatal: report in response
    echo json_encode(['status'=>'ok','message'=>'instance termination requested, but DB delete failed','instance_id'=>$instanceId,'aws_state'=>$awsState,'db_error'=>$e->getMessage()]);
    exit;
}

// 3) final short response
echo json_encode([
    'status' => 'ok',
    'instance_id' => $instanceId,
    'aws_state' => $awsState,
    'deleted_rows' => $deletedRows
]);
exit;
