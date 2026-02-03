<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get child_id and parent_id safely
$child_id  = $_GET['ac_id']      ?? '';
$parent_id = $_GET['parent_id'] ?? '';
$session_user_id = $_GET['user_id'] ?? '';

if (empty($parent_id)) {
    die("Invalid request. Missing parameters.");
}

// Include database connection and AWS SDK
require '../db.php';
require '../aws/aws-autoloader.php';

use Aws\Iam\IamClient;
use Aws\Exception\AwsException;

try {
    // Fetch stored AWS keys for this child account
    $stmt = $pdo->prepare("
        SELECT `aws_key`, `aws_secret`
        FROM `accounts`
        WHERE `account_id` = ?
    ");
    $stmt->execute([$parent_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die("<div class='alert alert-danger'>No account found.</div>");
    }

    $aws_access_key = htmlspecialchars($row['aws_key']);

    $aws_secret_key = htmlspecialchars($row['aws_secret']);

    // Decide response badge
    if ($aws_access_key !== null) {
        $response = "<div class='alert alert-success'>Account setup is perfect and ready to use.</div>";
    } else {
        $response = "<div class='alert alert-danger'>Account setup is required.</div>";
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>NID - <?php echo $_GET['parent_id']; ?></title>

    <!-- Bootstrap CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        body {
            background-color: #f8f9fa;
            font-family: Arial, sans-serif;
        }

        .response-box {
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-custom {
            min-width: 180px;
        }
    </style>
</head>

<body>
    <div class="container mt-4">
        <?php echo $response; ?>

        <div class="response-box mb-4" id="response">
            System response will appear here...
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <input type="hidden" id="aws_access_key" value="<?php echo $aws_access_key; ?>">
                <input type="hidden" id="aws_secret_key" value="<?php echo $aws_secret_key; ?>">
                <select id="region" class="form-select">
                    <!-- US -->
                    <option value="us-east-1">US East (N. Virginia)</option>
                    <option value="us-east-2">US East (Ohio)</option>
                    <option value="us-west-1">US West (N. California)</option>
                    <option value="us-west-2">US West (Oregon)</option>
                    <hr>
                    <!-- Africa -->
                    <option value="af-south-1">Africa (Cape Town)</option>
                    <hr>
                    <!-- Asia Pacific (expanded) -->
                    <option value="ap-east-1">Asia Pacific (Hong Kong)</option>
                    <option value="ap-south-2">Asia Pacific (Hyderabad)</option>
                    <option value="ap-southeast-3">Asia Pacific (Jakarta)</option>
                    <option value="ap-southeast-5">Asia Pacific (Malaysia)</option>
                    <option value="ap-southeast-4">Asia Pacific (Melbourne)</option>
                    <option value="ap-south-1">Asia Pacific (Mumbai)</option>
                    <option value="ap-southeast-6">Asia Pacific (New Zealand)</option>
                    <option value="ap-northeast-3">Asia Pacific (Osaka)</option>
                    <option value="ap-northeast-2">Asia Pacific (Seoul)</option>
                    <option value="ap-southeast-1">Asia Pacific (Singapore)</option>
                    <option value="ap-southeast-2">Asia Pacific (Sydney)</option>
                    <option value="ap-northeast-1">Asia Pacific (Tokyo)</option>
                    <option value="ap-east-2">Asia Pacific (Taipei)</option>
                    <option value="ap-southeast-7">Asia Pacific (Thailand)</option>
                    <hr>
                    <!-- Canada -->
                    <option value="ca-central-1">Canada (Central)</option>
                    <option value="ca-west-1">Canada West (Calgary)</option>
                    <hr>
                    <!-- Europe -->
                    <option value="eu-central-1">Europe (Frankfurt)</option>
                    <option value="eu-west-1">Europe (Ireland)</option>
                    <option value="eu-west-2">Europe (London)</option>
                    <option value="eu-south-1">Europe (Milan)</option>
                    <option value="eu-west-3">Europe (Paris)</option>
                    <option value="eu-south-2">Europe (Spain)</option>
                    <option value="eu-north-1">Europe (Stockholm)</option>
                    <option value="eu-central-2">Europe (Zurich)</option>
                    <hr>
                    <!-- Mexico -->
                    <option value="mx-central-1">Mexico (Central)</option>
                    <hr>
                    <!-- Middle East & Israel -->
                    <option value="me-south-1">Middle East (Bahrain)</option>
                    <option value="me-central-1">Middle East (UAE)</option>
                    <option value="il-central-1">Israel (Tel Aviv)</option>
                    <hr>
                    <!-- South America -->
                    <option value="sa-east-1">South America (São Paulo)</option>
                </select>
            </div>

            <div class="col-md-3">
                <button class="btn btn-primary btn-custom" onclick="checkQuota()">Check Quota of EC2</button>
            </div>

            <div class="col-md-2">
                <input type="hidden" id="aws_access_key" value="<?php echo $aws_access_key; ?>">
                <input type="hidden" id="aws_secret_key" value="<?php echo $aws_secret_key; ?>">
                <button
                    class="btn btn-secondary btn-custom"
                    onclick="checkAccountStatus()">Account Status</button>
            </div>

            <div class="col-md-2">
                <button
                    class="btn btn-warning btn-custom"
                    onclick="addAdminUser()">Make Master User</button>
            </div>

        </div>
        <hr>
        <div class="row mb-3">
            <div class="col-md-2">
                <select id="regionSelect" class="form-select">
                    <!-- US -->
                    <option value="us-east-1">US East (N. Virginia)</option>
                    <option value="us-east-2">US East (Ohio)</option>
                    <option value="us-west-1">US West (N. California)</option>
                    <option value="us-west-2">US West (Oregon)</option>
                    <hr>
                    <!-- Africa -->
                    <option value="af-south-1">Africa (Cape Town)</option>
                    <hr>
                    <!-- Asia Pacific (expanded) -->
                    <option value="ap-east-1">Asia Pacific (Hong Kong)</option>
                    <option value="ap-south-2">Asia Pacific (Hyderabad)</option>
                    <option value="ap-southeast-3">Asia Pacific (Jakarta)</option>
                    <option value="ap-southeast-5">Asia Pacific (Malaysia)</option>
                    <option value="ap-southeast-4">Asia Pacific (Melbourne)</option>
                    <option value="ap-south-1">Asia Pacific (Mumbai)</option>
                    <option value="ap-southeast-6">Asia Pacific (New Zealand)</option>
                    <option value="ap-northeast-3">Asia Pacific (Osaka)</option>
                    <option value="ap-northeast-2">Asia Pacific (Seoul)</option>
                    <option value="ap-southeast-1">Asia Pacific (Singapore)</option>
                    <option value="ap-southeast-2">Asia Pacific (Sydney)</option>
                    <option value="ap-northeast-1">Asia Pacific (Tokyo)</option>
                    <option value="ap-east-2">Asia Pacific (Taipei)</option>
                    <option value="ap-southeast-7">Asia Pacific (Thailand)</option>
                    <hr>
                    <!-- Canada -->
                    <option value="ca-central-1">Canada (Central)</option>
                    <option value="ca-west-1">Canada West (Calgary)</option>
                    <hr>
                    <!-- Europe -->
                    <option value="eu-central-1">Europe (Frankfurt)</option>
                    <option value="eu-west-1">Europe (Ireland)</option>
                    <option value="eu-west-2">Europe (London)</option>
                    <option value="eu-south-1">Europe (Milan)</option>
                    <option value="eu-west-3">Europe (Paris)</option>
                    <option value="eu-south-2">Europe (Spain)</option>
                    <option value="eu-north-1">Europe (Stockholm)</option>
                    <option value="eu-central-2">Europe (Zurich)</option>
                    <hr>
                    <!-- Mexico -->
                    <option value="mx-central-1">Mexico (Central)</option>
                    <hr>
                    <!-- Middle East & Israel -->
                    <option value="me-south-1">Middle East (Bahrain)</option>
                    <option value="me-central-1">Middle East (UAE)</option>
                    <option value="il-central-1">Israel (Tel Aviv)</option>
                    <hr>
                    <!-- South America -->
                    <option value="sa-east-1">South America (São Paulo)</option>
                </select>

            </div>
            <div class="col-md-2">
                <select id="instanceType" class="form-select">
                    <option value="t3.xlarge">t3.xlarge</option>
                    <option value="c5a.xlarge">c5a.xlarge</option>
                    <option value="c5.xlarge">c5.xlarge</option>
                    <option value="c7a.xlarge">c7a.xlarge</option>
                    <option value="c7.xlarge">c7.xlarge</option>
                    <option value="t3.2xlarge">t3.2xlarge</option>
                    <option value="c7a.2xlarge">c7a.2xlarge</option>
                    <option value="c7a.8xlarge">c7a.8xlarge</option>
                    <option value="c7i.xlarge">c7i.xlarge</option>
                    <option value="c7i.2xlarge">c7i.2xlarge</option>
                    <option value="c7i.8xlarge">c7i.8xlarge</option>
                </select>
            </div>
            <div class="col-md-2">
                <select id="marketType" class="form-select">
                    <option value="on-demand">On Demand</option>
                    <option value="spot">Spot</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-info mt-2" onclick="launchInSelectedRegion()">Create Desk</button>
            </div>
            <div class="col-md-2 d-grid">
                <!-- NEW: Scan & Record Instances Button -->
                <button class="btn btn-outline-primary mt-2" onclick="scanInstances()"> Scan & Record Desks </button>
            </div>
        </div>

        <hr>

        <div id="instanceContainer" data-parent-id="<?php echo $parent_id; ?>" class="table-responsive">
            <table class="table table-striped table-bordered text-center align-middle" id="instanceTable">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Instance ID</th>
                        <th>Region</th>
                        <th>Desk Size</th>
                        <th>Public IP</th>
                        <th>Password</th>
                        <th>State</th>
                        <th>Created At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <hr>
        <hr>
        <?php
        // 1) Fetch latest IAM admin user for this child account
        $stmt = $pdo->prepare("
  SELECT `login_url`, `username`, `password`, `access_key_id`, `secret_access_key`
  FROM `iam_users`
  WHERE `child_account_id` = ?
  ORDER BY `created_at` DESC
  LIMIT 1
");
        $stmt->execute([$parent_id]);
        $iamRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($iamRow):
        ?>
            <!-- 2) Render the five detail‑boxes -->
            <div class="row mb-4">
                <?php
                $fields = [
                    'Login URL'         => ['id' => 'loginUrl',          'val' => $iamRow['login_url']],
                    'Username'          => ['id' => 'userName',          'val' => $iamRow['username']],
                    'Password'          => ['id' => 'passWord',          'val' => $iamRow['password']],
                    'Access Key ID'     => ['id' => 'accessKeyId',       'val' => $iamRow['access_key_id']],
                    'Secret Access Key' => ['id' => 'secretAccessKey',   'val' => $iamRow['secret_access_key']],
                ];

                foreach ($fields as $label => $info):
                ?>
                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?= $label ?></label>
                        <div class="input-group">
                            <input type="text"
                                id="<?= $info['id'] ?>"
                                class="form-control"
                                readonly
                                value="<?= htmlspecialchars($info['val']) ?>">
                            <button class="btn btn-outline-secondary"
                                type="button"
                                onclick="copyField('<?= $info['id'] ?>')">
                                📋
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php
                // New combined field
                $combinedId = 'combinedKeys';
                $combinedVal = htmlspecialchars($iamRow['access_key_id'] . ' | ' . $iamRow['secret_access_key']);
                ?>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Access Key & Secret (one‑shot)</label>
                    <div class="input-group">
                        <input type="text"
                            id="<?= $combinedId ?>"
                            class="form-control"
                            readonly
                            value="<?= $combinedVal ?>">
                        <button class="btn btn-outline-secondary"
                            type="button"
                            onclick="copyField('<?= $combinedId ?>')">
                            📋
                        </button>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <button id="deliverBtn" class="btn btn-primary">Deliver</button>
                </div>

            </div>
        <?php
        endif;
        ?>

    </div>
    <script>
        document.getElementById('deliverBtn').onclick = async () => {
            const [id, secret] = document
                .getElementById('combinedKeys')
                .value.split('|')
                .map(s => s.trim());
            const res = await fetch('update.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    access_key_id: id,
                    secret_access_key: secret
                })
            });
            const {
                success
            } = await res.json();
            document.getElementById('response').textContent =
                success ?
                'Account delivered successfully' :
                'No matching record found';
        };
    </script>
    <script>
        // AWS credentials + account ID
        const awsAccessKey = "<?php echo $aws_access_key; ?>";
        const awsSecretKey = "<?php echo $aws_secret_key; ?>";
        const parent_id = "<?php echo $parent_id; ?>";
        // console.log("User ID:", parent_id);

        function checkQuota() {
            console.log("working");
            const region = $("#region").val();
            $.post("actions/check_quota.php", {
                region,
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey
            }, resp => $("#response").html(resp));
        }

        function checkAccountStatus() {
            $.post("child_actions/check_account_status.php", {
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey
            }, resp => $("#response").html(resp));
        }

        function launchInSelectedRegion() {
            launchInstance($("#regionSelect").val());
        }

        function launchInstance(region) {
            var payload = {
                aws_access_key: $('#aws_access_key').val(),
                aws_secret_key: $('#aws_secret_key').val(),
                region: region,
                parent_id: parent_id,
                instance_type: $('#instanceType').val()
            };

            // Start UI sequence
            $('#response').html('<div class="info">Creating Desk....</div>');

            $.post('actions/launch_instance.php', payload, function(raw) {
                var json = (typeof raw === 'string') ? JSON.parse(raw) : raw;

                if (json.status === 'ok') {
                    // Show keys created
                    $('#response').html(
                        '<div class="success"><strong>Keys created successfully</strong><br>' +
                        'Key: <code>' + (json.key_name || '') + '</code><br>' +
                        'PEM: <small>' + (json.pem_path || '') + '</small></div>'
                    );

                    // Next step: launching message
                    setTimeout(function() {
                        $('#response').append('<div class="info">Launching Desk in ' + json.region + ' of type ' + json.instance_type + ' (Windows)...</div>');
                    }, 500);

                    // Waiting for confirmation
                    setTimeout(function() {
                        $('#response').append('<div class="info">Waiting for confirmation...</div>');
                    }, 1000);

                    // Final success block
                    setTimeout(function() {
                        var finalHtml = '<div class="success"><strong>Desk Launched Successfully</strong><br>' +
                            'Instance ID: <code>' + (json.instance_id || '') + '</code><br>' +
                            'State: ' + (json.instance_state || '') + '<br>' +
                            'Public IP: <code>' + (json.public_ip || 'pending') + '</code><br>';

                        $('#response').append(finalHtml);

                        if (json.waiter_warning) {
                            $('#response').append('<div class="warn"><strong>Note:</strong> ' + $('<div/>').text(json.waiter_warning).html() + '</div>');
                        }
                    }, 1500);

                } else {
                    $('#response').html('<div class="error"><strong>Error:</strong> ' + (json.message || 'Unknown error') + '</div>');
                }
            }).fail(function(xhr) {
                var body = xhr.responseText || '';
                $('#response').html('<div class="error">Request failed: ' + xhr.status +
                    '<pre>' + $('<div/>').text(body).html() + '</pre></div>');
            });
        }
        // Keeps the currently shown parent id for refreshes
        let currentParentId = document.getElementById('instanceContainer').dataset.parentId || '';

        // Fetch and render
        function fetchInstances(currentParentId) {
            if (!parent_id) {
                $("#instanceTable tbody").html('<tr><td colspan="8">No parent_id provided.</td></tr>');
                return;
            }
            // currentParentId = parent_id;
            $.get("actions/fetch_instances.php", {
                parent_id: parent_id
            }, function(html) {
                $("#instanceTable tbody").html(html);
            }, 'html').fail(function() {
                console.error("Failed to fetch instances.");
                $("#instanceTable tbody").html('<tr><td colspan="8">Failed to fetch instances.</td></tr>');
            });
        }
        $(document).ready(function() {
            var childId = <?php echo isset($child_id) ? json_encode($child_id) : 'null'; ?>;

            if (childId !== null) {
                fetchInstances(childId);
            } else {
                console.error("childId is not defined.");
            }
        });

        function addAdminUser() {
            $("#response").html("<div class='text-info'>Creating IAM Admin user…</div>");
            $.post("child_actions/add_admin_user.php", {
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey,
                ac_id: parent_id,
                user_id: 1200
            }, json => {
                let data;
                try {
                    data = (typeof json === 'string') ? JSON.parse(json) : json;
                } catch {
                    return $("#response").html("<div class='alert alert-danger'>Invalid response.</div>");
                }
                if (data.error) {
                    return $("#response").html("<div class='alert alert-danger'>" + data.error + "</div>");
                }

                // build copy‑boxes
                const html = `
          <div class="mb-3">
            <label class="form-label">Login URL</label>
            <div class="input-group">
              <input type="text" class="form-control" id="loginUrl" readonly value="${data.login_url}">
              <button class="btn btn-outline-secondary" title="Copy URL" onclick="copyField('loginUrl')">📋</button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Username</label>
            <div class="input-group">
              <input type="text" class="form-control" id="userName" readonly value="${data.username}">
              <button class="btn btn-outline-secondary" title="Copy Username" onclick="copyField('userName')">📋</button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <div class="input-group">
              <input type="text" class="form-control" id="passWord" readonly value="${data.password}">
              <button class="btn btn-outline-secondary" title="Copy Password" onclick="copyField('passWord')">📋</button>
            </div>
          </div>
        `;
                $("#response").html(html);
            });
        }

        function checkMembership() {
            const region = $("#region").val() || "us-east-1"; // you can pick any org-aware region
            const accessKey = $("#aws_access_key").val();
            const secretKey = $("#aws_secret_key").val();

            // show a spinner/message
            $("#response").html(`<div class="text-info">Checking organization membership…</div>`);

            $.post("child_actions/check_membership.php", {
                aws_access_key: accessKey,
                aws_secret_key: secretKey,
                region: region
            }, function(resp) {
                // just dump the HTML/PHP response into the box
                $("#response").html(resp);
            }).fail(function(xhr) {
                $("#response").html(
                    `<div class="alert alert-danger">
         Error contacting server:<br>${xhr.responseText || xhr.statusText}
       </div>`
                );
            });
        }
    </script>

    <script>
        // Reuse awsAccessKey, awsSecretKey, and childAccountId defined in your page
        function scanInstances() {
            const region = $("#regionSelect").val();
            const awsAccessKey = $("#aws_access_key").val();
            const awsSecretKey = $("#aws_secret_key").val();

            // Show a scanning message
            $("#response").html(
                `<div class='text-info'>Scanning for EC2 instances in ${region}&hellip;</div>`
            );

            $.post("child_actions/scan_instances.php", {
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey,
                region: region
            }, function(json) {
                let data;
                try {
                    data = (typeof json === 'string') ? JSON.parse(json) : json;
                } catch (e) {
                    return $("#response").html(
                        `<div class='alert alert-danger'>Invalid JSON response:<br>${e.message}</div>`
                    );
                }

                if (data.error) {
                    // Display the full error message
                    $("#response").html(
                        `<div class='alert alert-danger'>Error: ${data.error}</div>`
                    );
                } else if (data.instances && data.instances.length > 0) {
                    let html = `<div class="alert alert-success">Found & recorded ${data.instances.length} instance(s):<ul>`;
                    data.instances.forEach(inst => {
                        html += `<li>${inst.InstanceId} (${inst.State})</li>`;
                    });
                    html += `</ul></div>`;
                    $("#response").html(html);
                    // Refresh your instances table
                    fetchInstances(childAccountId);
                } else {
                    $("#response").html(
                        `<div class='alert alert-warning'>No instances found in region ${region}.</div>`
                    );
                }
            }, 'json').fail(function(xhr) {
                // Show server-side exception text
                let errText = xhr.responseText || xhr.statusText;
                $("#response").html(
                    `<div class='alert alert-danger'>Server error while scanning:<br>${errText}</div>`
                );
            });
        }
    </script>
    <!-- Bootstrap JS -->
    <script>
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('button.start,button.stop,button.reload,button.terminate,button.changeip,button.getpsw');
            if (!btn) return;
            e.preventDefault();

            // map action -> endpoint
            const endpoints = {
                start: 'actions/start.php',
                stop: 'actions/stop.php',
                reload: 'actions/reload.php',
                terminate: 'actions/terminate.php',
                changeip: 'actions/changeip.php',
                getpsw: 'actions/getpsw.php'
            };
            const action = Object.keys(endpoints).find(a => btn.classList.contains(a)) || 'start';
            const url = endpoints[action];

            const box = document.getElementById('response');
            const row = btn.closest('tr');

            // read data-* attributes from button first, then fall back to the row
            const readAttr = (name) => btn.getAttribute(name) ?? row?.getAttribute(name) ?? null;

            const payload = {
                awsAccessKey: (typeof awsAccessKey !== 'undefined') ? awsAccessKey : null,
                awsSecretKey: (typeof awsSecretKey !== 'undefined') ? awsSecretKey : null,
                parent_id: readAttr('data-parent-id'),
                id: readAttr('data-id'),
                instance_id: readAttr('data-instance-id'),
                region: readAttr('data-region')
            };

            // normalize empty strings -> null, convert purely-numeric id to integer
            Object.keys(payload).forEach(k => {
                if (payload[k] === '') payload[k] = null;
            });
            if (payload.id !== null && /^[0-9]+$/.test(String(payload.id))) payload.id = parseInt(payload.id, 10);

            box.textContent = 'Processing...';
            btn.disabled = true;

            try {
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const text = await resp.text();
                try {
                    const json = JSON.parse(text);
                    box.innerHTML = `<pre>${JSON.stringify(json, null, 2)}</pre>`;
                } catch {
                    // Not JSON — show raw text / HTTP status
                    box.textContent = text || `HTTP ${resp.status}`;
                }
            } catch (err) {
                console.error(err);
                box.textContent = 'Request failed';
            } finally {
                btn.disabled = false;
            }
        });
    </script>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>