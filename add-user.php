<?php
// add-user.php - Add/Edit User Page
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Check login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

// Only admin can access
if ($_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

include 'includes/db.php';

$edit_mode = isset($_GET['edit']) && is_numeric($_GET['edit']);
$user_id = $edit_mode ? intval($_GET['edit']) : 0;
$user_data = [];

if ($edit_mode) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
    } else {
        $_SESSION['error'] = "User not found.";
        header("Location: users.php");
        exit;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'staff');
    $status = trim($_POST['status'] ?? 'active');
    
    // For new user, password is required
    if (!$edit_mode) {
        $password = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');
    }

    // Validation
    $errors = [];
    
    if (empty($username)) $errors[] = "Username is required.";
    if (empty($full_name)) $errors[] = "Full Name is required.";
    if (!in_array($role, ['admin', 'staff', 'accountant'])) $errors[] = "Invalid role selected.";
    if (!in_array($status, ['active', 'inactive'])) $errors[] = "Invalid status selected.";
    
    if (!$edit_mode) {
        if (empty($password)) $errors[] = "Password is required.";
        if ($password !== $confirm_password) $errors[] = "Passwords do not match.";
        if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
    }

    // Check if username already exists
    if ($edit_mode) {
        // For edit mode: check if username exists for OTHER users
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check_stmt->bind_param("si", $username, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $errors[] = "Username already exists. Please choose a different username.";
        }
        $check_stmt->close();
    } else {
        // For new user: check if username exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $errors[] = "Username already exists. Please choose a different username.";
        }
        $check_stmt->close();
    }

    // Check if email is valid
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (!empty($errors)) {
        $_SESSION['error'] = "• " . implode("<br>• ", $errors);
        header("Location: add-user.php" . ($edit_mode ? "?edit=$user_id" : ""));
        exit;
    }

    // Save or update user
    if ($edit_mode) {
        // Update user
        if (!empty($_POST['password'])) {
            // Update with password
            $password = trim($_POST['password']);
            $confirm_password = trim($_POST['confirm_password']);
            
            if ($password !== $confirm_password) {
                $_SESSION['error'] = "Passwords do not match.";
                header("Location: add-user.php?edit=$user_id");
                exit;
            }
            
            $sql = "UPDATE users SET username = ?, password = ?, email = ?, full_name = ?, role = ?, status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssi", $username, $password, $email, $full_name, $role, $status, $user_id);
        } else {
            // Update without password
            $sql = "UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", $username, $email, $full_name, $role, $status, $user_id);
        }
    } else {
        // Insert new user
        $sql = "INSERT INTO users (username, password, email, full_name, role, status) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $username, $password, $email, $full_name, $role, $status);
    }
    
    if ($stmt->execute()) {
        $_SESSION['success'] = $edit_mode ? "User updated successfully!" : "User added successfully!";
        header("Location: users.php");
        exit;
    } else {
        $_SESSION['error'] = "Database error: " . $stmt->error;
        header("Location: add-user.php" . ($edit_mode ? "?edit=$user_id" : ""));
        exit;
    }
    $stmt->close();
}

// Get all user roles
$user_roles = ['admin', 'staff', 'accountant'];
$status_options = ['active', 'inactive'];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<body>
    <?php include 'includes/topbar.php'; ?>
    <div class="startbar d-print-none">
        <?php include 'includes/leftbar-tab-menu.php'; ?>
        <?php include 'includes/leftbar.php'; ?>
        <div class="startbar-overlay d-print-none"></div>
    </div>
    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-fluid">
                <?php
                $page_title = $edit_mode ? "Edit User" : "Add New User";
                $breadcrumb_active = $page_title;
                include 'includes/breadcrumb.php';
                ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row align-items-center mb-3">
                    <div class="col">
                        <h3 class="mb-0"><?= $edit_mode ? 'Edit User' : 'Add New User'; ?></h3>
                        <small class="text-muted"><?= $edit_mode ? 'Update user information' : 'Create new system user'; ?></small>
                    </div>
                    <div class="col-auto">
                        <a href="users.php" class="btn btn-light">Back to Users</a>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">User Information</h4>
                    </div>
                    <div class="card-body">
                        <form id="userForm" action="add-user.php<?= $edit_mode ? '?edit=' . $user_id : ''; ?>" method="POST" novalidate>
                            <div class="row g-3">
                                <!-- Basic Information -->
                                <div class="col-md-6">
                                    <h5 class="mb-3">Basic Information</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Username <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="username" 
                                               value="<?= $edit_mode ? htmlspecialchars($user_data['username']) : (isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''); ?>" 
                                               required>
                                        <small class="text-muted"><?= $edit_mode ? 'Username can be changed, but must remain unique.' : 'Choose a unique username.'; ?></small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="full_name" 
                                               value="<?= $edit_mode ? htmlspecialchars($user_data['full_name']) : (isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''); ?>" 
                                               required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-control" name="email" 
                                               value="<?= $edit_mode ? htmlspecialchars($user_data['email']) : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''); ?>">
                                        <small class="text-muted">Optional email address for notifications</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5 class="mb-3">Account Settings</h5>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">User Role <span class="text-danger">*</span></label>
                                        <select class="form-control" name="role" required>
                                            <option value="">-- Select Role --</option>
                                            <?php foreach ($user_roles as $role): ?>
                                                <option value="<?= $role; ?>" 
                                                    <?= ($edit_mode && $user_data['role'] == $role) || (isset($_POST['role']) && $_POST['role'] == $role) ? 'selected' : ''; ?>>
                                                    <?= ucfirst($role); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Account Status <span class="text-danger">*</span></label>
                                        <select class="form-control" name="status" required>
                                            <option value="">-- Select Status --</option>
                                            <?php foreach ($status_options as $status): ?>
                                                <option value="<?= $status; ?>" 
                                                    <?= ($edit_mode && $user_data['status'] == $status) || (isset($_POST['status']) && $_POST['status'] == $status) ? 'selected' : ''; ?>>
                                                    <?= ucfirst($status); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label"><?= $edit_mode ? 'New Password (leave blank to keep current)' : 'Password'; ?> <?= !$edit_mode ? '<span class="text-danger">*</span>' : ''; ?></label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="password" 
                                                   id="password" <?= !$edit_mode ? 'required' : ''; ?>
                                                   minlength="6">
                                            <button type="button" class="btn btn-outline-secondary password-toggle" data-target="password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Confirm Password <?= !$edit_mode ? '<span class="text-danger">*</span>' : ''; ?></label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="confirm_password" 
                                                   id="confirm_password" <?= !$edit_mode ? 'required' : ''; ?>
                                                   minlength="6">
                                            <button type="button" class="btn btn-outline-secondary password-toggle" data-target="confirm_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Role Descriptions -->
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <h6 class="alert-heading mb-2">Role Permissions:</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Administrator:</strong>
                                                <ul class="mb-0">
                                                    <li>Full system access</li>
                                                    <li>Manage all users</li>
                                                    <li>View all reports</li>
                                                    <li>System configuration</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Staff:</strong>
                                                <ul class="mb-0">
                                                    <li>Member management</li>
                                                    <li>Collection processing</li>
                                                    <li>Basic reporting</li>
                                                    <li>No user management</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Accountant:</strong>
                                                <ul class="mb-0">
                                                    <li>Financial reports</li>
                                                    <li>Expense management</li>
                                                    <li>Payment tracking</li>
                                                    <li>No member management</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12 pt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>
                                        <?= $edit_mode ? 'Update User' : 'Save User'; ?>
                                    </button>
                                    <a href="users.php" class="btn btn-light">Cancel</a>
                                    
                                    <?php if ($edit_mode && $user_id != $_SESSION['user_id']): ?>
                                        <button type="button" class="btn btn-danger float-end" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                            <i class="fas fa-trash me-1"></i>Delete User
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
    
    <?php include 'includes/scripts.php'; ?>
    
    <!-- Delete Confirmation Modal -->
    <?php if ($edit_mode && $user_id != $_SESSION['user_id']): ?>
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete user <strong><?= htmlspecialchars($user_data['full_name']); ?></strong>?</p>
                    <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="delete-user.php?id=<?= $user_id; ?>" class="btn btn-danger">Delete User</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // Password validation
        document.getElementById('userForm')?.addEventListener('submit', function(e) {
            const password = document.querySelector('input[name="password"]');
            const confirmPassword = document.querySelector('input[name="confirm_password"]');
            const isEditMode = <?= $edit_mode ? 'true' : 'false'; ?>;
            
            // For new user, password is required
            if (!isEditMode) {
                if (password.value.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters long.');
                    password.focus();
                    return false;
                }
            }
            
            // For edit mode, if password field is filled, validate it
            if (isEditMode && password.value !== '') {
                if (password.value.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters long.');
                    password.focus();
                    return false;
                }
            }
            
            if (password.value !== confirmPassword.value) {
                e.preventDefault();
                alert('Passwords do not match.');
                confirmPassword.focus();
                return false;
            }
        });
        
        // Show password toggle
        document.querySelectorAll('.password-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('userForm');
            if (form) {
                form.addEventListener('submit', function() {
                    // Clear any previous error styles
                    document.querySelectorAll('.is-invalid').forEach(el => {
                        el.classList.remove('is-invalid');
                    });
                    
                    // Validate required fields
                    let isValid = true;
                    const requiredFields = form.querySelectorAll('[required]');
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        }
                    });
                    
                    if (!isValid) {
                        alert('Please fill in all required fields marked with *.');
                        return false;
                    }
                    
                    return true;
                });
            }
        });
    </script>
    
    <style>
        .is-invalid {
            border-color: #dc3545;
        }
        .password-toggle {
            cursor: pointer;
        }
    </style>
</body>
</html>