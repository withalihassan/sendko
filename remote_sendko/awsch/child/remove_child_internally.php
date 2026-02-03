<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// remove_child_internally.php
// Usage: /remove_child_internally.php?prnt_id=836149532856&child_id=231614140901

// declare(strict_types=1);/?

// include your DB bootstrap which creates $pdo (as in your snippet)
require '../../db.php';

// include AWS SDK autoloader
require '../../aws/aws-autoloader.php';

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

// Simple HTML helper
function html_page(string $title, string $body_html) : void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo "<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>";
    // minimal styling
    echo '<style>body{font-family:Inter,system-ui,Arial,Helvetica,sans-serif;margin:24px;background:#f7f7fb;color:#111}';
    echo '.card{background:#fff;border:1px solid #e5e7eb;padding:18px;border-radius:10px;box-shadow:0 4px 12px rgba(16,24,40,0.03);max-width:980px;margin:0 auto}';
    echo '.success{color:#065f46;background:#ecfdf5;padding:10px;border-radius:6px;border:1px solid #bbf7d0;margin-bottom:12px}';
    echo '.error{color:#7f1d1d;background:#fff1f2;padding:10px;border-radius:6px;border:1px solid #fecaca;margin-bottom:12px}';
    echo '.small{font-size:13px;color:#555}';
    echo 'a.btn{display:inline-block;padding:8px 12px;border-radius:6px;background:#2563eb;color:#fff;text-decoration:none;margin-top:10px}';
    echo 'pre{background:#0f1724;color:#e6eef8;padding:12px;border-radius:6px;overflow:auto;font-size:13px}';
    echo '</style></head><body><div class="card">';
    echo $body_html;
    echo '</div></body></html>';
    exit;
}

// Basic checks
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO connection ($pdo) not found. Ensure ../../db.php creates $pdo as in your snippet.');
    }

    // Read & sanitize GET parameters
    $prnt_id  = (string) trim((string)filter_input(INPUT_GET, 'prnt_id'));
    $child_id = (string) trim((string)filter_input(INPUT_GET, 'child_id'));

    if ($prnt_id === '' || $child_id === '') {
        http_response_code(400);
        html_page('Missing parameters', '<div class="error"><strong>Error:</strong> Missing <code>prnt_id</code> or <code>child_id</code> in the query string.</div>'
            .'<div class="small">Example: <code>?prnt_id=836149532856&child_id=231614140901</code></div>');
    }

    // Normalize whitespace
    $prnt_id  = preg_replace('/\s+/', '', $prnt_id);
    $child_id = preg_replace('/\s+/', '', $child_id);

    // Helper: fetch a single row by account_id OR id from a table
    $fetchStmt = $pdo->prepare("SELECT * FROM `%s` WHERE account_id = :needle OR id = :needle_int LIMIT 1");

    function fetchRow(PDO $pdo, string $table, string $needle) : ?array {
        $sql = "SELECT * FROM `" . str_replace('`','',$table) . "` WHERE account_id = :needle OR id = :needle_int LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':needle' => $needle, ':needle_int' => $needle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Fetch parent row from accounts
    $parentRow = fetchRow($pdo, 'accounts', $prnt_id);
    if (!$parentRow) {
        http_response_code(404);
        html_page('Parent not found', '<div class="error"><strong>Error:</strong> Parent account not found for <code>prnt_id</code> = '
            .htmlspecialchars($prnt_id, ENT_QUOTES, 'UTF-8').'</div>');
    }

    // Fetch child row from child_accounts
    $childRow = fetchRow($pdo, 'child_accounts', $child_id);
    if (!$childRow) {
        http_response_code(404);
        html_page('Child not found', '<div class="error"><strong>Error:</strong> Child account not found for <code>child_id</code> = '
            .htmlspecialchars($child_id, ENT_QUOTES, 'UTF-8').'</div>');
    }

    // Extract parent AWS credentials (supporting common column names)
    $parentAwsKey = $parentRow['aws_key'] ?? $parentRow['aws_access_key'] ?? $parentRow['aws_access_key_id'] ?? null;
    $parentAwsSecret = $parentRow['aws_secret'] ?? $parentRow['aws_secret_key'] ?? $parentRow['aws_secret_access_key'] ?? null;

    // Extract child credentials (not strictly needed for RemoveAccount call; shown for debug)
    $childAwsKey = $childRow['aws_access_key'] ?? $childRow['aws_key'] ?? null;
    $childAwsSecret = $childRow['aws_secret_key'] ?? $childRow['aws_secret'] ?? null;

    if (empty($parentAwsKey) || empty($parentAwsSecret)) {
        http_response_code(500);
        html_page('Parent credentials missing', '<div class="error"><strong>Error:</strong> Parent AWS credentials are missing in the database. '
            .'You must store <code>aws_key</code> and <code>aws_secret</code> (or equivalent) for the parent account.</div>'
            .'<div class="small">Parent DB id: '.htmlspecialchars($parentRow['id'] ?? 'n/a', ENT_QUOTES, 'UTF-8').'</div>');
    }

    // Account ID to remove: prefer account_id column, fallback to provided child_id
    $targetAccountId = $childRow['account_id'] ?? $childRow['id'] ?? $child_id;
    $targetAccountId = (string) preg_replace('/\s+/', '', $targetAccountId);

    // Mask helper for display (never show full secrets)
    $mask = function(?string $s){
        if (empty($s)) return null;
        $len = strlen($s);
        if ($len <= 8) return substr($s,0,1) . str_repeat('*', max(0,$len-2)) . substr($s,-1);
        return substr($s,0,4) . str_repeat('*', max(0,$len-8)) . substr($s,-4);
    };

    // Create AWS Organizations client with the parent's credentials
    $orgClient = new OrganizationsClient([
        'version' => 'latest',
        'region'  => 'us-east-1', // required by SDK though Organizations is global
        'credentials' => [
            'key'    => $parentAwsKey,
            'secret' => $parentAwsSecret,
        ],
    ]);

    // Try to remove the account
    try {
        $result = $orgClient->removeAccountFromOrganization([
            'AccountId' => $targetAccountId,
        ]);

        // Success: display friendly message
        $body = '<div class="success"><strong>Success:</strong> Child removed successfully.</div>';
        $body .= '<div class="small">Removed AWS Account ID: <strong>' . htmlspecialchars($targetAccountId, ENT_QUOTES, 'UTF-8') . '</strong></div>';

        // optionally show some AWS response metadata for debugging (non-sensitive)
        $awsArr = method_exists($result, 'toArray') ? $result->toArray() : (array)$result;
        $meta = $awsArr['ResponseMetadata'] ?? $awsArr;
        $body .= '<h4 style="margin-top:14px">AWS response (metadata)</h4>';
        $body .= '<pre>' . htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</pre>';

        // masked samples
        $body .= '<h4 style="margin-top:10px">DB samples (masked)</h4>';
        $body .= '<div class="small">Parent DB id: ' . htmlspecialchars($parentRow['id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . ' — account_id: ' . htmlspecialchars($parentRow['account_id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . '</div>';
        $body .= '<div class="small">Parent key: ' . htmlspecialchars($mask($parentAwsKey), ENT_QUOTES, 'UTF-8') . '</div>';
        $body .= '<div class="small" style="margin-top:6px">Child DB id: ' . htmlspecialchars($childRow['id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . ' — account_id: ' . htmlspecialchars($childRow['account_id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . '</div>';
        $body .= '<div class="small">Child key: ' . htmlspecialchars($mask($childAwsKey), ENT_QUOTES, 'UTF-8') . '</div>';

        $body .= '<div style="margin-top:14px"><a class="btn" href="javascript:history.back()">Go back</a></div>';

        html_page('Child removed', $body);

    } catch (AwsException $awsEx) {
        // AWS SDK returned an error (access denied, invalid account state, etc.)
        http_response_code(500);
        $awsErrCode = $awsEx->getAwsErrorCode();
        $awsErrMsg  = $awsEx->getAwsErrorMessage();
        $httpStatus = $awsEx->getStatusCode();

        $body  = '<div class="error"><strong>AWS error:</strong> Failed to remove child account.</div>';
        $body .= '<div class="small"><strong>AWS code:</strong> ' . htmlspecialchars((string)$awsErrCode, ENT_QUOTES, 'UTF-8')
               . ' — <strong>HTTP status:</strong> ' . htmlspecialchars((string)$httpStatus, ENT_QUOTES, 'UTF-8') . '</div>';
        $body .= '<h4 style="margin-top:12px">Message</h4>';
        $body .= '<pre>' . htmlspecialchars((string)$awsErrMsg, ENT_QUOTES, 'UTF-8') . '</pre>';

        // Show masked DB samples to help debugging without leaking secrets
        $body .= '<h4 style="margin-top:10px">DB samples (masked)</h4>';
        $body .= '<div class="small">Parent DB id: ' . htmlspecialchars($parentRow['id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . ' — account_id: ' . htmlspecialchars($parentRow['account_id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . '</div>';
        $body .= '<div class="small">Parent key: ' . htmlspecialchars($mask($parentAwsKey), ENT_QUOTES, 'UTF-8') . '</div>';
        $body .= '<div class="small" style="margin-top:6px">Child DB id: ' . htmlspecialchars($childRow['id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . ' — account_id: ' . htmlspecialchars($childRow['account_id'] ?? 'n/a', ENT_QUOTES, 'UTF-8') . '</div>';

        $body .= '<div style="margin-top:14px"><a class="btn" href="javascript:history.back()">Go back</a></div>';

        html_page('AWS Error', $body);

    } catch (Exception $e) {
        // Unexpected other errors
        http_response_code(500);
        html_page('Unexpected error', '<div class="error"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'
            .'<div class="small">Check server error logs for full trace.</div>');
    }

} catch (Exception $e) {
    http_response_code(500);
    html_page('Fatal error', '<div class="error"><strong>Fatal error:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>');
}
