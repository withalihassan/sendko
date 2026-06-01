<?php
// brs.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('../db.php'); // This file must initialize your $pdo connection

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

if (!function_exists('get_brs_regions')) {
    function get_brs_regions()
    {
        return [
            "ap-south-2",
            "ap-east-2",
            "ap-southeast-3",
            "ap-southeast-4",
            "ap-southeast-6",
            "eu-south-2",
            "eu-central-2",
            "me-central-1"
        ];
    }
}

if (!function_exists('normalizePatchLimit')) {
    function normalizePatchLimit($value)
    {
        if (!isset($value)) {
            return null;
        }

        $value = trim((string)$value);

        if ($value === '' || strtolower($value) === 'undefined') {
            return null;
        }

        $limit = intval($value);
        if ($limit < 1) {
            return null;
        }

        return $limit;
    }
}

if (!function_exists('buildOtpTasks')) {
    function buildOtpTasks(array $allowedNumbers, $patchLimit = null)
    {
        $otpTasks = [];

        if (empty($allowedNumbers)) {
            return $otpTasks;
        }

        // Patch limit mode: send exactly the selected number of patches per region.
        if ($patchLimit !== null) {
            $totalAllowed = count($allowedNumbers);
            for ($i = 0; $i < $patchLimit; $i++) {
                $row = $allowedNumbers[$i % $totalAllowed];
                $otpTasks[] = [
                    'id' => $row['id'],
                    'phone' => $row['phone_number']
                ];
            }
            return $otpTasks;
        }

        // Default / undefined mode: keep the original behavior.
        if (count($allowedNumbers) >= 6) {
            $limit = min(8, count($allowedNumbers));
            for ($i = 0; $i < $limit; $i++) {
                $otpTasks[] = [
                    'id' => $allowedNumbers[$i]['id'],
                    'phone' => $allowedNumbers[$i]['phone_number']
                ];
            }
            $otpTasks[] = [
                'id' => $allowedNumbers[5]['id'],
                'phone' => $allowedNumbers[5]['phone_number']
            ];
            $otpTasks[] = [
                'id' => $allowedNumbers[5]['id'],
                'phone' => $allowedNumbers[5]['phone_number']
            ];
        } else {
            foreach ($allowedNumbers as $number) {
                $otpTasks[] = [
                    'id' => $number['id'],
                    'phone' => $number['phone_number']
                ];
            }
        }

        return $otpTasks;
    }
}

// Ensure account ID is provided via GET
if (!isset($_GET['ac_id'])) {
    echo "Account ID required.";
    exit;
}

$id = htmlspecialchars($_GET['ac_id']);
$parent_id = isset($_GET['parrent_id']) ? (int) $_GET['parrent_id'] : 0;

// Fetch AWS credentials for the provided account ID from child_accounts table
$stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "Account not found.";
    exit;
}

$accountId = $id;
$snsSupportedLanguages = get_sns_supported_languages();
$brsRegions = get_brs_regions();

// Set the timezone to Asia/Karachi and define current timestamp
date_default_timezone_set('Asia/Karachi');
$currentTimestamp = date('Y-m-d H:i:s');

// Retrieve AWS keys from child_accounts
$aws_key    = $account['aws_access_key'];
$aws_secret = $account['aws_secret_key'];

// STREAMING MODE: If stream=1 is present, run the SSE loop.
if (isset($_GET['stream'])) {
    if (!isset($_GET['set_id']) || intval($_GET['set_id']) <= 0) {
        echo "No set selected.";
        exit;
    }

    $set_id = intval($_GET['set_id']);

    // Empty string means no selection => do not send LanguageCode to AWS.
    $language = (isset($_GET['language']) && $_GET['language'] !== '') ? trim($_GET['language']) : null;
    if ($language !== null && !array_key_exists($language, $snsSupportedLanguages)) {
        $language = null;
    }

    // Patch limit: undefined / empty => old behavior, 1..10 => exact count per region
    $patchLimit = normalizePatchLimit($_GET['patch_limit'] ?? null);

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

    sendSSE("STATUS", "Starting Bulk Regional Patching Process for Set ID: " . $set_id);

    $regionParam = isset($_GET['region']) ? trim($_GET['region']) : '';
    if ($regionParam !== '' && $regionParam !== 'all') {
        $regions = [$regionParam];
    } else {
        $regions = get_brs_regions();
    }

    $totalRegions = count($regions);
    $totalSuccess = 0;
    $usedRegions = 0;

    $internal_call = true;
    require_once('region_ajax_handler_brs.php');

    foreach ($regions as $region) {
        $stopFile = "stop_" . $accountId . ".txt";
        if (file_exists($stopFile)) {
            sendSSE("STATUS", "Process stopped by user.");
            unlink($stopFile);
            exit;
        }

        $usedRegions++;
        sendSSE("STATUS", "Moving to region: " . $region);
        sendSSE("COUNTERS", "Total Patch Done: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));

        $numbersResult = fetch_numbers($region, $pdo, $set_id);
        if (isset($numbersResult['error'])) {
            sendSSE("STATUS", "Error fetching numbers for region " . $region . ": " . $numbersResult['error']);
            sleep(5);
            continue;
        }

        $allowedNumbers = $numbersResult['data'];
        if (empty($allowedNumbers)) {
            sendSSE("STATUS", "No allowed numbers found in region: " . $region);
            sleep(5);
            continue;
        }

        $otpTasks = buildOtpTasks($allowedNumbers, $patchLimit);

        $otpSentInThisRegion = false;
        $verifDestError = false;

        foreach ($otpTasks as $task) {
            if (file_exists($stopFile)) {
                sendSSE("STATUS", "Process stopped by user.");
                unlink($stopFile);
                exit;
            }

            sendSSE("STATUS", "[$region] Sending Patching...");
            $sns = initSNS($aws_key, $aws_secret, $region);
            if (is_array($sns) && isset($sns['error'])) {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Failed: " . $sns['error']);
                continue;
            }

            $result = send_otp_single($task['id'], $task['phone'], $region, $aws_key, $aws_secret, $pdo, $sns, $language);

            if ($result['status'] === 'success') {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Sent");
                $totalSuccess++;
                $otpSentInThisRegion = true;
                sendSSE("COUNTERS", "Total Patch Done: $totalSuccess; In region: $region; Regions processed: $usedRegions; Remaining: " . ($totalRegions - $usedRegions));
                usleep(2500000);
            } elseif ($result['status'] === 'skip') {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Skipped: " . $result['message']);

                if (strpos($result['message'], 'Monthly spend limit reached') !== false) {
                    sendSSE("STATUS", "[$region] Spend limit hit. Skipping region...");
                    sleep(3);
                    $verifDestError = true;
                    break;
                }
            } elseif ($result['status'] === 'error') {
                sendSSE("ROW", $task['id'] . "|" . $task['phone'] . "|" . $region . "|Patch Failed: " . $result['message']);

                if (strpos($result['message'], "VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT") !== false) {
                    $verifDestError = true;
                    sendSSE("STATUS", "[$region] VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT error encountered. Skipping region.");
                    break;
                } elseif (
                    strpos($result['message'], "Access Denied") !== false ||
                    strpos($result['message'], "Region Restricted") !== false
                ) {
                    sendSSE("STATUS", "[$region] Critical error (" . $result['message'] . "). Skipping region.");
                    $verifDestError = true;
                    break;
                } else {
                    sleep(3);
                }
            }
        }

        if ($verifDestError) {
            sendSSE("STATUS", "Region $region encountered an error. Waiting 5 seconds...");
            sleep(5);
        } elseif ($otpSentInThisRegion) {
            sendSSE("STATUS", "Completed Patch sending for region $region. Waiting 15 seconds...");
            sleep(15);
        } else {
            sendSSE("STATUS", "Completed Patch sending for region $region. Waiting 5 seconds...");
            sleep(5);
        }
    }

    $summary = "Final Summary:<br>Total Patch sent: $totalSuccess<br>Regions processed: $usedRegions<br>Remaining regions: " . ($totalRegions - $usedRegions);
    sendSSE("SUMMARY", $summary);
    sendSSE("STATUS", "Process Completed.");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo $id; ?> | Half Regional Patch Sending</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f7f7f7;
        }

        .container {
            max-width: none;
            margin: auto;
            background: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 5px;
        }

        h1,
        h2 {
            text-align: center;
            color: #333;
        }

        label {
            font-weight: bold;
            margin-top: 10px;
            display: block;
        }

        input,
        textarea,
        select,
        button {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
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

        table,
        th,
        td {
            border: 1px solid #ccc;
        }

        th,
        td {
            padding: 5px;
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
            width: auto;
        }

        .page-grid {
            display: flex;
            flex-wrap: nowrap;
            gap: 20px;
            align-items: flex-start;
        }

        .left-panel {
            flex: 0 0 33.333%;
            max-width: 33.333%;
        }

        .right-panel {
            flex: 0 0 66.667%;
            max-width: 66.667%;
        }

        .form-grid {
            display: flex;
            gap: 15px;
            flex-wrap: nowrap;
            align-items: flex-start;
        }

        .form-grid .column {
            flex: 1 1 0;
            min-width: 170px;
        }

        @media (max-width: 992px) {
            .page-grid {
                flex-wrap: wrap;
            }

            .left-panel,
            .right-panel {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .form-grid {
                flex-wrap: wrap;
            }

            .form-grid .column {
                min-width: 220px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="page-grid">
            <div class="left-panel">
                <div class="container">
                    <h1> Half Enable Box</h1>
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
                <div class="container">
                    <h1> Half Bulk Regional Patch Sending</h1>
                    <?php
                    $stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets WHERE status = 'fresh' ORDER BY set_name ASC");
                    $sets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <form id="bulk-regional-otp-form">
                        <div class="form-grid">
                            <div class="column">
                                <label for="set_id">Select Set:</label>
                                <select id="set_id" name="set_id" required>
                                    <option value="">-- Select a Set --</option>
                                    <?php foreach ($sets as $set): ?>
                                        <option value="<?php echo $set['id']; ?>"><?php echo htmlspecialchars($set['set_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="column">
                                <label for="region_select">Select Region:</label>
                                <select id="region_select" name="region_select">
                                    <option value="all" selected>All Regions</option>
                                    <?php foreach ($brsRegions as $region): ?>
                                        <option value="<?php echo htmlspecialchars($region); ?>"><?php echo htmlspecialchars($region); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="column">
                                <label for="language_select">Select Language:</label>
                                <select id="language_select" name="language_select">
                                    <option value="">AWS Default (en-US)</option>
                                    <?php foreach ($snsSupportedLanguages as $code => $label): ?>
                                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo ($code === 'es-419') ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="column">
                                <label for="patch_limit">Patch limit:</label>
                                <select id="patch_limit" name="patch_limit">
                                    <option value="undefined" selected>Undefined</option>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                    <option value="6">6</option>
                                    <option value="7">7</option>
                                    <option value="8">8</option>
                                    <option value="9">9</option>
                                    <option value="10">10</option>
                                </select>
                            </div>
                        </div>

                        <label for="awsKey">AWS Key:</label>
                        <input type="text" id="awsKey" name="awsKey" value="<?php echo htmlspecialchars($aws_key, ENT_QUOTES); ?>" disabled>

                        <label for="awsSecret">AWS Secret:</label>
                        <input type="text" id="awsSecret" name="awsSecret" value="<?php echo htmlspecialchars($aws_secret, ENT_QUOTES); ?>" disabled>

                        <button type="button" id="start-bulk-regional-otp">Start Bulk Patch Process for Selected Set</button>
                    </form>

                    <label for="numbers">Allowed Phone Numbers (from database):</label>
                    <textarea id="numbers" name="numbers" rows="10" readonly></textarea>

                    <div id="process-status" class="message"></div>

                    <h2>Live Counters</h2>
                    <div id="counters"></div>

                    <h2>Patch Events</h2>
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
            const acId = <?php echo json_encode($id); ?>;
            let evtSource = null;

            $('#set_id, #region_select').change(function() {
                var set_id = $('#set_id').val();
                var region = $('#region_select').val() || 'all';

                if (!set_id) {
                    $('#numbers').val('');
                    return;
                }

                $.ajax({
                    url: 'region_ajax_handler_brs.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'fetch_numbers',
                        region: region,
                        set_id: set_id
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
                $('#process-status').removeClass('error').removeClass('success').text('');
                $('#numbers').val('');
                $('#sent-numbers-table tbody').html('');
                $('#summary').html('');
                $('#counters').html('');

                var region = $('#region_select').val();
                var language = $('#language_select').val();
                var patch_limit = $('#patch_limit').val();

                var sseUrl = "brs.php?ac_id=" + encodeURIComponent(acId) +
                    "&set_id=" + encodeURIComponent(set_id) +
                    "&stream=1" +
                    "&language=" + encodeURIComponent(language || '') +
                    "&patch_limit=" + encodeURIComponent(patch_limit || 'undefined');

                if (region && region !== 'all') {
                    sseUrl += "&region=" + encodeURIComponent(region);
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
            });
        });
    </script>

    <script>
        $(function() {
            const acId = <?php echo json_encode($id); ?>;
            const userId = <?php echo json_encode($parent_id); ?>;
            const regions = <?php echo json_encode($brsRegions); ?>;
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous"></script>
</body>

</html>