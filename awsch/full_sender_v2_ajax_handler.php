<?php
// full_sender_v2_ajax_handler.php
// Uses SNS ListSMSSandboxPhoneNumbers for pending numbers.
// Pending flow creates a fresh verified destination number and sends the verification code
// without doing an extra describe lookup for an existing VerifiedDestinationNumberId.

include('../db.php'); // Ensure your $pdo connection is initialized

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($internal_call)) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

/**
 * Initialize the Pinpoint SMS & Voice V2 client.
 * Returns the client object or an array ['error'=>message]
 */
function initPinpointV2($awsKey, $awsSecret, $awsRegion)
{
    try {
        return new PinpointSMSVoiceV2Client([
            'version'     => 'latest',
            'region'      => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    } catch (Exception $e) {
        return ['error' => 'Error initializing Pinpoint SMS Voice V2 client: ' . $e->getMessage()];
    }
}

/**
 * Initialize the SNS client for sandbox listing.
 */
function initSNSClient($awsKey, $awsSecret, $awsRegion)
{
    try {
        return new SnsClient([
            'version'     => 'latest',
            'region'      => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    } catch (Exception $e) {
        return ['error' => 'Error initializing SNS client: ' . $e->getMessage()];
    }
}

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

/**
 * Normalize language code to Pinpoint V2 style where possible.
 * Returns null when not provided or unsupported.
 */
function mapLanguageCode($lang)
{
    if ($lang === null) {
        return null;
    }

    $l = trim((string)$lang);
    if ($l === '' || strtolower($l) === 'undefined') {
        return null;
    }

    $normalized = strtoupper(str_replace('-', '_', $l));

    $map = [
        'DE_DE'  => 'DE_DE',
        'EN_GB'  => 'EN_GB',
        'EN_US'  => 'EN_US',
        'ES_419' => 'ES_419',
        'ES_ES'  => 'ES_ES',
        'FR_CA'  => 'FR_CA',
        'FR_FR'  => 'FR_FR',
        'IT_IT'  => 'IT_IT',
        'JA_JP'  => 'JA_JP',
        'KO_KR'  => 'KO_KR',
        'PT_BR'  => 'PT_BR',
        'ZH_CN'  => 'ZH_CN',
        'ZH_TW'  => 'ZH_TW',
        'ES419'  => 'ES_419',
        'ENUS'   => 'EN_US',
        'ENGB'   => 'EN_GB',
    ];

    return isset($map[$normalized]) ? $map[$normalized] : null;
}

function isConflictException(AwsException $e)
{
    $code = (string)$e->getAwsErrorCode();
    $message = (string)($e->getAwsErrorMessage() ?: $e->getMessage());

    return (
        stripos($code, 'Conflict') !== false ||
        stripos($message, 'conflicting operations') !== false ||
        stripos($message, 'conflict') !== false
    );
}

function extractResourceIdFromAwsException(AwsException $e)
{
    $candidates = [];

    try {
        $response = $e->getResponse();
        if ($response) {
            $body = (string)$response->getBody();
            if ($body !== '') {
                $candidates[] = $body;

                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    foreach (['ResourceId', 'resourceId', 'resource_id', 'VerifiedDestinationNumberId', 'verifiedDestinationNumberId'] as $key) {
                        if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                            return trim($decoded[$key]);
                        }
                    }

                    foreach ($decoded as $v) {
                        if (is_array($v)) {
                            foreach (['ResourceId', 'resourceId', 'resource_id', 'VerifiedDestinationNumberId', 'verifiedDestinationNumberId'] as $key) {
                                if (!empty($v[$key]) && is_string($v[$key])) {
                                    return trim($v[$key]);
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $ignored) {
    }

    $message = (string)($e->getAwsErrorMessage() ?: $e->getMessage());
    $candidates[] = $message;

    foreach ($candidates as $text) {
        if (preg_match('/"?(ResourceId|resourceId|VerifiedDestinationNumberId|verifiedDestinationNumberId)"?\s*[:=]\s*"?([A-Za-z0-9_:/+-]+)"?/i', $text, $m)) {
            return trim($m[2]);
        }
    }

    return null;
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

/**
 * Fetch pending sandbox phone numbers from SNS.
 * ListSMSSandboxPhoneNumbers returns verified and pending destination phone numbers.
 * We only keep pending numbers for the resend flow.
 */
function fetch_pending_sns_numbers($region, $awsKey, $awsSecret, $pdo = null)
{
    if (empty($region)) {
        return ['error' => 'Region is required.'];
    }

    $sns = initSNSClient($awsKey, $awsSecret, $region);
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
        $msg = $e->getAwsErrorMessage() ?: $e->getMessage();
        return ['error' => 'Error fetching pending SNS numbers: ' . $msg];
    } catch (Exception $e) {
        return ['error' => 'Error fetching pending SNS numbers: ' . $e->getMessage()];
    }

    return ['success' => true, 'region' => $region, 'data' => $numbers];
}

/**
 * Send OTP / create verified destination number, then send verification code.
 *
 * $language: null  => do NOT include LanguageCode in AWS request
 *            string => include LanguageCode with that code (if mapping exists, use mapping; otherwise use provided code)
 * $update_db: false => do not touch allowed_numbers table
 * $pending_flow: true => never do an extra describe lookup; use create response or conflict ResourceId only
 */
function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $pinpoint, $language = null, $update_db = true, $pending_flow = false)
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

    $languageCode = mapLanguageCode($language);
    $verifiedId = null;

    // Step 1: create verified destination number
    try {
        $createResp = $pinpoint->createVerifiedDestinationNumber([
            'DestinationPhoneNumber' => $phone,
        ]);

        if (isset($createResp['VerifiedDestinationNumberId']) && trim((string)$createResp['VerifiedDestinationNumberId']) !== '') {
            $verifiedId = trim((string)$createResp['VerifiedDestinationNumberId']);
        }
    } catch (AwsException $e) {
        $awsCode = $e->getAwsErrorCode();
        $awsMessage = $e->getAwsErrorMessage() ?: $e->getMessage();

        if (
            stripos($awsMessage, 'MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT') !== false ||
            stripos($awsMessage, 'Spend limit') !== false ||
            stripos($awsCode, 'ServiceQuotaExceeded') !== false ||
            stripos($awsMessage, 'ServiceQuotaExceededException') !== false
        ) {
            return ['status' => 'skip', 'message' => "Monthly spend limit reached. Skipping this number.", 'region' => $region];
        }

        if (
            stripos($awsMessage, 'VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT') !== false ||
            stripos($awsMessage, 'verified destination') !== false
        ) {
            return ['status' => 'error', 'message' => "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Try another region.", 'region' => $region];
        }

        if (stripos($awsMessage, 'Access Denied') !== false) {
            return ['status' => 'error', 'message' => "Access Denied", 'region' => $region];
        }

        if (isConflictException($e)) {
            $resourceId = extractResourceIdFromAwsException($e);

            if (!empty($resourceId)) {
                $verifiedId = $resourceId;
            } elseif (!$pending_flow) {
                try {
                    $desc = $pinpoint->describeVerifiedDestinationNumbers([
                        'DestinationPhoneNumbers' => [$phone],
                        'MaxResults' => 10,
                    ]);

                    if (!empty($desc['VerifiedDestinationNumbers'])) {
                        foreach ($desc['VerifiedDestinationNumbers'] as $row) {
                            if (
                                isset($row['DestinationPhoneNumber']) &&
                                trim((string)$row['DestinationPhoneNumber']) === $phone
                            ) {
                                $verifiedId = $row['VerifiedDestinationNumberId'] ?? null;
                                break;
                            }
                        }
                    }
                } catch (AwsException $e2) {
                    return ['status' => 'error', 'message' => 'Error finding existing verified number: ' . ($e2->getAwsErrorMessage() ?: $e2->getMessage()), 'region' => $region];
                }
            }

            if (empty($verifiedId)) {
                return ['status' => 'error', 'message' => 'Could not obtain VerifiedDestinationNumberId from createVerifiedDestinationNumber conflict response for ' . $phone, 'region' => $region];
            }
        } else {
            return ['status' => 'error', 'message' => "Error creating verified destination number: " . $awsMessage, 'region' => $region];
        }
    }

    if (!$pending_flow && empty($verifiedId)) {
        try {
            $desc = $pinpoint->describeVerifiedDestinationNumbers([
                'DestinationPhoneNumbers' => [$phone],
                'MaxResults' => 10,
            ]);

            if (!empty($desc['VerifiedDestinationNumbers'])) {
                foreach ($desc['VerifiedDestinationNumbers'] as $row) {
                    if (
                        isset($row['DestinationPhoneNumber']) &&
                        trim((string)$row['DestinationPhoneNumber']) === $phone
                    ) {
                        $verifiedId = $row['VerifiedDestinationNumberId'] ?? null;
                        break;
                    }
                }
            }
        } catch (AwsException $e) {
            return ['status' => 'error', 'message' => 'Could not obtain VerifiedDestinationNumberId: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()), 'region' => $region];
        }
    }

    if (empty($verifiedId)) {
        return ['status' => 'error', 'message' => 'Could not obtain VerifiedDestinationNumberId for ' . $phone, 'region' => $region];
    }

    // Step 2: send verification code
    try {
        $params = [
            'VerifiedDestinationNumberId' => $verifiedId,
            'VerificationChannel' => 'TEXT',
        ];

        if ($languageCode !== null) {
            $params['LanguageCode'] = $languageCode;
        }

        $pinpoint->sendDestinationNumberVerificationCode($params);
    } catch (AwsException $e) {
        $awsCode = $e->getAwsErrorCode();
        $awsMessage = $e->getAwsErrorMessage() ?: $e->getMessage();

        if (
            stripos($awsMessage, 'MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT') !== false ||
            stripos($awsMessage, 'Spend limit') !== false ||
            stripos($awsCode, 'ServiceQuotaExceeded') !== false ||
            stripos($awsMessage, 'ServiceQuotaExceededException') !== false
        ) {
            return ['status' => 'skip', 'message' => "Monthly spend limit reached. Skipping this number.", 'region' => $region];
        }

        if (
            stripos($awsMessage, 'VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT') !== false ||
            stripos($awsMessage, 'verified destination') !== false
        ) {
            return ['status' => 'error', 'message' => "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error. Try another region.", 'region' => $region];
        }

        if (stripos($awsMessage, 'Access Denied') !== false) {
            return ['status' => 'error', 'message' => "Access Denied", 'region' => $region];
        }

        return ['status' => 'error', 'message' => "Error sending verification code: " . $awsMessage, 'region' => $region];
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

    return ['status' => 'success', 'message' => "OTP sent to $phone successfully (verification code dispatched).", 'region' => $region];
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

    $pinpoint = initPinpointV2($awsKey, $awsSecret, $awsRegion);
    if (is_array($pinpoint) && isset($pinpoint['error'])) {
        echo json_encode(['status' => 'error', 'message' => $pinpoint['error']]);
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
        $result = fetch_pending_sns_numbers($region, $awsKey, $awsSecret, $pdo);

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
        $pending_flow = isset($_POST['pending_flow']) ? filter_var($_POST['pending_flow'], FILTER_VALIDATE_BOOLEAN) : false;

        $result = send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $pinpoint, $language, $update_db, $pending_flow);
        echo json_encode($result);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.', 'region' => $awsRegion]);
        exit;
    }
}
?>
