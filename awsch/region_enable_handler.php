<?php
// region_enable_handler.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include __DIR__ . '/../db.php';
require_once __DIR__ . '/../aws/aws-autoloader.php';
use Aws\Account\AccountClient;
use Aws\Exception\AwsException;

if (!isset($_GET['ac_id']) || !isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'ac_id and user_id required']);
    exit;
}
$ac_id   = isset($_GET['ac_id'])   ?        $_GET['ac_id']   : null;
$user_id = isset($_GET['user_id']) ?        $_GET['user_id'] : null;


$stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = :account_id");
$stmt->execute([':account_id'=>$ac_id]);
$acct = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acct) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'Account not found']);
    exit;
}

$accountClient = new AccountClient([
    'version'     => '2021-02-01',
    'region'      => 'us-east-1',
    'credentials' => [
        'key'    => $acct['aws_access_key'],
        'secret' => $acct['aws_secret_key'],
    ],
]);

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
switch ($action) {
  case 'check_region_status':
    $region = $_POST['region'] ?? '';
    try {
      $res = $accountClient->getRegionOptStatus(['RegionName'=>$region]);
      echo json_encode(['success'=>true, 'status'=>$res->get('RegionOptStatus')]);
    } catch (AwsException $e) {
      echo json_encode(['success'=>false,'error'=>$e->getAwsErrorMessage()]);
    }
    exit;

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