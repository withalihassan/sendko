<?php
// full_sender_v2_ajax_handler.php
// Updated to use Pinpoint SMS & Voice V2 APIs:
// - createVerifiedDestinationNumber
// - sendDestinationNumberVerificationCode
// Keeps existing database updates and return formats

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
 */
function mapLanguageCode($lang)
{
    $map = [
        "it-IT"  => "IT_IT",
        "es-419" => "ES_419",
        "en-US"  => "EN_US",
        "en-GB"  => "EN_GB",
        "de-DE"  => "DE_DE",
        "fr-FR"  => "FR_FR",
        "pt-BR"  => "PT_BR",
        "ja-JP"  => "JA_JP",
        "ko-KR"  => "KO_KR",
        "zh-CN"  => "ZH_CN",
        "zh-TW"  => "ZH_TW",
        "fr-CA"  => "FR_CA",
    ];
    $lang = trim($lang);
    return isset($map[$lang]) ? $map[$lang] : 'EN_US';
}

/**
 * Send OTP flow (Pinpoint V2):
 * 1) createVerifiedDestinationNumber -> returns VerifiedDestinationNumberId (or fallback to describe to fetch existing)
 * 2) sendDestinationNumberVerificationCode (VerificationChannel = TEXT)
 *
 * On success we reduce atm_left and update DB like before.
 */
function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $pinpointClient, $language = "")
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

    $languageCode = mapLanguageCode($language);

    // Step 1: try to create a verified destination number
    $verifiedId = null;
    try {
        $createResp = $pinpointClient->createVerifiedDestinationNumber([
            'DestinationPhoneNumber' => $phone,
            // 'ClientToken' => uniqid('', true), // optional idempotency token
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

    // Step 2: send verification code (TEXT)
    try {
        $sendResp = $pinpointClient->sendDestinationNumberVerificationCode([
            'VerifiedDestinationNumberId' => $verifiedId,
            'VerificationChannel' => 'TEXT',
            // 'LanguageCode' => $languageCode,
            // Optional: 'OriginationIdentity' => '...', 'ConfigurationSetName' => '...'
        ]);
        // success -> we received a MessageId in response
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
    $language = isset($_POST['language']) ? trim($_POST['language']) : "es-419";

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
