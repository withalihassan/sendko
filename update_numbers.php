<?php
include "./session.php";      // Your session file
include "./header.php";       // Your header file
include "db.php";             // Database connection

$message = '';

// Handle form submission to update allowed numbers
if (isset($_POST['submit'])) {
    // Get form data: dropdown selection, total attempts, and textarea content
    $set_id         = trim($_POST['set_id']); // New set id to update each number with
    $total_attempts = trim($_POST['total_attempts']);
    $numbers_data   = trim($_POST['numbers_data']);

    if (empty($set_id) || empty($total_attempts) || empty($numbers_data)) {
        $message = "Please fill in all fields.";
    } else {
        $total_attempts = (int)$total_attempts;
        $updatedCount = 0;
        $notFoundNumbers = [];

        // Split the textarea content by newlines
        $lines = preg_split("/\r\n|\n|\r/", $numbers_data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            // Each line should have two parts: phone number and used attempts
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue; // Skip lines that don't match the required format
            }
            $phone_number_input = $parts[0];
            $used_attempts = (int)$parts[1];

            // Prepare phone number for lookup: prepend '+' if not already present
            $lookup_phone = $phone_number_input;
            if ($lookup_phone[0] !== '+') {
                $lookup_phone = '+' . $lookup_phone;
            }

            // Calculate new atm_left value
            $new_atm_left = $total_attempts - $used_attempts;

            // Check if the number exists in the allowed_numbers table
            $stmt = $pdo->prepare("SELECT id FROM allowed_numbers WHERE phone_number = ?");
            $stmt->execute([$lookup_phone]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($record) {
                // Update record: update atm_left, status and set_id.
                if ($new_atm_left <= 0) {
                    // If the attempts are exhausted or negative, set atm_left to 0, mark as 'used'
                    $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = 0, status = 'used', set_id = ? WHERE phone_number = ?");
                    $updateStmt->execute([$set_id, $lookup_phone]);
                } else {
                    // Otherwise update atm_left, mark as 'fresh'
                    $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, status = 'fresh', set_id = ? WHERE phone_number = ?");
                    $updateStmt->execute([$new_atm_left, $set_id, $lookup_phone]);
                }
                $updatedCount++;
            } else {
                $notFoundNumbers[] = $phone_number_input;
            }
        }

        $message = "{$updatedCount} number(s) updated successfully.";
        if (!empty($notFoundNumbers)) {
            $message .= " The following numbers were not found: " . implode(", ", $notFoundNumbers);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update Allowed Numbers</title>
    <!-- Bootstrap CSS for styling -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <!-- Optional additional CSS or JS links can remain here -->
</head>
<body>
<div class="container-fluid" style="padding: 4%;">
    <h2>Update Allowed Numbers</h2>
    <?php 
    if (!empty($message)) {
        echo '<div class="alert alert-info">' . $message . '</div>';
    }
    ?>
    <!-- Form to update allowed numbers -->
    <form action="" method="post" class="mb-4">
        <div class="form-group">
            <label for="set_id">Select Set (Fresh Only):</label>
            <select name="set_id" id="set_id" class="form-control" required>
                <option value="">--Select Set--</option>
                <?php
                // Fetch sets that have a status of 'fresh'
                $stmt = $pdo->query("SELECT id, set_name FROM bulk_sets WHERE status = 'fresh' ORDER BY created_at DESC");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['set_name']) . '</option>';
                }
                ?>
            </select>
        </div>
        <div class="form-group">
            <label for="total_attempts">Total Attempts (for each number):</label>
            <input type="number" name="total_attempts" id="total_attempts" class="form-control" placeholder="Enter total attempts" required>
        </div>
        <div class="form-group">
            <label for="numbers_data">Numbers and Used Attempts</label>
            <textarea name="numbers_data" id="numbers_data" class="form-control" rows="6" placeholder="38349639305 15&#10;38349634768 15&#10;38349637201 12&#10;38349632773 2" required></textarea>
            <small class="form-text text-muted">
                Enter one record per line. Each line should have the phone number followed by the used attempts (separated by whitespace).<br>
                Note: The system will prepend a '+' to the number if it's missing.
            </small>
        </div>
        <button type="submit" name="submit" class="btn btn-primary">Update Numbers</button>
    </form>
</div>
</body>
</html>
