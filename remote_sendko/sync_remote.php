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
    echo json_encode([
        'success' => false,
        'message' => "Remote database connection failed: " . $e->getMessage()
    ]);
    exit;
}

$newRecordsCount = 0;
$updatedRecordsCount = 0;

try {
    // Fetch all records from remote accounts table without any WHERE clause
    $stmtRemote = $remotePdo->query("SELECT * FROM accounts");
    $remoteRecords = $stmtRemote->fetchAll(PDO::FETCH_ASSOC);

    foreach($remoteRecords as $record) {
        $account_id = $record['account_id'];
        
        // Check if a record with the same account_id already exists in the local DB.
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM accounts WHERE account_id = ?");
        $stmtCheck->execute([$account_id]);
        $exists = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        // Prepare common variables with defaults if not provided in remote record.
        $aws_key      = $record['aws_key'];
        $aws_secret   = $record['aws_secret'];
        $status       = $record['status'];
        $ac_state     = isset($record['ac_state']) ? $record['ac_state'] : 'orphan';
        $ac_score     = isset($record['ac_score']) ? $record['ac_score'] : 0;
        $ac_age       = isset($record['ac_age']) ? $record['ac_age'] : 0;
        $cr_offset    = isset($record['cr_offset']) ? $record['cr_offset'] : 0;
        $suspend_mode = isset($record['suspend_mode']) ? $record['suspend_mode'] : 'none';
        $added_date   = $record['added_date'];

        if ($exists['cnt'] == 0) {
            // Insert new record. 
            // by_user is set to 0 as a default.
            $by_user = 0;
            $stmtInsert = $pdo->prepare("INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, ac_state, ac_score, ac_age, cr_offset, suspend_mode, added_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmtInsert->execute([$by_user, $aws_key, $aws_secret, $account_id, $status, $ac_state, $ac_score, $ac_age, $cr_offset, $suspend_mode, $added_date])) {
                $newRecordsCount++;
            }
        } else {
            // Update existing record with remote data.
            $stmtUpdate = $pdo->prepare("UPDATE accounts SET aws_key = ?, aws_secret = ?, ac_state = ?, ac_score = ?, ac_age = ?, cr_offset = ?, suspend_mode = ?, added_date = ? WHERE account_id = ?");
            if ($stmtUpdate->execute([$aws_key, $aws_secret, $ac_state, $ac_score, $ac_age, $cr_offset, $suspend_mode, $added_date, $account_id])) {
                $updatedRecordsCount++;
            }
        }
    }

    echo json_encode([
        'success'         => true,
        'new_records'     => $newRecordsCount,
        'updated_records' => $updatedRecordsCount
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => "Error during sync: " . $e->getMessage()
    ]);
}
?>
