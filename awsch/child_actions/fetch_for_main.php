<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../db_connect.php'; // Database connection

$child_id = $_GET['child_id'] ?? null; 

if (!$child_id) {
    die("Invalid Request: Missing child_id");
}

// Prepare SQL query to join `launched_instances` with `aws_accounts`
$sql = "
    SELECT 
        li.id,
        li.account_id,
        li.instance_id,
        li.region,
        li.instance_type,
        li.launch_type,
        li.state,
        li.created_at,
        aa.aws_key AS aws_access_key,
        aa.aws_secret AS aws_secret_key
    FROM 
        launched_instances li
    INNER JOIN 
        aws_accounts aa ON li.account_id = aa.account_id
    WHERE 
        li.account_id = :child_id
        AND aa.account_id = :child_id
";

$stmt = $conn->prepare($sql); // Prepare the query
$stmt->bindParam(':child_id', $child_id, PDO::PARAM_STR); // Bind the parameter
$stmt->execute(); // Execute the query

$output = '';

if ($stmt->rowCount() > 0) { 
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $output .= "<tr>
            <td>{$row['id']}</td>
            <td>{$row['instance_id']}</td>
            <td>{$row['region']}</td>
            <td>{$row['instance_type']}</td>
            <td>{$row['launch_type']}</td>
            <td>{$row['state']}</td>
            <td>{$row['created_at']}</td>
            <td>
                <button class='btn btn-danger terminate' 
                        data-id='{$row['id']}' 
                        data-instance-id='{$row['instance_id']}' 
                        data-region='{$row['region']}'
                        data-access-key='{$row['aws_access_key']}' 
                        data-secret-key='{$row['aws_secret_key']}'> 
                    Terminate
                </button>
            </td>
        </tr>";
    }
} else {
    $output .= "<tr><td colspan='8'>No instances found.</td></tr>";
}

echo $output;
?>
