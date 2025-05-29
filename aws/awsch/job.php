<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$host = 'localhost';
$db = 'aws_accounts';
$user = 'root';
$pass = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Insert dummy data
$awsKey = 'dummy_key_' . rand(1000, 9999);
$awsSecretKey = 'dummy_secret_' . rand(1000, 9999);
$dailyhou = 6;
$acid = '3732782377';

$stmt = $conn->prepare("INSERT INTO accounts (aws_key, aws_secret_key, daily_hours, account_id) VALUES (?, ?, ?, ?)");
$stmt->execute([$awsKey, $awsSecretKey, $dailyhou, $acid]);

echo "Inserted account: $awsKey at " . date('Y-m-d H:i:s') . "\n";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Insertion Timer</title>
    <script>
        let countdown = 60; // 60 seconds countdown

        function startCountdown() {
            const timerElement = document.getElementById("timer");
            const interval = setInterval(() => {
                countdown--;
                timerElement.textContent = `Next insertion in: ${countdown} seconds`;

                if (countdown <= 0) {
                    clearInterval(interval);
                    location.reload(); // Refresh the page to trigger next insertion
                }
            }, 1000);
        }

        window.onload = startCountdown;
    </script>
</head>
<body>
    <h1>Dummy AWS Account Inserter</h1>
    <p id="timer">Next insertion in: 60 seconds</p>
</body>
</html>
