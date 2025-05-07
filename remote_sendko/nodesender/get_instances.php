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
            <th>Public IP</th>
            <th>Elastic IP</th>
            <th>Actions</th>
          </tr></thead><tbody>';
  foreach ($instances as $instance) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($instance['instance_id']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['region']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['instance_type']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['state']) . '</td>';
    echo '<td>' . htmlspecialchars($instance['launch_time']) . '</td>';
    echo '<td><a href="http://' . $instance['public_ip'] . '/sendko/" target="_blank">' . $instance['public_ip'] . '</a><br>
          </td>';

    echo '<td><a href="http://' . $instance['elastic_ip'] . '/sendko/" target="_blank">' . $instance['elastic_ip'] . '</a><br>
          </td>';
    echo '<td>S > <a href="http://' . $instance['public_ip'] . '/bulk_send.php?ac_id=' . $account_id . '&user_id=' . $session_id . '" target="_blank">' . $instance['public_ip'] . '</a><br>
          R > <a href="http://' . $instance['public_ip'] . '/bulk_regional_send.php?ac_id=' . $account_id . '&user_id=' . $session_id . '" target="_blank">' . $instance['public_ip'] . '</a>
      </td>';
    echo '<td>S > <a href="http://' . $instance['elastic_ip'] . '/bulk_send.php?ac_id=' . $account_id . '&user_id=' . $session_id . '" target="_blank">' . $instance['elastic_ip'] . '</a><br>
              R > <a href="http://' . $instance['elastic_ip'] . '/bulk_regional_send.php?ac_id=' . $account_id . '&user_id=' . $session_id . '" target="_blank">' . $instance['elastic_ip'] . '</a>
            </td>';
    // echo '<td>' . $instance['elastic_ip'] . '</td>';
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
