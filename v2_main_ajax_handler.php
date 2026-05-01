<?php
// v2_main_ajax_handler.php

include('db.php');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($internal_call)) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/aws/aws-autoloader.php';

use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Aws\Exception\AwsException;

function resultToArray($result): array
{
    if (is_object($result) && method_exists($result, 'toArray')) {
        return $result->toArray();
    }
    return (array) $result;
}

function initPinpointSMSV2($awsKey, $awsSecret, $awsRegion)
{
    try {
        return new PinpointSMSVoiceV2Client([
            'version' => 'latest',
            'region'  => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    } catch (Throwable $e) {
        return ['error' => 'Error initializing AWS client: ' . $e->getMessage()];
    }
}

function normalizePhoneNumber($phone): string
{
    $phone = trim((string) $phone);
    $phone = preg_replace('/[^\d+]/', '', $phone);

    if (str_starts_with($phone, '00')) {
        $phone = '+' . substr($phone, 2);
    }

    return $phone;
}

function awsLanguageCode($language): string
{
    $language = trim((string) $language);

    if ($language === '') {
        return 'ES_419';
    }

    $direct = strtoupper(str_replace('-', '_', $language));
    $allowed = [
        'DE_DE', 'EN_GB', 'EN_US', 'ES_419', 'ES_ES', 'FR_CA', 'FR_FR',
        'IT_IT', 'JA_JP', 'KO_KR', 'PT_BR', 'ZH_CN', 'ZH_TW'
    ];
    if (in_array($direct, $allowed, true)) {
        return $direct;
    }

    $map = [
        'english us'             => 'EN_US',
        'english (us)'           => 'EN_US',
        'united states'          => 'EN_US',
        'english uk'             => 'EN_GB',
        'english (uk)'           => 'EN_GB',
        'german'                 => 'DE_DE',
        'spanish latin america'  => 'ES_419',
        'spanish (latin america)' => 'ES_419',
        'spanish spain'          => 'ES_ES',
        'french canada'          => 'FR_CA',
        'french france'          => 'FR_FR',
        'italian'                => 'IT_IT',
        'japanese'               => 'JA_JP',
        'korean'                 => 'KO_KR',
        'portuguese brazil'      => 'PT_BR',
        'portuguese (brazil)'    => 'PT_BR',
        'chinese simplified'     => 'ZH_CN',
        'chinese (simplified)'   => 'ZH_CN',
        'chinese traditional'    => 'ZH_TW',
        'chinese (traditional)'  => 'ZH_TW',
        'default-it'             => 'EN_US',
    ];

    $key = strtolower(preg_replace('/\s+/', ' ', $language));
    return $map[$key] ?? 'ES_419';
}

function awsErrorMessage(AwsException $e): string
{
    return $e->getAwsErrorMessage() ?: $e->getMessage();
}

function describeVerifiedDestinationByPhone($sns, string $phone): ?array
{
    try {
        $res = $sns->describeVerifiedDestinationNumbers([
            'DestinationPhoneNumbers' => [normalizePhoneNumber($phone)],
        ]);

        $data = resultToArray($res);

        if (!empty($data['VerifiedDestinationNumbers'][0])) {
            return $data['VerifiedDestinationNumbers'][0];
        }

        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function createVerifiedDestinationNumber($sns, string $phone): array
{
    $res = $sns->createVerifiedDestinationNumber([
        'DestinationPhoneNumber' => $phone,
        'ClientToken' => hash('sha256', $phone . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)),
    ]);

    return resultToArray($res);
}

function sendDestinationNumberVerificationCode($sns, string $verifiedDestinationNumberId, string $languageCode): array
{
    $res = $sns->sendDestinationNumberVerificationCode([
        'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
        'VerificationChannel' => 'TEXT',
        'LanguageCode' => $languageCode,
    ]);

    return resultToArray($res);
}

function verifyDestinationNumber($sns, string $verifiedDestinationNumberId, string $verificationCode): array
{
    $res = $sns->verifyDestinationNumber([
        'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
        'VerificationCode' => $verificationCode,
    ]);

    return resultToArray($res);
}

function fetch_numbers($region, $user_id, $pdo, $set_id = null)
{
    if (empty($region)) {
        return ['error' => 'Region is required.'];
    }

    $query = "SELECT id, phone_number, atm_left, DATE_FORMAT(created_at, '%Y-%m-%d') as formatted_date
              FROM allowed_numbers
              WHERE status = 'fresh' AND atm_left > 0";
    $params = [];

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

function update_allowed_number_usage($pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ?");
    $stmt->execute([$id]);
    $numberData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$numberData) {
        return ['status' => 'error', 'message' => 'Number not found in database.'];
    }

    $current_atm = (int) $numberData['atm_left'];
    if ($current_atm <= 0) {
        return ['status' => 'error', 'message' => 'No remaining OTP attempts for this number.'];
    }

    $new_atm = $current_atm - 1;
    $new_status = ($new_atm === 0) ? 'used' : 'fresh';
    $last_used = date("Y-m-d H:i:s");

    $updateStmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = ?, status = ? WHERE id = ?");
    $updateStmt->execute([$new_atm, $last_used, $new_status, $id]);

    return [
        'status' => 'success',
        'atm_left' => $new_atm,
        'new_status' => $new_status,
        'last_used' => $last_used,
    ];
}

function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $user_id, $pdo, $sns, $language)
{
    $id = (int) $id;
    $phone = normalizePhoneNumber($phone);

    if (!$id || empty($phone)) {
        return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
    }

    $awsLang = awsLanguageCode($language);

    try {
        $existing = describeVerifiedDestinationByPhone($sns, $phone);

        if ($existing) {
            $status = strtoupper($existing['Status'] ?? 'PENDING');
            $verifiedId = $existing['VerifiedDestinationNumberId'] ?? null;

            if ($status === 'VERIFIED') {
                return [
                    'status' => 'skip',
                    'message' => 'Destination number already verified.',
                    'region' => $region
                ];
            }

            if (empty($verifiedId)) {
                return [
                    'status' => 'error',
                    'message' => 'Verified destination number ID not found.',
                    'region' => $region
                ];
            }

            $sendResult = sendDestinationNumberVerificationCode($sns, $verifiedId, $awsLang);

        } else {
            $created = createVerifiedDestinationNumber($sns, $phone);

            $verifiedId = $created['VerifiedDestinationNumberId'] ?? null;
            $status = strtoupper($created['Status'] ?? 'PENDING');

            if (empty($verifiedId)) {
                return [
                    'status' => 'error',
                    'message' => 'Failed to create verified destination number.',
                    'region' => $region
                ];
            }

            if ($status === 'VERIFIED') {
                return [
                    'status' => 'skip',
                    'message' => 'Destination number is already verified.',
                    'region' => $region
                ];
            }

            $sendResult = sendDestinationNumberVerificationCode($sns, $verifiedId, $awsLang);
        }

        $update = update_allowed_number_usage($pdo, $id);
        if ($update['status'] !== 'success') {
            return ['status' => 'error', 'message' => $update['message'], 'region' => $region];
        }

        $messageId = $sendResult['MessageId'] ?? '';
        return [
            'status' => 'success',
            'message' => "Verification code sent to {$phone} successfully.",
            'region' => $region,
            'message_id' => $messageId
        ];

    } catch (AwsException $e) {
        $errorMsg = awsErrorMessage($e);
        $errorCode = $e->getAwsErrorCode();

        if ($errorCode === 'ServiceQuotaExceededException' || stripos($errorMsg, 'quota') !== false) {
            return ['status' => 'skip', 'message' => 'Monthly spend limit or quota reached. Skipping this number.', 'region' => $region];
        }

        if (stripos($errorMsg, 'VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT') !== false) {
            return ['status' => 'skip', 'message' => 'Verified destination numbers per account limit reached.', 'region' => $region];
        }

        if (stripos($errorMsg, 'Access Denied') !== false) {
            return ['status' => 'error', 'message' => 'Access Denied / Region Restricted.', 'region' => $region];
        }

        return ['status' => 'error', 'message' => 'AWS error: ' . $errorMsg, 'region' => $region];

    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => 'Unexpected error: ' . $e->getMessage(), 'region' => $region];
    }
}

if (empty($internal_call)) {
    $awsKey    = isset($_POST['awsKey']) && !empty($_POST['awsKey']) ? $_POST['awsKey'] : 'DEFAULT_AWS_KEY';
    $awsSecret = isset($_POST['awsSecret']) && !empty($_POST['awsSecret']) ? $_POST['awsSecret'] : 'DEFAULT_AWS_SECRET';
    $awsRegion = !empty($_POST['region']) ? trim($_POST['region']) : 'ap-south-1';
    $action  = isset($_POST['action']) ? $_POST['action'] : '';
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    $sns = initPinpointSMSV2($awsKey, $awsSecret, $awsRegion);
    if (is_array($sns) && isset($sns['error'])) {
        echo json_encode(['status' => 'error', 'message' => $sns['error']]);
        exit;
    }

    if ($action === 'fetch_numbers') {
        $region = isset($_POST['region']) ? trim($_POST['region']) : '';
        $set_id = isset($_POST['set_id']) ? trim($_POST['set_id']) : '';
        $result = fetch_numbers($region, $user_id, $pdo, $set_id);
        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $result['error']]);
        } else {
            echo json_encode(array_merge(['status' => 'success'], $result));
        }
        exit;
    }

    if ($action === 'send_otp_single') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $region = isset($_POST['region']) ? trim($_POST['region']) : $awsRegion;
        $language = isset($_POST['language']) ? trim($_POST['language']) : 'ES_419';

        echo json_encode(send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $user_id, $pdo, $sns, $language));
        exit;
    }

    if ($action === 'verify_destination_number') {
        $verifiedDestinationNumberId = isset($_POST['verified_destination_number_id']) ? trim($_POST['verified_destination_number_id']) : '';
        $verificationCode = isset($_POST['verification_code']) ? trim($_POST['verification_code']) : '';

        if ($verifiedDestinationNumberId === '' || $verificationCode === '') {
            echo json_encode(['status' => 'error', 'message' => 'Verified destination number ID and verification code are required.']);
            exit;
        }

        try {
            $result = verifyDestinationNumber($sns, $verifiedDestinationNumberId, $verificationCode);
            echo json_encode(['status' => 'success', 'data' => $result]);
        } catch (AwsException $e) {
            echo json_encode(['status' => 'error', 'message' => awsErrorMessage($e)]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action.', 'region' => $awsRegion]);
    exit;
}