<?php
// staff/index.php - Staff Dashboard
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session and check login
session_start();

// Check login - Only staff can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit;
}

include '../includes/db.php';

// Get today's date for filtering
$today = date('Y-m-d');
$current_month = date('m');
$current_year = date('Y');

// Fetch Dashboard Stats for Staff
$total_collected_today = 0;
$today_due_count = 0;
$my_collections_today = 0;

// Get staff member ID
$staff_id = $_SESSION['user_id'];

// Total Collected Today (all staff)
$result = $conn->query("SELECT SUM(emi_amount) AS total FROM emi_schedule WHERE status = 'paid' AND paid_date = '$today'");
if ($row = $result->fetch_assoc()) $total_collected_today = $row['total'] ?? 0;

// Today's Due Count (all unpaid for today)
$result = $conn->query("SELECT COUNT(*) AS count FROM emi_schedule WHERE status = 'unpaid' AND emi_due_date = '$today'");
if ($row = $result->fetch_assoc()) $today_due_count = $row['count'];

// My Collections Today
$result = $conn->query("SELECT COUNT(*) AS count FROM emi_schedule WHERE status = 'paid' AND paid_date = '$today' AND collected_by = $staff_id");
if ($row = $result->fetch_assoc()) $my_collections_today = $row['count'];

// My Total Collections
$result = $conn->query("SELECT SUM(emi_amount) AS total FROM emi_schedule WHERE status = 'paid' AND collected_by = $staff_id");
if ($row = $result->fetch_assoc()) $my_total_collected = $row['total'] ?? 0;

// Recent Collections by this staff (last 5)
$sql_recent = "SELECT m.customer_name, m.customer_number, es.emi_amount, es.paid_date, es.emi_bill_number
               FROM emi_schedule es
               JOIN members m ON es.member_id = m.id
               WHERE es.status = 'paid' AND es.collected_by = $staff_id
               ORDER BY es.paid_date DESC
               LIMIT 5";
$result_recent = $conn->query($sql_recent);
$recent_collections = [];
while ($row = $result_recent->fetch_assoc()) {
    $recent_collections[] = $row;
}

// Get upcoming due dates (next 7 days)
$next_week = date('Y-m-d', strtotime('+7 days'));
$sql_upcoming = "SELECT m.customer_name, m.customer_number, es.emi_amount, es.emi_due_date
                 FROM emi_schedule es
                 JOIN members m ON es.member_id = m.id
                 WHERE es.status = 'unpaid' 
                 AND es.emi_due_date BETWEEN '$today' AND '$next_week'
                 ORDER BY es.emi_due_date ASC
                 LIMIT 10";
$result_upcoming = $conn->query($sql_upcoming);
$upcoming_due = [];
while ($row = $result_upcoming->fetch_assoc()) {
    $upcoming_due[] = $row;
}

// Function to get IST time
function getCurrentIST() {
    date_default_timezone_set('Asia/Kolkata');
    return date('h:i:s A');
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SRI VARI CHITS - Staff Dashboard</title>
    
    <!-- Favicon -->
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    
    <!-- Icons CSS -->
    <link rel="stylesheet" href="../assets/css/icons.min.css">
    
    <!-- App CSS -->
    <link rel="stylesheet" href="../assets/css/app.min.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .staff-card {
            border-radius: 10px;
            transition: all 0.3s;
            border: 1px solid transparent;
            height: 100%;
        }
        .staff-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .staff-icon {
            font-size: 2rem;
            margin-bottom: 15px;
        }
        .quick-action {
            background: white;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            height: 100%;
        }
        .quick-action:hover {
            border-color: #4a6491;
            background-color: #f8f9fa;
        }
        .action-icon-sm {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #4a6491;
        }
        .status-badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        .status-warning {
            background: #fff3cd;
            color: #856404;
        }
        .status-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        .due-soon {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .due-today {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body>
    <!-- Top Bar -->
    <div class="topbar d-print-none">
        <nav class="navbar navbar-expand-lg">
            <div class="container-fluid">
                <!-- Logo -->
                <a class="navbar-brand" href="index.php">
                    <span class="logo-lg">
                        <h4 class="mb-0 text-white">SRI VARI CHITS</h4>
                        <small class="text-white-50">Staff Dashboard</small>
                    </span>
                </a>

                <!-- Topbar Right -->
                <ul class="navbar-nav">
                    <!-- User -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item" href="my-collections.php">
                                <i class="fas fa-chart-line me-2"></i>My Collections
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="../logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Welcome Header -->
                <div class="row align-items-center mb-4">
                    <div class="col">
                        <h3 class="mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?>!</h3>
                        <small class="text-muted">Staff Dashboard - <?= date('d F Y'); ?></small>
                    </div>
                    <div class="col-auto">
                        <div class="alert alert-info py-2 mb-0">
                            <i class="fas fa-clock me-1"></i>
                            <strong>IST:</strong> <span id="current-time"><?php echo getCurrentIST(); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card staff-card border-primary">
                            <div class="card-body text-center">
                                <div class="staff-icon text-primary">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <h5 class="text-muted">Today's Due</h5>
                                <h2 class="text-primary mb-2"><?= $today_due_count; ?></h2>
                                <p class="text-muted mb-0">EMIs due today</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card staff-card border-success">
                            <div class="card-body text-center">
                                <div class="staff-icon text-success">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <h5 class="text-muted">My Collections Today</h5>
                                <h2 class="text-success mb-2"><?= $my_collections_today; ?></h2>
                                <p class="text-muted mb-0">Payments collected</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card staff-card border-info">
                            <div class="card-body text-center">
                                <div class="staff-icon text-info">
                                    <i class="fas fa-money-check-alt"></i>
                                </div>
                                <h5 class="text-muted">My Total Collections</h5>
                                <h2 class="text-info mb-2">₹<?= number_format($my_total_collected, 2); ?></h2>
                                <p class="text-muted mb-0">All time collection</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="card-title mb-3">Quick Actions</h4>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-action" onclick="window.location.href='collection-list.php'">
                            <div class="action-icon-sm">
                                <i class="fas fa-cash-register"></i>
                            </div>
                            <h6>Collect EMI</h6>
                            <p class="text-muted small">Process member payments</p>
                            <span class="status-badge status-warning"><?= $today_due_count; ?> due</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-action" onclick="window.location.href='manage-members.php'">
                            <div class="action-icon-sm">
                                <i class="fas fa-users"></i>
                            </div>
                            <h6>View Members</h6>
                            <p class="text-muted small">Browse all members</p>
                            <span class="status-badge status-info">View only</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-action" onclick="window.location.href='my-collections.php'">
                            <div class="action-icon-sm">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h6>My Reports</h6>
                            <p class="text-muted small">View my collection reports</p>
                            <span class="status-badge status-success">Performance</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-action" onclick="window.location.href='today-collections.php'">
                            <div class="action-icon-sm">
                                <i class="fas fa-list-check"></i>
                            </div>
                            <h6>Today's Summary</h6>
                            <p class="text-muted small">Today's collection summary</p>
                            <span class="status-badge status-info">Daily report</span>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Due Dates -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Upcoming Due Dates (Next 7 Days)</h4>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($upcoming_due)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($upcoming_due as $due): 
                                            $due_class = ($due['emi_due_date'] == $today) ? 'due-today' : 'due-soon';
                                            $due_label = ($due['emi_due_date'] == $today) ? 'Due Today!' : 'Due soon';
                                        ?>
                                            <div class="list-group-item <?= $due_class; ?> p-3 mb-2">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="mb-1"><?= htmlspecialchars($due['customer_name']); ?></h6>
                                                        <small class="text-muted"><?= htmlspecialchars($due['customer_number']); ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="fw-bold text-danger">₹<?= number_format($due['emi_amount'], 2); ?></div>
                                                        <small class="text-muted"><?= date('d-m-Y', strtotime($due['emi_due_date'])); ?></small>
                                                        <br>
                                                        <span class="badge bg-<?= ($due['emi_due_date'] == $today) ? 'danger' : 'warning'; ?>">
                                                            <?= $due_label; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        No upcoming due dates in next 7 days.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Collections -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">My Recent Collections</h4>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recent_collections)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Amount</th>
                                                    <th>Date</th>
                                                    <th>Bill No</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_collections as $rc): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($rc['customer_name']); ?></td>
                                                        <td class="text-success fw-bold">₹<?= number_format($rc['emi_amount'], 2); ?></td>
                                                        <td><?= date('d-m-Y', strtotime($rc['paid_date'])); ?></td>
                                                        <td><small><?= htmlspecialchars($rc['emi_bill_number']); ?></small></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        No recent collections.
                                    </div>
                                <?php endif; ?>
                                <div class="text-center mt-2">
                                    <a href="my-collections.php" class="btn btn-outline-primary btn-sm">View All My Collections</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Status -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 text-center">
                                        <div class="mb-3">
                                            <i class="fas fa-user-tie text-primary fs-1"></i>
                                            <h5 class="mt-2">Staff Role</h5>
                                            <p class="text-muted">Collection Staff</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <div class="mb-3">
                                            <i class="fas fa-calendar-check text-success fs-1"></i>
                                            <h5 class="mt-2">Today</h5>
                                            <p class="text-muted"><?= date('d F Y'); ?></p>
                                        </div>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <div class="mb-3">
                                            <i class="fas fa-target text-warning fs-1"></i>
                                            <h5 class="mt-2">Target</h5>
                                            <p class="text-muted">Collect All Dues</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <div class="mb-3">
                                            <i class="fas fa-chart-line text-info fs-1"></i>
                                            <h5 class="mt-2">Performance</h5>
                                            <p class="text-muted">Track Collections</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
    <script>
        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const options = { 
                timeZone: 'Asia/Kolkata',
                hour12: true,
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit'
            };
            const istTime = now.toLocaleTimeString('en-IN', options);
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = istTime;
            }
        }

        setInterval(updateCurrentTime, 1000);
        updateCurrentTime();

        // Quick action hover effects
        document.querySelectorAll('.quick-action').forEach(action => {
            action.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
                this.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
            });
            
            action.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });
    </script>
</body>
</html>