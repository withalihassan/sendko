<?php
// check_quota_update.php - simple: fetch quota and update accounts.ac_score
// If quota === 0 -> also set accounts.ac_worth = 'Quarantined'
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

try {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) throw new RuntimeException('POST id required (int)');

    // Path to your DB bootstrap which must provide $pdo (PDO instance)
    $dbPath = __DIR__ . '/../../db.php';
    if (!file_exists($dbPath)) throw new RuntimeException("db.php not found at: $dbPath");
    require_once $dbPath;
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('$pdo missing or not PDO.');

    // fetch account row
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Account id {$id} not found.");

    $awsKey = $row['aws_key'] ?? '';
    $awsSecret = $row['aws_secret'] ?? '';
    $region = !empty($row['region']) ? $row['region'] : 'us-east-1';
    $accountIdentifier = $row['account_id'] ?? $row['id'];

    // If no credentials, set ac_score = NULL and exit
    if (empty($awsKey) || empty($awsSecret)) {
        $u = $pdo->prepare("UPDATE accounts SET ac_score = NULL WHERE id = :id LIMIT 1");
        $u->execute([':id' => $id]);
        echo "Account: {$accountIdentifier} — Quota: N/A\n";
        exit;
    }

    // try to load AWS SDK autoloader (adjust list if needed)
    $autoloads = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../aws/aws-autoloader.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];
    $found = false;
    foreach ($autoloads as $a) {
        if (file_exists($a)) {
            require_once $a;
            $found = true;
            break;
        }
    }
    if (!$found || !class_exists('Aws\\ServiceQuotas\\ServiceQuotasClient')) {
        // can't call AWS, set ac_score = NULL
        $u = $pdo->prepare("UPDATE accounts SET ac_score = NULL WHERE id = :id LIMIT 1");
        $u->execute([':id' => $id]);
        echo "Account: {$accountIdentifier} — Quota: N/A\n";
        exit;
    }

    $creds = new Aws\Credentials\Credentials($awsKey, $awsSecret);
    $sq = new Aws\ServiceQuotas\ServiceQuotasClient([
        'region' => $region,
        'version' => 'latest',
        'credentials' => $creds
    ]);

    $serviceCode = 'ec2';
    $quotaCode   = 'L-34B43A08'; // change if you need a different quota
    $quotaValue = null;

    // Attempt 1: getServiceQuota
    try {
        $res = $sq->getServiceQuota(['ServiceCode' => $serviceCode, 'QuotaCode' => $quotaCode]);
        $arr = method_exists($res, 'toArray') ? $res->toArray() : (array)$res;
        if (isset($arr['Quota']['Value'])) $quotaValue = $arr['Quota']['Value'];
    } catch (Throwable $e) {
        // ignore and fallback to listServiceQuotas
    }

    // Attempt 2: listServiceQuotas fallback
    if ($quotaValue === null) {
        try {
            $list = $sq->listServiceQuotas(['ServiceCode' => $serviceCode, 'MaxResults' => 1000]);
            $listArr = method_exists($list, 'toArray') ? $list->toArray() : (array)$list;
            foreach ($listArr['Quotas'] ?? [] as $q) {
                if (!empty($q['QuotaCode']) && $q['QuotaCode'] === $quotaCode) {
                    $quotaValue = $q['Value'] ?? ($q['Quota']['Value'] ?? null);
                    break;
                }
            }
            if ($quotaValue === null) {
                foreach ($listArr['Quotas'] ?? [] as $q) {
                    if (!empty($q['QuotaName']) && stripos($q['QuotaName'], 'spot') !== false) {
                        $quotaValue = $q['Value'] ?? ($q['Quota']['Value'] ?? null);
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Normalize quota to numeric or NULL
    $isNumericQuota = is_numeric($quotaValue);
    if ($isNumericQuota) {
        // prefer integer when whole
        $quotaNormalized = ((float)$quotaValue == (int)$quotaValue) ? (int)$quotaValue : (float)$quotaValue;
        $bindValue = $quotaNormalized;
        $bindType = is_int($bindValue) ? PDO::PARAM_INT : PDO::PARAM_STR;
    } else {
        $bindValue = null;
        $bindType = PDO::PARAM_NULL;
    }

    // Update accounts.ac_score always (either numeric value or NULL)
    $u = $pdo->prepare("UPDATE accounts SET ac_score = :score WHERE id = :id LIMIT 1");
    if ($bindType === PDO::PARAM_NULL) {
        $u->bindValue(':score', null, PDO::PARAM_NULL);
    } elseif ($bindType === PDO::PARAM_INT) {
        $u->bindValue(':score', $bindValue, PDO::PARAM_INT);
    } else {
        $u->bindValue(':score', (string)$bindValue, PDO::PARAM_STR);
    }
    $u->bindValue(':id', $id, PDO::PARAM_INT);
    $u->execute();

    // NEW: only when quota is exactly 0 -> set ac_worth = 'Quarantined'
    if ($isNumericQuota && (float)$quotaValue === 0.0) {
        $q = $pdo->prepare("UPDATE accounts SET ac_worth = 'Quarantined' WHERE id = :id LIMIT 1");
        $q->execute([':id' => $id]);
        $status = 'Quarantined';
    } else {
        $status = 'No change';
    }

    $display = ($bindType === PDO::PARAM_NULL) ? 'N/A' : (string)$bindValue;
    echo "Account: {$accountIdentifier} — Quota: {$display} Status: {$status}\n";
    exit;

} catch (Throwable $ex) {
    // best-effort: set ac_score = NULL then output minimal message
    if (isset($pdo) && ($pdo instanceof PDO) && isset($id) && $id > 0) {
        try {
            $u = $pdo->prepare("UPDATE accounts SET ac_score = NULL WHERE id = :id LIMIT 1");
            $u->execute([':id' => $id]);
        } catch (Throwable $_) {
            // ignore
        }
    }
    echo "Account: " . (isset($accountIdentifier) ? $accountIdentifier : 'N/A') . " — Quota: N/A\n";
    exit;
}
