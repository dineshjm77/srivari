<?php
// account/index.php - Accountant Dashboard
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check login and role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

// Only accountant can access this dashboard
if ($_SESSION['role'] !== 'accountant') {
    header('Location:login.php');
    exit;
}

include 'includes/db.php';

// Get filter parameters
$filter_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
$payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : 'all';

// Calculate date range for current month if no custom range
if (empty($start_date) && empty($end_date)) {
    $start_date = date('Y-m-01', mktime(0, 0, 0, $filter_month, 1, $filter_year));
    $end_date = date('Y-m-t', mktime(0, 0, 0, $filter_month, 1, $filter_year));
}

// Build WHERE clause for collections
$where_clause = "WHERE es.status = 'paid' AND es.paid_date IS NOT NULL";
$params = [];
$types = '';

if (!empty($start_date) && !empty($end_date)) {
    $where_clause .= " AND es.paid_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

if ($plan_filter > 0) {
    $where_clause .= " AND m.plan_id = ?";
    $params[] = $plan_filter;
    $types .= 'i';
}

if ($payment_type != 'all') {
    $where_clause .= " AND es.payment_type = ?";
    $params[] = $payment_type;
    $types .= 's';
}

// Fetch all plans for dropdown
$sql_plans = "SELECT id, title FROM plans ORDER BY title ASC";
$result_plans = $conn->query($sql_plans);
$plans = [];
while ($row = $result_plans->fetch_assoc()) {
    $plans[] = $row;
}

// Get overall financial statistics
$sql_stats = "SELECT 
                COUNT(DISTINCT es.id) as total_transactions,
                COUNT(DISTINCT es.member_id) as total_members,
                SUM(CASE WHEN es.payment_type = 'cash' THEN es.cash_amount ELSE 0 END) as total_cash,
                SUM(CASE WHEN es.payment_type = 'upi' THEN es.upi_amount ELSE 0 END) as total_upi,
                SUM(CASE WHEN es.payment_type = 'both' THEN (es.cash_amount + es.upi_amount) ELSE 0 END) as total_both,
                SUM(CASE 
                    WHEN es.payment_type = 'cash' THEN es.cash_amount 
                    WHEN es.payment_type = 'upi' THEN es.upi_amount 
                    WHEN es.payment_type = 'both' THEN (es.cash_amount + es.upi_amount)
                    ELSE 0 
                END) as total_collection,
                AVG(CASE 
                    WHEN es.payment_type = 'cash' THEN es.cash_amount 
                    WHEN es.payment_type = 'upi' THEN es.upi_amount 
                    WHEN es.payment_type = 'both' THEN (es.cash_amount + es.upi_amount)
                    ELSE 0 
                END) as avg_transaction
              FROM emi_schedule es
              JOIN members m ON es.member_id = m.id
              $where_clause";

$stmt_stats = $conn->prepare($sql_stats);
if (!empty($params)) {
    $stmt_stats->bind_param($types, ...$params);
}
$stmt_stats->execute();
$result_stats = $stmt_stats->get_result();
$stats = $result_stats->fetch_assoc();
$stmt_stats->close();

// Get total members count
$sql_total_members = "SELECT COUNT(*) as total_members FROM members";
$result_total_members = $conn->query($sql_total_members);
$total_members = $result_total_members->fetch_assoc()['total_members'];

// Get total pending amount (unpaid EMIs)
$sql_pending = "SELECT 
                    COUNT(*) as pending_count,
                    SUM(emi_amount) as pending_amount
                FROM emi_schedule 
                WHERE status = 'unpaid' AND emi_due_date < CURDATE()";
$result_pending = $conn->query($sql_pending);
$pending_stats = $result_pending->fetch_assoc();

// Get total expenses
$sql_expenses = "SELECT SUM(amount) as total_expenses FROM expenses";
$result_expenses = $conn->query($sql_expenses);
$total_expenses = $result_expenses->fetch_assoc()['total_expenses'] ?? 0;

// Get total big winners amount
$sql_winners = "SELECT 
                    COUNT(*) as winner_count,
                    SUM(winner_amount) as total_winner_amount,
                    SUM(CASE WHEN paid_date IS NOT NULL THEN winner_amount ELSE 0 END) as paid_winner_amount
                FROM members 
                WHERE winner_amount IS NOT NULL AND winner_amount > 0";
$result_winners = $conn->query($sql_winners);
$winner_stats = $result_winners->fetch_assoc();

// Get daily collection summary
$sql_daily = "SELECT 
                DATE(es.paid_date) as payment_date,
                COUNT(DISTINCT es.id) as transaction_count,
                SUM(CASE 
                    WHEN es.payment_type = 'cash' THEN es.cash_amount 
                    WHEN es.payment_type = 'upi' THEN es.upi_amount 
                    WHEN es.payment_type = 'both' THEN (es.cash_amount + es.upi_amount)
                    ELSE 0 
                END) as total_amount
              FROM emi_schedule es
              JOIN members m ON es.member_id = m.id
              $where_clause
              GROUP BY DATE(es.paid_date)
              ORDER BY es.paid_date DESC
              LIMIT 7";

$stmt_daily = $conn->prepare($sql_daily);
if (!empty($params)) {
    $stmt_daily->bind_param($types, ...$params);
}
$stmt_daily->execute();
$result_daily = $stmt_daily->get_result();
$daily_collections = [];
while ($row = $result_daily->fetch_assoc()) {
    $daily_collections[] = $row;
}
$stmt_daily->close();

// Get non-collection list (members with pending EMIs)
$sql_non_collection = "SELECT 
                        m.id,
                        m.agreement_number,
                        m.customer_name,
                        m.customer_number,
                        p.title as plan_title,
                        COUNT(es.id) as pending_count,
                        SUM(es.emi_amount) as pending_amount,
                        MIN(es.emi_due_date) as earliest_due_date
                      FROM members m
                      JOIN plans p ON m.plan_id = p.id
                      JOIN emi_schedule es ON m.id = es.member_id
                      WHERE es.status = 'unpaid' 
                      AND es.emi_due_date < CURDATE()
                      GROUP BY m.id, m.agreement_number, m.customer_name, m.customer_number, p.title
                      ORDER BY earliest_due_date ASC
                      LIMIT 20";

$result_non_collection = $conn->query($sql_non_collection);
$non_collection_list = [];
while ($row = $result_non_collection->fetch_assoc()) {
    $non_collection_list[] = $row;
}

// Get member list for accountant view
$sql_members = "SELECT 
                    m.id,
                    m.agreement_number,
                    m.customer_name,
                    m.customer_number,
                    m.emi_date,
                    m.winner_amount,
                    m.winner_date,
                    p.title as plan_title,
                    p.monthly_installment,
                    (SELECT COUNT(*) FROM emi_schedule WHERE member_id = m.id AND status = 'paid') as paid_count,
                    (SELECT SUM(emi_amount) FROM emi_schedule WHERE member_id = m.id AND status = 'paid') as total_paid,
                    (SELECT COUNT(*) FROM emi_schedule WHERE member_id = m.id AND status = 'unpaid' AND emi_due_date < CURDATE()) as pending_count,
                    (SELECT SUM(emi_amount) FROM emi_schedule WHERE member_id = m.id AND status = 'unpaid' AND emi_due_date < CURDATE()) as pending_amount
                FROM members m
                JOIN plans p ON m.plan_id = p.id
                ORDER BY m.created_at DESC
                LIMIT 15";

$result_members = $conn->query($sql_members);
$member_list = [];
while ($row = $result_members->fetch_assoc()) {
    $member_list[] = $row;
}

// Get collection trend for chart (last 6 months)
$sql_trend = "SELECT 
                DATE_FORMAT(es.paid_date, '%b %Y') as month_year,
                COUNT(DISTINCT es.id) as transaction_count,
                SUM(CASE 
                    WHEN es.payment_type = 'cash' THEN es.cash_amount 
                    WHEN es.payment_type = 'upi' THEN es.upi_amount 
                    WHEN es.payment_type = 'both' THEN (es.cash_amount + es.upi_amount)
                    ELSE 0 
                END) as total_amount
              FROM emi_schedule es
              WHERE es.status = 'paid' AND es.paid_date IS NOT NULL
              AND es.paid_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              GROUP BY DATE_FORMAT(es.paid_date, '%Y-%m')
              ORDER BY es.paid_date ASC";

$result_trend = $conn->query($sql_trend);
$collection_trend = [];
while ($row = $result_trend->fetch_assoc()) {
    $collection_trend[] = $row;
}

// Get current IST time for display
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
    <title>Accountant Dashboard - SRI VARI CHITS</title>
    <?php include 'includes/head.php'; ?>
</head>
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
                $page_title = "Accountant Dashboard";
                $breadcrumb_active = "Financial Overview";
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
                        <h3 class="mb-0">Accountant Dashboard - Financial Management</h3>
                        <small class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Accountant'); ?>! Financial overview as of <?= date('d F Y'); ?></small>
                    </div>
                    <div class="col-auto">
                        <div class="alert alert-info py-2 mb-0">
                            <i class="fas fa-clock me-1"></i>
                            <strong>IST:</strong> <span id="current-time"><?php echo getCurrentIST(); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Financial Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Month</label>
                                <select class="form-control" name="month">
                                    <?php for($m=1; $m<=12; $m++): ?>
                                        <option value="<?= $m; ?>" <?= $filter_month == $m ? 'selected' : ''; ?>>
                                            <?= date('F', mktime(0,0,0,$m,1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Year</label>
                                <select class="form-control" name="year">
                                    <?php for($y=2020; $y<=date('Y')+1; $y++): ?>
                                        <option value="<?= $y; ?>" <?= $filter_year == $y ? 'selected' : ''; ?>>
                                            <?= $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?= $start_date; ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?= $end_date; ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Plan</label>
                                <select class="form-control" name="plan_id">
                                    <option value="0">All Plans</option>
                                    <?php foreach ($plans as $plan): ?>
                                        <option value="<?= $plan['id']; ?>" <?= $plan_filter == $plan['id'] ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($plan['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Payment Type</label>
                                <select class="form-control" name="payment_type">
                                    <option value="all" <?= $payment_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="cash" <?= $payment_type == 'cash' ? 'selected' : ''; ?>>Cash Only</option>
                                    <option value="upi" <?= $payment_type == 'upi' ? 'selected' : ''; ?>>UPI Only</option>
                                    <option value="both" <?= $payment_type == 'both' ? 'selected' : ''; ?>>Both (Cash+UPI)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label d-block">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-sync me-1"></i> Reset
                                    </a>
                                    <button type="button" class="btn btn-outline-success" onclick="exportToExcel()">
                                        <i class="fas fa-file-excel me-1"></i> Export
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Financial Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card border-primary shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-money-bill-wave fs-1 text-primary"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-muted fw-normal">Total Collection</h5>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($stats['total_collection'] ?? 0, 2); ?></h3>
                                        <small>
                                            Cash: ₹<?= number_format($stats['total_cash'] ?? 0, 2); ?> | 
                                            UPI: ₹<?= number_format($stats['total_upi'] ?? 0, 2); ?>
                                        </small>
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
                                        <i class="fas fa-users fs-1 text-success"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-muted fw-normal">Total Members</h5>
                                        <h3 class="mb-0 text-success"><?= number_format($total_members); ?></h3>
                                        <small>
                                            Collected: <?= number_format($stats['total_members'] ?? 0); ?> | 
                                            Avg: ₹<?= number_format($stats['avg_transaction'] ?? 0, 2); ?>
                                        </small>
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
                                        <i class="fas fa-clock fs-1 text-warning"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-muted fw-normal">Pending Collection</h5>
                                        <h3 class="mb-0 text-warning">₹<?= number_format($pending_stats['pending_amount'] ?? 0, 2); ?></h3>
                                        <small>
                                            <?= number_format($pending_stats['pending_count'] ?? 0); ?> EMIs pending
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-danger shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-trophy fs-1 text-danger"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-muted fw-normal">Winner Payments</h5>
                                        <h3 class="mb-0 text-danger">₹<?= number_format($winner_stats['total_winner_amount'] ?? 0, 2); ?></h3>
                                        <small>
                                            <?= number_format($winner_stats['winner_count'] ?? 0); ?> winners | 
                                            Paid: ₹<?= number_format($winner_stats['paid_winner_amount'] ?? 0, 2); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Collection Trend (Last 6 Months)</h4>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="collectionTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Payment Type Distribution</h4>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="paymentTypeChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions for Accountant -->
                <div class="row quick-actions mb-4">
                    <div class="col-12">
                        <h4 class="card-title mb-3">Quick Financial Actions</h4>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='collection-list.php'">
                            <i class="fas fa-cash-register action-icon"></i>
                            <h5 class="action-title">Collection Reports</h5>
                            <p class="action-desc">View detailed collection reports</p>
                            <span class="status-badge status-success">₹<?= number_format($stats['total_collection'] ?? 0, 2); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='#'">
                            <i class="fas fa-users action-icon"></i>
                            <h5 class="action-title">Member Reports</h5>
                            <p class="action-desc">Analyze member payment status</p>
                            <span class="status-badge status-primary"><?= $total_members; ?> members</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='#'">
                            <i class="fas fa-trophy action-icon"></i>
                            <h5 class="action-title">Winner Payments</h5>
                            <p class="action-desc">Manage winner payments</p>
                            <span class="status-badge status-warning"><?= number_format($winner_stats['winner_count'] ?? 0); ?> winners</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="action-card" onclick="window.location.href='#'">
                            <i class="fas fa-file-invoice-dollar action-icon"></i>
                            <h5 class="action-title">Expense Reports</h5>
                            <p class="action-desc">Track business expenses</p>
                            <span class="status-badge status-danger">₹<?= number_format($total_expenses, 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Recent Collections -->
                <div class="row mb-4">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Recent Collections (Last 7 Days)</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Transactions</th>
                                                <th>Amount (₹)</th>
                                                <th>Daily Avg</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($daily_collections)): ?>
                                                <?php foreach ($daily_collections as $day): 
                                                    $daily_avg = $day['transaction_count'] > 0 ? 
                                                        $day['total_amount'] / $day['transaction_count'] : 0;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= date('d M Y', strtotime($day['payment_date'])); ?></strong>
                                                            <br><small class="text-muted"><?= date('l', strtotime($day['payment_date'])); ?></small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?= $day['transaction_count']; ?></span>
                                                        </td>
                                                        <td class="text-success fw-bold">
                                                            ₹<?= number_format($day['total_amount'], 2); ?>
                                                        </td>
                                                        <td>
                                                            ₹<?= number_format($daily_avg, 2); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-4 text-muted">
                                                        No collections found for the selected period.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <?php if (!empty($daily_collections)): ?>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th class="text-end">Total:</th>
                                                <th><?= array_sum(array_column($daily_collections, 'transaction_count')); ?></th>
                                                <th class="text-success fw-bold">₹<?= number_format(array_sum(array_column($daily_collections, 'total_amount')), 2); ?></th>
                                                <th>
                                                    <?php 
                                                    $total_transactions = array_sum(array_column($daily_collections, 'transaction_count'));
                                                    $total_amount = array_sum(array_column($daily_collections, 'total_amount'));
                                                    $overall_avg = $total_transactions > 0 ? $total_amount / $total_transactions : 0;
                                                    ?>
                                                    ₹<?= number_format($overall_avg, 2); ?>
                                                </th>
                                            </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Financial Summary</h4>
                            </div>
                            <div class="card-body">
                                <div class="list-group">
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Total Collections</span>
                                        <span class="text-success fw-bold">₹<?= number_format($stats['total_collection'] ?? 0, 2); ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Pending Collections</span>
                                        <span class="text-warning fw-bold">₹<?= number_format($pending_stats['pending_amount'] ?? 0, 2); ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Total Expenses</span>
                                        <span class="text-danger fw-bold">₹<?= number_format($total_expenses, 2); ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Winner Payments Due</span>
                                        <span class="text-info fw-bold">
                                            ₹<?= number_format(($winner_stats['total_winner_amount'] ?? 0) - ($winner_stats['paid_winner_amount'] ?? 0), 2); ?>
                                        </span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Collection Efficiency</span>
                                        <span class="fw-bold">
                                            <?php 
                                            $total_emis = ($stats['total_collection'] ?? 0) + ($pending_stats['pending_amount'] ?? 0);
                                            $efficiency = $total_emis > 0 ? (($stats['total_collection'] ?? 0) / $total_emis) * 100 : 0;
                                            ?>
                                            <?= number_format($efficiency, 1); ?>%
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Member List with Financial Status -->
                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Member List with Financial Status</h4>
                            <a href="../customer-wise-reports.php" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="memberTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Member Details</th>
                                        <th>Plan</th>
                                        <th>Paid Amount</th>
                                        <th>Pending Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($member_list)): ?>
                                        <?php foreach ($member_list as $index => $member): 
                                            $is_winner = !empty($member['winner_amount']);
                                            $status_class = $member['pending_count'] > 0 ? 'warning' : ($is_winner ? 'success' : 'primary');
                                            $status_text = $is_winner ? 'Winner' : ($member['pending_count'] > 0 ? 'Pending' : 'Active');
                                        ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-2">
                                                            <div class="avatar-title bg-light text-primary rounded-circle">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?= htmlspecialchars($member['customer_name']); ?></h6>
                                                            <small class="text-muted"><?= $member['agreement_number']; ?></small>
                                                            <br><small class="text-muted"><?= htmlspecialchars($member['customer_number']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($member['plan_title']); ?>
                                                    <br><small class="text-muted">EMI: ₹<?= number_format($member['monthly_installment'], 2); ?></small>
                                                </td>
                                                <td class="text-success fw-bold">
                                                    ₹<?= number_format($member['total_paid'] ?? 0, 2); ?>
                                                    <br><small class="text-muted"><?= $member['paid_count']; ?> payments</small>
                                                </td>
                                                <td class="text-warning fw-bold">
                                                    ₹<?= number_format($member['pending_amount'] ?? 0, 2); ?>
                                                    <br><small class="text-muted"><?= $member['pending_count']; ?> pending</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $status_class; ?>"><?= $status_text; ?></span>
                                                    <?php if ($is_winner): ?>
                                                        <br><small class="text-muted">Won: ₹<?= number_format($member['winner_amount'], 2); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <a href="../emi-schedule-member.php?id=<?= $member['id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary" title="View Schedule">
                                                            <i class="fas fa-calendar-alt"></i>
                                                        </a>
                                                        <a href="../customer-details.php?id=<?= $member['id']; ?>" 
                                                           class="btn btn-sm btn-outline-info" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                No members found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Non-Collection List -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Non-Collection List (Pending EMIs)</h4>
                            <a href="#" class="btn btn-sm btn-outline-warning">View All Pending</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="nonCollectionTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Member Details</th>
                                        <th>Plan</th>
                                        <th>Pending Amount</th>
                                        <th>Pending EMIs</th>
                                        <th>Earliest Due</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($non_collection_list)): ?>
                                        <?php foreach ($non_collection_list as $index => $member): 
                                            $due_days = floor((time() - strtotime($member['earliest_due_date'])) / (60 * 60 * 24));
                                            $status_class = $due_days > 90 ? 'danger' : ($due_days > 30 ? 'warning' : 'info');
                                            $status_text = $due_days > 90 ? 'Defaulted' : ($due_days > 30 ? 'Overdue' : 'Due');
                                        ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-2">
                                                            <div class="avatar-title bg-light text-danger rounded-circle">
                                                                <i class="fas fa-exclamation-triangle"></i>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?= htmlspecialchars($member['customer_name']); ?></h6>
                                                            <small class="text-muted"><?= $member['agreement_number']; ?></small>
                                                            <br><small class="text-muted"><?= htmlspecialchars($member['customer_number']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($member['plan_title']); ?>
                                                </td>
                                                <td class="text-warning fw-bold">
                                                    ₹<?= number_format($member['pending_amount'] ?? 0, 2); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning"><?= $member['pending_count']; ?> EMIs</span>
                                                </td>
                                                <td>
                                                    <?= date('d M Y', strtotime($member['earliest_due_date'])); ?>
                                                    <br><small class="text-muted"><?= $due_days; ?> days ago</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $status_class; ?>"><?= $status_text; ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                Great! No pending collections found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($non_collection_list)): ?>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3" class="text-end">Total Pending:</th>
                                        <th class="text-warning fw-bold">₹<?= number_format(array_sum(array_column($non_collection_list, 'pending_amount')), 2); ?></th>
                                        <th><?= array_sum(array_column($non_collection_list, 'pending_count')); ?> EMIs</th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include '../includes/rightbar.php'; ?>
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>

    <?php include '../includes/scripts.php'; ?>
    
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        .status-primary {
            background: #cfe2ff;
            color: #084298;
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
        .chart-container {
            position: relative;
        }
        .avatar-sm {
            width: 40px;
            height: 40px;
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

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Collection Trend Chart
            const trendCtx = document.getElementById('collectionTrendChart').getContext('2d');
            const months = <?php echo json_encode(array_column($collection_trend, 'month_year')); ?>;
            const amounts = <?php echo json_encode(array_column($collection_trend, 'total_amount')); ?>;
            
            const trendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Collection Amount (₹)',
                        data: amounts,
                        backgroundColor: 'rgba(102, 126, 234, 0.2)',
                        borderColor: '#667eea',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '₹' + context.parsed.y.toLocaleString('en-IN');
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toLocaleString('en-IN');
                                }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Payment Type Chart
            const typeCtx = document.getElementById('paymentTypeChart').getContext('2d');
            const cashAmount = <?= $stats['total_cash'] ?? 0; ?>;
            const upiAmount = <?= $stats['total_upi'] ?? 0; ?>;
            const bothAmount = <?= $stats['total_both'] ?? 0; ?>;
            
            const typeChart = new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Cash', 'UPI', 'Both (Cash+UPI)'],
                    datasets: [{
                        data: [cashAmount, upiAmount, bothAmount],
                        backgroundColor: [
                            'rgba(40, 167, 69, 0.8)',
                            'rgba(0, 123, 255, 0.8)',
                            'rgba(255, 193, 7, 0.8)'
                        ],
                        borderColor: [
                            '#28a745',
                            '#007bff',
                            '#ffc107'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = cashAmount + upiAmount + bothAmount;
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ₹${value.toLocaleString('en-IN')} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        });

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

        // Export to Excel
        function exportToExcel() {
            const tables = [
                {id: 'memberTable', name: 'member-list'},
                {id: 'nonCollectionTable', name: 'non-collection-list'}
            ];
            
            tables.forEach(table => {
                const tableElement = document.getElementById(table.id);
                if (tableElement) {
                    const rows = tableElement.querySelectorAll('tr');
                    let csv = [];
                    
                    for (let i = 0; i < rows.length; i++) {
                        const row = [], cols = rows[i].querySelectorAll('td, th');
                        for (let j = 0; j < cols.length; j++) {
                            let text = cols[j].innerText.replace(/[₹]/g, '').trim();
                            row.push(`"${text}"`);
                        }
                        csv.push(row.join(','));
                    }
                    
                    const csvContent = csv.join('\n');
                    const blob = new Blob([csvContent], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.setAttribute('href', url);
                    a.setAttribute('download', `${table.name}-<?= date('Y-m-d'); ?>.csv`);
                    a.click();
                }
            });
        }

        // Quick date range selectors
        function setDateRange(range) {
            const today = new Date();
            let start, end;
            
            switch(range) {
                case 'today':
                    start = end = today.toISOString().split('T')[0];
                    break;
                case 'yesterday':
                    const yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    start = end = yesterday.toISOString().split('T')[0];
                    break;
                case 'this_week':
                    const firstDay = new Date(today.setDate(today.getDate() - today.getDay()));
                    const lastDay = new Date(today.setDate(today.getDate() - today.getDay() + 6));
                    start = firstDay.toISOString().split('T')[0];
                    end = lastDay.toISOString().split('T')[0];
                    break;
                case 'this_month':
                    start = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    end = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                    break;
            }
            
            document.querySelector('input[name="start_date"]').value = start;
            document.querySelector('input[name="end_date"]').value = end;
        }
    </script>
</body>
</html>