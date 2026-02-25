<?php
// region_ajax_handler_brs.php

include('../db.php'); // Ensure your $pdo connection is initialized

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($internal_call)) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

function initSNS($awsKey, $awsSecret, $awsRegion)
{
    try {
        $sns = new SnsClient([
            'version'     => 'latest',
            'region'      => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
        return $sns;
    } catch (Exception $e) {
        return ['error' => 'Error initializing SNS client: ' . $e->getMessage()];
    }
}

// Fetch phone numbers based solely on the set_id.
function fetch_numbers($region, $pdo, $set_id = null)
{
    if (empty($region)) {
        return ['error' => 'Region is required.'];
    }
    $query = "SELECT id, phone_number, atm_left, DATE_FORMAT(created_at, '%Y-%m-%d') as formatted_date 
              FROM allowed_numbers 
              WHERE status = 'fresh' AND atm_left > 0";
    $params = array();
    if (!empty($set_id)) {
        $query .= " AND set_id = ?";
        $params[] = $set_id;
    }
    $query .= " ORDER BY RAND() LIMIT 50";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['success' => true, 'region' => $region, 'data' => $numbers];
}

/**
 * Send OTP / create SMS sandbox phone number.
 *
 * $language: null  => do NOT include LanguageCode in AWS request
 *            string => include LanguageCode with that code (if mapping exists, use mapping; otherwise use provided code)
 */
function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $sns, $language = null)
{
    if (!$id || empty($phone)) {
        return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
    }
    $stmt = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ?");
    $stmt->execute([$id]);
    $numberData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$numberData) {
        return ['status' => 'error', 'message' => 'Number not found in database.', 'region' => $region];
    }
    $current_atm = intval($numberData['atm_left']);
    if ($current_atm <= 0) {
        return ['status' => 'error', 'message' => 'No remaining OTP attempts for this number.', 'region' => $region];
    }
    // Map provided language to proper LanguageCode if applicable.
    $languageMapping = [
        "it-IT"  => "it-IT",
        "es-419" => "es-419"
        // Add additional mappings as necessary.
    ];

    // Only determine languageCode if $language is not null/empty
    $languageCode = null;
    if ($language !== null && $language !== '') {
        // If we have a mapping, use it; otherwise pass the provided code as-is
        $languageCode = isset($languageMapping[$language]) ? $languageMapping[$language] : $language;
    }

    try {
        // Build params dynamically: include LanguageCode only when provided
        $params = [
            'PhoneNumber' => $phone,
        ];
        if ($language !== null && $language !== '') {
            $params['LanguageCode'] = $language;
        }

        $result = $sns->createSMSSandboxPhoneNumber($params);
    } catch (AwsException $e) {
        $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
        if (strpos($errorMsg, "MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT") !== false) {
            return ['status' => 'skip', 'message' => "Monthly spend limit reached. Skipping this number.", 'region' => $region];
        }
        if (strpos($errorMsg, "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
            return ['status' => 'error', 'message' => "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Try another region.", 'region' => $region];
        }
        if (strpos($errorMsg, "Access Denied") !== false) {
            return ['status' => 'error', 'message' => "Region Restricted moving to next", 'region' => $region];
        }
        return ['status' => 'error', 'message' => "Error sending OTP: " . $errorMsg, 'region' => $region];
    }
    try {
        $new_atm = $current_atm - 1;
        $new_status = ($new_atm == 0) ? 'used' : 'fresh';
        $last_used = date("Y-m-d H:i:s");
        $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = ?, status = ? WHERE id = ?");
        $updateStmt->execute([$new_atm, $last_used, $new_status, $id]);
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Database update error: ' . $e->getMessage(), 'region' => $region];
    }
    return ['status' => 'success', 'message' => "OTP sent to $phone successfully.", 'region' => $region];
}

if (empty($internal_call)) {
    $awsKey    = isset($_POST['awsKey']) && !empty($_POST['awsKey']) ? $_POST['awsKey'] : 'DEFAULT_AWS_KEY';
    $awsSecret = isset($_POST['awsSecret']) && !empty($_POST['awsSecret']) ? $_POST['awsSecret'] : 'DEFAULT_AWS_SECRET';
    $awsRegion = 'ap-south-1';
    if (!empty($_POST['region'])) {
        $awsRegion = trim($_POST['region']);
    }
    $action  = isset($_POST['action']) ? $_POST['action'] : '';

    // Retrieve language from POST for non-streaming calls.
    // IMPORTANT: default to null to represent "no language selected".
    $language = isset($_POST['language']) ? trim($_POST['language']) : null;

    $sns = initSNS($awsKey, $awsSecret, $awsRegion);
    if (is_array($sns) && isset($sns['error'])) {
        echo json_encode(['status' => 'error', 'message' => $sns['error']]);
        exit;
    }

    if ($action === 'fetch_numbers') {
        $region = isset($_POST['region']) ? trim($_POST['region']) : '';
        $set_id = isset($_POST['set_id']) ? trim($_POST['set_id']) : '';
        $result = fetch_numbers($region, $pdo, $set_id);
        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $result['error']]);
        } else {
            echo json_encode(array_merge(['status' => 'success'], $result));
        }
        exit;
    } elseif ($action === 'send_otp_single') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $region = isset($_POST['region']) ? trim($_POST['region']) : $awsRegion;
        $result = send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $sns, $language);
        echo json_encode($result);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.', 'region' => $awsRegion]);
        exit;
    }
}
