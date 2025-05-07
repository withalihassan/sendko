<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../db.php'; // Database connection
$child_id = $_GET['child_id'];
// echo "Received child ID: " . htmlspecialchars($child_id) . "<br>";
// Prepare SQL query with PDO to join `launched_instances` with `child_accounts`
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
        ca.aws_access_key,
        ca.aws_secret_key
    FROM 
        launched_instances li
    INNER JOIN 
        child_accounts ca ON li.account_id = ca.account_id WHERE li.account_id='$child_id' AND ca.account_id='$child_id'
";
$stmt = $pdo->prepare($sql); // Prepare the query
$stmt->execute(); // Execute the query

// Start output
$output = '';

if ($stmt->rowCount() > 0) { // Use rowCount() to check the number of rows
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Output the table rows
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

echo $output; // Output the rows

// Connection is automatically closed when the script ends, no need to call $conn->close()
?>
