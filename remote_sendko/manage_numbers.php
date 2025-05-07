<?php
include "./session.php";  // Must start the session and set $_SESSION['user_id']
include "./header.php";   // Your header file
require 'db.php';

session_start();
$session_id = $_SESSION['user_id'];
$message = '';

// Process Form Submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Bulk Import Numbers (Manual, CSV, or Excel)
    if (isset($_POST['import_numbers'])) {
        $importType = $_POST['import_type'];
        if ($importType == 'manual') {
            $manual = trim($_POST['manual_numbers']);
            if ($manual != '') {
                $lines = explode("\n", $manual);
                $importedCount = 0;
                foreach ($lines as $line) {
                    $num = trim($line);
                    if ($num != '') {
                        if ($num[0] !== '+') {
                            $num = '+' . $num;
                        }
                        try {
                            $stmt = $pdo->prepare("INSERT INTO allowed_numbers (phone_number, status, by_user) VALUES (?, 'fresh', ?)");
                            $stmt->execute([$num, $session_id]);
                            $importedCount++;
                        } catch (PDOException $e) {
                            // Optionally log error
                        }
                    }
                }
                $message = "Imported $importedCount numbers from manual input.";
            } else {
                $message = "No numbers provided in manual input.";
            }
        } elseif ($importType == 'csv') {
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
                // Check for CSV file extension
                $allowedExt = ['csv'];
                $fileExtension = pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION);
                if (!in_array(strtolower($fileExtension), $allowedExt)) {
                    $message = "Invalid file extension. Only CSV files are allowed.";
                } else {
                    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
                    $file = fopen($fileTmpPath, 'r');
                    if ($file !== false) {
                        $importedCount = 0;
                        while (($line = fgetcsv($file)) !== false) {
                            $num = trim($line[0]);
                            if ($num != '') {
                                if ($num[0] !== '+') {
                                    $num = '+' . $num;
                                }
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO allowed_numbers (phone_number, status, by_user) VALUES (?, 'fresh', ?)");
                                    $stmt->execute([$num, $session_id]);
                                    $importedCount++;
                                } catch (PDOException $e) {
                                    // Optionally log error
                                }
                            }
                        }
                        fclose($file);
                        $message = "Imported $importedCount numbers from CSV file.";
                    } else {
                        $message = "Error opening CSV file.";
                    }
                }
            } else {
                $message = "Error uploading CSV file.";
            }
        } elseif ($importType == 'excel') {
            if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == UPLOAD_ERR_OK) {
                $allowedExt = ['xls', 'xlsx'];
                $fileExtension = pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION);
                if (!in_array(strtolower($fileExtension), $allowedExt)) {
                    $message = "Invalid file extension. Only Excel files are allowed.";
                } else {
                    $fileTmpPath = $_FILES['excel_file']['tmp_name'];
                    // Load PhpSpreadsheet library (ensure it's installed via Composer)
                    require 'vendor/autoload.php';
                    try {
                        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmpPath);
                        $worksheet = $spreadsheet->getActiveSheet();
                        $importedCount = 0;
                        foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
                            // Skip header row (assuming first row is header)
                            if ($rowIndex == 1) continue;
                            // Assuming the Excel columns structure:
                            // A: Numbers (without country code)
                            // B: Range (ignored)
                            // C: Number (with country code)
                            // We'll use the "Number" column (C)
                            $cellValue = $worksheet->getCell('C' . $row->getRowIndex())->getValue();
                            $num = trim($cellValue);
                            if ($num != '') {
                                if ($num[0] !== '+') {
                                    $num = '+' . $num;
                                }
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO allowed_numbers (phone_number, status, by_user) VALUES (?, 'fresh', ?)");
                                    $stmt->execute([$num, $session_id]);
                                    $importedCount++;
                                } catch (PDOException $e) {
                                    // Optionally log error
                                }
                            }
                        }
                        $message = "Imported $importedCount numbers from Excel file.";
                    } catch (Exception $e) {
                        $message = "Error processing Excel file: " . $e->getMessage();
                    }
                }
            } else {
                $message = "Error uploading Excel file.";
            }
        } else {
            $message = "Invalid import type selected.";
        }
    }
    // Delete a number
    elseif (isset($_POST['delete_number'])) {
        $id = $_POST['delete_number'];
        try {
            $stmt = $pdo->prepare("DELETE FROM allowed_numbers WHERE id = ? AND by_user = ?");
            $stmt->execute([$id, $session_id]);
            $message = "Number deleted successfully.";
        } catch (PDOException $e) {
            $message = "Error deleting number: " . $e->getMessage();
        }
    }
    // Mark a number as used (update status and last_used timestamp)
    elseif (isset($_POST['sed'])) {
        $id = $_POST['sed'];
        try {
            $stmt = $pdo->prepare("UPDATE allowed_numbers SET status = 'used', last_used = NOW() WHERE id = ? AND by_user = ?");
            $stmt->execute([$id, $session_id]);
            $message = "Number marked as used successfully.";
        } catch (PDOException $e) {
            $message = "Error updating number: " . $e->getMessage();
        }
    }
}

// Fetch Stats for Current User
try {
    // Fresh numbers count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'fresh' AND by_user = ?");
    $stmt->execute([$session_id]);
    $freshCount = $stmt->fetchColumn();

    // Used numbers count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'used' AND by_user = ?");
    $stmt->execute([$session_id]);
    $usedNumbers = $stmt->fetchColumn();

    // Total numbers count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE by_user = ?");
    $stmt->execute([$session_id]);
    $totalNumbers = $stmt->fetchColumn();

    // All Time OTPs Sent (sum of time_used)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(time_used), 0) FROM allowed_numbers WHERE by_user = ?");
    $stmt->execute([$session_id]);
    $allTimeOTPs = $stmt->fetchColumn();

    // OTPs Sent Today (using last_used)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(time_used), 0) FROM allowed_numbers WHERE by_user = ? AND DATE(last_used) = CURDATE()");
    $stmt->execute([$session_id]);
    $todayOTPs = $stmt->fetchColumn();

    // OTPs Sent This Week (using YEARWEEK with mode 1)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(time_used), 0) FROM allowed_numbers WHERE by_user = ? AND YEARWEEK(last_used, 1) = YEARWEEK(CURDATE(), 1)");
    $stmt->execute([$session_id]);
    $weekOTPs = $stmt->fetchColumn();
} catch (PDOException $e) {
    $message = "Error fetching stats: " . $e->getMessage();
}
$global_id= NULL;
// Fetch All Numbers for Current User
$stmt = $pdo->prepare("SELECT * FROM allowed_numbers WHERE by_user = ? ORDER BY id DESC");
$stmt->execute([$global_id]);
$numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Current date/time for display
$currentDateTime = date("l, F j, Y, g:i A");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Global Numbers</title>
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- DataTables CSS for pagination -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css">
  <style>
    body { background-color: #f8f9fa; }
    .container { margin-top: 30px; }
    /* Stats Boxes */
    .stats-box {
      padding: 20px; 
      border-radius: 8px; 
      color: #fff; 
      text-align: center;
      margin-bottom: 20px;
    }
    .stats-box h4 { margin-bottom: 0; }
    .stats-fresh { background-color: #28a745; }
    .stats-used { background-color: #dc3545; }
    .stats-total { background-color: #17a2b8; }
    .stats-today { background-color: #ffc107; color: #000; }
    .stats-week { background-color: #6f42c1; }
    .stats-all { background-color: #343a40; }
    /* Collapsible Form Section */
    .collapse-header {
      cursor: pointer;
      background-color: #007bff;
      color: #fff;
      padding: 10px 15px;
      border-radius: 4px;
      margin-bottom: 15px;
    }
    .collapse-header:hover {
      background-color: #0056b3;
    }
  </style>
</head>
<body>
  <div class="container">
    <h2 class="mb-4">Manage Globally Allowed Numbers</h2>
    <p><?php echo $currentDateTime; ?></p>
    <?php if ($message): ?>
      <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- STATS BOXES in one row -->
    <div class="row">
      <div class="col-md-2">
        <div class="stats-box stats-fresh">
          <h4><?php echo $freshCount; ?></h4>
          <p>Fresh</p>
        </div>
      </div>
      <div class="col-md-2">
        <div class="stats-box stats-used">
          <h4><?php echo $usedNumbers; ?></h4>
          <p>Used</p>
        </div>
      </div>
      <div class="col-md-2">
        <div class="stats-box stats-total">
          <h4><?php echo $totalNumbers; ?></h4>
          <p>Total</p>
        </div>
      </div>
      <div class="col-md-2">
        <div class="stats-box stats-today">
          <h4><?php echo $todayOTPs; ?></h4>
          <p>OTPs Today</p>
        </div>
      </div>
      <div class="col-md-2">
        <div class="stats-box stats-week">
          <h4><?php echo $weekOTPs; ?></h4>
          <p>OTPs This Week</p>
        </div>
      </div>
      <div class="col-md-2">
        <div class="stats-box stats-all">
          <h4><?php echo $allTimeOTPs; ?></h4>
          <p>All Time OTPs</p>
        </div>
      </div>
    </div>

    <!-- Collapsible Bulk Import Form Section -->
    <div class="mb-4">
      <div class="collapse-header" data-toggle="collapse" data-target="#addForm" aria-expanded="false" aria-controls="addForm">
        Click to Show/Hide Bulk Import Form
      </div>
      <div class="collapse" id="addForm">
        <div class="card">
          <div class="card-header">Bulk Import Numbers</div>
          <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
              <div class="form-group">
                <label>Select Import Type:</label>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="import_type" id="import_manual" value="manual" checked>
                  <label class="form-check-label" for="import_manual">Manual Entry</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="import_type" id="import_csv" value="csv">
                  <label class="form-check-label" for="import_csv">CSV File Upload</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="import_type" id="import_excel" value="excel">
                  <label class="form-check-label" for="import_excel">Excel File Upload</label>
                </div>
              </div>
              <div class="form-group" id="manual_input">
                <label for="manual_numbers">Enter numbers (one per line):</label>
                <textarea class="form-control" name="manual_numbers" rows="5" placeholder="+1234567890"></textarea>
              </div>
              <div class="form-group" id="csv_input" style="display: none;">
                <label for="csv_file">Upload CSV File:</label>
                <input type="file" class="form-control-file" name="csv_file" accept=".csv">
              </div>
              <div class="form-group" id="excel_input" style="display: none;">
                <label for="excel_file">Upload Excel File:</label>
                <input type="file" class="form-control-file" name="excel_file" accept=".xls,.xlsx">
              </div>
              <button type="submit" name="import_numbers" class="btn btn-success">Import Numbers</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Table Displaying All Numbers with Pagination (DataTables) -->
    <table id="numbersTable" class="table table-bordered">
      <thead>
        <tr>
          <th>ID</th>
          <th>Phone Number</th>
          <th>Status</th>
          <th>Time Used</th>
          <th>Last Used</th>
          <th>Created At</th>
          <th>By User</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($numbers)): ?>
          <?php foreach ($numbers as $number): ?>
            <tr>
              <td><?php echo $number['id']; ?></td>
              <td><?php echo htmlspecialchars($number['phone_number']); ?></td>
              <td><?php echo htmlspecialchars($number['status']); ?></td>
              <td><?php echo htmlspecialchars($number['time_used']); ?></td>
              <td><?php echo htmlspecialchars($number['last_used']); ?></td>
              <td><?php echo htmlspecialchars($number['created_at']); ?></td>
              <td><?php echo htmlspecialchars($number['by_user']); ?></td>
              <td>
                <form method="POST" style="display:inline;">
                  <button type="submit" name="delete_number" value="<?php echo $number['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this number?');" disabled>Delete</button>
                </form>
                <?php if ($number['status'] !== 'used'): ?>
                  <form method="POST" style="display:inline;">
                    <button type="submit" name="sed" value="<?php echo $number['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Mark this number as used?');"  disabled>Used</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="8" class="text-center">No numbers found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- jQuery, Bootstrap, and DataTables JS -->
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
  <script>
    $(document).ready(function() {
        $('#numbersTable').DataTable();
        $('input[name="import_type"]').change(function() {
            var type = $(this).val();
            if (type === 'manual') {
              $('#manual_input').show();
              $('#csv_input').hide();
              $('#excel_input').hide();
            } else if (type === 'csv') {
              $('#manual_input').hide();
              $('#csv_input').show();
              $('#excel_input').hide();
            } else if (type === 'excel') {
              $('#manual_input').hide();
              $('#csv_input').hide();
              $('#excel_input').show();
            }
        });
    });
  </script>
</body>
</html>
