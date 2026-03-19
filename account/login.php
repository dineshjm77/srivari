<?php
// finance/login.php
session_start();
// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['username'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: index.php');
    } else {
        header('Location: staff/index.php');
    }
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    include 'includes/db.php'; // Resolves to finance/includes/db.php
   
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
   
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $sql = "SELECT id, username, full_name, role FROM users WHERE username = ? AND password = ? AND status = 'active'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $username, $password); // Note: In production, hash passwords!
        $stmt->execute();
        $result = $stmt->get_result();
       
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
           
            // Update last_login
            $update_sql = "UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param('i', $user['id']);
            $update_stmt->execute();
           
            // Set session variables
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_id'] = $user['id'];
           
            mysqli_close($conn);
           
            // Redirect based on role → admin → index.php, everyone else (including staff with empty role) → staff/index.php
            if ($user['role'] === 'admin') {
                header('Location: index.php');
            } else {
                header('Location: staff/index.php');
            }
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
        $stmt->close();
    }
    mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Finance Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px 0;
        }
        .login-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
        }
        .login-logo {
            width: 200px; /* Base width for logo */
            height: auto;
            max-width: 100%;
            margin: 0 auto 20px auto;
            display: block;
        }
        @media (max-width: 575.98px) {
            .login-logo {
                width: 150px; /* Smaller on mobile */
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card login-container shadow">
                    <div class="card-body p-4 p-md-5">
                        <!-- Company Logo -->
                        <img src="assets/images/selva.jpeg" alt="Company Logo" class="login-logo rounded mx-auto d-block">
                       
                        <h3 class="card-title text-center mb-4">Login</h3>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>