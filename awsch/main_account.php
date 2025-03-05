<?php
// Get child_id and parent_id safely using null coalescing operator
$child_id = $_GET['parent_id'] ?? '';
 $parent_id = $_GET['parent_id'] ?? '';

// Validate input to prevent SQL injection
if (empty($parent_id)) {
    die("Invalid request. Missing parameters.");
}

// Include database connection
require './db_connect.php';

try {
    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT `aws_key`, `aws_secret` FROM `aws_accounts` WHERE `account_id` = ?");
    $stmt->execute([$parent_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
// echo $row['aws_key'];
    if ($row) {
        $aws_access_key = htmlspecialchars($row['aws_key']);
        $aws_secret_key = htmlspecialchars($row['aws_secret']);
        if ($aws_access_key !=  NULL) {
            $response =  "<div class='alert alert-success' role='alert'>Account Setup Is perfect and ready to use</div>";
        } else {
            $response =  "<div class='alert alert-danger' role='alert'>Account Setup Is Required</div>";
            // die("error");
        }
    } else {
        $response =  "<div class='alert alert-danger' role='alert'>Account Setup Is perfect and ready to use</div>";
        die("No account found.");
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AWS EC2 Management</title>

    <!-- Bootstrap CSS for modern styling -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
            background-color: #ffffff;
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
        <div class="response-box mb-4" id="response">System response will appear here...</div>

        <div class="row mb-3">
            <div class="col-md-4">
                <input type="hidden" id="aws_access_key" value="<?php echo $aws_access_key; ?>">
                <input type="hidden" id="aws_secret_key" value="<?php echo $aws_secret_key; ?>">

                <select id="region" class="form-select">
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
            <div class="col-md-4">
                <button class="btn btn-primary btn-custom" onclick="checkQuota()">Check Quota of EC2</button>
            </div>
            <div class="col-md-4">
                <input type="hidden" id="aws_access_key" value="<?php echo $aws_access_key; ?>">
                <input type="hidden" id="aws_secret_key" value="<?php echo $aws_secret_key; ?>">
                <button class="btn btn-secondary btn-custom" onclick="checkAccountStatus()">Account Status</button>
            </div>
        </div>

        <hr>

        <div class="row mb-3">
            <div class="col-md-3">
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
                    <option value="c7a.xlarge">c7a.xlarge (4vcpu)</option>
                    <option value="c7a.2xlarge">c7a.2xlarge (8vcpu)</option>
                    <option value="c7a.4xlarge">c7a.4xlarge (16vcpu)</option>
                    <option value="c7a.8xlarge">c7a.8xlarge (32vcpu)</option>
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
            <div class="col-md-3 d-grid">
                <button class="btn btn-info mt-2" onclick="launchInSelectedRegion()">Launch in Selected Region</button>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-success" onclick="launchInAllRegions()">Launch in All Regions</button>
            </div>
        </div>

        <!-- <div id="response"></div> -->


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
    </div>

    <script>
        function checkQuota() {
            var region = $("#region").val();
            var awsAccessKey = $("#aws_access_key").val(); // Get AWS Access Key from input field
            var awsSecretKey = $("#aws_secret_key").val(); // Get AWS Secret Key from input field

            $.post("child_actions/check_quota.php", {
                region: region,
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey
            }, function(response) {
                $("#response").html(response);
            });
        }


        function checkAccountStatus() {
            var awsAccessKey = $("#aws_access_key").val();
            var awsSecretKey = $("#aws_secret_key").val();

            $.post("child_actions/check_account_status.php", {
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey
            }, function(response) {
                $("#response").html(response);
            });
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

            $.post("child_actions/launch_instance.php", {
                aws_access_key: awsAccessKey,
                aws_secret_key: awsSecretKey,
                region: region,
                instance_type: instanceType,
                market_type: marketType
            }, function(response) {
                $("#response").html(response);
            });
        }


        function fetchInstances(childId) {
            $.get("child_actions/fetch_for_main.php", {
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
    </script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>