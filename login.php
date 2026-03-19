<?php
// login.php - CHIT FUND SYSTEM - FIXED VERSION
session_start(); // ADD THIS AT THE VERY BEGINNING

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // Redirect based on role
    if ($_SESSION['role'] == 'admin') {
        header('Location: index.php');
        exit;
    } elseif ($_SESSION['role'] == 'staff') {
        header('Location: staff/index.php');
        exit;
    } elseif ($_SESSION['role'] == 'accountant') {
        header('Location: account/index.php');
        exit;
    }
}

// Include database connection
require_once 'includes/db.php';

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        // Query to check user credentials
        $sql = "SELECT id, username, password, full_name, role, status 
                FROM users 
                WHERE username = ? AND status = 'active'";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check password (plain text for demo - use password_hash() in production)
            if ($password === $user['password']) {
                // Update last login
                $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                
                // Debug: Check what's being set
                error_log("Login successful. User: " . $username . ", Role: " . $user['role']);
                
                // Redirect based on role
                if ($user['role'] == 'admin') {
                    header('Location: index.php');
                    exit;
                } elseif ($user['role'] == 'staff') {
                    header('Location: staff/index.php');
                    exit;
                } elseif ($user['role'] == 'accountant') {
                    header('Location: account/index.php');
                    exit;
                } else {
                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = 'Invalid password.';
            }
        } else {
            $error = 'User not found or account is inactive.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <title>Login - Sri Vari Chits Pvt Ltd</title>
    
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
<link rel="manifest" href="assets/fav/site.webmanifest">
    
    <!-- Bootstrap css -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css">
    
    <!-- Icofont css-->
    <link href="assets/css/icofont.min.css" rel="stylesheet" type="text/css">
    
    <!-- App css -->
    <link href="assets/css/style.css" rel="stylesheet" type="text/css">
    
    <style>
        body {
           
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            max-width: 420px;
            width: 100%;
            margin: 0 auto;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(45deg, #2c3e50, #4a6491);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .login-header h2 {
            font-size: 26px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
            margin: 0;
        }
        .login-body {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 25px;
        }
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }
        .form-control {
            height: 48px;
            border-radius: 8px;
            border: 1px solid #e1e5eb;
            padding: 10px 15px;
            font-size: 15px;
            transition: all 0.3s;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            color: white;
            height: 48px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            width: 100%;
            transition: all 0.3s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(102, 126, 234, 0.4);
        }
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
            font-size: 13px;
        }
        .alert {
            border-radius: 8px;
            border: none;
            padding: 12px 15px;
            font-size: 14px;
        }
        .company-logo {
            width: 80px;
            height: 80px;
            margin-bottom: 15px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.2);
        }
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
        }
        .input-group {
            position: relative;
        }
        .input-group .form-control {
            padding-left: 45px;
        }
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 13px;
        }
        .role-badges {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }
        .role-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-admin {
            background: #dc3545;
            color: white;
        }
        .badge-staff {
            background: #198754;
            color: white;
        }
        .badge-accountant {
            background: #0d6efd;
            color: white;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="assets/images/srivari.jpeg" alt="Logo" class="company-logo"> 
                <h2>Sri Vari Chits Pvt Ltd</h2>
                <p>Chit Fund Management System</p>
                <div class="role-badges">
                   
                </div>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="icofont-warning-alt me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="icofont-check-circled me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" autocomplete="off">
                    <div class="form-group">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <i class="icofont-user input-icon"></i>
                            <input type="text" class="form-control" id="username" name="username" 
                                   placeholder="Enter your username" required 
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <i class="icofont-lock input-icon"></i>
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Enter your password" required>
                            <button type="button" id="togglePassword" class="btn btn-link position-absolute end-0 top-50 translate-middle-y me-2" style="z-index: 5;">
                                <i class="icofont-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-login">
                            <i class="icofont-login me-2"></i>Login to Dashboard
                        </button>
                    </div>
                </form>
                
                
                
                <div class="login-footer">
                    <small><i class="icofont-shield-alt me-1"></i>Role-Based Access Control System</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Focus on username field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });
        
        // Show password toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            
            // Toggle eye icon
            if (type === 'text') {
                icon.classList.remove('icofont-eye');
                icon.classList.add('icofont-eye-blocked');
            } else {
                icon.classList.remove('icofont-eye-blocked');
                icon.classList.add('icofont-eye');
            }
        });
    </script>
</body>
</html>