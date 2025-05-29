<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// get_instances.php
include '../session.php';
include '../db.php';

if (!isset($_GET['account_id'])) {
  echo "No account ID provided.";
  exit;
}

$account_id = intval($_GET['account_id']);

$stmt = $pdo->prepare("SELECT * FROM instances WHERE account_id = ?");
$stmt->execute([$account_id]);
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($instances) {

  echo '<table class="table table-bordered table-striped">';
  echo '<thead><tr>
            <th>Instance ID</th>
            <th>Region</th>
            <th>Instance Type</th>
            <th>State</th>
            <th>Launch Time</th>
            <th>Host</th>
            <th>Elastic Host</th>
            <th>Actions</th>
          </tr></thead><tbody>';
  foreach ($instances as $instance) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($instance['instance_id']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['region']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['instance_type']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['state']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['launch_time']) . '</td>';
?>

    <?php
    $current_ip         = $instance['public_ip'];
    $current_elastic_ip = $instance['elastic_ip'];

    // 1) Flatten both columns into one union’ed table, then count per‐IP:
    $sql = <<<SQL
        SELECT
          SUM(CASE WHEN ip = :ip  THEN 1 ELSE 0 END) AS ip_count,
          SUM(CASE WHEN ip = :eip THEN 1 ELSE 0 END) AS eip_count
        FROM (
          SELECT public_ip AS ip FROM instances
          UNION ALL
          SELECT elastic_ip AS ip FROM instances
        ) AS all_ips
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':ip'  => $current_ip,
      ':eip' => $current_elastic_ip,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2) Check if **either** of those counts is ≥ 2:
    if (($row['ip_count']  >= 2) ||
      ($row['eip_count'] >= 2)
    ) {
       $status = "<span class='badge badge-danger'>Used</span>";
    } else {
      $status = "<span class='badge badge-success'>Fresh</span>";
    }
    
    echo '<td><a href="http://' . $instance['public_ip'] . '/" target="_blank">' . $instance['public_ip'] ." ".$status. '</a><br>
          </td>';

    echo '<td><a href="http://' . $instance['elastic_ip'] . '/" target="_blank">' . $instance['elastic_ip'] ." ".$status.  '</a><br>
          </td>';
    ?>
    <?php
    echo '<td>';
    // Terminate button
    echo '<form class="ajaxActionForm d-inline" method="post">
                <input type="hidden" name="account_id" value="' . $account_id . '">
                <input type="hidden" name="instance_id" value="' . htmlspecialchars($instance['instance_id']) . '">
                <input type="hidden" name="region" value="' . htmlspecialchars($instance['region']) . '">
                <input type="hidden" name="action" value="terminate">
                <button type="submit" class="btn btn-danger btn-sm">Terminate</button>
              </form> ';
    // Change IP button
    echo '<form class="ajaxActionForm d-inline" method="post">
                <input type="hidden" name="account_id" value="' . $account_id . '">
                <input type="hidden" name="instance_id" value="' . htmlspecialchars($instance['instance_id']) . '">
                <input type="hidden" name="region" value="' . htmlspecialchars($instance['region']) . '">
                <input type="hidden" name="action" value="change_ip">
                <button type="submit" class="btn btn-warning btn-sm">Change IP</button>
              </form> ';
    // Update button
    echo '<form class="ajaxActionForm d-inline" method="post">
                <input type="hidden" name="account_id" value="' . $account_id . '">
                <input type="hidden" name="instance_id" value="' . htmlspecialchars($instance['instance_id']) . '">
                <input type="hidden" name="region" value="' . htmlspecialchars($instance['region']) . '">
                <input type="hidden" name="action" value="update">
                <button type="submit" class="btn btn-info btn-sm">Update</button>
              </form> ';
    // Start button
    echo '<form class="ajaxActionForm d-inline" method="post">
            <input type="hidden" name="account_id" value="' . $account_id . '">
            <input type="hidden" name="instance_id" value="' . htmlspecialchars($instance['instance_id']) . '">
            <input type="hidden" name="region" value="' . htmlspecialchars($instance['region']) . '">
            <input type="hidden" name="action" value="start">
            <button type="submit" class="btn btn-success btn-sm">Start</button>
          </form> ';
    // Stop button
    echo '<form class="ajaxActionForm d-inline" method="post">
            <input type="hidden" name="account_id" value="' . $account_id . '">
            <input type="hidden" name="instance_id" value="' . htmlspecialchars($instance['instance_id']) . '">
            <input type="hidden" name="region" value="' . htmlspecialchars($instance['region']) . '">
            <input type="hidden" name="action" value="stop">
            <button type="submit" class="btn btn-warning btn-sm">Stop</button>
          </form>';
    echo '</td>';
    echo '</tr>';
  }
  echo '</tbody></table>';
} else {
  echo '<div class="alert alert-info">No instances found.</div>';
}
