<?php
// index.php - Dashboard for SRI VARI CHITS - FIXED VERSION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session and check login - ADD THIS SECTION
session_start();

// Check login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

// Only admin can access this dashboard
if ($_SESSION['role'] !== 'admin') {
    if ($_SESSION['role'] == 'staff') {
        header('Location: staff/index.php');
        exit;
    } elseif ($_SESSION['role'] == 'accountant') {
        header('Location: account/index.php');
        exit;
    } else {
        header('Location: login.php');
        exit;
    }
}

include 'includes/db.php';

// Fetch Dashboard Stats
$total_plans = 0;
$total_members = 0;
$total_collected = 0;
$total_pending = 0;
$total_expenses = 0;
$total_big_winners = 0;
$today_due_count = 0;
$today = date('Y-m-d');

// Total Plans
$result = $conn->query("SELECT COUNT(*) AS count FROM plans");
if ($row = $result->fetch_assoc()) $total_plans = $row['count'];

// Total Members
$result = $conn->query("SELECT COUNT(*) AS count FROM members");
if ($row = $result->fetch_assoc()) $total_members = $row['count'];

// Total Collected (all paid EMIs)
$result = $conn->query("SELECT SUM(emi_amount) AS total FROM emi_schedule WHERE status = 'paid'");
if ($row = $result->fetch_assoc()) $total_collected = $row['total'] ?? 0;


// Total Expenses
$result = $conn->query("SELECT SUM(amount) AS total FROM expenses");
if ($row = $result->fetch_assoc()) $total_expenses = $row['total'] ?? 0;

// Big Winners Count
$result = $conn->query("SELECT COUNT(*) AS count FROM members WHERE winner_amount IS NOT NULL AND winner_amount > 0");
if ($row = $result->fetch_assoc()) $total_big_winners = $row['count'];

// Today's Due Count
$result = $conn->query("SELECT COUNT(*) AS count FROM emi_schedule WHERE status = 'unpaid' AND emi_due_date = '$today'");
if ($row = $result->fetch_assoc()) $today_due_count = $row['count'];

// Recent Collections (last 5 paid)
$sql_recent = "SELECT m.customer_name, es.emi_amount, es.paid_date
               FROM emi_schedule es
               JOIN members m ON es.member_id = m.id
               WHERE es.status = 'paid'
               ORDER BY es.paid_date DESC
               LIMIT 5";
$result_recent = $conn->query($sql_recent);
$recent_collections = [];
while ($row = $result_recent->fetch_assoc()) {
    $recent_collections[] = $row;
}

// Get current IST time for display
function getCurrentIST() {
    date_default_timezone_set('Asia/Kolkata');
    return date('h:i:s A');
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
        
    </div>
    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-fluid">
                <?php
                $page_title = "Dashboard";
                $breadcrumb_active = "Dashboard";
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

                <div class="row align-items-center mb-4">
                    <div class="col">
                        <h3 class="mb-0">Welcome to SRI VARI CHITS Dashboard</h3>
                        <small class="text-muted">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>! Overview as of <?= date('d F Y'); ?></small>
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
                    <div class="col-xl-3 col-md-6">
                        <div class="card border-primary shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="iconoir-group fs-1 text-primary"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-muted fw-normal">Total Members</h5>
                                        <h3 class="mb-0 text-primary"><?= $total_members; ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-success shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="iconoir-hand-cash fs-1 text-success"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-muted fw-normal">Total Collected</h5>
                                        <h3 class="mb-0 text-success">₹<?= number_format($total_collected, 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-warning shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="iconoir-trophy fs-1 text-warning"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-muted fw-normal">Bid Winners</h5>
                                        <h3 class="mb-0 text-warning"><?= $total_big_winners; ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Active Plans & Expenses -->
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Active Chit Plans</h4>
                            </div>
                            <div class="card-body">
                                <h3 class="text-center text-primary"><?= $total_plans; ?></h3>
                                <p class="text-center text-muted">Total chit groups running</p>
                                <div class="text-center">
                                    <a href="chit-groups.php" class="btn btn-outline-primary">View All Groups</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Total Expenses</h4>
                            </div>
                            <div class="card-body">
                                <h3 class="text-center text-danger">₹<?= number_format($total_expenses, 2); ?></h3>
                                <p class="text-center text-muted">All recorded expenses</p>
                                <div class="text-center">
                                    <a href="manage-expenses.php" class="btn btn-outline-danger">Manage Expenses</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row quick-actions mb-4 mt-4">
                    <div class="col-12">
                        <h4 class="card-title mb-3">Quick Actions</h4>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='add-member.php'">
                            <i class="fas fa-user-plus action-icon"></i>
                            <h5 class="action-title">Add New Member</h5>
                            <p class="action-desc">Register new chit fund member</p>
                            <span class="status-badge status-success">Quick Entry</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='collection-list.php'">
                            <i class="fas fa-cash-register action-icon"></i>
                            <h5 class="action-title">Collect EMI</h5>
                            <p class="action-desc">Process member payments</p>
                            <span class="status-badge status-warning">Today: <?php echo $today_due_count; ?> due</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='manage-expenses.php'">
                            <i class="fas fa-file-invoice-dollar action-icon"></i>
                            <h5 class="action-title">Add Expense</h5>
                            <p class="action-desc">Record business expenses</p>
                            <span class="status-badge status-danger">Total: ₹<?php echo number_format($total_expenses, 2); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='plan-reports.php'">
                            <i class="fas fa-chart-bar action-icon"></i>
                            <h5 class="action-title">View Reports</h5>
                            <p class="action-desc">Generate financial reports</p>
                            <span class="status-badge status-success">Analytics</span>
                        </div>
                    </div>
                </div>

                <!-- Chit Plan Categories -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="card-title mb-3">Chit Plan Categories</h4>
                    </div>
                    <?php 
                    $plan_categories = [
                        '25 Lakhs Plan' => ['bg-primary', 'fas fa-indian-rupee-sign', 'chit-groups.php?filter=25lakh'],
                        '5-10 Lakhs Plans' => ['bg-success', 'fas fa-money-bill', 'chit-groups.php?filter=5-10lakh'],
                        '1-3 Lakhs Plans' => ['bg-info', 'fas fa-coins', 'chit-groups.php?filter=1-3lakh'],
                        'Weekly Plans' => ['bg-warning', 'fas fa-calendar-week', 'chit-groups.php?filter=weekly'],
                        'All Plans' => ['bg-dark', 'fas fa-list', 'chit-groups.php']
                    ];
                    
                    foreach ($plan_categories as $category => $details): 
                        list($bg_color, $icon, $url) = $details;
                    ?>
                    <div class="col-md-4 col-xl">
                        <div class="card <?php echo $bg_color; ?> text-white category-card" onclick="window.location.href='<?php echo $url; ?>'">
                            <div class="card-body d-flex flex-column align-items-center justify-content-center text-center p-3">
                                <div class="mb-3">
                                    <i class="<?php echo $icon; ?>" style="font-size: 2.5rem;"></i>
                                </div>
                                <h4 class="mb-0"><?php echo $category; ?></h4>
                                <div class="click-hint mt-2">
                                    <small>View Details</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Recent Collections -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Recent Collections (Last 5)</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_collections)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Customer</th>
                                            <th>Amount (₹)</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_collections as $index => $rc): ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td><?= htmlspecialchars($rc['customer_name']); ?></td>
                                                <td class="text-success fw-bold">₹<?= number_format($rc['emi_amount'], 2); ?></td>
                                                <td><?= date('d-m-Y', strtotime($rc['paid_date'])); ?></td>
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
                        <div class="text-center mt-3">
                            <a href="collection-reports.php" class="btn btn-outline-success">View Full Reports</a>
                        </div>
                    </div>
                </div>
                
                <!-- System Status -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">System Status</h4>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-database text-primary fs-4"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-0">Database</h6>
                                                <small class="text-muted">Connected</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-shield-alt text-success fs-4"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-0">Security</h6>
                                                <small class="text-muted">Active</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-user-check text-info fs-4"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-0">Session</h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($_SESSION['username']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-server text-warning fs-4"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="mb-0">Server</h6>
                                                <small class="text-muted">Running</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Financial Overview</h4>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3 text-center">
                                            <h6 class="text-muted mb-1">Collection Rate</h6>
                                            <?php 
                                            $total_emis = $total_collected + $total_pending;
                                            $collection_rate = $total_emis > 0 ? ($total_collected / $total_emis * 100) : 0;
                                            ?>
                                            <h3 class="text-success mb-0"><?php echo number_format($collection_rate, 1); ?>%</h3>
                                            <small class="text-muted">Efficiency</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3 text-center">
                                            <h6 class="text-muted mb-1">Avg. EMI</h6>
                                            <?php 
                                            $avg_emi = $total_members > 0 ? ($total_collected / $total_members) : 0;
                                            ?>
                                            <h3 class="text-primary mb-0">₹<?php echo number_format($avg_emi, 0); ?></h3>
                                            <small class="text-muted">Per Member</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3 text-center">
                                            <h6 class="text-muted mb-1">Expense Ratio</h6>
                                            <?php 
                                            $expense_ratio = $total_collected > 0 ? ($total_expenses / $total_collected * 100) : 0;
                                            ?>
                                            <h3 class="text-warning mb-0"><?php echo number_format($expense_ratio, 1); ?>%</h3>
                                            <small class="text-muted">Of Collections</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="border rounded p-3 text-center">
                                            <h6 class="text-muted mb-1">Member Growth</h6>
                                            <h3 class="text-info mb-0">+<?php echo $total_members; ?></h3>
                                            <small class="text-muted">Total Members</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
    
    <style>
        .action-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid transparent;
            cursor: pointer;
            height: 100%;
        }
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            border-color: #4a6491;
        }
        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: #4a6491;
        }
        .action-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        .action-desc {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 15px;
        }
        .category-card { 
            transition: transform 0.2s; 
            border-radius: 10px; 
            border: none; 
            color: white; 
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .category-card:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); 
        }
        .click-hint { 
            font-size: 0.75rem; 
            opacity: 0.8; 
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
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
        .status-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .quick-actions {
            margin-bottom: 30px;
        }
    </style>
    
    <script>
        // Update current time display
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

        // Update time every second
        setInterval(updateCurrentTime, 1000);
        updateCurrentTime(); // Initial call

        // Quick action card hover effects
        document.querySelectorAll('.action-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = '0 5px 20px rgba(0,0,0,0.15)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
            });
        });

        // Category card hover effects
        document.querySelectorAll('.category-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
                this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.2)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
            });
        });
    </script>
</body>
</html>