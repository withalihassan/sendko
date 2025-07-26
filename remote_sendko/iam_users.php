<?php
// iam_users.php
include "./header.php";
// ——————————————————————————
// 1) AJAX DELETE HANDLER
// ——————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    // send JSON only
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(0);

    require __DIR__ . '/session.php';
    require __DIR__ . '/db.php';

    $response = ['success' => false, 'msg' => 'Failed to delete selected users.'];
    if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        // sanitize ids
        $ids = array_map('intval', $_POST['selected_ids']);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM iam_users WHERE id IN ($in)");
        if ($stmt->execute($ids)) {
            $response = ['success' => true, 'msg' => 'Selected users deleted successfully.'];
        }
    }

    echo json_encode($response);
    exit;
}

// ——————————————————————————
// 2) REGULAR PAGE LOGIC (add-account, fetch rows, etc.)
// ——————————————————————————
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/session.php';
require __DIR__ . '/db.php';
require __DIR__ . '/aws/aws-autoloader.php';

$message = '';
if (isset($_POST['submit'])) {
    $aws_key    = trim($_POST['aws_key'] ?? '');
    $aws_secret = trim($_POST['aws_secret'] ?? '');
    if ($aws_key === '' || $aws_secret === '') {
        $message = 'AWS Key and Secret cannot be empty.';
    } else {
        try {
            $stsClient = new Aws\Sts\StsClient([
                'version'     => 'latest',
                'region'      => 'us-east-1',
                'credentials' => [
                    'key'    => $aws_key,
                    'secret' => $aws_secret,
                ],
            ]);
            $result     = $stsClient->getCallerIdentity();
            $account_id = $result->get('Account');
            $added_date = date('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                'INSERT INTO accounts (by_user, aws_key, aws_secret, account_id, status, added_date)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($ins->execute([
                $session_id, $aws_key, $aws_secret, $account_id, 'active', $added_date
            ])) {
                $message = 'Account added successfully. AWS Account ID: ' 
                            . htmlspecialchars($account_id, ENT_QUOTES, 'UTF-8');
            } else {
                $message = 'Failed to insert account into the database.';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
}

// fetch iam_users
$sql = '
    SELECT *
    FROM iam_users
    WHERE by_user = :uid
      AND added_by = \'girlsNew\'
    ORDER BY created_at DESC
';
$stmt = $pdo->prepare($sql);
$stmt->execute([':uid' => $session_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>IAM Accounts Manage</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
  <style>
    .d-inline-flex > * + * { margin-left: 0.5rem; }
  </style>
</head>
<body>
<div class="container-fluid" style="padding:1% 4% 4% 4%;">
  <?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <h2>IAM Accounts Lists</h2>
  <div class="status-message-iam mb-2"></div>
  <button id="delete-selected" class="btn btn-danger mb-3">Delete Selected</button>

  <table id="accountsTable3" class="display table table-bordered">
    <thead>
      <tr>
        <th><input type="checkbox" id="select-all"></th>
        <th>UID</th>
        <th>Child ID</th>
        <th>Key</th>
        <th>Secret Key</th>
        <th>Parent Exp.</th>
        <th>Added Date</th>
        <th>Status</th>
        <th>Clean</th>
        <th>Quick Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $seen = [];
      foreach ($rows as $row) {
          $id      = $row['id'] ?? '';
          $childId = $row['child_account_id'] ?? '';
          if (in_array($childId, $seen, true)) continue;
          $seen[] = $childId;

          // fetch parent info
          $infoStmt = $pdo->prepare(
            'SELECT parent_id, worth_type FROM child_accounts
             WHERE account_id = :cid LIMIT 1'
          );
          $infoStmt->execute([':cid' => $childId]);
          $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
          $parentExp = '<span class="badge badge-primary">Not Sure 🤔</span>';
          if (isset($info['worth_type'])) {
            if ($info['worth_type'] === 'half') {
              $parentExp = '<span class="badge badge-success">Full</span>';
            } elseif ($info['worth_type'] === 'full') {
              $parentExp = '<span class="badge badge-warning">Half</span>';
            }
          }

          $date = $row['created_at']
                  ? (new DateTime($row['created_at']))->format('d M g:i a')
                  : '';
          $status = $row['status'] ?? '';
          $badge = [
            'Delivered' => 'success',
            'Pending'   => 'warning',
            'Canceled'  => 'danger',
          ][$status] ?? 'primary';
      ?>
      <tr>
        <td>
          <input type="checkbox" class="row-select"
                 value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
        </td>
        <td><?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($childId, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($row['access_key_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($row['secret_access_key'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= $parentExp ?></td>
        <td><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <span class="badge badge-<?= $badge ?>">
            <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
          </span>
        </td>
        <td><?= htmlspecialchars($row['cleanup_status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <div class="d-inline-flex align-items-center">
            <div class="btn-group">
              <button class="btn btn-info btn-sm dropdown-toggle"
                      data-toggle="dropdown">Status</button>
              <div class="dropdown-menu">
                <?php foreach (['Delivered','Pending','Canceled','Recheck'] as $st): ?>
                  <a href="#"
                     class="dropdown-item update-status-btn-iam"
                     data-id="<?= $id ?>"
                     data-status="<?= $st ?>"><?= $st ?></a>
                <?php endforeach; ?>
              </div>
            </div>
            <a href="./iam_clear.php?ac_id=<?= $id ?>" target="_blank">
              <button class="btn btn-danger btn-sm mr-1">Clear</button>
            </a>
            <a href="./awsch/child_actions.php?
                     ac_id=<?= $childId ?>&
                     parent_id=<?= $info['parent_id'] ?? '' ?>&
                     user_id=<?= $session_id ?>"
               target="_blank">
              <button class="btn btn-success btn-sm mr-1">Open</button>
            </a>
          </div>
        </td>
      </tr>
      <?php } ?>
    </tbody>
  </table>
</div>

<!-- JS includes -->
<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

<script>
$(document).ready(function() {
  var table = $('#accountsTable3').DataTable({ paging: true });

  // Select all across pages
  $('#select-all').on('click', function() {
    var checked = this.checked;
    table.rows().nodes().to$()
         .find('input.row-select')
         .prop('checked', checked);
  });

  // Uncheck master if any row is unchecked
  $('#accountsTable3').on('change', '.row-select', function() {
    if (!this.checked) {
      $('#select-all').prop('checked', false);
    }
  });

  // Delete Selected
  $('#delete-selected').click(function() {
    var sel = [];
    table.rows().nodes().each(function(row) {
      $(row).find('input.row-select:checked').each(function() {
        sel.push(this.value);
      });
    });
    if (!sel.length) {
      return alert('Please select rows to delete.');
    }
    if (!confirm('Are you sure to delete selected rows?')) return;

    $.ajax({
      url: window.location.href,    // same page
      method: 'POST',
      data: { delete_selected: 1, selected_ids: sel },
      dataType: 'json'
    }).done(function(d) {
      if (d.success) {
        location.reload();
      } else {
        alert(d.msg);
      }
    }).fail(function() {
      alert('Invalid JSON response');
    });
  });

  // Update status (unchanged)
  $('body').on('click', '.update-status-btn-iam', function(e) {
    e.preventDefault();
    var id = $(this).data('id'),
        st = $(this).data('status');
    $.post('iam_users_status_ajax.php',
           { update_status:1, id:id, status:st },
           function(res) {
      var msg = $('.status-message-iam')
        .removeClass('alert-success alert-danger')
        .addClass(res.success?
          'alert alert-success':'alert alert-danger')
        .text(res.msg);
    }, 'json')
    .fail(function() {
      $('.status-message-iam')
        .addClass('alert alert-danger')
        .text('Invalid JSON returned');
    });
  });
});
</script>
</body>
</html>
