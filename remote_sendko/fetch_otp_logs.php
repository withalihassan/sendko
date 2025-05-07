<?php
session_start();
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if (!$user_id) {
    echo "No user logged in.";
    exit;
}
$logFile = "otp_process_" . $user_id . ".log";
if (file_exists($logFile)) {
    echo file_get_contents($logFile);
} else {
    echo "No logs yet.";
}
?>
