<style>
        /* Navbar container */
        .navbar {
            background-color: #333;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Navbar links container */
        .navbar-links {
            display: flex;
        }

        /* Individual navbar link */
        .navbar-link {
            color: white;
            padding: 14px 20px;
            text-decoration: none;
            text-align: center;
            transition: background-color 0.3s;
        }

        /* On hover, change the background color */
        .navbar-link:hover {
            background-color: #575757;
        }
    </style>

    <!-- Navbar container -->
    <div class="navbar">
        <!-- Navbar links -->
        <div class="navbar-links">
            <a href="./create_child_account.php"><div class="navbar-link">MakeChild</div></a>
            <a href="./index.php"><div class="navbar-link">Launch RDPs</div></a>
        </div>
    </div>
