<?php
// region_ajax_handler.php

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

if (!function_exists('get_sns_supported_languages')) {
    function get_sns_supported_languages()
    {
        return [
            'en-US' => 'English (United States)',
            'en-GB' => 'English (United Kingdom)',
            'es-419' => 'Spanish (Latin America) - 3P',
            'es-ES' => 'Spanish (Spain) - 3P',
            'de-DE' => 'German',
            'fr-CA' => 'French (Canada) - 3P',
            'fr-FR' => 'French (France) - 3P',
            'it-IT' => 'Italian - 1P',
            'ja-JP' => 'Japanese - 2P',
            'pt-BR' => 'Portuguese (Brazil) - 3P',
            'kr-KR' => 'Korean - 2P',
            'zh-CN' => 'Chinese (Simplified)',
            'zh-TW' => 'Chinese (Traditional)',
        ];
    }
}

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

function normalize_sns_phone_number($phone)
{
    $phone = trim((string)$phone);
    $phone = preg_replace('/[^\d+]/', '', $phone);

    if (substr($phone, 0, 2) === '00') {
        $phone = '+' . substr($phone, 2);
    }

    return $phone;
}

function find_allowed_number_by_phone($pdo, $phone)
{
    $normalizedPhone = normalize_sns_phone_number($phone);
    $digitsOnly = preg_replace('/\D/', '', $normalizedPhone);
    $phoneVariants = array_unique(array_filter([
        trim((string)$phone),
        $normalizedPhone,
        $digitsOnly,
        $digitsOnly !== '' ? '+' . $digitsOnly : ''
    ]));

    if (empty($phoneVariants)) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($phoneVariants), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, phone_number, atm_left, status, DATE_FORMAT(created_at, '%Y-%m-%d') as formatted_date
         FROM allowed_numbers
         WHERE phone_number IN ($placeholders)
         ORDER BY
             CASE
                 WHEN status = 'fresh' AND atm_left > 0 THEN 0
                 WHEN atm_left > 0 THEN 1
                 ELSE 2
             END,
             id DESC
         LIMIT 1"
    );
    $stmt->execute(array_values($phoneVariants));
    $number = $stmt->fetch(PDO::FETCH_ASSOC);

    return $number ?: null;
}

function fetch_pending_sns_numbers($region, $awsKey, $awsSecret, $pdo = null)
{
    if (empty($region)) {
        return ['error' => 'Region is required.'];
    }

    $sns = initSNS($awsKey, $awsSecret, $region);
    if (is_array($sns) && isset($sns['error'])) {
        return ['error' => $sns['error']];
    }

    $numbers = [];
    $nextToken = null;

    try {
        do {
            $params = ['MaxResults' => 100];
            if (!empty($nextToken)) {
                $params['NextToken'] = $nextToken;
            }

            $result = $sns->listSMSSandboxPhoneNumbers($params);
            $items = isset($result['PhoneNumbers']) ? $result['PhoneNumbers'] : [];

            foreach ($items as $item) {
                $phoneNumber = isset($item['PhoneNumber']) ? normalize_sns_phone_number($item['PhoneNumber']) : '';
                $status = isset($item['Status']) ? trim((string)$item['Status']) : '';

                if ($phoneNumber !== '' && strcasecmp($status, 'Pending') === 0) {
                    $numberRow = [
                        'id' => null,
                        'phone_number' => $phoneNumber,
                        'atm_left' => null,
                        'formatted_date' => null,
                        'status' => $status,
                        'db_status' => null,
                        'db_match' => false
                    ];

                    if ($pdo instanceof PDO) {
                        $allowedNumber = find_allowed_number_by_phone($pdo, $phoneNumber);

                        if ($allowedNumber) {
                            $numberRow['id'] = $allowedNumber['id'];
                            $numberRow['phone_number'] = $allowedNumber['phone_number'];
                            $numberRow['atm_left'] = $allowedNumber['atm_left'];
                            $numberRow['formatted_date'] = $allowedNumber['formatted_date'];
                            $numberRow['db_status'] = $allowedNumber['status'];
                            $numberRow['db_match'] = true;
                        }
                    }

                    $numbers[] = $numberRow;
                }
            }

            $nextToken = isset($result['NextToken']) ? (string)$result['NextToken'] : null;
        } while (!empty($nextToken));
    } catch (AwsException $e) {
        $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
        return ['error' => 'Error fetching pending SNS numbers: ' . $errorMsg];
    } catch (Exception $e) {
        return ['error' => 'Error fetching pending SNS numbers: ' . $e->getMessage()];
    }

    return ['success' => true, 'region' => $region, 'data' => $numbers];
}

/**
 * Send OTP / create SMS sandbox phone number.
 *
 * $language: null  => do NOT include LanguageCode in AWS request
 *            string => include LanguageCode with that code (if mapping exists, use mapping; otherwise use provided code)
 */
function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $sns, $language = null, $update_db = true)
{
    if (empty($phone)) {
        return ['status' => 'error', 'message' => 'Invalid phone number.', 'region' => $region];
    }

    if ($update_db && (!$id || intval($id) <= 0)) {
        return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
    }

    $current_atm = null;

    if ($update_db) {
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
    }

    // Map the provided language to the proper LanguageCode.
    $languageMapping = [
        "it-IT" => "it-IT",
        "es-419" => "es-419",
        "pt-BR" => "pt-BR",
        "de-DE" => "de-DE"
    ];

    $languageCode = null;
    if ($language !== null && $language !== '') {
        $languageCode = isset($languageMapping[$language]) ? $languageMapping[$language] : $language;
    }

    try {
        $params = [
            'PhoneNumber' => $phone,
        ];

        if ($languageCode !== null) {
            $params['LanguageCode'] = $languageCode;
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

    if ($update_db) {
        try {
            $new_atm = $current_atm - 1;
            $new_status = ($new_atm == 0) ? 'used' : 'fresh';
            $last_used = date("Y-m-d H:i:s");

            $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = ?, status = ? WHERE id = ?");
            $updateStmt->execute([$new_atm, $last_used, $new_status, $id]);
        } catch (PDOException $e) {
            return ['status' => 'error', 'message' => 'Database update error: ' . $e->getMessage(), 'region' => $region];
        }
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

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    $language = isset($_POST['language']) ? trim($_POST['language']) : null;
    $supportedLanguages = array_keys(get_sns_supported_languages());
    if ($language !== null && $language !== '' && !in_array($language, $supportedLanguages, true)) {
        $language = null;
    }

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
    } elseif ($action === 'fetch_pending_sns_numbers') {
        $region = isset($_POST['region']) ? trim($_POST['region']) : '';
        $result = fetch_pending_sns_numbers($region, $awsKey, $awsSecret);

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
        $update_db = isset($_POST['update_db']) ? filter_var($_POST['update_db'], FILTER_VALIDATE_BOOLEAN) : true;

        $result = send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $sns, $language, $update_db);
        echo json_encode($result);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.', 'region' => $awsRegion]);
        exit;
    }
}
?>
