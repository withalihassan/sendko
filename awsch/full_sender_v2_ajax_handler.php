<?php
// full_sender_v2_ajax_handler.php
// Updated to use Pinpoint SMS & Voice V2 APIs with optional LanguageCode support.

include('../db.php'); // Ensure your $pdo connection is initialized

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($internal_call)) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Aws\Exception\AwsException;

/**
 * Initialize the Pinpoint SMS & Voice V2 client.
 * Returns the client object or an array ['error'=>message]
 */
function initSNS($awsKey, $awsSecret, $awsRegion)
{
    try {
        $client = new PinpointSMSVoiceV2Client([
            'version'     => 'latest',
            'region'      => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
        return $client;
    } catch (Exception $e) {
        return ['error' => 'Error initializing Pinpoint SMS Voice V2 client: ' . $e->getMessage()];
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
 * Map incoming language codes (like es-419, it-IT) to the Pinpoint API format.
 * Returns one of: DE_DE, EN_GB, EN_US, ES_419, ES_ES, FR_CA, FR_FR, IT_IT, JA_JP, KO_KR, PT_BR, ZH_CN, ZH_TW
 * Returns null if input is empty or not recognized (so LanguageCode will not be sent).
 */
function mapLanguageCode($lang)
{
    if ($lang === null) return null;
    $l = trim($lang);
    if ($l === '') return null;

    // Normalize input: replace '-' with '_' and uppercase
    $norm = strtoupper(str_replace('-', '_', $l));

    $map = [
        "DE_DE"  => "DE_DE",
        "EN_GB"  => "EN_GB",
        "EN_US"  => "EN_US",
        "ES_419" => "ES_419",
        "ES_ES"  => "ES_ES",
        "FR_CA"  => "FR_CA",
        "FR_FR"  => "FR_FR",
        "IT_IT"  => "IT_IT",
        "JA_JP"  => "JA_JP",
        "KO_KR"  => "KO_KR",
        "PT_BR"  => "PT_BR",
        "ZH_CN"  => "ZH_CN",
        "ZH_TW"  => "ZH_TW",
        // Accept some common browser values or variants
        "ES419"  => "ES_419",
        "ES419"  => "ES_419",
        "ENUS"   => "EN_US",
        "ENGB"   => "EN_GB",
    ];

    return isset($map[$norm]) ? $map[$norm] : null;
}

/**
 * Send OTP flow (Pinpoint V2):
 * 1) createVerifiedDestinationNumber -> returns VerifiedDestinationNumberId (or fallback to describe to fetch existing)
 * 2) sendDestinationNumberVerificationCode (VerificationChannel = TEXT) — include LanguageCode only when provided/mapped
 *
 * $language: null => do NOT include LanguageCode
 *           string => source language string (e.g. "es-419", "it-IT") — will be mapped to Pinpoint code if possible
 */
function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $pinpointClient, $language = null)
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

    // Determine Pinpoint language code (or null)
    $languageCode = mapLanguageCode($language);

    // Step 1: try to create a verified destination number
    $verifiedId = null;
    try {
        $createResp = $pinpointClient->createVerifiedDestinationNumber([
            'DestinationPhoneNumber' => $phone,
            // optional: 'ClientToken' => uniqid('', true),
        ]);
        $verifiedId = isset($createResp['VerifiedDestinationNumberId']) ? $createResp['VerifiedDestinationNumberId'] : null;
    } catch (AwsException $e) {
        $awsCode = $e->getAwsErrorCode();
        $awsMessage = $e->getAwsErrorMessage() ?: $e->getMessage();

        // If number already exists (conflict), try describe to obtain existing VerifiedDestinationNumberId
        if (stripos($awsCode, 'Conflict') !== false || stripos($awsMessage, 'already') !== false) {
            try {
                $desc = $pinpointClient->describeVerifiedDestinationNumbers([
                    'DestinationPhoneNumbers' => [$phone],
                ]);
                if (!empty($desc['VerifiedDestinationNumbers']) && count($desc['VerifiedDestinationNumbers']) > 0) {
                    $verifiedId = $desc['VerifiedDestinationNumbers'][0]['VerifiedDestinationNumberId'] ?? null;
                }
            } catch (AwsException $e2) {
                // fallback to error below
                return ['status' => 'error', 'message' => 'Error finding existing verified number: ' . ($e2->getAwsErrorMessage() ?: $e2->getMessage()), 'region' => $region];
            }
        } else {
            // Handle spend limit / quota errors explicitly
            if (stripos($awsMessage, 'MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT') !== false || stripos($awsMessage, 'Spend limit') !== false || stripos($awsCode, 'ServiceQuotaExceeded') !== false || stripos($awsMessage, 'ServiceQuotaExceededException') !== false) {
                return ['status' => 'skip', 'message' => "Monthly spend limit reached. Skipping this number.", 'region' => $region];
            }
            if (stripos($awsMessage, 'VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT') !== false || stripos($awsMessage, 'verified destination') !== false) {
                return ['status' => 'error', 'message' => "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Try another region.", 'region' => $region];
            }
            if (stripos($awsMessage, 'Access Denied') !== false) {
                return ['status' => 'error', 'message' => "Access Denied", 'region' => $region];
            }

            return ['status' => 'error', 'message' => "Error creating verified destination number: " . $awsMessage, 'region' => $region];
        }
    }

    if (empty($verifiedId)) {
        return ['status' => 'error', 'message' => 'Could not obtain VerifiedDestinationNumberId for ' . $phone, 'region' => $region];
    }

    // Step 2: send verification code (TEXT) — include LanguageCode only when determined
    try {
        $params = [
            'VerifiedDestinationNumberId' => $verifiedId,
            'VerificationChannel' => 'TEXT',
        ];
        if ($languageCode !== null) {
            $params['LanguageCode'] = $languageCode;
        }

        $sendResp = $pinpointClient->sendDestinationNumberVerificationCode($params);
        // success -> success response
    } catch (AwsException $e) {
        $awsCode = $e->getAwsErrorCode();
        $awsMessage = $e->getAwsErrorMessage() ?: $e->getMessage();

        if (stripos($awsMessage, 'MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT') !== false || stripos($awsMessage, 'Spend limit') !== false || stripos($awsCode, 'ServiceQuotaExceeded') !== false || stripos($awsMessage, 'ServiceQuotaExceededException') !== false) {
            return ['status' => 'skip', 'message' => "Monthly spend limit reached. Skipping this number.", 'region' => $region];
        }
        if (stripos($awsMessage, 'VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT') !== false || stripos($awsMessage, 'verified destination') !== false) {
            return ['status' => 'error', 'message' => "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Try another region.", 'region' => $region];
        }
        if (stripos($awsMessage, 'Access Denied') !== false) {
            return ['status' => 'error', 'message' => "Access Denied", 'region' => $region];
        }

        return ['status' => 'error', 'message' => "Error sending verification code: " . $awsMessage, 'region' => $region];
    }

    // DB update (decrement atm_left, update last_used, status)
    try {
        $new_atm = $current_atm - 1;
        $new_status = ($new_atm == 0) ? 'used' : 'fresh';
        $last_used = date("Y-m-d H:i:s");
        $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = ?, status = ? WHERE id = ?");
        $updateStmt->execute([$new_atm, $last_used, $new_status, $id]);
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Database update error: ' . $e->getMessage(), 'region' => $region];
    }

    return ['status' => 'success', 'message' => "OTP sent to $phone successfully (verification code dispatched).", 'region' => $region];
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