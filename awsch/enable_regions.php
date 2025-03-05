<?php
// enable_regions.php
include "./session.php";
include('../db.php'); // This file must initialize your $pdo connection

// Ensure an account ID is provided via GET (using ac_id)
if (!isset($_GET['ac_id'])) {
    echo "No account ID provided.";
    exit;
}

$ac_id = intval($_GET['ac_id']);
$parrent_id = isset($_GET['parrent_id']) ? intval($_GET['parrent_id']) : 0;

// Fetch the AWS credentials for the provided account ID from child_accounts table
$stmt = $pdo->prepare("SELECT aws_access_key, aws_secret_key FROM child_accounts WHERE account_id = ?");
$stmt->execute([$ac_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo "Account not found.";
    exit;
}

$aws_key    = htmlspecialchars($account['aws_access_key']);
$aws_secret = htmlspecialchars($account['aws_secret_key']);

// Define the static list of regions to manage.
$staticRegions = [
    [ "code" => "me-central-1", "name" => "UAE (me-central-1)" ],
    [ "code" => "sa-east-1", "name" => "Sao Paulo (sa-east-1)" ],
    [ "code" => "af-south-1", "name" => "Africa (af-south-1)" ],
    [ "code" => "ap-southeast-3", "name" => "Jakarta (ap-southeast-3)" ],
    [ "code" => "ap-southeast-4", "name" => "Melbourne (ap-southeast-4)" ],
    [ "code" => "ca-west-1", "name" => "Calgary (ca-west-1)" ],
    [ "code" => "eu-south-1", "name" => "Milan (eu-south-1)" ],
    [ "code" => "eu-south-2", "name" => "Spain (eu-south-2)" ],
    [ "code" => "eu-central-2", "name" => "Zurich (eu-central-2)" ],
    [ "code" => "me-south-1", "name" => "Bahrain (me-south-1)" ],
    [ "code" => "il-central-1", "name" => "Tel Aviv (il-central-1)" ],
    [ "code" => "ap-south-2", "name" => "Hyedrabad (ap-south-2)" ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Bulk Regional Send - Region Options</title>
  <!-- Use Bootstrap for styling -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    body {
      background: #f7f7f7;
      padding: 20px;
    }
    .container {
      max-width: 900px;
      background: #fff;
      padding: 20px;
      border-radius: 5px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    .section-title {
      margin-top: 20px;
      margin-bottom: 15px;
    }
    #region-opt-div {
      display: none;
      margin-top: 15px;
    }
  </style>
</head>
<body>
<div class="container">
  <h1 class="text-center">Manage Region Options</h1>
  
  <!-- Display AWS Credentials (read-only) -->
  <div class="mb-4">
    <p><strong>AWS Key:</strong> <?php echo $aws_key; ?></p>
    <p><strong>AWS Secret:</strong> <?php echo $aws_secret; ?></p>
  </div>
  
  <!-- Section: Region Option Totals -->
  <div id="region-totals" class="mb-4"></div>
  
  <!-- Section: Region Option Management -->
  <h2 class="section-title">Disabled Regions</h2>
  <button id="toggle-regions-btn" class="btn btn-primary mb-3">Show/Hide Disabled Regions</button>
  
  <div id="region-opt-div" class="card card-body">
    <div class="form-group">
      <label for="region-select">Select a disabled region:</label>
      <select id="region-select" class="form-control">
        <!-- Options will be populated dynamically -->
      </select>
    </div>
    <div class="mb-2">
      <button id="enable-btn" class="btn btn-success">Enable Region</button>
      <button id="disable-btn" class="btn btn-danger">Disable Region</button>
    </div>
  </div>
  
  <!-- Bulk Enable Buttons -->
  <div class="mb-4">
    <button id="enable-set1" class="btn btn-success">Enable Set1</button>
    <button id="enable-set2" class="btn btn-success">Enable Set2</button>
  </div>
  
  <div id="response-message" class="mt-3"></div>
  
  <!-- Note for Half-Accounts -->
  <div class="alert alert-info mt-4">
    <strong>Note for Half-Accounts:</strong> Enable only these regions: "me-central-1", "ap-southeast-3", "ap-southeast-4", "eu-south-2", "eu-central-2", "ap-south-2".
  </div>
  
</div>

<!-- Include jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
  // Pass staticRegions from PHP to JavaScript
  var staticRegions = <?php echo json_encode($staticRegions); ?>;
  
  // Toggle the region options card
  $('#toggle-regions-btn').click(function(){
    $('#region-opt-div').slideToggle();
  });
  
  // Function to fetch region data (totals and full list) from AWS via list_regions.php,
  // then filter by the staticRegions list.
  function fetchRegionTotals() {
    var awsKey = "<?php echo $aws_key; ?>";
    var awsSecret = "<?php echo $aws_secret; ?>";
    $.ajax({
      url: 'list_regions.php',
      type: 'POST',
      dataType: 'json',
      data: { awsKey: awsKey, awsSecret: awsSecret },
      success: function(response) {
        if(response.status === 'success'){
          var awsRegions = response.regions; // full AWS region data from API
          var filteredEnabled = 0;
          var filteredDisabled = 0;
          var disabledOptions = [];
          // Loop through our staticRegions and check their opt status.
          staticRegions.forEach(function(reg){
            var found = awsRegions.find(function(r) {
              return r.RegionName === reg.code;
            });
            // If found and status is ENABLED, count as enabled.
            // Otherwise, count as disabled (including if not found).
            if(found && found.RegionOptStatus === "ENABLED") {
              filteredEnabled++;
            } else {
              filteredDisabled++;
              disabledOptions.push(reg);
            }
          });
          // Update totals display
          $("#region-totals").html(
            '<div class="alert alert-info">' +
            'Total Enabled Regions (managed): ' + filteredEnabled + '<br>' +
            'Total Disabled Regions (managed): ' + filteredDisabled +
            '</div>'
          );
          // Populate dropdown with only disabled regions from staticRegions.
          var select = $("#region-select");
          select.empty();
          if(disabledOptions.length > 0){
            disabledOptions.forEach(function(opt){
              select.append('<option value="'+ opt.code +'">'+ opt.name +'</option>');
            });
          } else {
            select.append('<option value="">No disabled regions available</option>');
          }
        } else {
          $("#region-totals").html('<div class="alert alert-danger">' + response.message + '</div>');
        }
      },
      error: function(xhr, status, error) {
        $("#region-totals").html('<div class="alert alert-danger">AJAX error: ' + error + '</div>');
      }
    });
  }
  
  // Fetch region totals when the page loads
  $(document).ready(function(){
    fetchRegionTotals();
  });
  
  // Function to perform the region option API call via AJAX for a single region
  function callRegionOptForRegion(actionType, region, callback) {
    var awsKey = "<?php echo $aws_key; ?>";
    var awsSecret = "<?php echo $aws_secret; ?>";
    
    $.ajax({
      url: 'region_opt.php',
      type: 'POST',
      dataType: 'json',
      data: {
        action: actionType,  // "enable" or "disable"
        region: region.code,
        awsKey: awsKey,
        awsSecret: awsSecret
      },
      success: function(response) {
        $('#response-message').append(
          '<div class="alert alert-success">' + response.message + ' for region ' + region.name + '</div>'
        );
        if(typeof callback === 'function') {
          callback();
        }
      },
      error: function(xhr, status, error) {
        $('#response-message').append(
          '<div class="alert alert-danger">AJAX error: ' + error + ' for region ' + region.name + '</div>'
        );
        if(typeof callback === 'function') {
          callback();
        }
      }
    });
  }
  
  // Recursive function to enable a list of regions with a 2-second gap between calls
  function enableRegionSet(regionSet, index) {
    if(index < regionSet.length) {
      callRegionOptForRegion('enable', regionSet[index], function(){
        setTimeout(function(){
          enableRegionSet(regionSet, index + 1);
        }, 2000);
      });
    } else {
      fetchRegionTotals(); // Refresh region totals after finishing the set
    }
  }
  
  // Bind click events to the individual Enable/Disable buttons
  $('#enable-btn').click(function(){
    var regionCode = $('#region-select').val();
    var awsKey = "<?php echo $aws_key; ?>";
    var awsSecret = "<?php echo $aws_secret; ?>";
    
    $.ajax({
      url: 'region_opt.php',
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'enable',
        region: regionCode,
        awsKey: awsKey,
        awsSecret: awsSecret
      },
      success: function(response) {
        $('#response-message').html('<div class="alert alert-success">' + response.message + '</div>');
        fetchRegionTotals();
      },
      error: function(xhr, status, error) {
        $('#response-message').html('<div class="alert alert-danger">AJAX error: ' + error + '</div>');
      }
    });
  });
  
  $('#disable-btn').click(function(){
    var regionCode = $('#region-select').val();
    var awsKey = "<?php echo $aws_key; ?>";
    var awsSecret = "<?php echo $aws_secret; ?>";
    
    $.ajax({
      url: 'region_opt.php',
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'disable',
        region: regionCode,
        awsKey: awsKey,
        awsSecret: awsSecret
      },
      success: function(response) {
        $('#response-message').html('<div class="alert alert-success">' + response.message + '</div>');
        fetchRegionTotals();
      },
      error: function(xhr, status, error) {
        $('#response-message').html('<div class="alert alert-danger">AJAX error: ' + error + '</div>');
      }
    });
  });
  
  // Bind click events for the bulk enable buttons
  $('#enable-set1').click(function(){
    // First 7 regions (indexes 0 to 6)
    var set1 = staticRegions.slice(0, 7);
    $('#response-message').html(''); // Clear previous messages
    enableRegionSet(set1, 0);
  });
  
  $('#enable-set2').click(function(){
    // Remaining 5 regions (indexes 7 to end)
    var set2 = staticRegions.slice(7);
    $('#response-message').html(''); // Clear previous messages
    enableRegionSet(set2, 0);
  });
</script>
</body>
</html>
