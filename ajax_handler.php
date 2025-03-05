<?php
// ajax_handler.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Include the AWS PHP SDK autoloader
require_once __DIR__ . '/aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

// Retrieve AWS credentials from POST (or fallback to defaults)
$awsKey = isset($_POST['awsKey']) && !empty($_POST['awsKey']) ? $_POST['awsKey'] : 'DEFAULT_AWS_KEY';
$awsSecret = isset($_POST['awsSecret']) && !empty($_POST['awsSecret']) ? $_POST['awsSecret'] : 'DEFAULT_AWS_SECRET';

// Determine region (default is ap-south-1)
$awsRegion = 'ap-south-1';
if (isset($_POST['region']) && !empty($_POST['region'])) {
    $awsRegion = trim($_POST['region']);
}

// Retrieve session_id from POST and treat it as the user ID.
$session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;

// Initialize the SNS client
try {
    $sns = new SnsClient([
        'version'     => 'latest',
        'region'      => $awsRegion,
        'credentials' => [
            'key'    => $awsKey,
            'secret' => $awsSecret,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error initializing SNS client: ' . $e->getMessage(),
    ]);
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

// Include database connection
include('db.php');

if ($action === 'fetch_numbers') {
    // Fetch allowed phone numbers from the database (with status "fresh")
    $region = isset($_POST['region']) ? trim($_POST['region']) : '';
    if (empty($region)) {
        echo json_encode(['status' => 'error', 'message' => 'Region is required.']);
        exit;
    }
    // Check for set_id; if provided, filter by it.
    $set_id = isset($_POST['set_id']) ? trim($_POST['set_id']) : '';

    $query = "SELECT id, phone_number, atm_left, last_used, created_at FROM allowed_numbers WHERE status = 'fresh' AND by_user = :session_id";
    $params = ['session_id' => $session_id];
    if (!empty($set_id)) {
        $query .= " AND set_id = :set_id";
        $params['set_id'] = $set_id;
    }
    $query .= " ORDER BY RAND() LIMIT 50";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'  => 'success',
        'region'  => $region,
        'data'    => $numbers
    ]);
    exit;
} elseif ($action === 'send_otp_single') {
    // Send OTP to a single phone number and update its record.
    $id    = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $region = isset($_POST['region']) ? trim($_POST['region']) : $awsRegion;

    if (empty($id) || empty($phone)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Invalid phone number or ID.',
            'region'  => $region
        ]);
        exit;
    }

    // Send OTP via AWS SNS Sandbox.
    try {
        // This example uses createSMSSandboxPhoneNumber; adjust as necessary for your OTP sending.
        $result = $sns->createSMSSandboxPhoneNumber([
            'PhoneNumber' => $phone,
        ]);
    } catch (AwsException $e) {
        $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
        if (strpos($errorMsg, "MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT") !== false) {
            echo json_encode([
                'status'  => 'skip',
                'message' => "Monthly spend limit reached. Skipping this number.",
                'region'  => $region
            ]);
            exit;
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => "Error sending OTP: " . $errorMsg,
                'region'  => $region
            ]);
            exit;
        }
    }

    // Update the allowed_numbers record:
    try {
        $stmt = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = :id AND by_user = :session_id");
        $stmt->execute(['id' => $id, 'session_id' => $session_id]);
        $numberData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$numberData) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Number not found.',
                'region'  => $region
            ]);
            exit;
        }
        $current_atm = (int)$numberData['atm_left'];
        if ($current_atm <= 0) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'No OTP attempts remaining for this number.',
                'region'  => $region
            ]);
            exit;
        }
        $new_atm = $current_atm - 1;
        $new_status = ($new_atm == 0) ? 'used' : 'fresh';
        $last_used = date("Y-m-d H:i:s");

        $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = :atm_left, last_used = :last_used, status = :status WHERE id = :id AND by_user = :session_id");
        $updateStmt->execute([
            'atm_left'    => $new_atm,
            'last_used'   => $last_used,
            'status'      => $new_status,
            'id'          => $id,
            'session_id'  => $session_id
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Error updating database: ' . $e->getMessage(),
            'region'  => $region
        ]);
        exit;
    }

    echo json_encode([
        'status'  => 'success',
        'message' => "OTP sent to $phone successfully.",
        'region'  => $region
    ]);
    exit;
} elseif ($action === 'update_status') {
    // Update the status of a given number (by ID) in the database.
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
    if (!$id || empty($new_status)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE allowed_numbers SET status = :new_status WHERE id = :id");
        $stmt->execute(['new_status' => $new_status, 'id' => $id]);
        echo json_encode([
            'status'  => 'success',
            'message' => "Status updated to $new_status."
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
} else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid action.',
        'region'  => $awsRegion
    ]);
    exit;
}
?>
