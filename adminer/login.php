<?php
session_start();
include "../db.php";  // Adjust this path if your db.php file is located elsewhere

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Retrieve credentials from POST
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Use a PDO prepared statement to avoid SQL injection
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = :username AND password = :password");
    $stmt->execute([':username' => $username, ':password' => $password]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $_SESSION['username'] = $username;
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Roboto', sans-serif;
      background: linear-gradient(135deg, #71b7e6, #9b59b6);
      margin: 0;
      height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .container {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 8px 16px rgba(0,0,0,0.3);
      width: 350px;
      padding: 40px;
      text-align: center;
    }
    .container h2 {
      margin-bottom: 20px;
      color: #333;
      font-weight: 300;
    }
    .container input {
      width: 100%;
      padding: 12px;
      margin: 8px 0;
      border: 1px solid #ddd;
      border-radius: 4px;
      box-sizing: border-box;
    }
    .container button {
      width: 100%;
      padding: 12px;
      background: #27ae60;
      border: none;
      border-radius: 4px;
      color: #fff;
      font-size: 16px;
      cursor: pointer;
      transition: background 0.3s ease;
    }
    .container button:hover {
      background: #219150;
    }
    .error-message {
      margin-bottom: 10px;
      color: #e74c3c;
      font-weight: 300;
    }
  </style>
</head>
<body>
<div class="container">
  <h2>Admin Login</h2>
  <?php if ($error != ''): ?>
    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <form action="login.php" method="post">
    <input type="text" name="username" id="username" placeholder="Username" required>
    <input type="password" name="password" id="password" placeholder="Password" required>
    <button type="submit">Login</button>
  </form>
</div>
</body>
</html>
