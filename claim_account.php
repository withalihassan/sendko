<?php
include 'db.php';
include './session.php';

if (!isset($_POST['id']) || !isset($_POST['claim_type'])) {
    die('Invalid request.');
}

$id = $_POST['id'];
$claim_type = strtolower(trim($_POST['claim_type']));

// Validate claim type
if ($claim_type !== 'full' && $claim_type !== 'half') {
    die('Invalid claim type.');
}

// Fetch the current account state
$stmt = $pdo->prepare("SELECT ac_state FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    die('Account does not exist.');
}

if ($account['ac_state'] === 'orphan') {
    // Claim the account: update state to claimed, set claimed_by and worth_type
    $stmt = $pdo->prepare("UPDATE accounts SET ac_state = 'claimed', claimed_by = ?, worth_type = ? WHERE id = ?");
    if ($stmt->execute([$session_id, $claim_type, $id])) {
        echo "Account claimed successfully as " . htmlspecialchars($claim_type) . ".";
    } else {
        echo "Failed to claim account.";
    }
} else if ($account['ac_state'] === 'claimed') {
    // Account already claimed, update the worth_type only
    $stmt = $pdo->prepare("UPDATE accounts SET worth_type = ? WHERE id = ?");
    if ($stmt->execute([$claim_type, $id])) {
        echo "Account claim type updated to " . htmlspecialchars($claim_type) . ".";
    } else {
        echo "Failed to update claim type.";
    }
} else {
    die('Account cannot be claimed.');
}
?>
