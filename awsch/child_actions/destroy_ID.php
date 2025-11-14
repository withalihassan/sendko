<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Adjust path if needed
require '../../aws/aws-autoloader.php';

use Aws\Organizations\OrganizationsClient;
use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

function renderForm($alertHtml = '') {
    echo <<<HTML
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Delete AWS Organization</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4">
  <h1 class="mb-4">Delete AWS Organization</h1>
  {$alertHtml}
  <form method="post" class="row g-3">
    <div class="col-md-4">
      <label class="form-label">AWS Access Key</label>
      <input name="aws_access_key" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">AWS Secret Key</label>
      <input name="aws_secret_key" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Region</label>
      <input name="region" class="form-control" value="us-east-1">
    </div>
    <div class="col-12">
      <button class="btn btn-danger" onclick="return confirm('Are you sure you want to DELETE this Organization? This is irreversible.');">Delete Organization</button>
    </div>
  </form>
</body></html>
HTML;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    renderForm();
}

$accessKey = trim($_POST['aws_access_key'] ?? '');
$secretKey = trim($_POST['aws_secret_key'] ?? '');
$region    = trim($_POST['region'] ?? 'us-east-1');

if (empty($accessKey) || empty($secretKey)) {
    renderForm("<div class='alert alert-danger'>Missing AWS credentials.</div>");
}

try {
    $org = new OrganizationsClient([
        'version' => 'latest',
        'region'  => $region,
        'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
    ]);

    $sts = new StsClient([
        'version' => 'latest',
        'region'  => $region,
        'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
    ]);

    // Get organization info and caller identity
    $orgInfo = $org->describeOrganization();
    $masterId = $orgInfo['Organization']['MasterAccountId'] ?? '';
    $masterEmail = $orgInfo['Organization']['MasterAccountEmail'] ?? '';

    $identity = $sts->getCallerIdentity();
    $ourAccountId = $identity['Account'] ?? '';

    if ($ourAccountId !== $masterId) {
        renderForm("<div class='alert alert-danger'>This operation must be run from the management (master) account. Your account: <strong>{$ourAccountId}</strong></div>");
    }

    // Attempt to delete the organization (organization must be empty of member accounts)
    $org->deleteOrganization();

    // Success page
    $msg = "Organization With ID ({$masterId}) Destroyed";
    echo <<<HTML
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Organization Deleted</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4">
  <div class="alert alert-success">
    <h4 class="alert-heading">Organization Deleted</h4>
    <!-- <p>{$msg}</p> -->
    <hr>
    <p><strong>Master Account ID:</strong> {$masterId}<br>
       <strong>Master Email:</strong> {$masterEmail}</p>
  </div>
  <p><a href="" class="btn btn-secondary">Back</a></p>
</body></html>
HTML;
} catch (AwsException $e) {
    $code = $e->getAwsErrorCode();
    $msg  = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

    // Known case: not in an org or org has members / other errors
    $alert = "<div class='alert alert-danger'><h4 class='alert-heading'>Error</h4><p>{$msg}</p></div>";
    renderForm($alert);
}
