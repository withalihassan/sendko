<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../db.php'; // This file defines $pdo
require '../../session.php'; // This file defines $pdo

if (isset($_SESSION['user_id'])) {
    // $session_id = 12;
    // $session_id = $_SESSION['user_id'];
}else {
}
// echo $session_id = $_SESSION['user_id'];
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
            //Age calculation strted 
            if ($account['status'] == 'ACTIVE') {
                if($account['created_at']  != NULL){
                $td_created_at = new DateTime($account['created_at']);
                $td_current_date = new DateTime();
                $diff = $td_created_at->diff($td_current_date);
                $child_ac_Age = "<span>" . $diff->format('%a days') . "</span>";
                }else{
                $child_ac_Age =  "<span>error1</span>";
                }
            } else {
                $child_ac_Age =  "<span>error2</span>";
            }
            //Age calculation ended
            //is in org checking
            if($account['is_in_org']=="No"){
                $color_mark="style='background-color: #ffcccc;'";
            }else{
                $color_mark="";
            }
            // Construct table row with escaped output and URL-encoded query parameters
            echo "<tr>
                    <td $color_mark>" . ($index + 1) . "</td>
                    <td>" . htmlspecialchars($account['name']) . "</td>
                    <td>" . htmlspecialchars($account['email']) . "</td>
                    <td>$statusBadge</td>
                    <td>" . $account['worth_type'] . "</td>
                    <td>" . (!empty($account['created_at']) ? date('j F', strtotime($account['created_at'])) : '') . "</td>

                    <td>$child_ac_Age</td>
                    <td>" . htmlspecialchars($account['account_id']) . "</td>
                    <td>
                        <a href='./bulk_regional_send.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-success'>Bulk Regional Send</a>
                        <a href='./brs.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-info'>BRS</a>
                        <a href='./enable_regions.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-secondary'>E-R</a>
                        <a href='./clear_single.php?ac_id=" . $account['account_id'] . "&parrent_id=" . $parentId . "' target='_blank' class='btn btn-warning'>Clear</a>
                        <a target='_blank' href='child_account.php?child_id=" . urlencode($account['account_id']) . "&parent_id=" . urlencode($parentId) . "' class='btn btn-primary'>Setup</a>
                        <a target='_blank' href='./chk_quality.php?ac_id=" . urlencode($account['account_id']) . "&parent_id=" . urlencode($parentId) . "' class='btn btn-warning'>CHK-Q</a>
                        <a target='_blank' href='./child_actions.php?ac_id=" . urlencode($account['account_id']) . "&parent_id=" . urlencode($parentId) . "&user_id=" . urlencode($session_id) . "&CHID=" . urlencode($index + 1) . "' class='btn btn-success'>Open</a>
                    </td>
                  </tr>";
        }
    } else {
        echo "<tr><td colspan='6' class='text-center'>No child accounts found.</td></tr>";
    }
} else {
    echo "<tr><td colspan='6' class='text-center'>Parent ID is missing.</td></tr>";
}
