<?php
// full_sender_v2_ajax_handler.php

include('../db.php');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($internal_call)) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../aws/aws-autoloader.php';

use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Aws\Exception\AwsException;

function initSNS($awsKey, $awsSecret, $awsRegion)
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
        return ['error' => 'Error initializing Pinpoint SMS Voice V2 client: ' . $e->getMessage()];
    }
}

function fetch_numbers($region, $pdo, $set_id = null)
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

function mapLanguageCode($lang)
{
    if ($lang === null) {
        return null;
    }

    $lang = trim((string)$lang);
    if ($lang === '') {
        return null;
    }

    $norm = strtoupper(str_replace('-', '_', $lang));

    $allowed = [
        'DE_DE', 'EN_GB', 'EN_US', 'ES_419', 'ES_ES', 'FR_CA', 'FR_FR',
        'IT_IT', 'JA_JP', 'KO_KR', 'PT_BR', 'ZH_CN', 'ZH_TW'
    ];

    if (in_array($norm, $allowed, true)) {
        return $norm;
    }

    $map = [
        'SPANISH LATIN AMERICA'   => 'ES_419',
        'SPANISH (LATIN AMERICA)' => 'ES_419',
        'SPANISH SPAIN'           => 'ES_ES',
        'ENGLISH US'              => 'EN_US',
        'ENGLISH (US)'            => 'EN_US',
        'UNITED STATES'           => 'EN_US',
        'ENGLISH UK'              => 'EN_GB',
        'ENGLISH (UK)'            => 'EN_GB',
        'GERMAN'                  => 'DE_DE',
        'FRENCH CANADA'           => 'FR_CA',
        'FRENCH FRANCE'           => 'FR_FR',
        'ITALIAN'                 => 'IT_IT',
        'JAPANESE'                => 'JA_JP',
        'KOREAN'                  => 'KO_KR',
        'PORTUGUESE BRAZIL'       => 'PT_BR',
        'PORTUGUESE (BRAZIL)'     => 'PT_BR',
        'CHINESE SIMPLIFIED'      => 'ZH_CN',
        'CHINESE (SIMPLIFIED)'    => 'ZH_CN',
        'CHINESE TRADITIONAL'     => 'ZH_TW',
        'CHINESE (TRADITIONAL)'   => 'ZH_TW',
        'DEFAULT-IT'              => 'IT_IT',
    ];

    return $map[$norm] ?? null;
}

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

    $phone = trim((string)$phone);

    $languageCode = mapLanguageCode($language);

    try {
        $createParams = [
            'DestinationPhoneNumber' => $phone,
        ];

        $createResp = $pinpointClient->createVerifiedDestinationNumber($createParams);
        $createRespArr = is_object($createResp) && method_exists($createResp, 'toArray') ? $createResp->toArray() : (array)$createResp;

        $verifiedId = $createRespArr['VerifiedDestinationNumberId'] ?? null;

        if (empty($verifiedId)) {
            $desc = $pinpointClient->describeVerifiedDestinationNumbers([
                'DestinationPhoneNumbers' => [$phone],
            ]);
            $descArr = is_object($desc) && method_exists($desc, 'toArray') ? $desc->toArray() : (array)$desc;

            if (!empty($descArr['VerifiedDestinationNumbers'][0]['VerifiedDestinationNumberId'])) {
                $verifiedId = $descArr['VerifiedDestinationNumbers'][0]['VerifiedDestinationNumberId'];
            }
        }

        if (empty($verifiedId)) {
            return ['status' => 'error', 'message' => 'Failed to get VerifiedDestinationNumberId.', 'region' => $region];
        }

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