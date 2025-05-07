<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
include('../db.php');
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $account_status = trim($_POST['account_status']);

    // Insert the new user (password stored as plain text per requirements)
    $stmt = $pdo->prepare("INSERT INTO users (username, password, account_status) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$username, $password, $account_status]);
        $message = "User added successfully!";
    } catch (PDOException $e) {
        $message = "Error adding user: " . $e->getMessage();
    }
}

// ----------------------------------------------------------------
// 1. Fetch account details with computed columns, including payable_value
// ----------------------------------------------------------------
$sql = "
  SELECT 
    account_id,
    status,
    ac_score,
    cr_offset,
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

// ----------------------------------------------------------------
// 2. Compute Totals for the headings:
//    - Total Final Value
//    - Total Payable Value (Final Value minus cr_offset)
//    - Total Offsets (cr_offset)
// ----------------------------------------------------------------
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css">
    <style>
        /* Single container styling */
        .container-fluid { padding: 0; }
        .content { padding: 20px; }
    </style>
</head>
<body>
    <?php include "./header.php"; ?>
    <!-- Cards Section -->
    <div class="container-fluid p-5">
        <div class="row no-gutters">
            <!-- Card 1: Total Accounts (active count) -->
            <div class="col-3 p-1">
                <div class="card bg-primary text-white border-0">
                    <div class="card-body p-2">
                        <?php
                        $stmtActive = $pdo->query("SELECT COUNT(*) FROM accounts WHERE status = 'active'");
                        $activeCount = $stmtActive->fetchColumn();
                        ?>
                        <h5 class="card-title m-0">Total Accounts</h5>
                        <h5 class="card-body m-0"><?php echo $activeCount; ?></h5>
                    </div>
                </div>
            </div>
            <!-- Card 2: Unpaid Accounts -->
            <div class="col-3 p-1">
                <div class="card bg-danger text-white border-0">
                    <div class="card-body p-2">
                        <?php
                        // Adjust query for unpaid accounts as needed. Here we assume account_status = 'pending'
                        $stmtUnpaid = $pdo->query("SELECT COUNT(*) FROM accounts WHERE status = 'pending'");
                        $unpaidCount = $stmtUnpaid->fetchColumn();
                        ?>
                        <h5 class="card-title m-0">Unpaid Accounts</h5>
                        <h5 class="card-body m-0"><?php echo $unpaidCount; ?></h5>
                    </div>
                </div>
            </div>
            <!-- Card 3: Total days counted (Total Final Value) -->
            <div class="col-3 p-1">
                <div class="card bg-success text-white border-0">
                    <div class="card-body p-2">
                        <h5 class="card-title m-0">Total days counted</h5>
                        <h5 class="card-body m-0"><?php echo (int)$totalFinal; ?></h5>
                    </div>
                </div>
            </div>
            <!-- Card 4: Remaining days (Total Payable Value) -->
            <div class="col-3 p-1">
                <div class="card bg-warning text-white border-0">
                    <div class="card-body p-2">
                        <h5 class="card-title m-0">Remaining days</h5>
                        <h5 class="card-body m-0"><?php echo (int)$totalPayable; ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- DataTable Section -->
    <div class="container-fluid">
        <div class="content">
            <h2>Accounts Summary</h2>
            <!-- Totals Heading -->
            <p><strong>Total Sum of Final Values:</strong> <?php echo (int)$totalFinal; ?></p>
            <p><strong>Total Sum of Payable Values:</strong> <?php echo (int)$totalPayable; ?></p>
            <p><strong>Paid Values (Sum of Offsets):</strong> <?php echo (int)$totalOffset; ?></p>
            <!-- DataTable -->
            <table id="accountsTable" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>Account ID</th>
                        <th>Status</th>
                        <th>Account Score</th>
                        <th>Calculated Age</th>
                        <th>Final Value</th>
                        <th>CR Offset</th>
                        <th>Payable Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($acc['account_id']); ?></td>
                            <td><?php echo htmlspecialchars($acc['status']); ?></td>
                            <td><?php echo (int)$acc['ac_score']; ?></td>
                            <td><?php echo (int)$acc['calculated_age']; ?></td>
                            <td><?php echo (int)$acc['final_value']; ?></td>
                            <td><?php echo (int)$acc['cr_offset']; ?></td>
                            <td><?php echo (int)$acc['payable_value']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
    <script>
    $(document).ready(function() {
        $('#accountsTable').DataTable();
    });
    </script>
</body>
</html>
