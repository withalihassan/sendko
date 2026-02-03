<?php
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST ?? null;
if (!$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'no input received']); exit; }

// required params
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

// load DB and AWS SDK
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

// 1) call AWS to stop the instance
try {
    $ec2 = new Ec2Client([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $awsAccessKey,
            'secret' => $awsSecretKey,
        ],
        // optional - remove or set to true in production if you want certificate verification
        'http' => ['verify' => false]
    ]);

    $resp = $ec2->stopInstances([
        'InstanceIds' => [$instanceId]
    ]);

    // parse AWS response for state info
    $stoppingInstances = $resp->get('StoppingInstances') ?? [];
    $awsCurrentState = null;
    if (!empty($stoppingInstances) && is_array($stoppingInstances)) {
        $first = $stoppingInstances[0];
        // current state name if available
        if (isset($first['CurrentState']['Name'])) {
            $awsCurrentState = $first['CurrentState']['Name'];
        } elseif (isset($first['PreviousState']['Name'])) {
            $awsCurrentState = $first['PreviousState']['Name'];
        }
    }
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode([
        'status'=>'error',
        'message'=>'AWS StopInstances call failed',
        'aws_error' => $e->getAwsErrorMessage() ?? $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'AWS call failed','error'=>$e->getMessage()]);
    exit;
}

// 2) update DB: set state = 'stopped' for the matching row
$updateSuccess = false;
$updatedRows = 0;
try {
    $targetState = 'stopped';
    if ($dbHandleInfo['type'] === 'pdo') {
        $pdo = $dbHandleInfo['handle'];
        $updateSql = "UPDATE `launched_desks` 
                      SET `state` = :state 
                      WHERE instance_id = :instance_id OR id = :id OR parent_id = :parent_id
                      LIMIT 1";
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute([
            ':state' => $targetState,
            ':instance_id' => $instanceId,
            ':id' => $id,
            ':parent_id' => $parent_id
        ]);
        $updatedRows = $stmt->rowCount();
        $updateSuccess = ($updatedRows > 0);
    } else { // mysqli
        $mysqli = $dbHandleInfo['handle'];
        $stmt = $mysqli->prepare("UPDATE `launched_desks` SET `state` = ? WHERE instance_id = ? OR id = ? OR parent_id = ? LIMIT 1");
        if ($stmt === false) throw new Exception("mysqli prepare failed: " . $mysqli->error);
        $idForUpdate = $id ?? '';
        $parentForUpdate = $parent_id ?? '';
        $stmt->bind_param('ssss', $targetState, $instanceId, $idForUpdate, $parentForUpdate);
        $stmt->execute();
        $updatedRows = $stmt->affected_rows;
        $updateSuccess = ($updatedRows > 0);
        $stmt->close();
    }
} catch (Exception $e) {
    // non-fatal: still return AWS response but include DB error
    echo json_encode([
        'status'=>'ok',
        'message'=>'instance stop requested, but DB update failed',
        'instance_id' => $instanceId,
        'aws_state' => $awsCurrentState,
        'db_error' => $e->getMessage()
    ]);
    exit;
}

// 3) final response
if ($updateSuccess) {
    echo json_encode([
        'status' => 'ok',
        'message' => 'stop request sent and DB updated to stopped',
        'instance_id' => $instanceId,
        'aws_state' => $awsCurrentState
    ]);
    exit;
} else {
    // AWS call succeeded but DB update did not match any rows
    echo json_encode([
        'status' => 'ok',
        'message' => 'stop request sent but DB update did not affect any rows',
        'instance_id' => $instanceId,
        'aws_state' => $awsCurrentState
    ]);
    exit;
}
