<?php
require 'db_connect.php';

$stmt = $conn->prepare("SELECT id, email, account_id, status FROM aws_accounts WHERE display='yes' ORDER BY id DESC");
$stmt->execute();
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($accounts) > 0) {
    foreach ($accounts as $index => $account) {
        $statusBadge = $account['status'] === 'Active' 
            ? "<span class='badge badge-success'>Active</span>" 
            : "<span class='badge badge-danger'>Suspended</span>";
            
        echo "<tr>
                <td>" . ($index + 1) . "</td>
                <td>{$account['email']}</td>
                <td>{$account['account_id']}</td>
                <td>$statusBadge</td>
                <td>
                    <button class='btn btn-success btn-sm btn-open' data-id='{$account['account_id']}'>Open</button>
                    <button class='btn btn-info btn-sm btn-status' data-id='{$account['id']}'>Check Status</button>
                    <a target='_blank' href='main_account.php?parent_id={$account['account_id']}'><button class='btn btn-info btn-sm'>Main Open</button></a>
                </td>
              </tr>";
    }
} else {
    echo "<tr><td colspan='5' class='text-center'>No AWS accounts found.</td></tr>";
}
?>
