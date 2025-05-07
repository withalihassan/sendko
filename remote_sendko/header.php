<style>
  /* Reset default margins and padding */
  body, ul, li {
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  /* Header container with a vibrant gradient background */
  .header {
      background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
      padding: 15px 30px;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  }

  /* Branding / welcome message */
  .brand {
      font-size: 26px;
      font-weight: bold;
  }

  /* Navigation menu styling */
  .nav ul {
      list-style: none;
      display: flex;
      align-items: center;
  }

  .nav li {
      margin: 0 15px;
  }

  .nav li a {
      text-decoration: none;
      color: #fff;
      font-size: 18px;
      font-weight: 500;
      transition: color 0.3s ease, transform 0.3s ease;
  }

  .nav li a:hover {
      color: #dcdcdc;
      transform: translateY(-2px);
  }

  /* Style for the Logout button */
  .logout-button {
      background: transparent;
      border: 2px solid #fff;
      color: #fff;
      padding: 5px 12px;
      border-radius: 4px;
      font-size: 16px;
      transition: background 0.3s ease, color 0.3s ease;
  }

  .logout-button:hover {
      background: #fff;
      color: #2575fc;
  }
</style>

<header class="header">
  <div class="brand">Welcome, <?php echo htmlspecialchars($username); ?>!</div>
  <nav class="nav">
    <ul>
      <li><a href="index.php">Home</a></li>
      <li><a href="../">Sender</a></li>
      <li><a href="number_dir.php">Numbers Directory</a></li>
      <li><a href="my_numbers.php">My Numbers</a></li>
      <li><a href="./update_numbers.php">Update Numbers</a></li>
      <!-- <li><a href="ac_summary.php">Ac Summary</a></li> -->
      <li><a class="logout-button" href="logout.php">Logout</a></li>
    </ul>
  </nav>
</header>
