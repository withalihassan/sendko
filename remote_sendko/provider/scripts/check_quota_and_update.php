<?php
// check_quota_display.php  (DEV) - prints "Account: X — Quota: Y" (tries hard to find quota)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$debugLog = sys_get_temp_dir() . '/check_quota_display.log';
function dbg($m)
{
  global $debugLog;
  @file_put_contents($debugLog, date('c') . ' - ' . $m . PHP_EOL, FILE_APPEND);
}

header('Content-Type: text/plain; charset=utf-8');

try {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id <= 0) throw new Exception('POST id required (int)');

  // include your DB (adjust path if necessary)
  $dbPath = __DIR__ . '/../../db.php';
  if (!file_exists($dbPath)) throw new Exception("db.php not found at: $dbPath");
  require_once $dbPath;
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new Exception('$pdo missing or not PDO.');

  // fetch account row
  $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new Exception("Account id {$id} not found.");

  dbg("DB row: " . var_export(array_intersect_key($row, ['id' => 1, 'account_id' => 1, 'aws_key' => 1, 'region' => 1]), true));

  $awsKey = $row['aws_key'] ?? '';
  $awsSecret = $row['aws_secret'] ?? '';
  $region = !empty($row['region']) ? $row['region'] : 'us-east-1';
  $accountIdentifier = $row['account_id'] ?? $row['id'];

  if (empty($awsKey) || empty($awsSecret)) throw new Exception("AWS credentials missing for id {$id}.");

  // load SDK autoloader (adjust as needed)
  $autoloads = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../aws/aws-autoloader.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
  ];
  $used = null;
  foreach ($autoloads as $a) {
    if (file_exists($a)) {
      require_once $a;
      $used = $a;
      break;
    }
  }
  if (!$used) throw new Exception('AWS autoloader not found. Tried: ' . implode('; ', $autoloads));
  dbg("Autoloader used: {$used}");

  if (!class_exists('Aws\\Credentials\\Credentials')) throw new Exception('Aws\\Credentials\\Credentials missing.');
  $creds = new Aws\Credentials\Credentials($awsKey, $awsSecret);

  // STS: who are we? (useful to detect wrong keys)
  $stsIdentity = null;
  try {
    $sts = new Aws\Sts\StsClient([
      'region' => $region,
      'version' => 'latest',
      'credentials' => $creds
    ]);
    $stsIdentity = $sts->getCallerIdentity()->toArray();
    dbg("STS identity: " . var_export($stsIdentity, true));
  } catch (Aws\Exception\AwsException $e) {
    dbg("STS failed: " . $e->getMessage());
    // continue — not fatal for display, but helpful in log
  }

  // ServiceQuotas client
  $sq = new Aws\ServiceQuotas\ServiceQuotasClient([
    'region' => $region,
    'version' => 'latest',
    'credentials' => $creds
  ]);

  $serviceCode = 'ec2';
  $quotaCode   = 'L-34B43A08'; // spot instance quota (change if you need another)
  $quotaValue = null;
  $quotaRaw = null;

  // Attempt 1: getServiceQuota
  try {
    $res = $sq->getServiceQuota(['ServiceCode' => $serviceCode, 'QuotaCode' => $quotaCode]);
    $quotaRaw = method_exists($res, 'toArray') ? $res->toArray() : (array)$res;
    dbg("getServiceQuota raw: " . var_export($quotaRaw, true));
    if (isset($quotaRaw['Quota']['Value'])) $quotaValue = $quotaRaw['Quota']['Value'];
  } catch (Aws\Exception\AwsException $e) {
    dbg("getServiceQuota AwsException: " . $e->getMessage());
    // continue to fallback
  } catch (Throwable $t) {
    dbg("getServiceQuota threw: " . $t->getMessage());
  }

  // Attempt 2: listServiceQuotas fallback if no value
  if ($quotaValue === null) {
    try {
      $list = $sq->listServiceQuotas(['ServiceCode' => $serviceCode, 'MaxResults' => 1000]);
      $listArr = method_exists($list, 'toArray') ? $list->toArray() : (array)$list;
      dbg("listServiceQuotas count: " . count($listArr['Quotas'] ?? []));
      // first search by QuotaCode
      foreach ($listArr['Quotas'] ?? [] as $q) {
        if (isset($q['QuotaCode']) && $q['QuotaCode'] === $quotaCode) {
          $quotaRaw = $q;
          $quotaValue = $q['Value'] ?? ($q['Quota']['Value'] ?? null);
          break;
        }
      }
      // if still not found, try lookups by common names (extra heuristics)
      if ($quotaValue === null) {
        foreach ($listArr['Quotas'] ?? [] as $q) {
          // look for text containing 'Spot' / 'Spot Instance' as heuristic
          if (!empty($q['QuotaName']) && stripos($q['QuotaName'], 'spot') !== false) {
            $quotaRaw = $q;
            $quotaValue = $q['Value'] ?? ($q['Quota']['Value'] ?? null);
            break;
          }
        }
      }
      // attach sample to log
      dbg("list sample: " . var_export(array_slice($listArr['Quotas'] ?? [], 0, 6), true));
    } catch (Aws\Exception\AwsException $e) {
      dbg("listServiceQuotas AwsException: " . $e->getMessage());
    } catch (Throwable $t) {
      dbg("listServiceQuotas threw: " . $t->getMessage());
    }
  }

  // Normalize displayable quota
  $displayQuota = 'N/A';
  if ($quotaValue !== null && $quotaValue !== '') {
    // cast nicely
    if (is_numeric($quotaValue)) {
      // if it's whole number, show as int, otherwise show float
      $displayQuota = (float)$quotaValue == (int)$quotaValue ? (string)(int)$quotaValue : (string)(float)$quotaValue;
    } else {
      $displayQuota = (string)$quotaValue;
    }
  }

  // Decide status (example rule: quarantine when <= 1)
  $status = 'No change';
  if (is_numeric($displayQuota)) {
    if ((float)$displayQuota <= 1) $status = 'Quarantined';
  }
  // --- after we set $status ----------------
  if ($status === 'Quarantined') {
    try {
      // Update the accounts table to mark as Quarantine
      $u = $pdo->prepare("UPDATE accounts SET ac_worth = :worth WHERE id = :id LIMIT 1");
      $u->execute([':worth' => 'Quarantined', ':id' => $id]);
      dbg("DB update: set ac_worth='Quarantined' for id={$id}; rows=" . $u->rowCount());
    } catch (Throwable $t) {
      dbg("DB update failed for id={$id}: " . $t->getMessage());
      // do not throw—log and continue, so the script output stays friendly
    }
  }
  // Print concise output (same format you used)
  echo "Account: {$accountIdentifier} — Quota: {$displayQuota} Status: {$status}";

  // Also log the full raw quota for deeper inspection
  dbg("FINAL: account={$accountIdentifier} displayQuota={$displayQuota} raw=" . var_export($quotaRaw, true));

  exit;
} catch (Throwable $ex) {
  dbg("Exception: " . $ex->getMessage() . ' trace:' . $ex->getTraceAsString());
  // DEV friendly output
  echo "Account: {$id} — Quota: N/A\n";
  echo "Status: No change\n";
  exit;
}
