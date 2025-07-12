<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../db.php';
require '../aws/aws-autoloader.php';
use Aws\Sns\SnsClient;
use Aws\Exception\AwsException;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'fetch_sandbox') {
    header('Content-Type: application/json');
    $account_id = $_POST['account_id'] ?? '';

    $stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo json_encode(['status' => 'error', 'message' => 'Account not found']);
        exit;
    }

    $sns = new SnsClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => [
            'key' => $account['aws_access_key'],
            'secret' => $account['aws_secret_key']
        ]
    ]);

    try {
        $result = $sns->listSMSSandboxPhoneNumbers();
        $numbers = $result['PhoneNumbers'] ?? [];
        $data = [];
        foreach ($numbers as $entry) {
            $data[] = [
                'phone' => $entry['PhoneNumber'],
                'status' => $entry['Status']
            ];
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (AwsException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getAwsErrorMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'resend_code') {
    header('Content-Type: application/json');
    $account_id = $_POST['account_id'] ?? '';
    $phone = $_POST['phone'] ?? '';

    $stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo json_encode(['status' => 'error', 'message' => 'Account not found']);
        exit;
    }

    $sns = new SnsClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => [
            'key' => $account['aws_access_key'],
            'secret' => $account['aws_secret_key']
        ]
    ]);

    try {
        $sns->createSMSSandboxPhoneNumber([
            'PhoneNumber' => $phone,
            'LanguageCode' => 'en-US'
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Verification code resent to ' . $phone]);
    } catch (AwsException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getAwsErrorMessage()]);
    }
    exit;
}

if (!isset($_GET['parent_id'])) {
    die("Missing parent_id");
}

$parent_id = htmlspecialchars($_GET['parent_id']);
$stmt = $pdo->prepare("SELECT id, name, email, account_id FROM child_accounts WHERE parent_id = ? AND account_id <> ?");
$stmt->execute([$parent_id, $parent_id]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>SNS Sandbox Numbers</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="p-4">
<h3 class="mb-4">SNS Sandbox Phone Numbers (Virginia Region)</h3>
<button class="btn btn-success mb-4" id="fetch_all">Fetch All Childs Numbers</button>
<div class="row">
    <?php foreach ($accounts as $acc): ?>
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">
                <?= htmlspecialchars($acc['name']) ?> (<?= htmlspecialchars($acc['email']) ?>)
            </div>
            <div class="card-body">
                <p><strong>Account ID:</strong> <?= $acc['account_id'] ?></p>
                <button class="btn btn-primary btn-sm fetch" data-id="<?= $acc['account_id'] ?>">Fetch Numbers</button>
                <div id="result_<?= $acc['account_id'] ?>" class="mt-2" style="font-size: 13px;"></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
function renderNumbers(accountId, data) {
    let box = $('#result_' + accountId);
    if (data.length === 0) {
        box.html('No sandbox numbers found.');
    } else {
        let html = '';
        data.forEach(row => {
            html += `Phone: ${row.phone}<br>Status: ${row.status}`;
            if (row.status === 'Pending') {
                html += ` <button class='btn btn-sm btn-warning resend' data-id='${accountId}' data-phone='${row.phone}'>Resend Code</button>`;
            }
            html += '<hr>';
        });
        box.html(html);
    }
}

$('.fetch').click(function() {
    let id = $(this).data('id');
    let box = $('#result_' + id);
    box.html('Loading...');

    $.post('', { action: 'fetch_sandbox', account_id: id }, function(res) {
        if (res.status === 'success') {
            renderNumbers(id, res.data);
        } else {
            box.html('Error: ' + res.message);
        }
    }, 'json');
});

$('#fetch_all').click(function() {
    $('.fetch').each(function() {
        $(this).trigger('click');
    });
});

$(document).on('click', '.resend', function() {
    let id = $(this).data('id');
    let phone = $(this).data('phone');
    let btn = $(this);
    btn.prop('disabled', true).text('Sending...');

    $.post('', { action: 'resend_code', account_id: id, phone: phone }, function(res) {
        if (res.status === 'success') {
            alert(res.message);
        } else {
            alert('Error: ' + res.message);
        }
        btn.prop('disabled', false).text('Resend Code');
        $('[data-id="' + id + '"]').first().trigger('click');
    }, 'json');
});
</script>
</body>
</html>
