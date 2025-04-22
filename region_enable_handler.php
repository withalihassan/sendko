<?php
// region_enable_handler.php

// 1) Show all errors for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2) DB connection
include __DIR__ . '/db.php'; // defines $pdo

// 3) AWS SDK
require_once __DIR__ . '/aws/aws-autoloader.php';
use Aws\Account\AccountClient;
use Aws\Exception\AwsException;

// 4) Get account and user IDs
if (!isset($_GET['ac_id']) || !isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'ac_id and user_id required']);
    exit;
}
$ac_id   = intval($_GET['ac_id']);
$user_id = intval($_GET['user_id']);

// 5) Fetch AWS keys from DB
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = :id");
$stmt->execute([':id'=>$ac_id]);
$acct = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acct) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'Account not found']);
    exit;
}

// 6) Instantiate AccountClient with fetched creds
$accountClient = new AccountClient([
    'version'     => '2021-02-01',
    'region'      => 'us-east-1',               // control‑plane region :contentReference[oaicite:8]{index=8}
    'credentials' => [
        'key'    => $acct['aws_key'],
        'secret' => $acct['aws_secret'],
    ],
]);

header('Content-Type: application/json');

// 7) Handle AJAX actions
$action = $_POST['action'] ?? '';
switch ($action) {

  // 7a) Check current opt‑in status
  case 'check_region_status':
    $region = $_POST['region'] ?? '';
    try {
      $res = $accountClient->getRegionOptStatus(['RegionName'=>$region]);
      echo json_encode(['success'=>true, 'status'=>$res->get('RegionOptStatus')]);
    } catch (AwsException $e) {
      echo json_encode(['success'=>false,'error'=>$e->getAwsErrorMessage()]);
    }
    exit;

  // 7b) Submit enable request
  case 'enable_region':
    $region = $_POST['region'] ?? '';
    try {
      $accountClient->enableRegion(['RegionName'=>$region]);
      echo json_encode(['success'=>true]);
    } catch (AwsException $e) {
      echo json_encode(['success'=>false,'error'=>$e->getAwsErrorMessage()]);
    }
    exit;

  default:
    echo json_encode(['success'=>false,'error'=>'Invalid action']);
    exit;
}
