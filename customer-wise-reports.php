<?php
// customer-wise-reports.php - Customer-wise Reports (FIXED VERSION)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

// Get filter parameters
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'all';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'newest';
$show_all = isset($_GET['show_all']) ? true : false;

// Build WHERE clause for filters
$where_clause = "WHERE 1=1";
$params = [];
$types = '';

if (!empty($search_query)) {
    $where_clause .= " AND (
        m.customer_name LIKE ? OR 
        m.agreement_number LIKE ? OR 
        m.customer_number LIKE ? OR 
        m.customer_number2 LIKE ? OR 
        m.nominee_name LIKE ? OR 
        m.nominee_number LIKE ?
    )";
    $search_param = "%{$search_query}%";
    $params = array_fill(0, 6, $search_param);
    $types = 'ssssss';
}

if ($plan_filter > 0) {
    $where_clause .= " AND m.plan_id = ?";
    $params[] = $plan_filter;
    $types .= 'i';
}

// FIXED: Use winner_amount instead of non-existent status column
if ($status_filter == 'active') {
    $where_clause .= " AND m.winner_amount IS NULL";
} elseif ($status_filter == 'completed') {
    $where_clause .= " AND m.winner_amount IS NOT NULL";
} elseif ($status_filter == 'defaulted') {
    // Since there's no status column, we'll check for pending payments older than 90 days
    $where_clause .= " AND m.id IN (
        SELECT DISTINCT es.member_id 
        FROM emi_schedule es 
        WHERE es.status = 'unpaid' 
        AND es.emi_due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    )";
}

// Payment status filter based on EMI payments
if ($payment_status == 'up_to_date') {
    // Members with no pending EMIs
    $where_clause .= " AND m.id NOT IN (
        SELECT DISTINCT member_id 
        FROM emi_schedule 
        WHERE status = 'unpaid' AND emi_due_date < CURDATE()
    )";
} elseif ($payment_status == 'pending') {
    // Members with pending EMIs
    $where_clause .= " AND m.id IN (
        SELECT DISTINCT member_id 
        FROM emi_schedule 
        WHERE status = 'unpaid' AND emi_due_date < CURDATE()
    )";
}

// Build ORDER BY clause
$order_by = "";
switch ($sort_by) {
    case 'name_asc':
        $order_by = "ORDER BY m.customer_name ASC";
        break;
    case 'name_desc':
        $order_by = "ORDER BY m.customer_name DESC";
        break;
    case 'agreement_asc':
        $order_by = "ORDER BY m.agreement_number ASC";
        break;
    case 'agreement_desc':
        $order_by = "ORDER BY m.agreement_number DESC";
        break;
    case 'plan_asc':
        $order_by = "ORDER BY p.title ASC";
        break;
    case 'plan_desc':
        $order_by = "ORDER BY p.title DESC";
        break;
    case 'oldest':
        $order_by = "ORDER BY m.created_at ASC";
        break;
    case 'newest':
    default:
        $order_by = "ORDER BY m.created_at DESC";
        break;
}

// Fetch all plans for dropdown
$sql_plans = "SELECT id, title FROM plans ORDER BY title ASC";
$result_plans = $conn->query($sql_plans);
$plans = [];
while ($row = $result_plans->fetch_assoc()) {
    $plans[] = $row;
}

// Get total customer statistics (FIXED: removed status column references)
$sql_stats = "SELECT 
                COUNT(DISTINCT m.id) as total_customers,
                COUNT(DISTINCT CASE WHEN m.winner_amount IS NOT NULL THEN m.id END) as completed_customers,
                COUNT(DISTINCT CASE WHEN m.winner_amount IS NULL THEN m.id END) as active_customers,
                COUNT(DISTINCT CASE WHEN m.id IN (
                    SELECT DISTINCT es.member_id 
                    FROM emi_schedule es 
                    WHERE es.status = 'unpaid' 
                    AND es.emi_due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                ) THEN m.id END) as defaulted_customers,
                COUNT(DISTINCT p.id) as total_plans,
                SUM(m.winner_amount) as total_winner_amount,
                MIN(m.created_at) as first_member_date,
                MAX(m.created_at) as last_member_date
              FROM members m
              LEFT JOIN plans p ON m.plan_id = p.id
              $where_clause";

$stmt_stats = $conn->prepare($sql_stats);
if (!empty($params)) {
    $stmt_stats->bind_param($types, ...$params);
}
$stmt_stats->execute();
$result_stats = $stmt_stats->get_result();
$stats = $result_stats->fetch_assoc();
$stmt_stats->close();

// Get customer details with plan info (FIXED: removed status column)
$sql_customers = "SELECT 
                    m.id,
                    m.agreement_number,
                    m.customer_name,
                    m.customer_number,
                    m.customer_number2,
                    m.nominee_name,
                    m.nominee_number,
                    m.customer_address,
                    m.emi_date,
                    m.plan_id,
                    m.monthly_installment,
                    m.winner_amount,
                    m.winner_date,
                    m.winner_number,
                    m.paid_date,
                    m.created_at,
                    p.title as plan_title,
                    p.total_months,
                    p.total_received_amount as plan_total,
                    u.full_name as collected_by
                  FROM members m
                  JOIN plans p ON m.plan_id = p.id
                  LEFT JOIN users u ON m.collected_by = u.id
                  $where_clause
                  $order_by
                  " . ($show_all ? "" : "LIMIT 100");

$stmt_customers = $conn->prepare($sql_customers);
if (!empty($params)) {
    $stmt_customers->bind_param($types, ...$params);
}
$stmt_customers->execute();
$result_customers = $stmt_customers->get_result();
$customers = [];
while ($row = $result_customers->fetch_assoc()) {
    $customers[] = $row;
}
$stmt_customers->close();

// For each customer, get payment statistics
foreach ($customers as &$customer) {
    $customer_id = $customer['id'];
    
    // Get total paid amount
    $sql_paid = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(emi_amount) as total_paid,
                    MAX(paid_date) as last_payment_date,
                    MIN(emi_due_date) as first_due_date,
                    MAX(emi_due_date) as last_due_date
                 FROM emi_schedule 
                 WHERE member_id = ? AND status = 'paid'";
    
    $stmt_paid = $conn->prepare($sql_paid);
    $stmt_paid->bind_param('i', $customer_id);
    $stmt_paid->execute();
    $result_paid = $stmt_paid->get_result();
    $payment_stats = $result_paid->fetch_assoc();
    $stmt_paid->close();
    
    $customer['payment_stats'] = $payment_stats;
    
    // Get pending EMIs
    $sql_pending = "SELECT 
                       COUNT(*) as pending_count,
                       SUM(emi_amount) as pending_amount,
                       MIN(emi_due_date) as next_due_date
                    FROM emi_schedule 
                    WHERE member_id = ? AND status = 'unpaid' AND emi_due_date <= CURDATE()";
    
    $stmt_pending = $conn->prepare($sql_pending);
    $stmt_pending->bind_param('i', $customer_id);
    $stmt_pending->execute();
    $result_pending = $stmt_pending->get_result();
    $pending_stats = $result_pending->fetch_assoc();
    $stmt_pending->close();
    
    $customer['pending_stats'] = $pending_stats;
    
    // Check if defaulted (pending payments older than 90 days)
    $sql_defaulted = "SELECT COUNT(*) as defaulted_count
                      FROM emi_schedule 
                      WHERE member_id = ? 
                      AND status = 'unpaid' 
                      AND emi_due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
    
    $stmt_defaulted = $conn->prepare($sql_defaulted);
    $stmt_defaulted->bind_param('i', $customer_id);
    $stmt_defaulted->execute();
    $result_defaulted = $stmt_defaulted->get_result();
    $defaulted_stats = $result_defaulted->fetch_assoc();
    $stmt_defaulted->close();
    
    $customer['is_defaulted'] = ($defaulted_stats['defaulted_count'] ?? 0) > 0;
    
    // Calculate progress
    $total_months = $customer['total_months'];
    $paid_months = $payment_stats['total_payments'] ?? 0;
    $customer['progress_percentage'] = $total_months > 0 ? round(($paid_months / $total_months) * 100) : 0;
    
    // Determine payment status
    if (!empty($customer['winner_amount'])) {
        $customer['payment_status'] = 'completed';
        $customer['status_text'] = 'Winner';
        $customer['status_class'] = 'success';
    } elseif ($customer['is_defaulted']) {
        $customer['payment_status'] = 'defaulted';
        $customer['status_text'] = 'Defaulted';
        $customer['status_class'] = 'danger';
    } elseif ($pending_stats['pending_count'] > 0) {
        $customer['payment_status'] = 'pending';
        $customer['status_text'] = 'Pending';
        $customer['status_class'] = 'warning';
    } else {
        $customer['payment_status'] = 'up_to_date';
        $customer['status_text'] = 'Active';
        $customer['status_class'] = 'primary';
    }
}

// Get plan-wise customer distribution
$sql_plan_distribution = "SELECT 
                            p.id,
                            p.title,
                            COUNT(DISTINCT m.id) as customer_count,
                            COUNT(DISTINCT CASE WHEN m.winner_amount IS NOT NULL THEN m.id END) as completed_count,
                            AVG(m.monthly_installment) as avg_installment
                          FROM plans p
                          LEFT JOIN members m ON p.id = m.plan_id
                          GROUP BY p.id, p.title
                          HAVING customer_count > 0
                          ORDER BY customer_count DESC";

$result_plan_distribution = $conn->query($sql_plan_distribution);
$plan_distribution = [];
while ($row = $result_plan_distribution->fetch_assoc()) {
    $plan_distribution[] = $row;
}

// Get monthly customer registration trend
$sql_registration_trend = "SELECT 
                              DATE_FORMAT(m.created_at, '%b %Y') as month_year,
                              COUNT(DISTINCT m.id) as new_customers,
                              COUNT(DISTINCT CASE WHEN m.winner_amount IS NOT NULL THEN m.id END) as completed_in_month
                           FROM members m
                           GROUP BY DATE_FORMAT(m.created_at, '%Y-%m')
                           ORDER BY m.created_at DESC
                           LIMIT 12";

$result_registration_trend = $conn->query($sql_registration_trend);
$registration_trend = [];
while ($row = $result_registration_trend->fetch_assoc()) {
    $registration_trend[] = $row;
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
                $page_title = "Customer-wise Reports";
                $breadcrumb_active = "Customer Analysis";
                include 'includes/breadcrumb.php';
                ?>
                
                <div class="row align-items-center mb-4">
                    <div class="col">
                        <h3 class="mb-0">Customer-wise Reports</h3>
                        <small class="text-muted">Detailed analysis of all customers and their payment status</small>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex gap-2">
                            <button onclick="window.print()" class="btn btn-outline-primary">
                                <i class="fas fa-print me-1"></i> Print
                            </button>
                            <button onclick="exportToExcel()" class="btn btn-outline-success">
                                <i class="fas fa-file-excel me-1"></i> Export Excel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Filters & Search</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search Customer</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-search"></i>
                                    </span>
                                    <input type="text" class="form-control" name="search" 
                                           value="<?= htmlspecialchars($search_query) ?>" 
                                           placeholder="Name, Agreement No, Phone...">
                                </div>
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
                            
                            <div class="col-md-2">
                                <label class="form-label">Member Status</label>
                                <select class="form-control" name="status">
                                    <option value="all" <?= $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?= $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="completed" <?= $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="defaulted" <?= $status_filter == 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Payment Status</label>
                                <select class="form-control" name="payment_status">
                                    <option value="all" <?= $payment_status == 'all' ? 'selected' : ''; ?>>All Payments</option>
                                    <option value="up_to_date" <?= $payment_status == 'up_to_date' ? 'selected' : ''; ?>>Up to Date</option>
                                    <option value="pending" <?= $payment_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Sort By</label>
                                <select class="form-control" name="sort_by">
                                    <option value="newest" <?= $sort_by == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="oldest" <?= $sort_by == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="name_asc" <?= $sort_by == 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                                    <option value="name_desc" <?= $sort_by == 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                                    <option value="agreement_asc" <?= $sort_by == 'agreement_asc' ? 'selected' : ''; ?>>Agreement No ↑</option>
                                    <option value="agreement_desc" <?= $sort_by == 'agreement_desc' ? 'selected' : ''; ?>>Agreement No ↓</option>
                                    <option value="plan_asc" <?= $sort_by == 'plan_asc' ? 'selected' : ''; ?>>Plan A-Z</option>
                                    <option value="plan_desc" <?= $sort_by == 'plan_desc' ? 'selected' : ''; ?>>Plan Z-A</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Display Options</label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="show_all" id="showAll" <?= $show_all ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="showAll">
                                        Show All Customers (No Limit)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-1"></i> Apply Filters
                                        </button>
                                        <a href="customer-wise-reports.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-sync me-1"></i> Reset
                                        </a>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">
                                            Showing: <?= count($customers); ?> customers | 
                                            Total: <?= number_format($stats['total_customers'] ?? 0); ?> customers
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-primary-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-users fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Total Customers</h5>
                                        <h3 class="mb-0"><?= number_format($stats['total_customers'] ?? 0); ?></h3>
                                        <small>
                                            Active: <?= number_format($stats['active_customers'] ?? 0); ?> | 
                                            Completed: <?= number_format($stats['completed_customers'] ?? 0); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-success-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-trophy fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Winners Amount</h5>
                                        <h3 class="mb-0">₹<?= number_format($stats['total_winner_amount'] ?? 0, 2); ?></h3>
                                        <small>
                                            <?= number_format($stats['completed_customers'] ?? 0); ?> winners | 
                                            <?= number_format($stats['total_plans'] ?? 0); ?> plans
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-info-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-calendar-alt fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Member Since</h5>
                                        <h3 class="mb-0">
                                            <?= !empty($stats['first_member_date']) ? date('M Y', strtotime($stats['first_member_date'])) : 'N/A'; ?>
                                        </h3>
                                        <small>
                                            First: <?= !empty($stats['first_member_date']) ? date('d M Y', strtotime($stats['first_member_date'])) : 'N/A'; ?> | 
                                            Last: <?= !empty($stats['last_member_date']) ? date('d M Y', strtotime($stats['last_member_date'])) : 'N/A'; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-danger-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Defaulted</h5>
                                        <h3 class="mb-0"><?= number_format($stats['defaulted_customers'] ?? 0); ?></h3>
                                        <small>
                                            <?= $stats['total_customers'] > 0 ? 
                                                round(($stats['defaulted_customers'] / $stats['total_customers']) * 100, 1) : 0 ?>% of total
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Plan-wise Customer Distribution</h4>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="planDistributionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Monthly Customer Registration</h4>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="registrationTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customers List -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Customer Details</h4>
                            <small class="text-muted">Showing <?= count($customers); ?> customers</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($customers)): ?>
                            <div class="row">
                                <?php foreach ($customers as $customer): 
                                    $is_winner = !empty($customer['winner_amount']);
                                    $status_text = $customer['status_text'];
                                    $status_class = $customer['status_class'];
                                ?>
                                    <div class="col-xl-4 col-md-6 mb-4">
                                        <div class="card h-100 border-<?= $status_class; ?> customer-card">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h5 class="card-title mb-0"><?= htmlspecialchars($customer['customer_name']); ?></h5>
                                                    <small class="text-muted"><?= $customer['agreement_number']; ?></small>
                                                </div>
                                                <div>
                                                    <span class="badge bg-<?= $status_class; ?>"><?= $status_text; ?></span>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <small>Plan Progress</small>
                                                        <small><?= $customer['progress_percentage']; ?>%</small>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar 
                                                            <?= $customer['progress_percentage'] >= 100 ? 'bg-success' : 
                                                               ($customer['progress_percentage'] >= 50 ? 'bg-info' : 'bg-warning'); ?>" 
                                                            role="progressbar" 
                                                            style="width: <?= min($customer['progress_percentage'], 100); ?>%">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <table class="table table-sm mb-0">
                                                    <tr>
                                                        <td width="40%"><small class="text-muted">Plan:</small></td>
                                                        <td><strong><?= htmlspecialchars($customer['plan_title']); ?></strong></td>
                                                    </tr>
                                                    <tr>
                                                        <td><small class="text-muted">Monthly EMI:</small></td>
                                                        <td class="text-success fw-bold">₹<?= number_format($customer['monthly_installment'], 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><small class="text-muted">Phone:</small></td>
                                                        <td><?= htmlspecialchars($customer['customer_number']); ?></td>
                                                    </tr>
                                                    <?php if (!empty($customer['customer_number2'])): ?>
                                                    <tr>
                                                        <td><small class="text-muted">Alt Phone:</small></td>
                                                        <td><?= htmlspecialchars($customer['customer_number2']); ?></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <tr>
                                                        <td><small class="text-muted">Nominee:</small></td>
                                                        <td><?= htmlspecialchars($customer['nominee_name']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><small class="text-muted">Member Since:</small></td>
                                                        <td><?= date('d M Y', strtotime($customer['created_at'])); ?></td>
                                                    </tr>
                                                </table>
                                                
                                                <div class="row mt-3">
                                                    <div class="col-6">
                                                        <div class="text-center p-2 bg-light rounded">
                                                            <small class="text-muted d-block">Paid</small>
                                                            <strong class="text-success">₹<?= number_format($customer['payment_stats']['total_paid'] ?? 0, 2); ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?= $customer['payment_stats']['total_payments'] ?? 0; ?> payments</small>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="text-center p-2 bg-light rounded">
                                                            <small class="text-muted d-block">Pending</small>
                                                            <strong class="text-warning">₹<?= number_format($customer['pending_stats']['pending_amount'] ?? 0, 2); ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?= $customer['pending_stats']['pending_count'] ?? 0; ?> EMIs</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($is_winner): ?>
                                                    <div class="alert alert-success mt-3 mb-0 py-2">
                                                        <div class="d-flex justify-content-between">
                                                            <div>
                                                                <small class="d-block">Winner Amount</small>
                                                                <strong>₹<?= number_format($customer['winner_amount'], 2); ?></strong>
                                                            </div>
                                                            <div class="text-end">
                                                                <small class="d-block">Winner Date</small>
                                                                <strong><?= date('d M Y', strtotime($customer['winner_date'])); ?></strong>
                                                            </div>
                                                        </div>
                                                        <?php if (!empty($customer['winner_number'])): ?>
                                                            <small class="d-block mt-1">Winner No: <?= $customer['winner_number']; ?></small>
                                                        <?php endif; ?>
                                                        <?php if (!empty($customer['paid_date'])): ?>
                                                            <small class="d-block mt-1 text-success">
                                                                <i class="fas fa-check-circle me-1"></i>Paid on <?= date('d M Y', strtotime($customer['paid_date'])); ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <small class="d-block mt-1 text-warning">
                                                                <i class="fas fa-clock me-1"></i>Payment Pending
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-footer bg-transparent">
                                                <div class="d-flex justify-content-between">
                                                    <a href="emi-schedule-member.php?id=<?= $customer['id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-calendar-alt me-1"></i> Schedule
                                                    </a>
                                                    
                                                    <?php if ($customer['is_defaulted']): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-exclamation-triangle me-1"></i> Default
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <div class="mb-3">
                                    <i class="fas fa-users fa-3x text-muted"></i>
                                </div>
                                <h5 class="text-muted">No customers found</h5>
                                <p class="text-muted">Try changing your search criteria or filters</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!$show_all && $stats['total_customers'] > count($customers)): ?>
                    <div class="card-footer text-center">
                        <a href="customer-wise-reports.php?<?= http_build_query(array_merge($_GET, ['show_all' => 1])); ?>" 
                           class="btn btn-outline-primary">
                            <i class="fas fa-eye me-1"></i> Show All Customers (<?= $stats['total_customers']; ?>)
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Plan-wise Distribution Table -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Plan-wise Summary</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Plan</th>
                                        <th>Total Customers</th>
                                        <th>Active</th>
                                        <th>Completed</th>
                                        <th>Defaulted</th>
                                        <th>Avg EMI</th>
                                        <th>% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($plan_distribution)): ?>
                                        <?php foreach ($plan_distribution as $plan): 
                                            // Calculate defaulted customers for this plan
                                            $sql_defaulted = "SELECT COUNT(DISTINCT m.id) as defaulted_count
                                                              FROM members m
                                                              WHERE m.plan_id = ?
                                                              AND m.id IN (
                                                                  SELECT DISTINCT es.member_id 
                                                                  FROM emi_schedule es 
                                                                  WHERE es.status = 'unpaid' 
                                                                  AND es.emi_due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                                              )";
                                            $stmt_def = $conn->prepare($sql_defaulted);
                                            $stmt_def->bind_param('i', $plan['id']);
                                            $stmt_def->execute();
                                            $result_def = $stmt_def->get_result();
                                            $defaulted_count = $result_def->fetch_assoc()['defaulted_count'] ?? 0;
                                            $stmt_def->close();
                                            
                                            $percentage = $stats['total_customers'] > 0 ? 
                                                round(($plan['customer_count'] / $stats['total_customers']) * 100, 1) : 0;
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($plan['title']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary"><?= $plan['customer_count']; ?></span>
                                                </td>
                                                <td>
                                                    <?= $plan['customer_count'] - $plan['completed_count']; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success"><?= $plan['completed_count']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger"><?= $defaulted_count; ?></span>
                                                </td>
                                                <td class="text-success fw-bold">
                                                    ₹<?= number_format($plan['avg_installment'], 2); ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="flex-grow-1 me-2">
                                                            <div class="progress" style="height: 5px;">
                                                                <div class="progress-bar bg-info" 
                                                                     role="progressbar" 
                                                                     style="width: <?= $percentage; ?>%">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div style="width: 40px;">
                                                            <small><?= $percentage; ?>%</small>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-3 text-muted">
                                                No plan distribution data available
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
    
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Modal for Customer Details -->
    <div class="modal fade" id="customerDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customer Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="customerDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .bg-primary-gradient {
            background: linear-gradient(45deg, #667eea, #764ba2);
        }
        
        .bg-success-gradient {
            background: linear-gradient(45deg, #11998e, #38ef7d);
        }
        
        .bg-info-gradient {
            background: linear-gradient(45deg, #00c6ff, #0072ff);
        }
        
        .bg-danger-gradient {
            background: linear-gradient(45deg, #dc3545, #fd7e14);
        }
        
        .bg-warning-gradient {
            background: linear-gradient(45deg, #ff9a00, #ff5e00);
        }
        
        .chart-container {
            position: relative;
        }
        
        .customer-card {
            transition: transform 0.2s;
        }
        
        .customer-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .progress {
            border-radius: 3px;
        }
        
        .progress-bar {
            border-radius: 3px;
        }
    </style>
    
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Plan Distribution Chart
            const planCtx = document.getElementById('planDistributionChart').getContext('2d');
            const planLabels = <?php echo json_encode(array_column($plan_distribution, 'title')); ?>;
            const planData = <?php echo json_encode(array_column($plan_distribution, 'customer_count')); ?>;
            
            const planChart = new Chart(planCtx, {
                type: 'bar',
                data: {
                    labels: planLabels,
                    datasets: [{
                        label: 'Customer Count',
                        data: planData,
                        backgroundColor: 'rgba(102, 126, 234, 0.7)',
                        borderColor: '#667eea',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                autoSkip: true,
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
            
            // Registration Trend Chart
            const regCtx = document.getElementById('registrationTrendChart').getContext('2d');
            const regMonths = <?php echo json_encode(array_column(array_reverse($registration_trend), 'month_year')); ?>;
            const regNewCustomers = <?php echo json_encode(array_column(array_reverse($registration_trend), 'new_customers')); ?>;
            const regCompleted = <?php echo json_encode(array_column(array_reverse($registration_trend), 'completed_in_month')); ?>;
            
            const regChart = new Chart(regCtx, {
                type: 'line',
                data: {
                    labels: regMonths,
                    datasets: [
                        {
                            label: 'New Customers',
                            data: regNewCustomers,
                            backgroundColor: 'rgba(102, 126, 234, 0.2)',
                            borderColor: '#667eea',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Completed',
                            data: regCompleted,
                            backgroundColor: 'rgba(40, 167, 69, 0.2)',
                            borderColor: '#28a745',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
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
        });
        
        // Show customer details
        function showCustomerDetails(customerId) {
            const modalContent = document.getElementById('customerDetailsContent');
            
            // Show loading
            modalContent.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading customer details...</p>
                </div>
            `;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('customerDetailsModal'));
            modal.show();
            
            // Load details via AJAX
            fetch(`ajax/get-customer-details.php?customer_id=${customerId}`)
                .then(response => response.text())
                .then(data => {
                    modalContent.innerHTML = data;
                })
                .catch(error => {
                    modalContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error loading customer details: ${error}
                        </div>
                    `;
                });
        }
        
        // Export to Excel
        function exportToExcel() {
            // Create a simple table for export
            const data = [
                ['Agreement No', 'Customer Name', 'Phone', 'Plan', 'Monthly EMI', 'Paid Amount', 'Pending Amount', 'Status', 'Member Since']
            ];
            
            <?php foreach ($customers as $customer): ?>
                data.push([
                    '<?= $customer['agreement_number']; ?>',
                    '<?= addslashes($customer['customer_name']); ?>',
                    '<?= $customer['customer_number']; ?>',
                    '<?= addslashes($customer['plan_title']); ?>',
                    '₹<?= number_format($customer['monthly_installment'], 2); ?>',
                    '₹<?= number_format($customer['payment_stats']['total_paid'] ?? 0, 2); ?>',
                    '₹<?= number_format($customer['pending_stats']['pending_amount'] ?? 0, 2); ?>',
                    '<?= $customer['status_text']; ?>',
                    '<?= date('d M Y', strtotime($customer['created_at'])); ?>'
                ]);
            <?php endforeach; ?>
            
            // Convert to CSV
            const csvContent = data.map(row => 
                row.map(cell => `"${cell}"`).join(',')
            ).join('\n');
            
            // Download CSV file
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('href', url);
            a.setAttribute('download', `customer-reports-<?= date('Y-m-d'); ?>.csv`);
            a.click();
        }
    </script>
</body>
</html>