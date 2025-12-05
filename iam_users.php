<?php
// iam_users.php
include "./header.php";
require __DIR__ . '/session.php';
require __DIR__ . '/db.php';
require __DIR__ . '/aws/aws-autoloader.php';

use Aws\Sts\StsClient;
use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

// ——————————————————————————
// 1) AJAX HANDLERS (Delete + Status Check)
// ——————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(0);

    // DELETE SELECTED
    if (isset($_POST['delete_selected']) && is_array($_POST['selected_ids'])) {
        $ids = array_map('intval', $_POST['selected_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM iam_users WHERE id IN ($placeholders)");
        $success = $stmt->execute($ids);
        echo json_encode(['success' => $success, 'msg' => $success ? 'Selected users deleted.' : 'Delete failed.']);
        exit;
    }

    // CHECK STATUS
    if (isset($_POST['check_status'], $_POST['id'])) {
        $id = (int) $_POST['id'];
        $stmt = $pdo->prepare('SELECT access_key_id, secret_access_key FROM iam_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success'=>false,'msg'=>'User not found']);
            exit;
        }
        $key    = $row['access_key_id'];
        $secret = $row['secret_access_key'];
        $region = 'us-east-1';
        // Validate STS
        try {
            $sts = new StsClient(['version'=>'latest','region'=>$region,'credentials'=>['key'=>$key,'secret'=>$secret]]);
            $identity = $sts->getCallerIdentity();
            $acct = $identity['Account'];
        } catch (AwsException $e) {
            $status = 'Suspended';
            $pdo->prepare('UPDATE iam_users SET status = ? WHERE id = ?')->execute([$status, $id]);
            echo json_encode(['success'=>true,'status'=>$status,'msg'=>'Account suspended or invalid keys']);
            exit;
        }
        // Check org membership
        try {
            $org = new OrganizationsClient(['version'=>'latest','region'=>$region,'credentials'=>['key'=>$key,'secret'=>$secret]]);
            $info = $org->describeOrganization();
            $master = $info['Organization']['MasterAccountId'] ?? null;
            $status = ($acct === $master) ? 'Master' : 'Attached';
        } catch (AwsException $e) {
            $status = 'Standalone';
        }
        // Update DB
        $pdo->prepare('UPDATE iam_users SET status = ? WHERE id = ?')->execute([$status, $id]);
        echo json_encode(['success'=>true,'status'=>$status,'msg'=>'Status: '.$status]);
        exit;
    }
}

// ——————————————————————————
// 2) PAGE LOGIC
// ——————————————————————————
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

// Fetch non-suspended users girlsNew
$stmt = $pdo->prepare(
    "SELECT *
     FROM iam_users
     WHERE by_user  = :uid
       AND added_by = 'girls'
       AND (
         status IS NULL
         OR status NOT IN ('Master','Suspended','Canceled')
         OR status = 'Standalone'
       )
     ORDER BY created_at DESC"
);



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
  <style>.d-inline-flex > * + * { margin-left: .5rem; }</style>
</head>
<body>
<div class="container-fluid p-4">
  <h2>IAM Accounts Manager <a href="masters_iam_users.php" target="_blank"><button class="btn bt-xs btn-primary">Open Master IAM Users</button></a></h2>

  <!-- Status Check Response -->
  <div id="check-response" class="mb-3"></div>

  <button id="delete-selected" class="btn btn-danger mb-3">Delete Selected</button>

  <table id="accountsTable" class="table table-bordered table-striped">
    <thead>
      <tr>
        <th><input type="checkbox" id="select-all"></th>
        <th>ID</th>
        <th>Child ID</th>
        <th>Access Key</th>
        <th>Secret Key</th>
        <th>Parent Exp.</th>
        <th>Added Date</th>
        <th>Status</th>
        <th>Cleanup Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $seen = [];
      foreach ($rows as $row) {
          $id = (int) $row['id'];
          $child = htmlspecialchars($row['child_account_id'], ENT_QUOTES);
          if (in_array($child, $seen, true)) continue;
          $seen[] = $child;

          // Parent Exp
          $infoStmt = $pdo->prepare('SELECT parent_id, worth_type FROM child_accounts WHERE account_id = ?');
          $infoStmt->execute([$child]);
          $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
          // $master_parent_id=$info['parent_id'];
          $parentExp = '<span class="badge badge-primary">Unknown</span>';
          $parent_sending_possible="normal"; // means Parent sendiing possible
          if (!empty($info['worth_type'])) {
              $parentExp = $info['worth_type'] === 'half'
                  ? '<span class="badge badge-success">Full</span>'
                  : '<span class="badge badge-warning">Half</span>';

               $parent_sending_possible="special"; // means Parent not sendiing possible
          }

          $date = $row['created_at']
              ? (new DateTime($row['created_at']))->format('d M g:i a')
              : '';
          $statusText = htmlspecialchars($row['status'] ?? '', ENT_QUOTES);
      ?>
      <tr data-id="<?= $id ?>">
        <td><input type="checkbox" class="row-select" value="<?= $id ?>"></td>
        <td><?= $id ?></td>
        <td><?= $child ?></td>
        <td><?= htmlspecialchars($row['access_key_id'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($row['secret_access_key'], ENT_QUOTES) ?></td>
        <td><?= $parentExp ?></td>
        <td><?= $date ?></td>
        <td><span class="badge badge-info status-cell"><?= $statusText ?></span></td>
        <td><?= htmlspecialchars($row['cleanup_status'] ?? '', ENT_QUOTES) ?></td>
        <td class="d-inline-flex">
          <button class="btn btn-sm btn-info check-status">Check Status</button>
          <div class="btn-group">
            <button class="btn btn-sm btn-secondary dropdown-toggle" data-toggle="dropdown">Update</button>
            <div class="dropdown-menu">
              <?php foreach (['Delivered','Pending','Canceled','Recheck'] as $s): ?>
                <a class="dropdown-item upd-status" href="#" data-id="<?= $id ?>" data-status="<?= $s ?>"><?= $s ?></a>
              <?php endforeach; ?>
            </div>
          </div>
          <a href="iam_clear.php?ac_id=<?= $id ?>" class="btn btn-sm btn-danger" target="_blank">Clear</a>
          <a href="awsch/child_actions.php?ac_id=<?= $child ?>&user_id=<?= $session_id ?>&parent_sen_pos=<?= $parent_sending_possible ?>" class="btn btn-sm btn-success" target="_blank">Open</a>
          <!-- <a href="awsch/iam_brs.php?ac_id=<?= $child ?>&user_id=<?= $session_id ?>&parrent_id=<?= $master_parent_id ?>" class="btn btn-sm btn-success" target="_blank">Send</a> -->
        </td>
      </tr>
      <?php } ?>
    </tbody>
  </table>
</div>

<!-- JS includes -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
$(function(){
  var tbl = $('#accountsTable').DataTable();

  // Select all rows
  $('#select-all').on('click', function() {
    tbl.rows().nodes().to$().find('.row-select').prop('checked', this.checked);
  });

  // Delete Selected
  $('#delete-selected').click(function(){
    var ids = tbl.rows().nodes().to$().find('.row-select:checked').map(function(){ return this.value; }).get();
    if (!ids.length) return alert('Select rows');
    if (!confirm('Delete selected?')) return;
    $.post('', { delete_selected:1, selected_ids: ids }, function(res){
      if (res.success) location.reload(); else alert(res.msg);
    }, 'json');
  });

  // Check Status
  $('#accountsTable').on('click', '.check-status', function(){
    var btn = $(this).prop('disabled', true).text('Checking...');
    var row = btn.closest('tr');
    var id = row.data('id');
    $.post('', { check_status:1, id: id }, function(res){
      $('#check-response').removeClass().addClass(res.success ? 'alert alert-success' : 'alert alert-danger').text(res.msg);
      if (res.success) {
        row.find('.status-cell').text(res.status).removeClass().addClass('badge badge-info status-cell');
      }
    }, 'json').always(function(){ btn.prop('disabled', false).text('Check Status'); });
  });

  // Update Status via dropdown
  $('body').on('click', '.upd-status', function(e){
    e.preventDefault();
    var id = $(this).data('id'), st = $(this).data('status');
    $.post('iam_users_status_ajax.php', { update_status:1, id: id, status: st }, function(res){
      $('#check-response').removeClass().addClass(res.success ? 'alert alert-success' : 'alert alert-danger').text(res.msg);
    }, 'json');
  });
});
</script>
</body>
</html>
