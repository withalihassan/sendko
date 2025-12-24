<?php
// child_actions/readd_account.php
header('Content-Type: application/json; charset=utf-8');

// Prevent HTML error output that breaks JSON parsing on client
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../db.php'; // adjust path if needed

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

$child_account_id = $data['child_account_id'] ?? null;
$access_key_id = $data['access_key_id'] ?? null;
$secret_access_key = $data['secret_access_key'] ?? null;
$login_url = $data['login_url'] ?? null;
$username = $data['username'] ?? null;
$password = $data['password'] ?? null;
$requested_by = $data['requested_by'] ?? null;

if (!$child_account_id || !$access_key_id || !$secret_access_key) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: child_account_id / access_key_id / secret_access_key.']);
    exit;
}

try {
    // begin transaction
    $pdo->beginTransaction();

    // Insert new row. status is explicitly set to NULL.
    $insertSql = "
        INSERT INTO `iam_users`
        (child_account_id, login_url, username, password, access_key_id, secret_access_key, `status`, created_at)
        VALUES
        (:child_account_id, :login_url, :username, :password, :access_key_id, :secret_access_key, NULL, NOW())
    ";
    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        ':child_account_id' => $child_account_id,
        ':login_url' => $login_url,
        ':username' => $username,
        ':password' => $password,
        ':access_key_id' => $access_key_id,
        ':secret_access_key' => $secret_access_key
    ]);

    $newId = $pdo->lastInsertId();
    if (!$newId) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Insert failed.']);
        exit;
    }

    // Delete previous rows for this child_account_id except the newly inserted row
    $delSql = "DELETE FROM `iam_users` WHERE child_account_id = :child_account_id AND id != :keep_id";
    $delStmt = $pdo->prepare($delSql);
    $delStmt->execute([
        ':child_account_id' => $child_account_id,
        ':keep_id' => $newId
    ]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "IAM user re-added successfully (id: $newId). Previous rows removed."
    ]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    // Do not expose detailed error to client; send minimal message
    echo json_encode(['success' => false, 'message' => 'Database error during re-add.']);
    // Also log the exception to server error log for debugging
    error_log("readd_account.php error: " . $e->getMessage());
    exit;
}
