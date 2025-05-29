<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// index.php
include '../session.php';
include '../db.php';
include "../header.php";
// Expect an account ID via GET
if (!isset($_GET['id'])) {
    echo "No account ID provided.";
    exit;
}
$account_id = intval($_GET['id']);
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <!-- Title now includes the unique account ID -->
    <title>Account <?php echo $account_id; ?></title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <style>
        /* Optional: Adjust inline form spacing on smaller screens */
        @media (max-width: 576px) {
            .form-inline .form-group {
                display: block;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid " style="padding: 0% 3% 3% 3%;">
        <!-- Main heading now includes the account ID -->
        <h1 class="mt-5">AWS EC2 Instance Manager - Account <?php echo $account_id; ?></h1>
        <!-- Alert messages will appear here -->
        <div id="message"></div>

        <!-- Launch Instance Form (inline) -->
        <form id="launchForm" method="post" class="form-inline">
            <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
            <div class="form-group mb-2 mr-3">
                <label for="region" class="mr-2">Select Region:</label>
                <select name="region" id="region" class="form-control">
                    <option value="us-east-2">Ohio (us-east-2)</option>
                    <option value="us-west-1">California (us-west-1)</option>
                    <option value="ap-south-1">Mumbai (ap-south-1)</option>
                    <option value="us-west-2" disabled>Oregon (us-west-2)</option>
                    <!-- Additional regions can be added here -->
                </select>
            </div>
            <div class="form-group mb-2 mr-3">
                <label for="instance_type" class="mr-2">Select Instance Type:</label>
                <select name="instance_type" id="instance_type" class="form-control">
                    <option value="c5.xlarge">c5.xlarge</option>
                    <option value="t2.small">t2.small</option>
                    <option value="t2.medium">t2.medium</option>
                    <!-- Additional instance types can be added here -->
                </select>
            </div>
            <button type="submit" class="btn btn-primary mb-2">Launch Instance</button>
        </form>

        <hr>

        <!-- Scan Instances Form (inline) -->
        <h2 class="mt-5">Scan Instances in a Region</h2>
        <form id="scanForm" method="post" class="form-inline">
            <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
            <div class="form-group mb-2 mr-3">
                <label for="scan_region" class="mr-2">Select Region:</label>
                <select name="region" id="scan_region" class="form-control">
                    <option value="us-east-2">Ohio (us-east-2)</option>
                    <option value="us-west-1">California (us-west-1)</option>
                    <option value="ap-south-1">Mumbai (ap-south-1)</option>
                    <option value="us-west-2" disabled>Oregon (us-west-2)</option>
                    <!-- Additional regions can be added here -->
                </select>
            </div>
            <button type="submit" class="btn btn-secondary mb-2">Scan Instances</button>
        </form>

        <hr>

        <!-- Launched Instances with additional controls -->
        <h2 class="mt-5">Launched EC2 Instances</h2>
        <!-- The instances table will be loaded here -->
        <div id="instancesTable"></div>
    </div>

    <script>
        // Display a message for success/error
        function showMessage(message, type = 'success') {
            $('#message').html('<div class="alert alert-' + type + '">' + message + '</div>');
            setTimeout(function() {
                $('#message').html('');
            }, 5000);
        }

        // Load the instances table via AJAX
        function loadInstances() {
            $.ajax({
                url: 'get_instances.php',
                type: 'GET',
                data: {
                    account_id: <?php echo $account_id; ?>
                },
                success: function(response) {
                    $('#instancesTable').html(response);
                },
                error: function() {
                    $('#instancesTable').html('<div class="alert alert-danger">Error loading instances.</div>');
                }
            });
        }

        $(document).ready(function() {
            loadInstances();

            // Handle launch form submission via AJAX
            $('#launchForm').submit(function(e) {
                e.preventDefault();
                $.ajax({
                    url: 'launch_instance.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    timeout: 180000, // Set timeout to 180 seconds (3 minutes)
                    success: function(response) {
                        if (response.success) {
                            showMessage(response.message, 'success');
                            $('#launchForm')[0].reset();
                            loadInstances();
                        } else {
                            showMessage(response.message, 'danger');
                        }
                    },
                    error: function(xhr) {
                        showMessage('Error launching instance. The process may be taking longer than expected.', 'danger');
                    }
                });
            });

            // Handle scan form submission via AJAX
            $('#scanForm').submit(function(e) {
                e.preventDefault();
                $.ajax({
                    url: 'scan_instances.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showMessage(response.message, 'success');
                            loadInstances();
                        } else {
                            showMessage(response.message, 'danger');
                        }
                    },
                    error: function() {
                        showMessage('Error scanning instances.', 'danger');
                    }
                });
            });

            // Refresh status button click event
            $('#refreshStatus').click(function() {
                loadInstances();
            });

            // Handle action forms (Terminate, Start, Stop, etc.) via AJAX using delegation
            $(document).on('submit', '.ajaxActionForm', function(e) {
                e.preventDefault();
                var action = $(this).find('input[name="action"]').val();
                // Ask for confirmation for actions as needed
                if (action === 'terminate') {
                    if (!confirm('Are you sure you want to terminate this instance?')) {
                        return false;
                    }
                } else if (action === 'stop') {
                    if (!confirm('Are you sure you want to stop this instance?')) {
                        return false;
                    }
                } else if (action === 'start') {
                    if (!confirm('Are you sure you want to start this instance?')) {
                        return false;
                    }
                }
                $.ajax({
                    url: 'instance_action.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showMessage(response.message, 'success');
                            loadInstances();
                        } else {
                            showMessage(response.message, 'danger');
                        }
                    },
                    error: function() {
                        showMessage('Error processing action.', 'danger');
                    }
                });
            });
        });
    </script>
</body>

</html>