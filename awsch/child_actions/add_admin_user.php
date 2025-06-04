<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../../db.php';
require '../../aws/aws-autoloader.php';
use Aws\Iam\IamClient;
use Aws\Exception\AwsException;

header('Content-Type: application/json');

// 1) grab posted data
$accessKey = $_POST['aws_access_key'] ?? '';
$secretKey = $_POST['aws_secret_key'] ?? '';
$childId   = $_POST['ac_id']         ?? '';
$user_id   = $_POST['user_id']         ?? '';

if (!$accessKey || !$secretKey || !$childId) {
    echo json_encode(['error'=>'Missing credentials or account ID.']);
    exit;
}

$iam = new IamClient([
    'version'     => 'latest',
    'region'      => 'us-east-1', // IAM is global
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey
    ]
]);

try {
    // 2) generate username + password
    $username = 'admin_' . uniqid();
    $password = bin2hex(random_bytes(8)) . 'Aa1!';

    // 3) create IAM user
    $iam->createUser(['UserName'=>$username]);

    // 4) attach AdministratorAccess
    $iam->attachUserPolicy([
        'UserName'  => $username,
        'PolicyArn' => 'arn:aws:iam::aws:policy/AdministratorAccess'
    ]);

    // 5) create console login profile
    $iam->createLoginProfile([
        'UserName'              => $username,
        'Password'              => $password,
        'PasswordResetRequired' => false
    ]);

    // 6) create access key for API
    $keyResult = $iam->createAccessKey(['UserName'=>$username]);
    $accessKeyId     = $keyResult['AccessKey']['AccessKeyId'];
    $secretAccessKey = $keyResult['AccessKey']['SecretAccessKey'];

    // 7) build the sign‑in URL (by querying your own account ID)
    $sts = new \Aws\Sts\StsClient([
        'version'=>'latest',
        'region'=>'us-east-1',
        'credentials'=>[
            'key'=>$accessKey,'secret'=>$secretKey
        ]
    ]);
    $acct     = $sts->getCallerIdentity([])['Account'];
    $loginUrl = "https://{$acct}.signin.aws.amazon.com/console";
    $added_by="boys";
    // 8) persist everything
    $stmt = $pdo->prepare("
      INSERT INTO `iam_users`
        (`by_user`,`child_account_id`,`username`,`password`,
         `access_key_id`,`secret_access_key`,`login_url`, `added_by`)
      VALUES (?, ?, ?, ?, ?, ?, ?,?)
    ");
    $stmt->execute([
      $user_id, $childId, $username, $password,
      $accessKeyId, $secretAccessKey, $loginUrl, $added_by
    ]);

    // 9) return JSON for the front‑end
    echo json_encode([
      'username'         => $username,
      'password'         => $password,
      'access_key_id'    => $accessKeyId,
      'secret_access_key'=> $secretAccessKey,
      'login_url'        => $loginUrl
    ]);

} catch (AwsException $e) {
    echo json_encode(['error' => $e->getAwsErrorMessage()]);
}
