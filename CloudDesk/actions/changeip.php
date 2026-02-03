<?php
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST ?? null;
if (!$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'no input']); exit; }

$awsAccessKey = $input['awsAccessKey'] ?? null;
$awsSecretKey = $input['awsSecretKey'] ?? null;
$instanceId   = $input['instance_id'] ?? null;
$region       = $input['region'] ?? null;
$parent_id    = $input['parent_id'] ?? null;
$id           = $input['id'] ?? null;

if (!$awsAccessKey || !$awsSecretKey || !$instanceId || !$region) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'missing parameters']);
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../aws/aws-autoloader.php';
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

function getDbHandle() {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return ['type'=>'pdo','handle'=>$GLOBALS['pdo']];
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return ['type'=>'pdo','handle'=>$GLOBALS['db']];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['conn']];
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['mysqli']];
    if (isset($GLOBALS['link']) && $GLOBALS['link'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['link']];
    return null;
}

$dbHandleInfo = getDbHandle();

try {
    $ec2 = new Ec2Client([
        'version' => 'latest',
        'region'  => $region,
        'credentials' => ['key'=>$awsAccessKey,'secret'=>$awsSecretKey],
        'http' => ['verify'=>false]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'ec2 client error','error'=>$e->getMessage()]);
    exit;
}

function removeAddress($ec2, $addr) {
    $id = $addr['AllocationId'] ?? ($addr['PublicIp'] ?? null);
    try {
        if (!empty($addr['AssociationId'])) {
            $ec2->disassociateAddress(['AssociationId' => $addr['AssociationId']]);
        } elseif (!empty($addr['PublicIp'])) {
            $ec2->disassociateAddress(['PublicIp' => $addr['PublicIp']]);
        }
    } catch (AwsException $e) { }
    try {
        if (!empty($addr['AllocationId'])) {
            $ec2->releaseAddress(['AllocationId' => $addr['AllocationId']]);
        } elseif (!empty($addr['PublicIp'])) {
            $ec2->releaseAddress(['PublicIp' => $addr['PublicIp']]);
        }
    } catch (AwsException $e) { }
    return $id;
}

$previous_deleted = [];
try {
    $desc = $ec2->describeAddresses(['Filters'=>[['Name'=>'instance-id','Values'=>[$instanceId]]]]);
    $addrs = $desc->get('Addresses') ?? [];
    $count = 0;
    foreach ($addrs as $a) {
        if ($count >= 2) break;
        $deleted = removeAddress($ec2, $a);
        if ($deleted) $previous_deleted[] = $deleted;
        $count++;
    }
} catch (AwsException $e) { }

$new = ['public_ip'=>null,'allocation_id'=>null,'association_id'=>null];
try {
    $alloc = $ec2->allocateAddress(['Domain'=>'vpc']);
    $allocId = $alloc->get('AllocationId');
    $pubIp = $alloc->get('PublicIp');
    $assoc = $ec2->associateAddress(['InstanceId'=>$instanceId,'AllocationId'=>$allocId]);
    $assocId = $assoc->get('AssociationId') ?? null;
    $new = ['public_ip'=>$pubIp,'allocation_id'=>$allocId,'association_id'=>$assocId];
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'allocate/associate failed','error'=>$e->getAwsErrorMessage() ?? $e->getMessage()]);
    exit;
}

if ($dbHandleInfo) {
    try {
        $targetIp = $new['public_ip'];
        if ($dbHandleInfo['type'] === 'pdo') {
            $pdo = $dbHandleInfo['handle'];
            $sql = "UPDATE `launched_desks` SET `public_ip` = :ip WHERE instance_id = :instance_id OR id = :id OR parent_id = :parent_id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':ip'=>$targetIp,':instance_id'=>$instanceId,':id'=>$id,':parent_id'=>$parent_id]);
        } else {
            $mysqli = $dbHandleInfo['handle'];
            $stmt = $mysqli->prepare("UPDATE `launched_desks` SET `public_ip` = ? WHERE instance_id = ? OR id = ? OR parent_id = ? LIMIT 1");
            if ($stmt) {
                $idForUpdate = $id ?? '';
                $parentForUpdate = $parent_id ?? '';
                $stmt->bind_param('ssss', $targetIp, $instanceId, $idForUpdate, $parentForUpdate);
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Exception $e) { }
}

echo json_encode([
    'previous_aborted' => array_values($previous_deleted),
    'new_assigned' => $new
]);
exit;
