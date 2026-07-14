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
use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

function result_to_array($result): array
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

function initSNSClient($awsKey, $awsSecret, $awsRegion)
{
    try {
        return new SnsClient([
            'version' => 'latest',
            'region'  => $awsRegion,
            'credentials' => [
                'key'    => $awsKey,
                'secret' => $awsSecret,
            ],
        ]);
    } catch (Throwable $e) {
        return ['error' => 'Error initializing SNS client: ' . $e->getMessage()];
    }
}

function normalize_phone_number($phone): string
{
    $phone = trim((string) $phone);
    $phone = preg_replace('/[^\d+]/', '', $phone);

    if (str_starts_with($phone, '00')) {
        $phone = '+' . substr($phone, 2);
    }

    return $phone;
}

function normalize_language_code($language): ?string
{
    $language = trim((string) $language);

    if ($language === '' || strtolower($language) === 'undefined') {
        return null;
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
        'english us'              => 'EN_US',
        'english (us)'            => 'EN_US',
        'english uk'              => 'EN_GB',
        'english (uk)'             => 'EN_GB',
        'german'                  => 'DE_DE',
        'spanish latin america'   => 'ES_419',
        'spanish (latin america)' => 'ES_419',
        'spanish spain'           => 'ES_ES',
        'french canada'           => 'FR_CA',
        'french france'           => 'FR_FR',
        'italian'                 => 'IT_IT',
        'japanese'                => 'JA_JP',
        'korean'                  => 'KO_KR',
        'portuguese brazil'       => 'PT_BR',
        'portuguese (brazil)'     => 'PT_BR',
        'chinese simplified'      => 'ZH_CN',
        'chinese (simplified)'    => 'ZH_CN',
        'chinese traditional'     => 'ZH_TW',
        'chinese (traditional)'   => 'ZH_TW',
    ];

    $key = strtolower(preg_replace('/\s+/', ' ', $language));
    return $map[$key] ?? null;
}

function aws_error_message(AwsException $e): string
{
    return $e->getAwsErrorMessage() ?: $e->getMessage();
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

function find_allowed_number_by_phone($pdo, $phone): ?array
{
    $normalizedPhone = normalize_phone_number($phone);
    $digitsOnly = preg_replace('/\D/', '', $normalizedPhone);
    $phoneVariants = array_unique(array_filter([
        trim((string) $phone),
        $normalizedPhone,
        $digitsOnly,
        $digitsOnly !== '' ? '+' . $digitsOnly : '',
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

    $sns = initSNSClient($awsKey, $awsSecret, $region);
    if (is_array($sns) && isset($sns['error'])) {
        return ['error' => $sns['error']];
    }

    $numbers = [];
    $nextToken = null;

    try {
        do {
            $params = [
                'MaxResults' => 100,
            ];

            if (!empty($nextToken)) {
                $params['NextToken'] = $nextToken;
            }

            $result = $sns->listSMSSandboxPhoneNumbers($params);
            $data = result_to_array($result);

            $items = $data['PhoneNumbers'] ?? [];
            foreach ($items as $item) {
                $phoneNumber = normalize_phone_number($item['PhoneNumber'] ?? '');
                $status = trim((string) ($item['Status'] ?? ''));

                if ($phoneNumber === '' || strcasecmp($status, 'Pending') !== 0) {
                    continue;
                }

                $numberRow = [
                    'id' => null,
                    'phone_number' => $phoneNumber,
                    'atm_left' => null,
                    'formatted_date' => null,
                    'status' => $status,
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
                    } else {
                        $numberRow['db_status'] = null;
                        $numberRow['db_match'] = false;
                    }
                }

                $numbers[] = $numberRow;
            }

            $nextToken = $data['NextToken'] ?? null;
            $nextToken = $nextToken !== null ? (string) $nextToken : null;
        } while (!empty($nextToken));
    } catch (AwsException $e) {
        return ['error' => 'Error fetching pending SNS numbers: ' . aws_error_message($e)];
    } catch (Throwable $e) {
        return ['error' => 'Error fetching pending SNS numbers: ' . $e->getMessage()];
    }

    return ['success' => true, 'region' => $region, 'data' => $numbers];
}

function get_verified_destination_by_phone($sns, string $phone): ?array
{
    try {
        $res = $sns->describeVerifiedDestinationNumbers([
            'DestinationPhoneNumbers' => [normalize_phone_number($phone)],
        ]);

        $data = result_to_array($res);
        if (!empty($data['VerifiedDestinationNumbers'][0])) {
            return $data['VerifiedDestinationNumbers'][0];
        }
    } catch (Throwable $e) {
    }

    return null;
}

function create_verified_destination_number($sns, string $phone): array
{
    $res = $sns->createVerifiedDestinationNumber([
        'DestinationPhoneNumber' => normalize_phone_number($phone),
        'ClientToken' => hash('sha256', $phone . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)),
    ]);

    return result_to_array($res);
}

function send_destination_verification_code($sns, string $verifiedDestinationNumberId, ?string $languageCode): array
{
    $params = [
        'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
        'VerificationChannel' => 'TEXT',
    ];

    if ($languageCode !== null) {
        $params['LanguageCode'] = $languageCode;
    }

    $res = $sns->sendDestinationNumberVerificationCode($params);
    return result_to_array($res);
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

function extract_verified_destination_id_from_response($response): ?string
{
    $data = result_to_array($response);

    foreach ([
        'VerifiedDestinationNumberId',
        'verifiedDestinationNumberId',
        'ResourceId',
        'resourceId'
    ] as $key) {
        if (!empty($data[$key])) {
            return trim((string) $data[$key]);
        }
    }

    return null;
}

function extract_verified_destination_id_from_exception(AwsException $e): ?string
{
    $body = '';
    try {
        $response = $e->getResponse();
        if ($response) {
            $body = (string) $response->getBody();
        }
    } catch (Throwable $ignored) {
    }

    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (['VerifiedDestinationNumberId', 'verifiedDestinationNumberId', 'ResourceId', 'resourceId'] as $key) {
                if (!empty($decoded[$key])) {
                    return trim((string) $decoded[$key]);
                }
            }

            foreach ($decoded as $val) {
                if (is_array($val)) {
                    foreach (['VerifiedDestinationNumberId', 'verifiedDestinationNumberId', 'ResourceId', 'resourceId'] as $key) {
                        if (!empty($val[$key])) {
                            return trim((string) $val[$key]);
                        }
                    }
                }
            }
        }

        if (preg_match('/<(?:VerifiedDestinationNumberId|ResourceId)>([^<]+)<\/(?:VerifiedDestinationNumberId|ResourceId)>/i', $body, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/"(?:VerifiedDestinationNumberId|ResourceId)"\s*:\s*"([^"]+)"/i', $body, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/(?:VerifiedDestinationNumberId|ResourceId)\s*[:=]\s*([A-Za-z0-9_:\\/+-]+)/i', $body, $m)) {
            return trim($m[1]);
        }
    }

    $message = aws_error_message($e);
    if (preg_match('/"(?:VerifiedDestinationNumberId|ResourceId)"\s*:\s*"([^"]+)"/i', $message, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/(?:VerifiedDestinationNumberId|ResourceId)\s*[:=]\s*([A-Za-z0-9_:\\/+-]+)/i', $message, $m)) {
        return trim($m[1]);
    }

    return null;
}

function send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $user_id, $pdo, $sns, $language, $update_db = true, $pending_flow = false)
{
    $phone = normalize_phone_number($phone);
    $languageCode = normalize_language_code($language);

    if ($phone === '') {
        return ['status' => 'error', 'message' => 'Invalid phone number.', 'region' => $region];
    }

    if ($pending_flow) {
        if ($update_db) {
            $dbId = (int) $id;
            if ($dbId <= 0) {
                return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
            }

            $current = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ?");
            $current->execute([$dbId]);
            $numberData = $current->fetch(PDO::FETCH_ASSOC);

            if (!$numberData) {
                return ['status' => 'error', 'message' => 'Number not found in database.', 'region' => $region];
            }

            if ((int) $numberData['atm_left'] <= 0) {
                return ['status' => 'error', 'message' => 'No remaining OTP attempts for this number.', 'region' => $region];
            }
        }

        try {
            $createResult = $sns->createVerifiedDestinationNumber([
                'DestinationPhoneNumber' => $phone,
            ]);

            $verifiedDestinationNumberId = extract_verified_destination_id_from_response($createResult);
            if (empty($verifiedDestinationNumberId)) {
                return ['status' => 'error', 'message' => 'Could not obtain VerifiedDestinationNumberId from createVerifiedDestinationNumber response.', 'region' => $region];
            }

            $params = [
                'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
                'VerificationChannel' => 'TEXT',
            ];

            if ($languageCode !== null) {
                $params['LanguageCode'] = $languageCode;
            }

            $sns->sendDestinationNumberVerificationCode($params);

            if ($update_db) {
                $dbId = (int) $id;
                if ($dbId <= 0) {
                    return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
                }

                $update = update_allowed_number_usage($pdo, $dbId);
                if ($update['status'] !== 'success') {
                    return ['status' => 'error', 'message' => $update['message'], 'region' => $region];
                }
            }

            return [
                'status' => 'success',
                'message' => "Verification code sent to {$phone} successfully.",
                'region' => $region,
                'message_id' => ''
            ];
        } catch (AwsException $e) {
            $errorMsg = aws_error_message($e);
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

            if (stripos($errorMsg, 'conflict') !== false || $errorCode === 'ConflictException') {
                $verifiedDestinationNumberId = extract_verified_destination_id_from_exception($e);

                if (!empty($verifiedDestinationNumberId)) {
                    try {
                        $params = [
                            'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
                            'VerificationChannel' => 'TEXT',
                        ];

                        if ($languageCode !== null) {
                            $params['LanguageCode'] = $languageCode;
                        }

                        $sns->sendDestinationNumberVerificationCode($params);

                        if ($update_db) {
                            $dbId = (int) $id;
                            if ($dbId <= 0) {
                                return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
                            }

                            $update = update_allowed_number_usage($pdo, $dbId);
                            if ($update['status'] !== 'success') {
                                return ['status' => 'error', 'message' => $update['message'], 'region' => $region];
                            }
                        }

                        return [
                            'status' => 'success',
                            'message' => "Verification code sent to {$phone} successfully.",
                            'region' => $region,
                            'message_id' => ''
                        ];
                    } catch (AwsException $e2) {
                        $msg2 = aws_error_message($e2);
                        if ($errorCode === 'ServiceQuotaExceededException' || stripos($msg2, 'quota') !== false) {
                            return ['status' => 'skip', 'message' => 'Monthly spend limit or quota reached. Skipping this number.', 'region' => $region];
                        }
                        return ['status' => 'error', 'message' => 'AWS error: ' . $msg2, 'region' => $region];
                    } catch (Throwable $e2) {
                        return ['status' => 'error', 'message' => 'Unexpected error: ' . $e2->getMessage(), 'region' => $region];
                    }
                }

                return ['status' => 'error', 'message' => 'CreateVerifiedDestinationNumber returned a conflict but no VerifiedDestinationNumberId was available.', 'region' => $region];
            }

            return ['status' => 'error', 'message' => 'AWS error: ' . $errorMsg, 'region' => $region];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Unexpected error: ' . $e->getMessage(), 'region' => $region];
        }
    }

    if (!$update_db) {
        $verifiedDestinationNumberId = trim((string) $id);
        if ($verifiedDestinationNumberId === '') {
            return ['status' => 'error', 'message' => 'Verified destination number ID is required.', 'region' => $region];
        }

        try {
            $params = [
                'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
                'VerificationChannel' => 'TEXT',
            ];

            if ($languageCode !== null) {
                $params['LanguageCode'] = $languageCode;
            }

            $sns->sendDestinationNumberVerificationCode($params);

            return [
                'status' => 'success',
                'message' => "Verification code sent to {$phone} successfully.",
                'region' => $region,
                'message_id' => ''
            ];
        } catch (AwsException $e) {
            $errorMsg = aws_error_message($e);
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

    $id = (int) $id;
    if ($id <= 0) {
        return ['status' => 'error', 'message' => 'Invalid phone number or ID.', 'region' => $region];
    }

    $current = $pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id = ?");
    $current->execute([$id]);
    $numberData = $current->fetch(PDO::FETCH_ASSOC);

    if (!$numberData) {
        return ['status' => 'error', 'message' => 'Number not found in database.', 'region' => $region];
    }

    $atmLeft = (int) $numberData['atm_left'];
    if ($atmLeft <= 0) {
        return ['status' => 'error', 'message' => 'No remaining OTP attempts for this number.', 'region' => $region];
    }

    $verifiedDestinationNumberId = null;

    try {
        $existing = get_verified_destination_by_phone($sns, $phone);

        if (!empty($existing['VerifiedDestinationNumberId'])) {
            $verifiedDestinationNumberId = (string) $existing['VerifiedDestinationNumberId'];
        } else {
            $created = create_verified_destination_number($sns, $phone);
            $verifiedDestinationNumberId = extract_verified_destination_id_from_response($created);

            if (empty($verifiedDestinationNumberId)) {
                $refetched = get_verified_destination_by_phone($sns, $phone);
                $verifiedDestinationNumberId = $refetched['VerifiedDestinationNumberId'] ?? null;
            }
        }

        if (empty($verifiedDestinationNumberId)) {
            return ['status' => 'error', 'message' => 'Failed to get VerifiedDestinationNumberId.', 'region' => $region];
        }

        $params = [
            'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
            'VerificationChannel' => 'TEXT',
        ];

        if ($languageCode !== null) {
            $params['LanguageCode'] = $languageCode;
        }

        $sns->sendDestinationNumberVerificationCode($params);

        $update = update_allowed_number_usage($pdo, $id);
        if ($update['status'] !== 'success') {
            return ['status' => 'error', 'message' => $update['message'], 'region' => $region];
        }

        return [
            'status' => 'success',
            'message' => "OTP sent to {$phone} successfully (verification code dispatched).",
            'region' => $region,
            'message_id' => ''
        ];
    } catch (AwsException $e) {
        $errorMsg = aws_error_message($e);
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
    $awsKey    = isset($_POST['awsKey']) && $_POST['awsKey'] !== '' ? $_POST['awsKey'] : 'DEFAULT_AWS_KEY';
    $awsSecret = isset($_POST['awsSecret']) && $_POST['awsSecret'] !== '' ? $_POST['awsSecret'] : 'DEFAULT_AWS_SECRET';
    $awsRegion = !empty($_POST['region']) ? trim($_POST['region']) : 'ap-south-1';
    $action = $_POST['action'] ?? '';
    $language = isset($_POST['language']) ? trim($_POST['language']) : null;
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

    if ($action === 'fetch_pending_sns_numbers') {
        $region = isset($_POST['region']) ? trim($_POST['region']) : '';
        $result = fetch_pending_sns_numbers($region, $awsKey, $awsSecret, $pdo);

        if (isset($result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $result['error']]);
        } else {
            echo json_encode(array_merge(['status' => 'success'], $result));
        }
        exit;
    }

    if ($action === 'send_otp_single') {
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $region = isset($_POST['region']) ? trim($_POST['region']) : $awsRegion;
        $update_db = isset($_POST['update_db']) ? filter_var($_POST['update_db'], FILTER_VALIDATE_BOOLEAN) : true;
        $pending_flow = isset($_POST['pending_flow']) ? filter_var($_POST['pending_flow'], FILTER_VALIDATE_BOOLEAN) : false;

        $result = send_otp_single($id, $phone, $region, $awsKey, $awsSecret, $user_id, $pdo, $sns, $language, $update_db, $pending_flow);
        echo json_encode($result);
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
            $res = $sns->verifyDestinationNumber([
                'VerifiedDestinationNumberId' => $verifiedDestinationNumberId,
                'VerificationCode' => $verificationCode,
            ]);

            echo json_encode(['status' => 'success', 'data' => result_to_array($res)]);
        } catch (AwsException $e) {
            echo json_encode(['status' => 'error', 'message' => aws_error_message($e)]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action.', 'region' => $awsRegion]);
    exit;
}
?>
