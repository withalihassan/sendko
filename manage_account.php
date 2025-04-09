<?php
include('db.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?php echo $_GET['ac_id']; ?> AC ID</title>
  <!-- Bootstrap CSS for styling -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
  <!-- DataTables CSS for pagination -->
  <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.css">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
</head>
<body>
<div class="container-fluid" >
  <div class="row align-items-center">
    <!-- Node Manager & Launch Instance Form Column -->
    <div class="col-6">
      <h2 class="mb-2">Node Manager</h2>
      <!-- Alert messages will appear here -->
      <div id="message"></div>
      <form id="launchForm" method="post" class="form-inline">
        <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
        <div class="form-group mr-2">
          <label for="region" class="mr-2">Select Region:</label>
          <select name="region" id="region" class="form-control">
            <option value="us-west-2">Virginia (us-west-2)</option>
            <option value="us-west-2">Ohio (us-west-2)</option>
            <option value="us-west-2">Oregon (us-west-2)</option>
          </select>
        </div>
        <div class="form-group mr-2">
          <label for="instance_type" class="mr-2">Select Type:</label>
          <select name="instance_type" id="instance_type" class="form-control">
            <option value="c5.xlarge">c5.xlarge</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Launch Node</button>
      </form>
    </div>
    <!-- Scan Instances Form Column -->
    <div class="col-6">
      <h2 class="mb-2">Scan Instances in a Region</h2>
      <form id="scanForm" method="post" class="form-inline">
        <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
        <div class="form-group mr-2">
          <label for="scan_region" class="mr-2">Select Region:</label>
          <select name="region" id="scan_region" class="form-control">
            <option value="us-west-2">Oregon (us-west-2)</option>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary">Scan Instances</button>
      </form>
    </div>
  </div>
  <hr>
  <!-- Running Instance Display Section -->
  <table class="table table-bordered table-striped">
    <thead>
      <tr>
        <th>Instance ID</th>
        <th>Region</th>
        <th>Type</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>i-1234567890abcdef</td>
        <td>us-west-2</td>
        <td>c5.xlarge</td>
        <td>Running</td>
      </tr>
    </tbody>
  </table>
  <!-- Quick Actions Row -->
  <div class="row mb-3">
    <div class="col">
      <span class="mr-3 font-weight-bold">Quick Actions:</span>
      <button type="button" class="btn btn-info btn-sm mr-2">Check Status</button>
      <button type="button" class="btn btn-info btn-sm mr-2">Check Node Limits</button>
      <button type="button" class="btn btn-info btn-sm">Check Sender Eligibility</button>
    </div>
  </div>
  <!-- Parent Account Section -->
  <h2>Parent Account</h2>
  <table class="table table-bordered table-striped">
    <thead>
      <tr>
        <th>Ac ID</th>
        <th>Start Sending</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>PA-001</td>
        <td>Yes</td>
        <td>
          <button type="button" class="btn btn-success btn-sm">Action</button>
        </td>
      </tr>
    </tbody>
  </table>
  <!-- Child Accounts Section -->
  <h2>Child Accounts</h2>
  <table class="table table-bordered table-striped">
    <thead>
      <tr>
        <th>Ac ID</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>CH-001</td>
        <td>
          <button type="button" class="btn btn-primary btn-sm mr-2">Fetch Childs</button>
          <button type="button" class="btn btn-danger btn-sm">Del</button>
        </td>
      </tr>
    </tbody>
  </table>
</div>
<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
</body>
</html>
