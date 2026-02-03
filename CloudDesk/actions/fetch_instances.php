<?php
// child_actions/fetch_instances.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

// adjust path if needed
require '../../db.php'; // must set $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "<tr><td colspan='8'>Database not configured.</td></tr>";
    exit;
}

$parent_id = trim((string)($_GET['parent_id'] ?? ''));

if ($parent_id === '') {
    echo "<tr><td colspan='8'>No parent_id provided.</td></tr>";
    exit;
}

try {
    // Only select safe fields. parent_id is stored in launched_desks.
    $sql = "SELECT id, instance_id, region_name AS region, type AS instance_type,
                   NULLIF('', '') AS launch_type, state, public_ip, password,  launched_at AS created_at
            FROM launched_desks
            WHERE parent_id = :pid
            ORDER BY id DESC
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pid' => $parent_id]);

    if ($stmt->rowCount() === 0) {
        echo "<tr><td colspan='8'>No instances found.</td></tr>";
        exit;
    }

    $out = '';
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = htmlspecialchars($r['id']);
        $instance_id = htmlspecialchars($r['instance_id']);
        $region = htmlspecialchars($r['region']);
        $instance_type = htmlspecialchars($r['instance_type']);
        $launch_type = htmlspecialchars($r['launch_type'] ?? '');
        $state = htmlspecialchars($r['state']);
        $public_ip = htmlspecialchars($r['public_ip']);
        $password = $r['password'];
        $created_at = htmlspecialchars($r['created_at']);

$out .= "<tr id='row-{$id}'>
    <td data-field='id'>{$id}</td>
    <td data-field='instance_id'>{$instance_id}</td>
    <td data-field='region'>{$region}</td>
    <td data-field='instance_type'>{$instance_type}</td>
    <td data-field='public_ip'>{$public_ip}</td>
    <td data-field='password'>{$password}</td>
    <td data-field='state'>{$state}</td>
    <td data-field='created_at'>{$created_at}</td>
    <td>
      <button class='btn btn-primary start' 
              data-id='{$id}' data-instance-id='{$instance_id}'  data-region='{$region}'> Start </button>
      <button class='btn btn-warning stop' 
              data-id='{$id}' data-instance-id='{$instance_id}'  data-region='{$region}'> Stop </button>      
      <button class='btn btn-success reload' 
              data-id='{$id}' data-instance-id='{$instance_id}'  data-region='{$region}'> reload </button>
      <button class='btn btn-danger terminate' 
              data-id='{$id}' data-instance-id='{$instance_id}'  data-region='{$region}'> Terminate </button>
      <button class='btn btn-info changeip' 
              data-id='{$id}' data-instance-id='{$instance_id}'  data-region='{$region}'> Chng IP </button>
      <button class='btn btn-dark getpsw' 
              data-id='{$id}' data-instance-id='{$instance_id}'  data-region='{$region}'> Get PSW</button>
    </td>
</tr>";
    }

    echo $out;

} catch (Throwable $e) {
    // Do not leak sensitive info to the client in production
    echo "<tr><td colspan='8'>Error fetching instances.</td></tr>";
    // optionally log error to server logs
    error_log("fetch_instances error: " . $e->getMessage());
}
