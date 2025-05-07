<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// OPTION 1: Keep login check (ensure your login process sets $_SESSION['username'])
// if (!isset($_SESSION['username'])) {
//     header("Location: login.php");
//     exit;
// }

// OPTION 2: Remove login check if you want to access this page without login
// (Comment out or delete the above if-block)

// Include database connection
include('./db.php');

include "./session.php";
// ---------- AJAX Update Handling ----------
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'update_single') {
        // Update CR Offset for a single account
        if (isset($_POST['account_id']) && isset($_POST['cr_offset'])) {
            $account_id = $_POST['account_id'];
            $cr_offset = $_POST['cr_offset'];
            $stmt = $pdo->prepare("UPDATE accounts SET cr_offset = ? WHERE account_id = ?");
            if ($stmt->execute([$cr_offset, $account_id])) {
                echo "Offset updated successfully for account " . htmlspecialchars($account_id);
            } else {
                echo "Failed to update offset for account " . htmlspecialchars($account_id);
            }
        } else {
            echo "Invalid parameters for single update.";
        }
        exit;
    }
    if ($_POST['action'] === 'update_bulk') {
        // Bulk update CR Offset for selected accounts
        if (isset($_POST['accounts']) && isset($_POST['cr_offset'])) {
            $accounts = $_POST['accounts']; // array of account ids
            $cr_offset = $_POST['cr_offset'];
            // Create placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($accounts), '?'));
            $sql = "UPDATE accounts SET cr_offset = ? WHERE account_id IN ($placeholders)";
            $params = array_merge([$cr_offset], $accounts);
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($params)) {
                echo "Offset updated successfully for selected accounts.";
            } else {
                echo "Failed to update offsets for selected accounts.";
            }
        } else {
            echo "Invalid parameters for bulk update.";
        }
        exit;
    }
}

// ---------------------
// Optional: User Add Section
// ---------------------
$message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $account_status = trim($_POST['account_status']);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, account_status) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$username, $password, $account_status]);
        $message = "User added successfully!";
    } catch (PDOException $e) {
        $message = "Error adding user: " . $e->getMessage();
    }
}

// ---------------------
// 1. Fetch account details with computed columns
// ---------------------
$sql = "
  SELECT 
    account_id,
    status,
    ac_score,
    cr_offset,
    worth_type,
    CASE 
      WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
      WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
      ELSE 0
    END AS calculated_age,
    GREATEST(
      CASE 
        WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
        WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
        ELSE 0
      END,
      ac_score
    ) AS final_value,
    (GREATEST(
      CASE 
        WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
        WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
        ELSE 0
      END,
      ac_score
    ) - cr_offset) AS payable_value
  FROM accounts
  ORDER BY account_id
";
$stmt = $pdo->query($sql);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------
// 2. Compute overall totals
// ---------------------
$totalQuery = "
  SELECT 
    SUM(
      GREATEST(
        CASE 
          WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
          WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
          ELSE 0
        END,
        ac_score
      )
    ) AS total_final,
    SUM(
      (GREATEST(
        CASE 
          WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
          WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
          ELSE 0
        END,
        ac_score
      ) - cr_offset)
    ) AS total_payable,
    SUM(cr_offset) AS total_offset
  FROM accounts
";
$stmtTotal = $pdo->query($totalQuery);
$totals = $stmtTotal->fetch(PDO::FETCH_ASSOC);
$totalFinal   = $totals['total_final'];
$totalPayable = $totals['total_payable'];
$totalOffset  = $totals['total_offset'];

// ---------------------
// 3. Compute separate totals by claim type (full vs half)
// ---------------------
$totalTypeQuery = "
 SELECT 
    SUM(CASE WHEN worth_type = 'full' THEN 
         GREATEST(
           CASE 
             WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
             WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
             ELSE 0
           END,
           ac_score
         ) ELSE 0 END) AS final_full,
    SUM(CASE WHEN worth_type = 'half' THEN 
         GREATEST(
           CASE 
             WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
             WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
             ELSE 0
           END,
           ac_score
         ) ELSE 0 END) AS final_half,
    SUM(CASE WHEN worth_type = 'full' THEN 
         (GREATEST(
           CASE 
             WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
             WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
             ELSE 0
           END,
           ac_score
         ) - cr_offset) ELSE 0 END) AS payable_full,
    SUM(CASE WHEN worth_type = 'half' THEN 
         (GREATEST(
           CASE 
             WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
             WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
             ELSE 0
           END,
           ac_score
         ) - cr_offset) ELSE 0 END) AS payable_half,
    SUM(CASE WHEN worth_type = 'full' THEN cr_offset ELSE 0 END) AS offset_full,
    SUM(CASE WHEN worth_type = 'half' THEN cr_offset ELSE 0 END) AS offset_half
 FROM accounts
";
$stmtTotalType = $pdo->query($totalTypeQuery);
$totalsType = $stmtTotalType->fetch(PDO::FETCH_ASSOC);
$finalFull         = $totalsType['final_full'];
$finalHalf         = $totalsType['final_half'];
$totalPayableFull  = $totalsType['payable_full'];
$totalPayableHalf  = $totalsType['payable_half'];
$totalOffsetFull   = $totalsType['offset_full'];
$totalOffsetHalf   = $totalsType['offset_half'];

// ---------------------
// 4. Compute count of accounts by claim type (Full vs Half)
// ---------------------
$countQuery = "
   SELECT 
     SUM(CASE WHEN worth_type = 'full' THEN 1 ELSE 0 END) AS count_full,
     SUM(CASE WHEN worth_type = 'half' THEN 1 ELSE 0 END) AS count_half
   FROM accounts
";
$stmtCount = $pdo->query($countQuery);
$countTotals = $stmtCount->fetch(PDO::FETCH_ASSOC);
$countFull = $countTotals['count_full'];
$countHalf = $countTotals['count_half'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accounts Summary & Management</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css">
    <style>
        .container-fluid { padding: 0; }
        .content { padding: 20px; }
        .cr-offset { width: 80px; }
        .summary-col { padding: 20px; }
        .summary-box { border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <?php 
    include "header.php";
    ?>
    <!-- Overall Summary Cards -->
    <div class="container-fluid p-5">
        <div class="row">
            <!-- Total Accounts -->
            <div class="col-md-2">
                <div class="card bg-primary text-white text-center">
                    <div class="card-body">
                        <?php
                        $stmtActive = $pdo->query("SELECT COUNT(*) FROM accounts WHERE status = 'active'");
                        $activeCount = $stmtActive->fetchColumn();
                        ?>
                        <h5>Total Accounts</h5>
                        <h4><?php echo $activeCount; ?></h4>
                    </div>
                </div>
            </div>
            <!-- Unpaid Accounts -->
            <div class="col-md-2">
                <div class="card bg-danger text-white text-center">
                    <div class="card-body">
                        <?php
                        $stmtUnpaid = $pdo->query("SELECT COUNT(*) FROM accounts WHERE status = 'pending'");
                        $unpaidCount = $stmtUnpaid->fetchColumn();
                        ?>
                        <h5>Unpaid Accounts</h5>
                        <h4><?php echo $unpaidCount; ?></h4>
                    </div>
                </div>
            </div>
            <!-- Total Final Value -->
            <div class="col-md-2">
                <div class="card bg-success text-white text-center">
                    <div class="card-body">
                        <h5>Total Final Values</h5>
                        <h4><?php echo (int)$totalFinal; ?></h4>
                    </div>
                </div>
            </div>
            <!-- Total Payable Value -->
            <div class="col-md-2">
                <div class="card bg-warning text-white text-center">
                    <div class="card-body">
                        <h5>Total Payable Values</h5>
                        <h4><?php echo (int)$totalPayable; ?></h4>
                    </div>
                </div>
            </div>
            <!-- Total Offsets -->
            <div class="col-md-2">
                <div class="card bg-info text-white text-center">
                    <div class="card-body">
                        <h5>Paid Offsets</h5>
                        <h4><?php echo (int)$totalOffset; ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Extra Line: Count of Full and Half Accounts -->
    <div class="container-fluid mb-4">
        <div class="row">
            <div class="col-md-6 text-center">
                <h5>Total Full Accounts: <?php echo $countFull; ?></h5>
            </div>
            <div class="col-md-6 text-center">
                <h5>Total Half Accounts: <?php echo $countHalf; ?></h5>
            </div>
        </div>
    </div>

    <!-- Claim Type Summaries in Two Columns -->
    <div class="container-fluid">
        <div class="row">
            <!-- Full Accounts Summary -->
            <div class="col-md-6 summary-col">
                <div class="summary-box">
                    <h4>Full Accounts Summary</h4>
                    <p><strong>Total Final Values:</strong> <?php echo (int)$finalFull; ?></p>
                    <p><strong>Total Payable:</strong> <?php echo (int)$totalPayableFull; ?></p>
                    <p><strong>Total Offsets:</strong> <?php echo (int)$totalOffsetFull; ?></p>
                </div>
            </div>
            <!-- Half Accounts Summary -->
            <div class="col-md-6 summary-col">
                <div class="summary-box">
                    <h4>Half Accounts Summary</h4>
                    <p><strong>Total Final Values:</strong> <?php echo (int)$finalHalf; ?></p>
                    <p><strong>Total Payable:</strong> <?php echo (int)$totalPayableHalf; ?></p>
                    <p><strong>Total Offsets:</strong> <?php echo (int)$totalOffsetHalf; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Accounts Table Section with CR Offset Live Update & Bulk Update -->
    <div class="container-fluid">
        <div class="content">
            <table id="accountsTable" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Account ID</th>
                        <th>Status</th>
                        <th>Account Score</th>
                        <th>Calculated Age</th>
                        <th>Final Value</th>
                        <th>CR Offset</th>
                        <th>Payable Value</th>
                        <th>Full</th>
                        <th>Half</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td><input type="checkbox" class="row-select" data-account="<?php echo htmlspecialchars($acc['account_id']); ?>"></td>
                            <td><?php echo htmlspecialchars($acc['account_id']); ?></td>
                            <td><?php echo htmlspecialchars($acc['status']); ?></td>
                            <td><?php echo (int)$acc['ac_score']; ?></td>
                            <td><?php echo (int)$acc['calculated_age']; ?></td>
                            <td><?php echo (int)$acc['final_value']; ?></td>
                            <td>
                                <input type="number" class="form-control cr-offset" data-account="<?php echo htmlspecialchars($acc['account_id']); ?>" value="<?php echo (int)$acc['cr_offset']; ?>">
                            </td>
                            <td><?php echo (int)$acc['payable_value']; ?></td>
                            <td><?php echo ($acc['worth_type'] === 'full') ? (int)$acc['final_value'] : ''; ?></td>
                            <td><?php echo ($acc['worth_type'] === 'half') ? (int)$acc['final_value'] : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Bulk Update Section for CR Offset -->
            <div class="mt-3">
                <div class="form-inline">
                    <label for="bulkOffset" class="mr-2"><strong>Update Selected CR Offset:</strong></label>
                    <input type="number" id="bulkOffset" class="form-control mr-2" placeholder="Enter new offset">
                    <button id="updateBulk" class="btn btn-primary">Update Selected</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery and DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#accountsTable').DataTable();

        // "Select All" checkbox functionality
        $('#selectAll').on('click', function() {
            $('.row-select').prop('checked', this.checked);
        });

        // Live update for CR Offset (single row)
        $('.cr-offset').on('change', function() {
            var accountId = $(this).data('account');
            var newOffset = $(this).val();
            $.ajax({
                url: 'ac_summary.php',
                type: 'POST',
                data: {
                    action: 'update_single',
                    account_id: accountId,
                    cr_offset: newOffset
                },
                success: function(response) {
                    alert(response);
                    // Optionally, update the payable value dynamically
                },
                error: function() {
                    alert('Error updating offset for account ' + accountId);
                }
            });
        });

        // Bulk update for selected CR Offsets
        $('#updateBulk').on('click', function() {
            var newBulkOffset = $('#bulkOffset').val();
            if(newBulkOffset === '') {
                alert('Please enter a value.');
                return;
            }
            var selectedAccounts = [];
            $('.row-select:checked').each(function() {
                selectedAccounts.push($(this).data('account'));
            });
            if(selectedAccounts.length === 0) {
                alert('Please select at least one row.');
                return;
            }
            $.ajax({
                url: 'ac_summary.php',
                type: 'POST',
                data: {
                    action: 'update_bulk',
                    accounts: selectedAccounts,
                    cr_offset: newBulkOffset
                },
                success: function(response) {
                    alert(response);
                    location.reload();
                },
                error: function() {
                    alert('Error updating offsets for selected accounts.');
                }
            });
        });
    });
    </script>
</body>
</html>
