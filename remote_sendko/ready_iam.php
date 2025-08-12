<?php
// iam_users.php

include "./header.php";
require __DIR__ . '/session.php';   // must call session_start() and set $_SESSION['user_id']
require __DIR__ . '/db.php';         // must set up $pdo = new PDO(...);
require __DIR__ . '/aws/aws-autoloader.php';

use Aws\Exception\AwsException;

// make sure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = $_SESSION['user_id'];

// Fetch child accounts with specified conditions:
//  - belongs to current user
//  - in_org = 'yes'
//  - status != 'suspended'
//  - added_date > '2025-05-01'
//  - age >= 6 days
//  - not already in iam_users table
$sql = "
    SELECT 
        c.id,
        c.parent_id,
        c.email,
        c.account_id AS child_account_id,
        c.status,
        c.created_at,
        c.name,
        c.aws_access_key,
        c.aws_secret_key,
        c.worth_type,
        c.last_used,
        c.ac_score,
        c.added_date,
        c.is_in_org
    FROM child_accounts c
    INNER JOIN accounts a
        ON c.parent_id = a.account_id
    LEFT JOIN iam_users i
        ON c.account_id = i.child_account_id
    WHERE a.by_user              = :userId
      AND c.is_in_org            = 'yes'
      AND c.status               <> 'suspended'
      AND DATEDIFF(CURDATE(), c.added_date) >= 6
      AND i.child_account_id IS NULL
    ORDER BY c.added_date ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':userId' => $userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>IAM Child Accounts</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
  <style>
    .d-inline-flex > * + * { margin-left: .5rem; }
  </style>
</head>
<body>
<div class="container-fluid p-4">
  <h2>Child Accounts (In Org = yes, Active, Added after 2025-05-01, Age ≥ 6 days)</h2>
  <table id="accountsTable" class="table table-striped table-bordered">
    <thead>
      <tr>
        <th>ID</th>
        <th>Parent ID</th>
        <th>Email</th>
        <th>Child Account ID</th>
        <th>Status</th>
        <th>Created At</th>
        <th>Name</th>
        <th>Age (days)</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $acct): 
        $added = new DateTime($acct['added_date']);
        $now   = new DateTime();
        $diff  = $added->diff($now)->days;
    ?>
      <tr>
        <td><?= htmlspecialchars($acct['id']) ?></td>
        <td><?= htmlspecialchars($acct['parent_id']) ?></td>
        <td><?= htmlspecialchars($acct['email']) ?></td>
        <td><?= htmlspecialchars($acct['child_account_id']) ?></td>
        <td><?= htmlspecialchars($acct['status']) ?></td>
        <td><?= htmlspecialchars($acct['created_at']) ?></td>
        <td><?= htmlspecialchars($acct['name']) ?></td>
        <td><?= $diff ?></td>
        <td class="d-inline-flex">
          <a class="btn btn-sm btn-primary"
             href="child_actions.php?ac_id=<?= urlencode($acct['child_account_id']) ?>
                                   &parent_id=<?= urlencode($acct['parent_id']) ?>
                                   &user_id=<?= urlencode($userId) ?>">
            Open
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- JS includes -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
  $(document).ready(function(){
    $('#accountsTable').DataTable({
      pageLength: 10,
      order: [[ 7, "asc" ]] // sort by Age column
    });
  });
</script>
</body>
</html>
