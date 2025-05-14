<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('db.php'); // must define and connect $pdo (an instance of PDO)

// --- AJAX status-update handler ---
if (isset($_POST['update_status'])) {
    header('Content-Type: application/json');

    // grab & sanitize
    $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';

    if ($id <= 0 || $status === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'msg'     => 'Invalid ID or status'
        ]);
        exit;
    }

    try {
        // prepare & execute
        $stmt = $pdo->prepare("UPDATE `iam_users` SET `status` = :status WHERE `id` = :id");
        $stmt->execute([
            ':status' => $status,
            ':id'     => $id
        ]);

        // if you want, check rowCount to see if anything changed:
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'msg'     => "Status set to $status of Account ID $id"
            ]);
        } else {
            // no row updated—either ID not found or status identical
            echo json_encode([
                'success' => true,
                'msg'     => "No change (perhaps status was already $status)"
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'msg'     => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit; // stop further output
}
?>
