<?php
// sync_remote.php
header('Content-Type: application/json');

// Include your local database connection
include('db.php');

// Remote database connection details
$remote_host     = '47.89.232.110';
$remote_dbname   = 'sender';
$remote_username = 'sender';
$remote_password = 'Tech@#009';

try {
    $remotePdo = new PDO("mysql:host=$remote_host;dbname=$remote_dbname;charset=utf8mb4", $remote_username, $remote_password);
    $remotePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => "Remote database connection failed: " . $e->getMessage()]);
    exit;
}

$newRecordsCount = 0;

try {
    // Fetch all records from remote accounts table without any WHERE clause
    $stmtRemote = $remotePdo->query("SELECT * FROM accounts");
    $remoteRecords = $stmtRemote->fetchAll(PDO::FETCH_ASSOC);

    foreach($remoteRecords as $record) {
        // Check if a record with the same account_id already exists in the local DB
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM accounts WHERE account_id = ?");
        $stmtCheck->execute([$record['account_id']]);
        $exists = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($exists['cnt'] == 0) {
            // Insert record into local DB.
            // Using 0 for by_user as it's an integer column.
            $by_user    = 0;
            $aws_key    = $record['aws_key'];
            $aws_secret = $record['aws_secret'];
            $account_id = $record['account_id'];
            $status     = $record['status'];
            // If remote record contains ac_state, use it; otherwise default to 'orphan'
            $ac_state   = isset($record['ac_state']) ? $record['ac_state'] : 'orphan';
            // If remote record contains ac_score, use it; otherwise default to 0
            $ac_score   = isset($record['ac_score']) ? $record['ac_score'] : 0;
            $added_date = $record['added_date'];

            $stmtInsert = $pdo->prepare("INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, ac_state, ac_score, added_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmtInsert->execute([$by_user, $aws_key, $aws_secret, $account_id, $status, $ac_state, $ac_score, $added_date])) {
                $newRecordsCount++;
            }
        }
    }

    echo json_encode(['success' => true, 'new_records' => $newRecordsCount]);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => "Error during sync: " . $e->getMessage()]);
}
?>
