<?php
// account_queries/setup_child_account.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_POST['child_id'])) {
    echo json_encode(['success' => false, 'message' => 'Child ID missing.']);
    exit;
}
$child_id = intval($_POST['child_id']);
include __DIR__ . '/../db.php';

// Placeholder for your actual setup logic.
// For now, just return a success message.
echo json_encode(['success' => true, 'message' => 'Setup executed for child account ID: ' . $child_id]);
