<?php
// get_ec2_password.php — robust decrypt for EC2 Windows password
// Requires: db.php (defines $pdo or $mysqli) and AWS PHP SDK autoloader (aws-autoloader.php)

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(0);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$awsKey = $in['awsAccessKey'] ?? null;
$awsSec = $in['awsSecretKey'] ?? null;
$region = $in['region'] ?? null;
$instance = $in['instance_id'] ?? $in['InstanceId'] ?? null;
$id = $in['id'] ?? null;
$parent = $in['parent_id'] ?? null;
$key_passphrase = $in['key_passphrase'] ?? null;
$debug = !empty($in['debug']);

// minimal input check
if (!$awsKey || !$awsSec || !$region || !$instance) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing required: awsAccessKey/awsSecretKey/region/instance_id']);
    exit;
}

function collect_openssl_errors() {
    $errs = [];
    while ($e = openssl_error_string()) $errs[] = $e;
    return $errs;
}

// fetch key_material row (same logic you already had)
$row = null;
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT id,key_material FROM launched_desks WHERE instance_id = :i OR id = :id OR parent_id = :p LIMIT 1");
        $stmt->execute([':i'=>$instance,':id'=>$id,':p'=>$parent]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif (isset($mysqli) && $mysqli instanceof mysqli) {
        $s = $mysqli->prepare("SELECT id,key_material FROM launched_desks WHERE instance_id = ? OR id = ? OR parent_id = ? LIMIT 1");
        $s->bind_param('sss', $instance, $id, $parent);
        $s->execute();
        $row = $s->get_result()->fetch_assoc() ?: null;
        $s->close();
    } else {
        throw new Exception('DB handle ($pdo or $mysqli) not found in db.php');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'DB query failed','error'=>$e->getMessage()]);
    exit;
}

if (!$row || empty($row['key_material'])) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'key_material not found for this instance/id/parent_id']);
    exit;
}

/**
 * Try convert OpenSSH private key to PEM using ssh-keygen.
 * Returns PEM string on success or null on failure. Populates $debugOut array with messages if provided.
 */
function try_convert_openssh_to_pem(string $rawKey, ?string $passphrase, array &$debugOut = null) : ?string {
    $debugOut = $debugOut ?? [];
    // locate ssh-keygen
    $which = trim(@shell_exec('command -v ssh-keygen 2>/dev/null || which ssh-keygen 2>/dev/null'));
    if ($which === '') {
        $debugOut[] = "ssh-keygen not found on system (shell_exec may be disabled).";
        return null;
    }

    $tmpIn = tempnam(sys_get_temp_dir(), 'key_in_');
    if ($tmpIn === false) {
        $debugOut[] = "could not create temp file";
        return null;
    }
    file_put_contents($tmpIn, $rawKey);

    // Build conversion command. Use -p -m PEM to rewrite file to PEM format.
    // If passphrase provided we include -P old -N '' to remove passphrase (works if correct)
    $cmd = escapeshellcmd($which) . ' -p -f ' . escapeshellarg($tmpIn) . ' -m PEM 2>&1';
    if ($passphrase !== null && $passphrase !== '') {
        // -P is old passphrase, -N is new passphrase
        $cmd = escapeshellcmd($which) . ' -p -f ' . escapeshellarg($tmpIn) . ' -P ' . escapeshellarg($passphrase) . ' -N ' . escapeshellarg('') . ' -m PEM 2>&1';
    }

    $out = @shell_exec($cmd);
    $debugOut[] = "ssh-keygen output: " . ($out === null ? '' : $out);

    // read back file
    $pem = @file_get_contents($tmpIn);
    // cleanup
    @unlink($tmpIn);

    if ($pem !== false && strpos($pem, '-----BEGIN') !== false) {
        return $pem;
    }
    return null;
}

// normalize key material
$keyMaterial = trim($row['key_material']);
$keyMaterial = str_replace('\\n', "\n", $keyMaterial);

// strip surrounding quotes if any
if ((substr($keyMaterial,0,1) === '"' && substr($keyMaterial,-1) === '"') ||
    (substr($keyMaterial,0,1) === "'" && substr($keyMaterial,-1) === "'")) {
    $keyMaterial = substr($keyMaterial,1,-1);
}

// if we see public key line -> error
if (preg_match('/^\s*(ssh-(rsa|ed25519|dss)|ecdsa-sha2-)/', $keyMaterial)) {
    http_response_code(500);
    $resp = ['status'=>'error','message'=>'Stored key_material appears to be a public key (ssh-rsa) not a private key'];
    if ($debug) $resp['debug'] = ['first_200_chars'=>substr($keyMaterial,0,200)];
    echo json_encode($resp);
    exit;
}

// If not PEM-looking, try base64 decode -> PEM or wrap as RSA PRIVATE KEY
if (strpos($keyMaterial, '-----BEGIN') === false) {
    $maybe = base64_decode($keyMaterial, true);
    if ($maybe !== false && strpos($maybe, '-----BEGIN') !== false) {
        $keyMaterial = $maybe;
    } else {
        // assume raw base64 blob of DER or raw base64 text; wrap as PKCS#1 PEM
        $keyMaterial = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split(trim($keyMaterial), 64, "\n") . "-----END RSA PRIVATE KEY-----\n";
    }
}

// attempt to load private key via openssl (with passphrase first)
$privateKey = false;
$errs_loading1 = $errs_loading2 = $errs_loading3 = null;

if ($key_passphrase !== null && $key_passphrase !== '') {
    $privateKey = @openssl_pkey_get_private($keyMaterial, $key_passphrase);
    if ($privateKey === false) $errs_loading1 = collect_openssl_errors();
}

if ($privateKey === false) {
    $privateKey = @openssl_pkey_get_private($keyMaterial);
    if ($privateKey === false) $errs_loading2 = collect_openssl_errors();
}

// fallback: if OPENSSH private key format detected, try converting with ssh-keygen
$debug_ssh = null;
if ($privateKey === false && strpos($keyMaterial, '-----BEGIN OPENSSH PRIVATE KEY-----') !== false) {
    $converted = try_convert_openssh_to_pem($row['key_material'], $key_passphrase, $debug_ssh);
    if ($converted !== null) {
        $privateKey = @openssl_pkey_get_private($converted, $key_passphrase ?: null);
        if ($privateKey === false) $errs_loading3 = collect_openssl_errors();
        else $keyMaterial = $converted;
    }
}

// fallback: try interpreting original DB value as base64 DER and wrap
if ($privateKey === false) {
    $orig = trim($row['key_material']);
    $der = @base64_decode($orig, true);
    if ($der !== false) {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END RSA PRIVATE KEY-----\n";
        $privateKey = @openssl_pkey_get_private($pem);
        if ($privateKey === false) $errs_loading3 = collect_openssl_errors();
        else $keyMaterial = $pem;
    }
}

if ($privateKey === false) {
    $resp = ['status'=>'error','message'=>'invalid private key: openssl could not parse it'];
    if ($debug) {
        $resp['debug'] = [
            'openssl_errors_with_passphrase' => $errs_loading1 ?? null,
            'openssl_errors_without_passphrase' => $errs_loading2 ?? null,
            'openssl_errors_der_wrap' => $errs_loading3 ?? null,
            'sshkeygen_debug' => $debug_ssh,
            'note' => 'If key is OpenSSH format, ensure ssh-keygen is available or convert offline to PEM. If key is passphrase-protected, provide key_passphrase.'
        ];
    }
    http_response_code(500);
    echo json_encode($resp);
    exit;
}

// call AWS GetPasswordData
try {
    $ec2 = new Ec2Client([
        'version' => 'latest',
        'region' => $region,
        'credentials' => ['key' => $awsKey, 'secret' => $awsSec],
    ]);
    $g = $ec2->getPasswordData(['InstanceId' => $instance]);
    $pwdData = $g->get('PasswordData') ?? '';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'AWS GetPasswordData failed','error'=>$e->getMessage()]);
    exit;
}

if (empty($pwdData)) {
    http_response_code(409);
    echo json_encode(['status'=>'error','message'=>'PasswordData empty. Instance not ready or not Windows.']);
    exit;
}

// decrypt
$encrypted = base64_decode($pwdData, true);
if ($encrypted === false) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'PasswordData base64 decode failed']);
    exit;
}

$ok = @openssl_private_decrypt($encrypted, $password, $privateKey, OPENSSL_PKCS1_PADDING);
if (function_exists('openssl_pkey_free')) @openssl_pkey_free($privateKey); else @openssl_free_key($privateKey);

if ($ok === false || $password === null || $password === '') {
    $resp = ['status'=>'error','message'=>$id.'decryption failed. The key may not match the EC2 keypair or format is unsupported.($id)'];
    if ($debug) {
        $resp['debug'] = [
            'openssl_errors' => collect_openssl_errors(),
            'pwdData_len' => strlen($pwdData),
            'pwdData_decoded_len' => is_string($encrypted) ? strlen($encrypted) : null,
            'hint' => 'Confirm the stored private key matches the instance keypair and is an RSA private key in PEM format.'
        ];
    }
    http_response_code(500);
    echo json_encode($resp);
    exit;
}

// convert UTF-16LE to UTF-8 if needed
if (strpos($password, "\0") !== false) {
    $conv = @iconv('UTF-16LE', 'UTF-8', $password);
    if ($conv !== false && trim($conv) !== '') $password = $conv;
}

// best-effort DB update for password
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $u = $pdo->prepare("UPDATE launched_desks SET password = :pw WHERE id = :id OR instance_id = :i LIMIT 1");
        // ensure id present
        $uid = $row['id'] ?? null;
        $u->execute([':pw'=>$password, ':id'=>$uid, ':i'=>$instance]);
    } elseif (isset($mysqli) && $mysqli instanceof mysqli) {
        $s = $mysqli->prepare("UPDATE launched_desks SET password = ? WHERE id = ? OR instance_id = ? LIMIT 1");
        $s->bind_param('sss', $password, $row['id'], $instance);
        $s->execute();
        $s->close();
    }
} catch (Exception $e) {
    // ignore DB update failures for minimal flow; but include debug if requested
    if ($debug) $dbUpdateError = $e->getMessage();
}

// success response
$resp = ['status'=>'ok','instance_id'=>$instance,'password'=>$password];
if ($debug) {
    $resp['debug_meta'] = [
        'row_id' => $row['id'] ?? null,
        'key_material_first_120_chars' => substr($keyMaterial,0,120),
        'sshkeygen_debug' => $debug_ssh ?? null,
        'db_update_error' => $dbUpdateError ?? null
    ];
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
