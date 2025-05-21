<?php
include '../db.php';              // defines $pdo
// 1) grab & decode
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

// 2) validate
if (
    !is_array($data)
 || empty($data['access_key_id'])
 || empty($data['secret_access_key'])
) {
    echo json_encode(['success'=>false,'error'=>'Invalid input']);
    exit;
}

// 3) update
$stmt = $pdo->prepare("
    UPDATE iam_users
       SET status = 'Delivered'
     WHERE access_key_id       = ?
       AND secret_access_key   = ?
");
$stmt->execute([
    $data['access_key_id'],
    $data['secret_access_key'],
]);

// 4) respond
echo json_encode(['success'=>$stmt->rowCount()>0]);
