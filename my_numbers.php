<?php
include "./session.php";  // Must start the session and set $_SESSION['user_id']
include "./header.php";   // Your header file
require 'db.php';

session_start();
$session_id = $_SESSION['user_id'];
$message = '';
// Retrieve the record ID from GET for display and form
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  die('No valid ID provided.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Retrieve atm_left from POST
  $atm_left = isset($_POST['atm_left']) ? (int)$_POST['atm_left'] : null;
  $status = 'fresh'; // Fixed per requirement

  if ($atm_left === null) {
    $message = 'Please provide a valid ATM Left value.';
  } else {
    // Prepare and execute the update
    $stmt = $pdo->prepare(
      "UPDATE `allowed_numbers`
             SET `status`   = :status,
                 `atm_left` = :atm_left
             WHERE `set_id` = :id"
    );
    try {
      $stmt->execute([
        ':status'   => $status,
        ':atm_left' => $atm_left,
        ':id'       => $id,
      ]);
      $rows = $stmt->rowCount();
      if ($rows > 0) {
        $message = "Record (ID: $id) updated successfully! ATM Left set to $atm_left.";
      } else {
        $message = "No changes made. Either the record doesn't exist or ATM Left was already $atm_left.";
      }
    } catch (PDOException $e) {
      $message = 'Error updating record: ' . htmlspecialchars($e->getMessage());
    }
  }

  // Redirect to avoid form resubmission and ensure GET parameter persists
  header("Location: " . $_SERVER['PHP_SELF'] . "?id=$id&msg=" . urlencode($message));
  exit;
}

// Capture message from GET after redirect
if (isset($_GET['msg'])) {
  $message = $_GET['msg'];
}
// Determine if a set id is provided in the URL
$selected_set_id = (isset($_GET['id']) && !empty($_GET['id'])) ? trim($_GET['id']) : null;

// Process Form Submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  // Bulk Import Numbers - Only Manual Entry is allowed now
  if (isset($_POST['import_numbers'])) {
    $manual = trim($_POST['manual_numbers']);
    $set_id = trim($_POST['set_id']);  // Get the selected set from the dropdown
    if ($manual != '' && $set_id != '') {
      $lines = explode("\n", $manual);
      $importedCount = 0;
      $skippedCount = 0;
      foreach ($lines as $line) {
        $num = trim($line);
        if ($num != '') {
          if ($num[0] !== '+') {
            $num = '+' . $num;
          }
          // Check if the number already exists for this user
          $stmtCheck = $pdo->prepare("SELECT id FROM allowed_numbers WHERE phone_number = ? AND by_user = ?");
          $stmtCheck->execute([$num, $session_id]);
          if ($stmtCheck->rowCount() > 0) {
            $skippedCount++;
            continue;  // Skip duplicate
          }
          try {
            // Get current timestamp in Pakistan timezone
            $created_at = (new DateTime('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d H:i:s');
            // Insert new number with atm_left = 10, include set_id and the created_at timestamp
            $stmt = $pdo->prepare("INSERT INTO allowed_numbers (phone_number, status, atm_left, by_user, set_id, created_at) VALUES (?, 'fresh', 10, ?, ?, ?)");
            $stmt->execute([$num, $session_id, $set_id, $created_at]);
            $importedCount++;
          } catch (PDOException $e) {
            $skippedCount++;
          }
        }
      }
      $message = "Imported $importedCount numbers from manual input.";
      if ($skippedCount > 0) {
        $message .= " Skipped $skippedCount duplicate numbers.";
      }
    } else {
      $message = "Please provide numbers and select a set.";
    }
  }
  // Delete a single number
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
  // Bulk Delete All Numbers
  elseif (isset($_POST['delete_all'])) {
    try {
      $stmt = $pdo->prepare("DELETE FROM allowed_numbers WHERE by_user = ? AND set_id = ?");
      $stmt->execute([$session_id, $selected_set_id]);
      $deletedCount = $stmt->rowCount();
      $message = "Bulk deleted $deletedCount numbers successfully.";
    } catch (PDOException $e) {
      $message = "Error in bulk deleting numbers: " . $e->getMessage();
    }
  }

  // Send OTP (mark number used) and decrement atm_left by 1
  elseif (isset($_POST['send_otp'])) {
    $id = $_POST['send_otp'];
    try {
      // Fetch current allowed number details
      $stmt = $pdo->prepare("SELECT atm_left, phone_number FROM allowed_numbers WHERE id = ? AND by_user = ?");
      $stmt->execute([$id, $session_id]);
      $numberData = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($numberData) {
        if ($numberData['atm_left'] > 0) {
          $new_atm_left = $numberData['atm_left'] - 1;
          $new_status = ($new_atm_left == 0) ? 'used' : 'fresh';
          // Update allowed number: decrement atm_left, update last_used and status if needed
          $stmt = $pdo->prepare("UPDATE allowed_numbers SET atm_left = ?, last_used = NOW(), status = ? WHERE id = ? AND by_user = ?");
          $stmt->execute([$new_atm_left, $new_status, $id, $session_id]);
          $message = "OTP sent successfully. Remaining attempts updated.";
        } else {
          $message = "This number has no OTP attempts remaining.";
        }
      } else {
        $message = "Invalid number.";
      }
    } catch (PDOException $e) {
      $message = "Error sending OTP: " . $e->getMessage();
    }
  }
}

// Fetch Stats for Current User
try {
  if ($selected_set_id) {
    // Filter stats for the specific set_id
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'fresh' AND by_user = ? AND set_id = ?");
    $stmt->execute([$session_id, $selected_set_id]);
    $freshCount = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'used' AND by_user = ? AND set_id = ?");
    $stmt->execute([$session_id, $selected_set_id]);
    $usedNumbers = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE by_user = ? AND set_id = ?");
    $stmt->execute([$session_id, $selected_set_id]);
    $totalNumbers = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT SUM(10 - atm_left) FROM allowed_numbers WHERE by_user = ? AND DATE(last_used) = CURDATE() AND set_id = ?");
    $stmt->execute([$session_id, $selected_set_id]);
    $todayOTPs = $stmt->fetchColumn();

    // New box: Total ATM Left for the set
    $stmt = $pdo->prepare("SELECT SUM(atm_left) FROM allowed_numbers WHERE by_user = ? AND set_id = ?");
    $stmt->execute([$session_id, $selected_set_id]);
    $totalAtmLeft = $stmt->fetchColumn();
  } else {
    // Global stats for all numbers for the current user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'fresh' AND by_user = ?");
    $stmt->execute([$session_id]);
    $freshCount = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE status = 'used' AND by_user = ?");
    $stmt->execute([$session_id]);
    $usedNumbers = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM allowed_numbers WHERE by_user = ?");
    $stmt->execute([$session_id]);
    $totalNumbers = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT SUM(10 - atm_left) FROM allowed_numbers WHERE by_user = ? AND DATE(last_used) = CURDATE()");
    $stmt->execute([$session_id]);
    $todayOTPs = $stmt->fetchColumn();

    // New box: Total ATM Left for all numbers
    $stmt = $pdo->prepare("SELECT SUM(atm_left) FROM allowed_numbers WHERE by_user = ?");
    $stmt->execute([$session_id]);
    $totalAtmLeft = $stmt->fetchColumn();
  }
  if (!$todayOTPs) {
    $todayOTPs = 0;
  }
  if (!$totalAtmLeft) {
    $totalAtmLeft = 0;
  }
} catch (PDOException $e) {
  $message = "Error fetching stats: " . $e->getMessage();
}

// Fetch Numbers:
// If a set id is provided in URL, fetch numbers for that set; otherwise, fetch all numbers for the current user.
if ($selected_set_id) {
  $stmt = $pdo->prepare("SELECT an.*, bs.set_name FROM allowed_numbers an LEFT JOIN bulk_sets bs ON an.set_id = bs.id WHERE an.set_id = ? AND an.by_user = ? ORDER BY an.id DESC");
  $stmt->execute([$selected_set_id, $session_id]);
} else {
  $stmt = $pdo->prepare("SELECT an.*, bs.set_name FROM allowed_numbers an LEFT JOIN bulk_sets bs ON an.set_id = bs.id WHERE an.by_user = ? ORDER BY an.id DESC");
  $stmt->execute([$session_id]);
}
$numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Current date/time for display
$currentDateTime = date("l, F j, Y, g:i A");
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>My Numbers</title>
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- DataTables CSS for pagination -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css">
  <style>
    body {
      background-color: #f8f9fa;
    }

    .container {
      margin-top: 30px;
    }

    /* Stats Boxes */
    .stats-box {
      padding: 20px;
      border-radius: 8px;
      color: #fff;
      text-align: center;
      margin-bottom: 20px;
    }

    .stats-box h4 {
      margin-bottom: 0;
    }

    .stats-fresh {
      background-color: #28a745;
    }

    .stats-used {
      background-color: #dc3545;
    }

    .stats-total {
      background-color: #17a2b8;
    }

    .stats-today {
      background-color: #ffc107;
      color: #000;
    }

    .stats-atmleft {
      background-color: #6f42c1;
    }

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
    <h2 class="mb-4">Manage Numbers</h2>
    <p><?php echo $currentDateTime; ?></p>
    <?php if ($message): ?>
      <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- STATS BOXES (5 boxes: Fresh, Used, Total, OTPs Today, Total ATM Left) -->
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
        <div class="stats-box stats-atmleft">
          <h4><?php echo $totalAtmLeft; ?></h4>
          <p>Total ATM Left</p>
        </div>
      </div>
    </div>
    <div class="container mt-4">
      <?php if ($message): ?>
        <div class="alert alert-info" role="alert">
          <?php echo htmlspecialchars($message); ?>
        </div>
      <?php endif; ?>

      <form class="form-inline" method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $id); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <label class="sr-only" for="atm_left">ATM Left</label>
        <input type="number" class="form-control mb-2 mr-sm-2" id="atm_left" name="atm_left"
          placeholder="ATM Left" required>

        <button type="submit" class="btn btn-primary mb-2">Update</button>
      </form>
    </div>

    <!-- Collapsible Bulk Import Form Section (Only Manual Entry) -->
    <div class="mb-4">
      <div class="collapse-header" data-toggle="collapse" data-target="#addForm" aria-expanded="false" aria-controls="addForm">
        Click to Show/Hide Bulk Import Form
      </div>
      <div class="collapse" id="addForm">
        <div class="card">
          <div class="card-header">Bulk Import Numbers (Manual Entry Only)</div>
          <div class="card-body">
            <form method="POST">
              <!-- Dropdown to select a set -->
              <div class="form-group">
                <label for="set_id">Select Set:</label>
                <select name="set_id" id="set_id" class="form-control" style="width:auto; white-space: normal;">
                  <option value="">Select a Set</option>
                  <?php
                  // Fetch available sets from bulk_sets table
                  $stmtSets = $pdo->query("SELECT id, set_name FROM bulk_sets ORDER BY set_name ASC");
                  while ($set = $stmtSets->fetch(PDO::FETCH_ASSOC)) {
                    echo '<option value="' . htmlspecialchars($set['id']) . '">' . htmlspecialchars($set['set_name']) . '</option>';
                  }
                  ?>
                </select>

              </div>
              <div class="form-group">
                <label for="manual_numbers">Enter numbers (one per line):</label>
                <textarea class="form-control" name="manual_numbers" rows="5" placeholder="+1234567890"></textarea>
              </div>
              <button type="submit" name="import_numbers" class="btn btn-success">Import Numbers</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Bulk Delete All Numbers Button -->
    <form method="POST" onsubmit="return confirm('Are you sure you want to bulk delete all numbers?');" class="mb-3">
      <button type="submit" name="delete_all" class="btn btn-danger">Bulk Delete All Numbers</button>
    </form>

    <!-- Table Displaying All Numbers with Pagination (DataTables) -->
    <table id="numbersTable" class="table table-bordered">
      <thead>
        <tr>
          <th>ID</th>
          <th>Phone Number</th>
          <th>Status</th>
          <th>ATM Left</th>
          <th>Last Used</th>
          <th>Created At</th>
          <th>Set Name</th>
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
              <td><?php echo htmlspecialchars($number['atm_left']); ?></td>
              <td><?php echo htmlspecialchars($number['last_used']); ?></td>
              <td><?php echo htmlspecialchars($number['created_at']); ?></td>
              <td><?php echo htmlspecialchars($number['set_name']); ?></td>
              <td>
                <form method="POST" style="display:inline;">
                  <button type="submit" name="delete_number" value="<?php echo $number['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this number?');">Delete</button>
                </form>
                <?php if ($number['status'] !== 'used' && $number['atm_left'] > 0): ?>
                  <form method="POST" style="display:inline;">
                    <button type="submit" name="send_otp" value="<?php echo $number['id']; ?>" class="btn btn-primary btn-sm" onclick="return confirm('Send OTP to this number?');">Send OTP</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="8" class="text-center">No numbers found.</td>
          </tr>
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
    });
  </script>
</body>

</html>