<?php
// users.php - List Users Page
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Check login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

// Only admin can access - FIXED THE REDIRECT
if ($_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

include 'includes/db.php';

// Handle search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$sql = "SELECT * FROM users WHERE 1=1";
$params = [];
$types = '';

if (!empty($search)) {
    $sql .= " AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

if (!empty($role_filter)) {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
    $types .= 's';
}

if (!empty($status_filter)) {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$sql .= " ORDER BY created_at DESC";

// Prepare and execute query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);
$total_users = count($users);

// Get unique roles and statuses for filters
$role_result = $conn->query("SELECT DISTINCT role FROM users ORDER BY role");
$roles = [];
while ($row = $role_result->fetch_assoc()) {
    $roles[] = $row['role'];
}

$status_result = $conn->query("SELECT DISTINCT status FROM users ORDER BY status");
$statuses = [];
while ($row = $status_result->fetch_assoc()) {
    $statuses[] = $row['status'];
}
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
                $page_title = "User Management";
                $breadcrumb_active = "Users";
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
                        <h3 class="mb-0">User Management</h3>
                        <small class="text-muted">Manage system users and their permissions</small>
                    </div>
                    <div class="col-auto">
                        <a href="add-user.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i>Add New User
                        </a>
                    </div>
                </div>
                
                <!-- Filters Card -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="users.php" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search" placeholder="Search by name, username or email" 
                                           value="<?= htmlspecialchars($search); ?>">
                                    <button class="btn btn-outline-primary" type="submit">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Role</label>
                                <select class="form-control" name="role">
                                    <option value="">All Roles</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role; ?>" <?= $role_filter == $role ? 'selected' : ''; ?>>
                                            <?= ucfirst($role); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-control" name="status">
                                    <option value="">All Status</option>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?= $status; ?>" <?= $status_filter == $status ? 'selected' : ''; ?>>
                                            <?= ucfirst($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Users List Card -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">System Users (<?= $total_users; ?>)</h4>
                            <div>
                                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                                    <i class="fas fa-print me-1"></i>Print
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($total_users > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>User</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Last Login</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $index => $user): ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-3">
                                                            <div class="avatar-title bg-primary-subtle text-primary rounded-circle fs-16">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?= htmlspecialchars($user['full_name']); ?></h6>
                                                            <small class="text-muted">ID: <?= $user['id']; ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($user['username']); ?></td>
                                                <td>
                                                    <?php if (!empty($user['email'])): ?>
                                                        <a href="mailto:<?= htmlspecialchars($user['email']); ?>" class="text-decoration-none">
                                                            <?= htmlspecialchars($user['email']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $role_badges = [
                                                        'admin' => 'danger',
                                                        'staff' => 'success',
                                                        'accountant' => 'info'
                                                    ];
                                                    $badge_class = $role_badges[$user['role']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?= $badge_class; ?>"><?= ucfirst($user['role']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($user['status'] == 'active'): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($user['last_login']): ?>
                                                        <?= date('d M Y, h:i A', strtotime($user['last_login'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Never</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= date('d M Y', strtotime($user['created_at'])); ?></td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <a href="add-user.php?edit=<?= $user['id']; ?>" class="btn btn-sm btn-outline-primary" 
                                                           title="Edit User">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        
                                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    data-bs-toggle="modal" data-bs-target="#deleteModal<?= $user['id']; ?>"
                                                                    title="Delete User">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                                    title="Cannot delete yourself">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <!-- Delete Modal for each user -->
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <div class="modal fade" id="deleteModal<?= $user['id']; ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Confirm Delete</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <p>Are you sure you want to delete user <strong><?= htmlspecialchars($user['full_name']); ?></strong>?</p>
                                                                    <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <a href="delete-user.php?id=<?= $user['id']; ?>" class="btn btn-danger">Delete User</a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Summary -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="alert alert-light">
                                        <h6 class="alert-heading mb-2">User Summary:</h6>
                                        <?php
                                        $active_users = array_filter($users, fn($u) => $u['status'] == 'active');
                                        $admin_users = array_filter($users, fn($u) => $u['role'] == 'admin');
                                        $staff_users = array_filter($users, fn($u) => $u['role'] == 'staff');
                                        $accountant_users = array_filter($users, fn($u) => $u['role'] == 'accountant');
                                        ?>
                                        <div class="row">
                                            <div class="col-6">
                                                <small>Active Users: <strong><?= count($active_users); ?></strong></small><br>
                                                <small>Inactive Users: <strong><?= $total_users - count($active_users); ?></strong></small>
                                            </div>
                                            <div class="col-6">
                                                <small>Admins: <strong><?= count($admin_users); ?></strong></small><br>
                                                <small>Staff: <strong><?= count($staff_users); ?></strong></small><br>
                                                <small>Accountants: <strong><?= count($accountant_users); ?></strong></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 text-end">
                                    <a href="add-user.php" class="btn btn-primary">
                                        <i class="fas fa-user-plus me-1"></i>Add New User
                                    </a>
                                   
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <div class="avatar-lg mx-auto mb-4">
                                    <div class="avatar-title bg-light text-primary rounded-circle fs-24">
                                        <i class="fas fa-users"></i>
                                    </div>
                                </div>
                                <h5>No Users Found</h5>
                                <p class="text-muted">No users match your search criteria.</p>
                                <a href="users.php" class="btn btn-primary me-2">
                                    <i class="fas fa-sync me-1"></i>Reset Filters
                                </a>
                                <a href="add-user.php" class="btn btn-success">
                                    <i class="fas fa-user-plus me-1"></i>Add First User
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
    
    <?php include 'includes/scripts.php'; ?>
    
    <style>
        .avatar-sm {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar-lg {
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar-title {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
    
    <script>
        // Quick status toggle
        document.querySelectorAll('.status-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const currentStatus = this.getAttribute('data-status');
                const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
                
                if (confirm('Are you sure you want to change the user status?')) {
                    fetch('toggle-user-status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `id=${userId}&status=${newStatus}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
                }
            });
        });
        
        // Search focus
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value === '') {
                searchInput.focus();
            }
        });
    </script>
</body>
</html>