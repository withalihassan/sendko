<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../db.php';

// Get and sanitize parent_id (expects integer)
$accountId = filter_input(INPUT_GET, 'parent_id', FILTER_SANITIZE_NUMBER_INT);

// Simple helper to escape output
function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Delete Child Accounts</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
/* Simple, modern alert styles (no external CSS required) */
.container { max-width: 800px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial; padding: 0 16px; }
.alert { border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); position: relative; display: flex; gap: 12px; align-items:flex-start; }
.alert.success { background: linear-gradient(90deg,#e6ffef,#f0fff7); border: 1px solid #b0f0d6; color: #054f2d; }
.alert.error   { background: linear-gradient(90deg,#ffecec,#fff5f5); border: 1px solid #f2b6b6; color: #5a1414; }
.alert.info    { background: linear-gradient(90deg,#eef6ff,#f5fbff); border: 1px solid #cfe7ff; color: #0b3d66; }

.alert .title { font-weight: 700; margin-bottom: 4px; display:block; }
.alert .msg   { margin: 0; line-height:1.4; }

.actions { display:flex; gap:8px; margin-top: 8px; }
.btn {
    display:inline-block;
    padding: 8px 12px;
    border-radius: 8px;
    border: 0;
    cursor: pointer;
    font-weight:600;
    box-shadow: 0 4px 10px rgba(0,0,0,0.06);
}
.btn.primary { background: #0b76ff; color: #fff; }
.btn.ghost   { background: transparent; color: #0b76ff; border: 1px solid rgba(11,118,255,0.12); }
.closeBtn {
    position:absolute;
    right:10px;
    top:10px;
    background:transparent;
    border:0;
    font-size:16px;
    cursor:pointer;
    color:inherit;
}

/* small responsive */
@media (max-width:480px){ .container { margin: 20px 12px; } }
</style>
</head>
<body>
<div class="container">
<?php
if (empty($accountId)) {
    // show immediate error if missing parent_id
    echo '<div class="alert error">
            <button class="closeBtn" onclick="this.parentElement.style.display=\'none\'">&times;</button>
            <div>
              <span class="title">Missing parameter</span>
              <p class="msg">Required parameter <strong>ID</strong> was not provided or is invalid.</p>
            </div>
          </div>
          <div class="actions">
            <button class="btn ghost" onclick="history.back()">Go back</button>
          </div>';
    exit;
}

try {
    // Use prepared statement to avoid SQL injection
    $stmt = $pdo->prepare("DELETE FROM child_accounts WHERE parent_id = :parent_id AND is_in_org = 'No'");
    $executed = $stmt->execute([':parent_id' => $accountId]);
    $deletedRows = $stmt->rowCount();

    if ($executed && $deletedRows > 0) {
        // Success alert
        echo '<div class="alert success" id="alertBox">
                <button class="closeBtn" onclick="document.getElementById(\'alertBox\').style.display=\'none\'">&times;</button>
                <div>
                  <span class="title">Success</span>
                  <p class="msg">Deleted <strong>' . e($deletedRows) . '</strong> child account(s).</p>
                </div>
              </div>';
        // keep original redirect after 1 second
        // echo "<script>setTimeout(function() { window.location.href = 'new_page.php'; }, 1000);</script>";
    } elseif ($executed && $deletedRows === 0) {
        // No rows matched (informational)
        echo '<div class="alert info" id="alertBox">
                <button class="closeBtn" onclick="document.getElementById(\'alertBox\').style.display=\'none\'">&times;</button>
                <div>
                  <span class="title">No records removed</span>
                  <p class="msg">The query ran successfully but no child accounts Found that are ready to delete</p>
                </div>
              </div>';
        // still redirect as before
        // echo "<script>setTimeout(function() { window.location.href = 'new_page.php'; }, 1000);</script>";
    } else {
        // Execution failed for some reason
        $err = $stmt->errorInfo();
        $errMsg = isset($err[2]) ? $err[2] : 'Unknown error';
        echo '<div class="alert error" id="alertBox">
                <button class="closeBtn" onclick="document.getElementById(\'alertBox\').style.display=\'none\'">&times;</button>
                <div>
                  <span class="title">Error deleting child accounts</span>
                  <p class="msg">Database error: ' . e($errMsg) . '</p>
                </div>
              </div>';
    }
} catch (PDOException $ex) {
    // Catch exceptions and show them nicely (you can hide detailed errors in production)
    echo '<div class="alert error" id="alertBox">
            <button class="closeBtn" onclick="document.getElementById(\'alertBox\').style.display=\'none\'">&times;</button>
            <div>
              <span class="title">Exception</span>
              <p class="msg">Exception message: ' . e($ex->getMessage()) . '</p>
            </div>
          </div>';
}
?>

<!-- Action buttons (Back + optional manual link to continue) -->
<div class="actions">
  <!-- <button class="btn ghost" onclick="history.back()">Go back</button>
  <button class="btn primary" onclick="window.location.href='new_page.php'">Continue</button> -->
</div>

</div>
</body>
</html>