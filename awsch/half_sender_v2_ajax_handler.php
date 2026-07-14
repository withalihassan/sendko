<?php
// half_sender_v2_ajax_handler.php
// Pinpoint SMS & Voice V2 handler with database flow + pending verified-destination flow.

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
 * Returns the client object or an array ['error' => message]
 */
function initSNS($awsKey, $awsSecret, $awsRegion)
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
 * Map incoming language codes (like es-419, it-IT) to the Pinpoint API format.
 * Returns null if no mapping / language not provided.
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

    $norm = strtoupper(str_replace('-', '_', $l));

    $map = [
        'IT_IT'   => 'IT_IT',
        'ES_419'  => 'ES_419',
        'EN_US'   => 'EN_US',
        'EN_GB'   => 'EN_GB',
        'DE_DE'   => 'DE_DE',
        'FR_FR'   => 'FR_FR',
        'PT_BR'   => 'PT_BR',
        'JA_JP'   => 'JA_JP',
        'KO_KR'   => 'KO_KR',
        'ZH_CN'   => 'ZH_CN',
        'ZH_TW'   => 'ZH_TW',
        'FR_CA'   => 'FR_CA',
        'ES_ES'   => 'ES_ES',
        'ES419'   => 'ES_419',
        'ENGB'    => 'EN_GB',
        'ENUS'    => 'EN_US',
    ];

    return isset($map[$norm]) ? $map[$norm] : null;
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

function decrement_allowed_number_attempt($pdo, $id, $region)
{
    $id = intval($id);
    if ($id <= 0) {
        return ['status' => 'error', 'message' => 'Invalid database number ID.', 'region' => $region];
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

    try {
        $new_atm = $current_atm - 1;
        $new_status = ($new_atm == 0) ? 'used' : 'fresh';
        $last_used = date("Y-m-d H:i:s");

        $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = ?, status = ? WHERE id = ?");
        $updateStmt->execute([$new_atm, $last_used, $new_status, $id]);
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Database update error: ' . $e->getMessage(), 'region' => $region];
    }

    return ['status' => 'success', 'message' => 'Database attempt updated.', 'region' => $region];
}

/**
 * Fetch pending verified destination numbers from Pinpoint V2.
 * Uses status = PENDING.
 */
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
            $params = [
                'MaxResults' => 100,
                'Filters' => [
                    [
                        'Name' => 'status',
                        'Values' => ['PENDING'],
                    ],
                ],
            ];

            if (!empty($nextToken)) {
                $params['NextToken'] = $nextToken;
            }

            $result = $sns->describeVerifiedDestinationNumbers($params);

            $items = isset($result['VerifiedDestinationNumbers']) ? $result['VerifiedDestinationNumbers'] : [];
            foreach ($items as $item) {
                $phoneNumber = isset($item['DestinationPhoneNumber']) ? normalize_sns_phone_number($item['DestinationPhoneNumber']) : '';
                $status = isset($item['Status']) ? trim((string)$item['Status']) : '';
                $vdnId = isset($item['VerifiedDestinationNumberId']) ? trim((string)$item['VerifiedDestinationNumberId']) : '';
                $created = isset($item['CreatedTimestamp']) ? $item['CreatedTimestamp'] : null;

                if ($phoneNumber !== '' && strcasecmp($status, 'PENDING') === 0) {
                    $formattedDate = null;
                    if (!empty($created)) {
                        try {
                            if ($created instanceof DateTimeInterface) {
                                $formattedDate = $created->format('Y-m-d');
                            } else {
                                $formattedDate = date('Y-m-d', strtotime((string)$created));
                            }
                        } catch (Exception $e) {
                            $formattedDate = null;
                        }
                    }

                    $numberRow = [
                        'id' => $vdnId,
                        'db_id' => null,
                        'phone_number' => $phoneNumber,
                        'atm_left' => null,
                        'formatted_date' => $formattedDate,
                        'status' => $status,
                        'db_status' => null,
                        'db_match' => false
                    ];

                    if ($pdo instanceof PDO) {
                        $allowedNumber = find_allowed_number_by_phone($pdo, $phoneNumber);

                        if ($allowedNumber) {
                            $numberRow['db_id'] = $allowedNumber['id'];
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
 * Send OTP flow:
 * - database mode: createVerifiedDestinationNumber + sendDestinationNumberVerificationCode + DB update
 * - pending mode: reuse VerifiedDestinationNumberId and send verification code only
 *
 * $language: null => do not include LanguageCode
 *           string => include LanguageCode when mapped
 * $update_db: false => do not touch allowed_numbers table
 */
function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $pinpointClient, $language = null, $update_db = true, $pending_db_id = null)
{
    if (empty($phone)) {
        return ['status' => 'error', 'message' => 'Invalid phone number.', 'region' => $region];
    }

    $phone = trim((string)$phone);
    $languageCode = mapLanguageCode($language);

    // Pending mode: the passed $id is the VerifiedDestinationNumberId
    if (!$update_db) {
        if (empty($id)) {
            return ['status' => 'error', 'message' => 'Verified destination number ID is required.', 'region' => $region];
        }

        if ($pending_db_id !== null && intval($pending_db_id) > 0) {
            $stmt = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ?");
            $stmt->execute([intval($pending_db_id)]);
            $numberData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$numberData) {
                return ['status' => 'error', 'message' => 'Number not found in database.', 'region' => $region];
            }

            if (intval($numberData['atm_left']) <= 0) {
                return ['status' => 'error', 'message' => 'No remaining OTP attempts for this number.', 'region' => $region];
            }
        }

        try {
            $sendParams = [
                'VerifiedDestinationNumberId' => $id,
                'VerificationChannel' => 'TEXT',
            ];

            if ($languageCode !== null) {
                $sendParams['LanguageCode'] = $languageCode;
            }

            $pinpointClient->sendDestinationNumberVerificationCode($sendParams);

            if ($pending_db_id !== null && intval($pending_db_id) > 0) {
                $dbUpdate = decrement_allowed_number_attempt($pdo, $pending_db_id, $region);
                if ($dbUpdate['status'] !== 'success') {
                    return $dbUpdate;
                }
            }

            return ['status' => 'success', 'message' => "Verification code sent to $phone successfully.", 'region' => $region];
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
    }

    // Database mode
    if (!$id || intval($id) <= 0) {
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

    $verifiedId = null;

    try {
        $createResp = $pinpointClient->createVerifiedDestinationNumber([
            'DestinationPhoneNumber' => $phone,
        ]);

        if (is_object($createResp) && method_exists($createResp, 'toArray')) {
            $createRespArr = $createResp->toArray();
            $verifiedId = $createRespArr['VerifiedDestinationNumberId'] ?? null;
        } else {
            $createRespArr = (array)$createResp;
            $verifiedId = $createRespArr['VerifiedDestinationNumberId'] ?? null;
        }

        if (empty($verifiedId)) {
            try {
                $desc = $pinpointClient->describeVerifiedDestinationNumbers([
                    'DestinationPhoneNumbers' => [$phone],
                ]);

                if (is_object($desc) && method_exists($desc, 'toArray')) {
                    $descArr = $desc->toArray();
                } else {
                    $descArr = (array)$desc;
                }

                if (!empty($descArr['VerifiedDestinationNumbers'][0]['VerifiedDestinationNumberId'])) {
                    $verifiedId = $descArr['VerifiedDestinationNumbers'][0]['VerifiedDestinationNumberId'];
                }
            } catch (AwsException $e2) {
                return ['status' => 'error', 'message' => 'Failed to get VerifiedDestinationNumberId: ' . ($e2->getAwsErrorMessage() ?: $e2->getMessage()), 'region' => $region];
            }
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

        if (
            stripos($awsCode, 'Conflict') !== false ||
            stripos($awsMessage, 'already') !== false ||
            stripos($awsMessage, 'conflict') !== false
        ) {
            try {
                $desc = $pinpointClient->describeVerifiedDestinationNumbers([
                    'DestinationPhoneNumbers' => [$phone],
                ]);

                if (is_object($desc) && method_exists($desc, 'toArray')) {
                    $descArr = $desc->toArray();
                } else {
                    $descArr = (array)$desc;
                }

                if (!empty($descArr['VerifiedDestinationNumbers'])) {
                    foreach ($descArr['VerifiedDestinationNumbers'] as $row) {
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
        } else {
            return ['status' => 'error', 'message' => "Error creating verified destination number: " . $awsMessage, 'region' => $region];
        }
    }

    if (empty($verifiedId)) {
        return ['status' => 'error', 'message' => 'Failed to get VerifiedDestinationNumberId.', 'region' => $region];
    }

    try {
        $sendParams = [
            'VerifiedDestinationNumberId' => $verifiedId,
            'VerificationChannel' => 'TEXT',
        ];

        if ($languageCode !== null) {
            $sendParams['LanguageCode'] = $languageCode;
        }

        $pinpointClient->sendDestinationNumberVerificationCode($sendParams);
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

    try {
        $dbUpdate = decrement_allowed_number_attempt($pdo, $id, $region);
        if ($dbUpdate['status'] !== 'success') {
            return $dbUpdate;
        }
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

    $action = isset($_POST['action']) ? $_POST['action'] : '';
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
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $region = isset($_POST['region']) ? trim($_POST['region']) : $awsRegion;
        $update_db = isset($_POST['update_db']) ? filter_var($_POST['update_db'], FILTER_VALIDATE_BOOLEAN) : true;
        $pending_db_id = isset($_POST['db_id']) ? intval($_POST['db_id']) : null;

        $result = send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $pdo, $sns, $language, $update_db, $pending_db_id);
        echo json_encode($result);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.', 'region' => $awsRegion]);
        exit;
    }
}
?>
