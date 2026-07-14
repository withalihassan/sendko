<?php
// v2_main_ac.php

require_once __DIR__ . '/aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

include('db.php');

if (!isset($_GET['ac_id'], $_GET['user_id'])) {
    echo "Account ID and User ID required.";
    exit;
}

$id = (int) $_GET['ac_id'];
$user_id = (int) $_GET['user_id'];

function get_v2_languages(): array
{
    return [
        'ES_419' => 'Spanish Latin America',
        'EN_US'  => 'English (US)',
        'EN_GB'  => 'English (UK)',
        'ES_ES'  => 'Spanish (Spain)',
        'FR_CA'  => 'French (Canada)',
        'FR_FR'  => 'French (France)',
        'IT_IT'  => 'Italian',
        'JA_JP'  => 'Japanese',
        'KO_KR'  => 'Korean',
        'PT_BR'  => 'Portuguese (Brazil)',
        'ZH_CN'  => 'Chinese Simplified',
        'ZH_TW'  => 'Chinese Traditional',
        'DE_DE'  => 'German',
    ];
}

function get_v2_regions(): array
{
    return [
        "us-east-1",
        "us-east-2",
        "us-west-1",
        "us-west-2",
        "af-south-1",
        "ap-south-1",
        "ap-south-2",
        "ap-east-1",
        "ap-east-2",
        "ap-northeast-1",
        "ap-northeast-2",
        "ap-northeast-3",
        "ap-southeast-1",
        "ap-southeast-2",
        "ap-southeast-3",
        "ap-southeast-4",
        "ap-southeast-6",
        "ca-central-1",
        "ca-west-1",
        "eu-central-1",
        "eu-central-2",
        "eu-west-1",
        "eu-west-2",
        "eu-west-3",
        "eu-north-1",
        "eu-south-1",
        "eu-south-2",
        "me-central-1",
        "il-central-1",
        "mx-central-1",
        "sa-east-1",
    ];
}

function normalize_patch_limit($value): ?int
{
    if (!isset($value)) {
        return null;
    }

    $value = trim((string) $value);

    if ($value === '' || strtolower($value) === 'undefined') {
        return null;
    }

    $limit = (int) $value;
    return $limit > 0 ? $limit : null;
}

function build_otp_tasks(array $allowedNumbers, ?int $patchLimit = null): array
{
    $tasks = [];
    $count = count($allowedNumbers);

    if ($count === 0) {
        return $tasks;
    }

    if ($patchLimit !== null) {
        for ($i = 0; $i < $patchLimit; $i++) {
            $row = $allowedNumbers[$i % $count];
            $tasks[] = [
                'id' => $row['id'],
                'phone' => $row['phone_number'],
            ];
        }
        return $tasks;
    }

    if ($count >= 8) {
        for ($i = 0; $i < 8; $i++) {
            $tasks[] = [
                'id' => $allowedNumbers[$i]['id'],
                'phone' => $allowedNumbers[$i]['phone_number'],
            ];
        }

        if (isset($allowedNumbers[5])) {
            $tasks[] = [
                'id' => $allowedNumbers[5]['id'],
                'phone' => $allowedNumbers[5]['phone_number'],
            ];
            $tasks[] = [
                'id' => $allowedNumbers[5]['id'],
                'phone' => $allowedNumbers[5]['phone_number'],
            ];
        }
    } else {
        foreach ($allowedNumbers as $number) {
            $tasks[] = [
                'id' => $number['id'],
                'phone' => $number['phone_number'],
            ];
        }
    }

    return $tasks;
}

if (isset($_POST['action']) && $_POST['action'] === 'stop_process') {
    $stopFile = "stop_" . $id . ".txt";
    file_put_contents($stopFile, "stop");
    echo json_encode(['success' => true, 'message' => 'Process stopped successfully.']);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'update_account') {
    if ($id > 0) {
        date_default_timezone_set('Asia/Karachi');
        $currentTimestamp = date('Y-m-d H:i:s');

        try {
            $stmt = $pdo->prepare("UPDATE accounts SET ac_score = ac_score + 1, last_used = :last_used WHERE id = :id");
            $stmt->execute([
                ':id' => $id,
                ':last_used' => $currentTimestamp
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Account updated successfully.',
                'time' => $currentTimestamp
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database update failed: ' . $e->getMessage()
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid account ID.']);
    }
    exit;
}

$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "Account not found.";
    exit;
}

$aws_key = $account['aws_key'];
$aws_secret = $account['aws_secret'];

$checkEnableRegions = [
    "me-central-1",
    "af-south-1",
    "ap-east-1",
    "ap-south-2",
    "ap-southeast-3",
    "ap-southeast-4",
    "ap-southeast-6",
    "ap-east-2",
    "ca-west-1",
    "eu-south-1",
    "eu-south-2",
    "eu-central-2",
    "il-central-1",
    "mx-central-1"
];

if (isset($_GET['stream'])) {
    $mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'database';

    if ($mode !== 'sns_pending') {
        if (!isset($_GET['set_id']) || (int) $_GET['set_id'] <= 0) {
            echo "No set selected.";
            exit;
        }
        $set_id = (int) $_GET['set_id'];
    } else {
        $set_id = null;
    }

    $language = (isset($_GET['language']) && trim($_GET['language']) !== '') ? trim($_GET['language']) : null;
    $selectedRegion = isset($_GET['region']) ? trim($_GET['region']) : '';
    $patchLimit = normalize_patch_limit($_GET['patch_limit'] ?? null);

    $stopFile = "stop_" . $id . ".txt";
    if (file_exists($stopFile)) {
        unlink($stopFile);
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    while (ob_get_level()) {
        ob_end_flush();
    }
    set_time_limit(0);
    ignore_user_abort(true);

    function sendSSE($type, $message)
    {
        echo "data:" . $type . "|" . str_replace("\n", "\\n", $message) . "\n\n";
        flush();
    }

    if ($mode === 'sns_pending') {
        sendSSE("STATUS", "Starting SNS Pending Number Verification Process");
    } else {
        sendSSE("STATUS", "Starting Bulk Regional Patch Process for Set ID: $set_id");
    }

    $regions = $selectedRegion !== '' ? [$selectedRegion] : get_v2_regions();

    $totalRegions = count($regions);
    $totalSuccess = 0;
    $usedRegions = 0;

    $internal_call = true;
    require_once('v2_main_ajax_handler.php');

    foreach ($regions as $region) {
        if (file_exists($stopFile)) {
            sendSSE("STATUS", "Process stopped by user.");
            unlink($stopFile);
            exit;
        }

        if (in_array($region, $checkEnableRegions, true)) {
            $enabled = false;
            $retryCount = 0;

            while (!$enabled) {
                try {
                    $regionEc2Client = new Ec2Client([
                        'version' => 'latest',
                        'region' => $region,
                        'credentials' => [
                            'key'    => $aws_key,
                            'secret' => $aws_secret,
                        ],
                    ]);

                    $regionEc2Client->describeInstanceTypeOfferings([
                        'LocationType' => 'region'
                    ]);

                    $enabled = true;
                    sendSSE("STATUS", "✅ Region $region enabled verification passed");
                } catch (AwsException $e) {
                    $errorCode = $e->getAwsErrorCode();
                    if ($errorCode === 'OptInRequired' || $errorCode === 'AuthFailure') {
                        sendSSE("STATUS", "⏳ Region $region requires enablement. Waiting 30 seconds... (Retry #$retryCount)");
                        $retryCount++;
                        sleep(30);
                    } else {
                        sendSSE("STATUS", "⚠️ Error checking region $region: " . $e->getAwsErrorMessage());
                        sleep(30);
                    }
                }

                if (file_exists($stopFile)) {
                    sendSSE("STATUS", "Process stopped by user.");
                    unlink($stopFile);
                    exit;
                }
            }
        }

        $usedRegions++;
        sendSSE("STATUS", "🚀 Moving to region: $region");
        sendSSE("COUNTERS", "Total Patch sent: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));

        if ($mode === 'sns_pending') {
            $numbersResult = fetch_pending_sns_numbers($region, $aws_key, $aws_secret, $pdo);
        } else {
            $numbersResult = fetch_numbers($region, $user_id, $pdo, $set_id);
        }

        if (isset($numbersResult['error'])) {
            sendSSE("STATUS", "❌ Error fetching numbers for region $region: " . $numbersResult['error']);
            sleep(5);
            continue;
        }

        $allowedNumbers = $numbersResult['data'];
        if (empty($allowedNumbers)) {
            if ($mode === 'sns_pending') {
                sendSSE("STATUS", "ℹ️ No pending SNS numbers found in region: $region");
            } else {
                sendSSE("STATUS", "ℹ️ No allowed numbers found in region: $region");
            }
            sleep(5);
            continue;
        }

        if ($mode === 'sns_pending') {
            $otpTasks = [];
            foreach ($allowedNumbers as $row) {
                $rowId = isset($row['id']) ? (int) $row['id'] : 0;
                $phone = $row['phone_number'] ?? '';
                $atmLeft = isset($row['atm_left']) ? (int) $row['atm_left'] : 0;

                if ($rowId <= 0) {
                    sendSSE("ROW", "|" . $phone . "|" . $region . "|⏭️ Patch Skipped: Number not found in database.");
                    continue;
                }

                if ($atmLeft <= 0) {
                    sendSSE("ROW", $rowId . "|" . $phone . "|" . $region . "|⏭️ Patch Skipped: No remaining OTP attempts for this number.");
                    continue;
                }

                $otpTasks[] = [
                    'id' => $rowId,
                    'phone' => $phone,
                ];
            }
        } else {
            $otpTasks = build_otp_tasks($allowedNumbers, $patchLimit);
        }

        $otpSentInThisRegion = false;
        $verifDestError = false;

        foreach ($otpTasks as $task) {
            if (file_exists($stopFile)) {
                sendSSE("STATUS", "🛑 Process stopped by user.");
                unlink($stopFile);
                exit;
            }

            sendSSE("STATUS", "[$region] Sending Patch...");
            $sns = initPinpointSMSV2($aws_key, $aws_secret, $region);
            if (is_array($sns) && isset($sns['error'])) {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Failed: " . $sns['error']);
                continue;
            }

            if ($mode === 'sns_pending') {
                $result = send_otp_single($task['id'], $task['phone'], $region, $aws_key, $aws_secret, $user_id, $pdo, $sns, $language, true, true);
            } else {
                $result = send_otp_single($task['id'], $task['phone'], $region, $aws_key, $aws_secret, $user_id, $pdo, $sns, $language, true, false);
            }

            if ($result['status'] === 'success') {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|✅ Patch Sent");
                $totalSuccess++;
                $otpSentInThisRegion = true;
                sendSSE("COUNTERS", "Total Patch sent: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));
                sleep(2);
            } elseif ($result['status'] === 'skip') {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|⏭️ Patch Skipped: " . $result['message']);

                if (
                    strpos($result['message'], 'Monthly spend limit') !== false ||
                    strpos($result['message'], 'quota reached') !== false ||
                    strpos($result['message'], 'Monthly spend limit or quota reached') !== false
                ) {
                    sendSSE("STATUS", "[$region] Spend limit reached. Moving to next region...");
                    break;
                }
            } elseif ($result['status'] === 'error') {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|❌ Patch Failed: " . $result['message']);

                if (strpos($result['message'], "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
                    $verifDestError = true;
                    sendSSE("STATUS", "[$region] 🚨 VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error");
                    break;
                } elseif (
                    strpos($result['message'], "Access Denied") !== false ||
                    strpos($result['message'], "Region Restricted") !== false
                ) {
                    sendSSE("STATUS", "[$region] 🔒 Critical error: " . $result['message']);
                    break;
                } else {
                    sleep(5);
                }
            }
        }

        if ($verifDestError) {
            sendSSE("STATUS", "⏳ Region $region encountered error. Waiting 5 seconds...");
            sleep(5);
        } elseif ($otpSentInThisRegion) {
            sendSSE("STATUS", "✅ Completed Patch sending for $region. Waiting 5 seconds...");
            sleep(5);
        } else {
            sendSSE("STATUS", "✅ Completed Patch sending for $region. Waiting 2 seconds...");
            sleep(2);
        }
    }

    $summary = "🎉 Final Summary:<br>Total Patch sent: $totalSuccess<br>Regions processed: $usedRegions<br>Remaining regions: " . ($totalRegions - $usedRegions);
    sendSSE("SUMMARY", $summary);
    sendSSE("STATUS", "🏁 Process Completed.");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $id; ?> | Bulk Regional Patch Sending</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4WkG879m7" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f7f7f7;
        }

        .page-layout {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: nowrap;
        }

        .left-panel {
            flex: 0 0 33.333%;
            max-width: 33.333%;
        }

        .right-panel {
            flex: 0 0 66.667%;
            max-width: 66.667%;
        }

        .panel-card {
            width: 100%;
            background: #fff;
            padding: 3px 10px 3px 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 5px;
        }

        h1, h2 {
            text-align: center;
            color: #333;
        }

        label {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }

        input,
        textarea,
        select,
        button {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }

        button {
            background: #007bff;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }

        button:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        .message {
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            display: none;
        }

        .success {
            background: #d4edda;
            color: #155724;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        table, th, td {
            border: 1px solid #ccc;
        }

        th, td {
            padding: 8px;
            text-align: center;
        }

        th {
            background: #f4f4f4;
        }

        #counters {
            background: #eee;
            color: #333;
            padding: 5px 10px;
            margin: 10px 0;
            font-weight: bold;
            text-align: center;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 3px;
            display: inline-block;
        }

        .inline-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .inline-row > div {
            flex: 1;
            min-width: 200px;
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .button-row button {
            flex: 1;
            min-width: 150px;
        }

        @media (max-width: 992px) {
            .page-layout {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="page-layout">
        <div class="left-panel">
            <div class="panel-card">
                <h1>V2 Region Enable Boxs</h1>
                <button id="enableRegionsButton" class="btn btn-primary mb-3">
                    Enable All Opt-In Regions
                </button>
                <table id="regions-status-table" class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Region</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="right-panel">
            <div class="panel-card">
                <h1>v2 Bulk Regional Patch Sending</h1>

                <div class="button-row">
                    <button id="updateButton">Mark as Completed</button>
                    <button id="stopButton" style="background:#dc3545;">Stop Process</button>
                </div>

                <?php
                $stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets ORDER BY set_name ASC");
                $sets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <form id="bulk-regional-otp-form">
                    <div class="inline-row">
                        <div>
                            <label for="set_id">Select Set:</label>
                            <select id="set_id" name="set_id" required>
                                <option value="">-- Select a Set --</option>
                                <?php foreach ($sets as $set): ?>
                                    <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="region_select">Select Region:</label>
                            <select id="region_select" name="region_select">
                                <option value="">All Regions</option>
                                <?php foreach (get_v2_regions() as $reg): ?>
                                    <option value="<?php echo $reg; ?>"><?php echo $reg; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="lang_select">Select Language:</label>
                            <select id="lang_select" name="lang_select">
                                <option value="">No language selected</option>
                                <option value="ES_419" selected>Spanish Latin America</option>
                                <option value="EN_US">English (US)</option>
                                <option value="EN_GB">English (UK)</option>
                                <option value="ES_ES">Spanish (Spain)</option>
                                <option value="FR_CA">French (Canada)</option>
                                <option value="FR_FR">French (France)</option>
                                <option value="IT_IT">Italian</option>
                                <option value="JA_JP">Japanese</option>
                                <option value="KO_KR">Korean</option>
                                <option value="PT_BR">Portuguese (Brazil)</option>
                                <option value="ZH_CN">Chinese Simplified</option>
                                <option value="ZH_TW">Chinese Traditional</option>
                                <option value="DE_DE">German</option>
                            </select>
                        </div>
                    </div>

                    <label for="awsCreds">AWS Credentials (Key | Secret):</label>
                    <input type="text" id="awsCreds" name="awsCreds" value="<?php echo htmlspecialchars($aws_key . ' | ' . $aws_secret, ENT_QUOTES); ?>" disabled>

                    <button type="button" id="start-bulk-regional-otp">Start Bulk Patch Process for Selected Set</button>
                    <button type="button" id="verify-pending-sns-numbers">Verify Pending SNS Numbers</button>
                </form>

                <label for="numbers">Allowed Phone Numbers (from database):</label>
                <textarea id="numbers" name="numbers" rows="5" readonly></textarea>

                <div id="process-status" class="message"></div>

                <h2>Live Counters</h2>
                <div id="counters"></div>

                <h2>OTP Events</h2>
                <table id="sent-numbers-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Phone Number</th>
                            <th>Region</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>

                <h2>Final Summary</h2>
                <div id="summary"></div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var userId = <?php echo $user_id; ?>;
    var acId = <?php echo $id; ?>;
    var evtSource = null;

    function resetOutput() {
        $('#process-status').removeClass('error success').text('');
        $('#numbers').val('');
        $('#sent-numbers-table tbody').html('');
        $('#summary').html('');
        $('#counters').html('');
    }

    function startStream(sseUrl) {
        if (evtSource) {
            evtSource.close();
        }

        evtSource = new EventSource(sseUrl);

        evtSource.onmessage = function(e) {
            var data = e.data;
            var parts = data.split("|");
            var type = parts[0];

            if (type === "ROW") {
                var id = parts[1];
                var phone = parts[2];
                var region = parts[3];
                var status = parts.slice(4).join("|");
                var row = '<tr><td>' + id + '</td><td>' + phone + '</td><td>' + region + '</td><td>' + status + '</td></tr>';
                $('#sent-numbers-table tbody').append(row);
            } else {
                var content = parts.slice(1).join("|").replace(/\\n/g, "<br>");
                if (type === "STATUS") {
                    $('#process-status').text(content).show();
                } else if (type === "COUNTERS") {
                    $('#counters').html(content);
                } else if (type === "SUMMARY") {
                    $('#summary').html(content);
                }
            }
        };

        evtSource.onerror = function() {
            $('#process-status').text("An error occurred with the SSE connection.").addClass('error').show();
            if (evtSource) {
                evtSource.close();
            }
        };
    }

    $('#set_id, #region_select').change(function() {
        var set_id = $('#set_id').val();
        var region = $('#region_select').val() || "dummy";

        if (!set_id) {
            $('#numbers').val('');
            return;
        }

        $.ajax({
            url: 'v2_main_ajax_handler.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'fetch_numbers',
                region: region,
                set_id: set_id,
                user_id: userId
            },
            success: function(response) {
                if (response.status === 'success') {
                    var displayText = "";
                    response.data.forEach(function(item) {
                        displayText += "ID: " + item.id + " | Phone: " + item.phone_number + " | ATM Left: " + item.atm_left + " | Date: " + item.formatted_date + "\n";
                    });
                    $('#numbers').val(displayText);
                } else {
                    $('#numbers').val('Error: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                $('#numbers').val('AJAX error: ' + error);
            }
        });
    });

    $('#start-bulk-regional-otp').click(function() {
        var set_id = $('#set_id').val();
        if (!set_id) {
            alert("Please select a set.");
            return;
        }

        $(this).prop('disabled', true);
        $('#verify-pending-sns-numbers').prop('disabled', true);
        resetOutput();

        var region = $('#region_select').val();
        var language = $('#lang_select').val() || '';
        var sseUrl = "v2_main_ac.php?ac_id=" + encodeURIComponent(acId) +
            "&user_id=" + encodeURIComponent(userId) +
            "&set_id=" + encodeURIComponent(set_id) +
            "&stream=1" +
            "&language=" + encodeURIComponent(language);

        if (region) {
            sseUrl += "&region=" + encodeURIComponent(region);
        }

        startStream(sseUrl);
    });

    $('#verify-pending-sns-numbers').click(function() {
        $(this).prop('disabled', true);
        $('#start-bulk-regional-otp').prop('disabled', true);
        resetOutput();
        $('#numbers').val('Loading pending SNS numbers...');

        var region = $('#region_select').val() || '';
        var language = $('#lang_select').val() || '';

        if (region) {
            $.ajax({
                url: 'v2_main_ajax_handler.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'fetch_pending_sns_numbers',
                    region: region,
                    user_id: userId
                },
                success: function(response) {
                    if (response.status === 'success' && response.data) {
                        var displayText = "";
                        response.data.forEach(function(item) {
                            displayText += "ID: " + (item.id || '—') + " | Phone: " + item.phone_number + " | Status: " + item.status + (item.formatted_date ? " | Date: " + item.formatted_date : "") + "\n";
                        });
                        $('#numbers').val(displayText || 'No pending SNS numbers found.');
                    } else {
                        $('#numbers').val('Error: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#numbers').val('AJAX error: ' + error);
                }
            });
        } else {
            $('#numbers').val('Pending SNS numbers will be processed region by region.');
        }

        var sseUrl = "v2_main_ac.php?ac_id=" + encodeURIComponent(acId) +
            "&user_id=" + encodeURIComponent(userId) +
            "&stream=1" +
            "&mode=sns_pending" +
            "&language=" + encodeURIComponent(language);

        if (region) {
            sseUrl += "&region=" + encodeURIComponent(region);
        }

        startStream(sseUrl);
    });
});
</script>

<script>
$(document).ready(function() {
    $("#updateButton").click(function() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'update_account'
            },
            success: function(response) {
                if (response.success) {
                    $("#result").html("<p style='color: green;'>" + response.message + "</p>");
                } else {
                    $("#result").html("<p style='color: red;'>" + response.message + "</p>");
                }
            },
            error: function() {
                $("#result").html("<p style='color: red;'>An error occurred while updating the account.</p>");
            }
        });
    });
});
</script>

<script>
$(function() {
    const acId = <?php echo $id; ?>;
    const userId = <?php echo $user_id; ?>;
    const regions = [
        "me-central-1", "af-south-1", "ap-east-1", "ap-south-2", "ap-southeast-3",
        "ap-southeast-4", "ap-southeast-6",
        "ap-east-2", "ca-west-1", "eu-south-1", "eu-south-2",
        "eu-central-2", "il-central-1", "mx-central-1"
    ];
    const maxConcurrent = 6;
    const delayMs = 2000;
    const pollIntervals = {};
    let queue = [];
    let activeCount = 0;

    $('#enableRegionsButton').on('click', () => {
        const $tbody = $('#regions-status-table tbody').empty();
        queue = regions.slice();
        activeCount = 0;
        scheduleNext($tbody);
    });

    function scheduleNext($tbody) {
        if (activeCount < maxConcurrent && queue.length > 0) {
            const region = queue.shift();
            checkAndSubmit(region, $tbody);
        }

        if (queue.length > 0 || activeCount > 0) {
            setTimeout(() => scheduleNext($tbody), delayMs);
        }
    }

    function checkAndSubmit(region, $tbody) {
        let $row = $tbody.find(`tr[data-region="${region}"]`);
        if (!$row.length) {
            $tbody.append(`
                <tr data-region="${region}">
                    <td>${region}</td>
                    <td class="status">Checking…</td>
                </tr>
            `);
            $row = $tbody.find(`tr[data-region="${region}"]`);
        }

        const $status = $row.find('.status');

        $.post(
            `region_enable_handler.php?ac_id=${encodeURIComponent(acId)}&user_id=${encodeURIComponent(userId)}`, {
                action: 'check_region_status',
                region
            },
            'json'
        ).done(data => {
            if (data.success && data.status === 'ENABLED') {
                $status.text('Already Enabled');
            } else {
                $status.text('Submitted, Waiting…');
                $.post(
                    `region_enable_handler.php?ac_id=${encodeURIComponent(acId)}&user_id=${encodeURIComponent(userId)}`, {
                        action: 'enable_region',
                        region
                    },
                    'json'
                ).done(() => {
                    activeCount++;
                    startPolling(region, $status);
                }).fail(() => {
                    $status.text('Enable Error');
                });
            }
        }).fail(() => {
            $status.text('Check Error');
        });
    }

    function startPolling(region, $status) {
        if (pollIntervals[region]) {
            clearInterval(pollIntervals[region]);
        }

        pollIntervals[region] = setInterval(() => {
            $.post(
                `region_enable_handler.php?ac_id=${encodeURIComponent(acId)}&user_id=${encodeURIComponent(userId)}`, {
                    action: 'check_region_status',
                    region
                },
                'json'
            ).done(data => {
                if (data.success && data.status === 'ENABLED') {
                    clearInterval(pollIntervals[region]);
                    $status.text('Enabled Successfully');
                    activeCount--;
                } else {
                    $status.text(`Still Enabling…(${data.status})`);
                }
            }).fail(() => {
                $status.text('Poll Error');
            });
        }, 40000);
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js" integrity="sha384-k6d4wzSIapyDyv1kpU366/PK5hCdSbCRGRCMv+eplOQJWyd1fbcAu9OCUj5zNLiq" crossorigin="anonymous"></script>
</body>
</html>
