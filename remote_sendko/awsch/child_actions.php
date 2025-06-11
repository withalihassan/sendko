<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get child_id and parent_id safely
$child_id  = $_GET['ac_id']      ?? '';
$parent_id = $_GET['parent_id'] ?? '';
$session_user_id = $_GET['user_id'] ?? '';

if (empty($child_id) || empty($parent_id)) {
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
        SELECT `aws_access_key`, `aws_secret_key`
        FROM `child_accounts`
        WHERE `account_id` = ?
    ");
    $stmt->execute([$child_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die("<div class='alert alert-danger'>No account found.</div>");
    }

    $aws_access_key = htmlspecialchars($row['aws_access_key']);
    $aws_secret_key = htmlspecialchars($row['aws_secret_key']);

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
    <title>AWSsss EC2 & IAM Admin</title>

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
                    <option value="us-east-1">US East (N. Virginia)</option>
                    <option value="us-east-2">US East (Ohio)</option>
                    <option value="us-west-1">US West (N. California)</option>
                    <option value="us-west-2">US West (Oregon)</option>
                    <option disabled>──────────</option>
                    <option value="ap-south-1">Asia Pacific (Mumbai)</option>
                    <option value="ap-northeast-3">Asia Pacific (Osaka)</option>
                    <option value="ap-northeast-2">Asia Pacific (Seoul)</option>
                    <option value="ap-southeast-1">Asia Pacific (Singapore)</option>
                    <option value="ap-southeast-2">Asia Pacific (Sydney)</option>
                    <option value="ap-northeast-1">Asia Pacific (Tokyo)</option>
                    <option disabled>──────────</option>
                    <option value="ca-central-1">Canada (Central)</option>
                    <option disabled>──────────</option>
                    <option value="eu-central-1">Europe (Frankfurt)</option>
                    <option value="eu-west-1">Europe (Ireland)</option>
                    <option value="eu-west-2">Europe (London)</option>
                    <option value="eu-west-3">Europe (Paris)</option>
                    <option value="eu-north-1">Europe (Stockholm)</option>
                    <option value="sa-east-1">South America (São Paulo)</option>
                </select>
            </div>

            <div class="col-md-3">
                <button
                    class="btn btn-primary btn-custom"
                    onclick="checkQuota()">Check Quota of EC2</button>
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
                    onclick="addAdminUser()">Add Admin User</button>
            </div>
            <div class="col-md-2">
                <button class="btn btn-warning btn-custom" onclick="checkMembership()">
                    Check Membership
                </button>
            </div>


        </div>
        <hr>
        <div class="row mb-3">
            <div class="col-md-2">
                <select id="regionSelect" class="form-select">
                    <option value="us-east-1">US East (N. Virginia)</option>
                    <option value="us-east-2">US East (Ohio)</option>
                    <option value="us-west-1">US West (N. California)</option>
                    <option value="us-west-2">US West (Oregon)</option>
                    <hr>
                    <option value="ap-south-1">Asia Pacific (Mumbai)</option>
                    <option value="ap-northeast-3">Asia Pacific (Osaka)</option>
                    <option value="ap-northeast-2">Asia Pacific (Seoul)</option>
                    <option value="ap-southeast-1">Asia Pacific (Singapore)</option>
                    <option value="ap-southeast-2">Asia Pacific (Sydney)</option>
                    <option value="ap-northeast-1">Asia Pacific (Tokyo)</option>
                    <hr>
                    <option value="ca-central-1">Canada (Central)</option>
                    <hr>
                    <option value="eu-central-1">Europe (Frankfurt)</option>
                    <option value="eu-west-1">Europe (Ireland)</option>
                    <option value="eu-west-2">Europe (London)</option>
                    <option value="eu-west-3">Europe (Paris)</option>
                    <option value="eu-north-1">Europe (Stockholm)</option>
                    <option value="sa-east-1">South America (São Paulo)</option>
                </select>
            </div>
            <div class="col-md-2">
                <select id="instanceType" class="form-select">
                    <option value="t2.micro">t2.micro</option>
                    <option value="c7a.xlarge">c5a.xlarge</option>
                    <option value="c7a.xlarge">c7a.xlarge</option>
                    <option value="c7a.2xlarge">c7a.2xlarge</option>
                    <option value="c7a.8xlarge">c7a.8xlarge</option>
                    <option value="c7i.xlarge">c7i.xlarge</option>
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
                <button class="btn btn-info mt-2" onclick="launchInSelectedRegion()">Launch in Selected Region</button>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-success" onclick="launchInAllRegions()" disabled>Launch in All Regions</button>
            </div>
            <div class="col-md-2 d-grid">
                <!-- NEW: Scan & Record Instances Button -->
                <button class="btn btn-outline-primary mt-2" onclick="scanInstances()">
                    Scan & Record Instances
                </button>
            </div>
        </div>

        <hr>

        <div class="table-responsive">
            <table class="table table-striped table-bordered text-center align-middle" id="instanceTable">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Instance ID</th>
                        <th>Region</th>
                        <th>Instance Type</th>
                        <th>Launch Type</th>
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
        $stmt->execute([$child_id]);
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
        const childAccountId = "<?php echo $child_id; ?>";
        const user_id = "<?php echo $_GET['user_id']; ?>";
        // console.log("User ID:", user_id);

        function checkQuota() {
            const region = $("#region").val();
            $.post("child_actions/check_quota.php", {
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

        function launchInAllRegions() {
            launchInstance("all");
        }

        function launchInSelectedRegion() {
            launchInstance($("#regionSelect").val());
        }

        function launchInstance(region) {
            var awsAccessKey = $("#aws_access_key").val();
            var awsSecretKey = $("#aws_secret_key").val();
            var instanceType = $("#instanceType").val();
            var marketType = $("#marketType").val();
            console.log(awsAccessKey);

            $.post("child_actions/launch_instance.php", {
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey,
                region: region,
                instance_type: instanceType,
                market_type: marketType
            }, function(response) {
                $("#response").html(response);
            });
            console.log(region);
        }

        function fetchInstances(childId) {
            $.get("child_actions/fetch_instances.php", {
                child_id: childId
            }, function(data) {
                $("#instanceTable tbody").html(data);
            }).fail(function() {
                console.error("Failed to fetch instances.");
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
        $(document).on('click', '.terminate', function() {
            var instanceId = $(this).data('instance-id');
            var recordId = $(this).data('id');
            var region = $(this).data('region');
            var accessKey = $(this).data('access-key'); // Access Key
            var secretKey = $(this).data('secret-key'); // Secret Key

            // Confirm termination
            if (confirm("Are you sure you want to terminate this instance?")) {
                // Send a request to terminate the instance
                $.post("child_actions/terminate_instance.php", {
                    instance_id: instanceId,
                    record_id: recordId,
                    region: region,
                    access_key: accessKey,
                    secret_key: secretKey
                }, function(response) {
                    if (response.success) {
                        alert("Instance terminated successfully.");
                        // Remove the terminated row from the table
                        $("button[data-id='" + recordId + "']").closest('tr').remove();
                    } else {
                        alert("Error terminating instance: " + response.message);
                    }
                }, 'json');
            }
        });

        function addAdminUser() {
            $("#response").html("<div class='text-info'>Creating IAM Admin user…</div>");
            $.post("child_actions/add_admin_user.php", {
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey,
                ac_id: childAccountId,
                user_id: user_id
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

        function copyField(fieldId) {
            const val = document.getElementById(fieldId).value;
            navigator.clipboard.writeText(val)
                .then(() => {
                    // optional: flash a tooltip or alert
                    alert("Copied to clipboard!");
                })
                .catch(() => {
                    alert("Copy failed. Please try manually.");
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>