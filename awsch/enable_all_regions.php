<?php
// enable_all_regions.php

include "./session.php";
include('../db.php'); // This file must initialize your $pdo connection

if (!isset($_GET['parent_id'])) {
    echo "No parent id provided.";
    exit;
}

$parent_id = htmlspecialchars($_GET['parent_id']);

// Fetch all child accounts for the provided parent id (excluding the parent itself)
$stmt = $pdo->prepare("SELECT `id`, `parent_id`, `email`, `account_id`, `status`, `created_at`, `name`, `aws_access_key`, `aws_secret_key`, `worth_type` FROM child_accounts WHERE parent_id = ? AND account_id != ?");
$stmt->execute([$parent_id, $parent_id]);
$childAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define the static list of regions
$staticRegions = [
    [ "code" => "me-central-1", "name" => "UAE (me-central-1)" ],
    [ "code" => "sa-east-1",    "name" => "Sao Paulo (sa-east-1)" ],
    [ "code" => "af-south-1",    "name" => "Africa (af-south-1)" ],
    [ "code" => "ap-southeast-3","name" => "Jakarta (ap-southeast-3)" ],
    [ "code" => "ap-southeast-4","name" => "Melbourne (ap-southeast-4)" ],
    [ "code" => "ca-west-1",     "name" => "Calgary (ca-west-1)" ],
    [ "code" => "eu-south-1",    "name" => "Milan (eu-south-1)" ],
    [ "code" => "eu-south-2",    "name" => "Spain (eu-south-2)" ],
    [ "code" => "eu-central-2",  "name" => "Zurich (eu-central-2)" ],
    [ "code" => "me-south-1",    "name" => "Bahrain (me-south-1)" ],
    [ "code" => "il-central-1",  "name" => "Tel Aviv (il-central-1)" ],
    [ "code" => "ap-south-2",    "name" => "Hyderabad (ap-south-2)" ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Enable Regions for Child Accounts</title>
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    body {
      background: #f7f7f7;
      padding: 20px;
    }
    .child-box {
      margin-bottom: 20px;
    }
    .card {
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      position: relative;
    }
    .global-buttons {
      margin-bottom: 20px;
    }
    /* Display the worth_type in the top-right corner */
    .worth-type {
      position: absolute;
      top: 0;
      right: 0;
      background: #ffc107;
      padding: 5px 10px;
      font-size: 0.8rem;
      font-weight: bold;
      border-bottom-left-radius: 5px;
    }
  </style>
</head>
<body>
<div class="container-fluid">
  <h1 class="text-center">Enable Regions for Child Accounts</h1>
  
  <!-- Global Bulk Enable Buttons -->
  <div class="global-buttons text-center">
    <button id="global-enable-set1" class="btn btn-success mr-2">Enable Set1 of All Childs</button>
    <button id="global-enable-set2" class="btn btn-success mr-2">Enable Set2 of All Childs</button>
    <button id="global-enable-half" class="btn btn-success">Enable Half Ac Regions</button>
  </div>
  
  <!-- Child Account Cards (3 per row) -->
  <div class="row">
    <?php if(count($childAccounts) > 0): ?>
      <?php foreach($childAccounts as $child): ?>
        <div class="col-md-4">
          <div class="card child-box" 
               data-child-id="<?php echo htmlspecialchars($child['account_id']); ?>" 
               data-aws-key="<?php echo htmlspecialchars($child['aws_access_key']); ?>" 
               data-aws-secret="<?php echo htmlspecialchars($child['aws_secret_key']); ?>"
               data-worth-type="<?php echo isset($child['worth_type']) ? htmlspecialchars($child['worth_type']) : 'null'; ?>">
            <div class="worth-type">
              <?php echo isset($child['worth_type']) ? htmlspecialchars($child['worth_type']) : 'null'; ?>
            </div>
            <div class="card-header">
              <h5><?php echo htmlspecialchars($child['name']); ?> (<?php echo htmlspecialchars($child['email']); ?>)</h5>
            </div>
            <div class="card-body">
              <p><strong>AWS Key:</strong> <?php echo htmlspecialchars($child['aws_access_key']); ?></p>
              <p><strong>AWS Secret:</strong> <?php echo htmlspecialchars($child['aws_secret_key']); ?></p>
              <!-- Region Totals for this child account -->
              <div class="region-totals mb-2">
                <em>Loading region totals...</em>
              </div>
              <div class="mb-2">
                <button class="btn btn-primary enable-set1">Enable Set1</button>
                <button class="btn btn-primary enable-set2">Enable Set2</button>
                <button class="btn btn-primary enable-half">Enable Half Ac Regions</button>
              </div>
              <div class="child-response"></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="col-12">
        <div class="alert alert-warning">No child accounts found for parent id <?php echo $parent_id; ?>.</div>
      </div>
    <?php endif; ?>
  </div>
  
  <div class="alert alert-info mt-4">
    <strong>Note for Half-Accounts:</strong> Enable only these regions: "me-central-1", "ap-southeast-3", "ap-southeast-4", "eu-south-2", "eu-central-2", "ap-south-2".
  </div>
</div>

<!-- jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
// Pass staticRegions from PHP to JavaScript
var staticRegions = <?php echo json_encode($staticRegions); ?>;

// Function to fetch region totals for a specific child box
function fetchRegionTotalsForChild(childBox) {
  var awsKey = $(childBox).data('aws-key');
  var awsSecret = $(childBox).data('aws-secret');
  
  // Show loading text
  $(childBox).find('.region-totals').html('<em>Loading region totals...</em>');
  
  $.ajax({
    url: 'list_regions.php',
    type: 'POST',
    dataType: 'json',
    data: { awsKey: awsKey, awsSecret: awsSecret },
    success: function(response) {
      if(response.status === 'success'){
        var awsRegions = response.regions;
        var filteredEnabled = 0;
        var filteredDisabled = 0;
        staticRegions.forEach(function(reg) {
          var found = awsRegions.find(function(r) {
            return r.RegionName === reg.code;
          });
          if(found && found.RegionOptStatus === "ENABLED") {
            filteredEnabled++;
          } else {
            filteredDisabled++;
          }
        });
        $(childBox).find('.region-totals').html(
          '<div class="alert alert-info p-2" style="font-size: 0.9rem;">' +
          'Enabled Regions (managed): ' + filteredEnabled + '<br>' +
          'Disabled Regions (managed): ' + filteredDisabled +
          '</div>'
        );
      } else {
        $(childBox).find('.region-totals').html('<div class="alert alert-danger p-2">' + response.message + '</div>');
      }
    },
    error: function(xhr, status, error) {
      $(childBox).find('.region-totals').html('<div class="alert alert-danger p-2">AJAX error: ' + error + '</div>');
    }
  });
}

// Recursive function to enable a list of regions with a 2-second gap for a specific child card
function enableRegionSetForChild(childBox, regionSet, index) {
  if(index < regionSet.length) {
    var region = regionSet[index];
    var awsKey = $(childBox).data('aws-key');
    var awsSecret = $(childBox).data('aws-secret');
    
    $.ajax({
      url: 'region_opt.php',
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'enable',
        region: region.code,
        awsKey: awsKey,
        awsSecret: awsSecret
      },
      success: function(response) {
        $(childBox).find('.child-response').append(
          '<div class="alert alert-success p-2" style="font-size: 0.9rem;">' + response.message + ' for region ' + region.name + '</div>'
        );
        setTimeout(function(){
          enableRegionSetForChild(childBox, regionSet, index + 1);
        }, 2000);
      },
      error: function(xhr, status, error) {
        $(childBox).find('.child-response').append(
          '<div class="alert alert-danger p-2" style="font-size: 0.9rem;">AJAX error: ' + error + ' for region ' + region.name + '</div>'
        );
        setTimeout(function(){
          enableRegionSetForChild(childBox, regionSet, index + 1);
        }, 2000);
      }
    });
  } else {
    // Refresh the region totals after finishing the set
    fetchRegionTotalsForChild(childBox);
  }
}

$(document).ready(function(){
  // On page load, fetch region totals for each child card
  $('.child-box').each(function(){
    fetchRegionTotalsForChild(this);
  });
  
  // Individual child card buttons
  $('.enable-set1').click(function(){
    var childBox = $(this).closest('.child-box');
    $(childBox).find('.child-response').html(''); // Clear previous messages
    var set1 = staticRegions.slice(0, 7);
    enableRegionSetForChild(childBox, set1, 0);
  });
  
  $('.enable-set2').click(function(){
    var childBox = $(this).closest('.child-box');
    $(childBox).find('.child-response').html(''); // Clear previous messages
    var set2 = staticRegions.slice(7);
    enableRegionSetForChild(childBox, set2, 0);
  });
  
  $('.enable-half').click(function(){
    var childBox = $(this).closest('.child-box');
    $(childBox).find('.child-response').html('');
    // Define the half regions to enable (only these regions)
    var halfSet = staticRegions.filter(function(region){
      return ["me-central-1", "ap-southeast-3", "ap-southeast-4", "eu-south-2", "eu-central-2", "ap-south-2"].indexOf(region.code) !== -1;
    });
    enableRegionSetForChild(childBox, halfSet, 0);
  });
  
  // Global buttons for all child boxes
  $('#global-enable-set1').click(function(){
    $('.child-box').each(function(){
      $(this).find('.child-response').html('');
      var set1 = staticRegions.slice(0, 7);
      enableRegionSetForChild(this, set1, 0);
    });
  });
  
  $('#global-enable-set2').click(function(){
    $('.child-box').each(function(){
      $(this).find('.child-response').html('');
      var set2 = staticRegions.slice(7);
      enableRegionSetForChild(this, set2, 0);
    });
  });
  
  $('#global-enable-half').click(function(){
    $('.child-box').each(function(){
      $(this).find('.child-response').html('');
      // Define the half regions to enable globally
      var halfSet = staticRegions.filter(function(region){
        return ["me-central-1", "ap-southeast-3", "ap-southeast-4", "eu-south-2", "eu-central-2", "ap-south-2"].indexOf(region.code) !== -1;
      });
      enableRegionSetForChild(this, halfSet, 0);
    });
  });
});
</script>
</body>
</html>
