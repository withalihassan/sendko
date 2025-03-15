<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../db.php'; // This file defines $pdo

if (isset($_GET['parent_id'])) {
    $parentId = $_GET['parent_id'];
    // $user_id = $_GET['user_id'];

    $stmt = $pdo->prepare("SELECT * FROM child_accounts WHERE parent_id = ? AND account_id != ?");
    $stmt->execute([$parentId, $parentId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($accounts) > 0) {
        foreach ($accounts as $index => $account) {
            // Determine status badge based on account status
            $statusBadge = ($account['status'] === 'ACTIVE')
                ? "<span class='badge bg-success'>Active</span>"
                : "<span class='badge bg-danger'>Suspended</span>";

            // Construct table row with escaped output and URL-encoded query parameters
            echo "<tr>
                    <td>" . ($index + 1) . "</td>
                    <td>" . htmlspecialchars($account['name']) . "</td>
                    <td>" . htmlspecialchars($account['email']) . "</td>
                    <td>$statusBadge</td>
                    <td>" . $account['worth_type']. "</td>
                    <td>" . htmlspecialchars($account['account_id']) . "</td>
                    <td>
                        <a href='./bulk_regional_send.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-success'>Bulk Regional Send</a>
                        <a href='./brs.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-info'>BRS</a>
                        <a href='./enable_regions.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-secondary'>E-R</a>
                        <a href='./clear_single.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-warning'>Clear</a>
                        <a target='_blank' href='child_account.php?child_id=" . urlencode($account['account_id']) . "&parent_id=" . urlencode($parentId) . "' class='btn btn-primary'>Setup</a>
                        <a target='_blank' href='./chk_quality.php?ac_id=" . urlencode($account['account_id']) . "&parent_id=" . urlencode($parentId) . "' class='btn btn-warning'>CHK-Q</a>
                    </td>
                  </tr>";
        }
    } else {
        echo "<tr><td colspan='6' class='text-center'>No child accounts found.</td></tr>";
    }
} else {
    echo "<tr><td colspan='6' class='text-center'>Parent ID is missing.</td></tr>";
}
?>
