<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Adjust this path if needed
require '../../aws/aws-autoloader.php';

use Aws\Organizations\OrganizationsClient;
use Aws\Sts\StsClient;
use Aws\Exception\AwsException;

// Render the HTML form
function renderForm($alertHtml = '') {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>AWS Org Membership Checker</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
  <h1 class="mb-4">AWS Organization Membership Checker</h1>
  {$alertHtml}
  <form method="post" class="row g-3">
    <div class="col-md-4">
      <label for="aws_access_key" class="form-label">AWS Access Key</label>
      <input type="text" name="aws_access_key" id="aws_access_key" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label for="aws_secret_key" class="form-label">AWS Secret Key</label>
      <input type="text" name="aws_secret_key" id="aws_secret_key" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label for="region" class="form-label">Region</label>
      <input type="text" name="region" id="region" class="form-control" value="us‑east‑1">
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-primary">Check Organization</button>
    </div>
  </form>
</body>
</html>
HTML;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    renderForm();
}

// Grab POST values
$accessKey = trim($_POST['aws_access_key']  ?? '');
$secretKey = trim($_POST['aws_secret_key']  ?? '');
$region    = trim($_POST['region']          ?? 'us‑east‑1');

if (empty($accessKey) || empty($secretKey)) {
    renderForm("<div class='alert alert-danger'>Missing AWS credentials.</div>");
}

try {
    // Organizations client
    $org = new OrganizationsClient([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey,
        ],
    ]);

    // STS client
    $sts = new StsClient([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey,
        ],
    ]);

    // 1) Describe the Org (gives master account ID & email)
    $orgInfo = $org->describeOrganization();
    $masterId    = $orgInfo['Organization']['MasterAccountId']    ?? '';
    $masterEmail = $orgInfo['Organization']['MasterAccountEmail'] ?? '';

    // 2) Get our own account ID via STS
    $identity     = $sts->getCallerIdentity();
    $ourAccountId = $identity['Account'];

    // 3) Compare IDs
    $isMaster = ($ourAccountId === $masterId);

    // Render result page
    $heading    = $isMaster ? 'Master Account Detected' : 'Member Account Detected';
    $alertClass = $isMaster ? 'success'              : 'info';
    $roleText   = $isMaster
                    ? 'This AWS account <strong>is the master account</strong> of its AWS Organization.'
                    : 'This AWS account <strong>is a member account</strong> in an AWS Organization.';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Organization Check Result</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="alert alert-{$alertClass}">
    <h4 class="alert-heading">{$heading}</h4>
    <p>{$roleText}</p>
    <hr>
    <p>
      <strong>Your Account ID:</strong> {$ourAccountId}<br>
      <strong>Organization Master ID:</strong> {$masterId}<br>
      <strong>Organization Master Email:</strong> {$masterEmail}
    </p>
  </div>
  <a href="" class="btn btn-secondary">Check Again</a>
</body>
</html>
HTML;

} catch (AwsException $e) {
    $code = $e->getAwsErrorCode();
    $msg  = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

    // Not in an Org?
    if ($code === 'AWSOrganizationsNotInUseException'
        || stripos($e->getMessage(), 'not in organization') !== false
    ) {
        echo <<<HTML
<div class="alert alert-warning m-4">
  <h4 class="alert-heading">Standalone Account</h4>
  <p>This AWS account is <strong>not</strong> part of any AWS Organization.</p>
</div>
<p class="text-center"><a href="" class="btn btn-secondary">Try Again</a></p>
HTML;
    } else {
        // Other error
        echo <<<HTML
<div class="alert alert-danger m-4">
  <h4 class="alert-heading">Error Checking Organization</h4>
  <p>{$msg}</p>
</div>
<p class="text-center"><a href="" class="btn btn-secondary">Try Again</a></p>
HTML;
    }
}
