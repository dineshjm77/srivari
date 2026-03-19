<?php
// history-paid-list.php - History of Paid EMIs with Filtering
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Initialize filter variables
$filter_month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$filter_from = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$filter_to = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$filter_period = isset($_GET['period']) ? $_GET['period'] : 'all';
$filter_payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : 'all';
$filter_collector = isset($_GET['collector']) ? intval($_GET['collector']) : 0;

// Get current date info
$current_year = date('Y');
$current_month = date('m');
$current_week = date('W');

// Get collectors list
$collectors_sql = "SELECT id, username, full_name FROM users WHERE role IN ('staff', 'admin', 'accountant') AND status = 'active' ORDER BY full_name";
$collectors_result = $conn->query($collectors_sql);
$collectors = [];
while ($row = $collectors_result->fetch_assoc()) {
    $collectors[] = $row;
}

// Initialize WHERE conditions
$where_conditions = ["es.status = 'paid'"];
$params = [];
$types = '';

// Apply period filters
switch ($filter_period) {
    case 'this_month':
        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $where_conditions[] = "es.paid_date BETWEEN ? AND ?";
        $params[] = $first_day;
        $params[] = $last_day;
        $types .= 'ss';
        break;
        
    case 'last_month':
        $first_day_last = date('Y-m-01', strtotime('-1 month'));
        $last_day_last = date('Y-m-t', strtotime('-1 month'));
        $where_conditions[] = "es.paid_date BETWEEN ? AND ?";
        $params[] = $first_day_last;
        $params[] = $last_day_last;
        $types .= 'ss';
        break;
        
    case 'this_week':
        $monday = date('Y-m-d', strtotime('monday this week'));
        $sunday = date('Y-m-d', strtotime('sunday this week'));
        $where_conditions[] = "es.paid_date BETWEEN ? AND ?";
        $params[] = $monday;
        $params[] = $sunday;
        $types .= 'ss';
        break;
        
    case 'last_week':
        $monday_last = date('Y-m-d', strtotime('monday last week'));
        $sunday_last = date('Y-m-d', strtotime('sunday last week'));
        $where_conditions[] = "es.paid_date BETWEEN ? AND ?";
        $params[] = $monday_last;
        $params[] = $sunday_last;
        $types .= 'ss';
        break;
        
    case 'custom':
        if (!empty($filter_from) && !empty($filter_to)) {
            $where_conditions[] = "es.paid_date BETWEEN ? AND ?";
            $params[] = $filter_from;
            $params[] = $filter_to;
            $types .= 'ss';
        }
        break;
        
    case 'specific_month':
        if ($filter_month > 0 && $filter_year > 0) {
            $first_day_month = date("{$filter_year}-{$filter_month}-01");
            $last_day_month = date("Y-m-t", strtotime($first_day_month));
            $where_conditions[] = "es.paid_date BETWEEN ? AND ?";
            $params[] = $first_day_month;
            $params[] = $last_day_month;
            $types .= 'ss';
        }
        break;
        
    case 'all':
        // No date filter for 'all'
        break;
}

// Apply payment type filter
if ($filter_payment_type != 'all') {
    $where_conditions[] = "es.payment_type = ?";
    $params[] = $filter_payment_type;
    $types .= 's';
}

// Apply collector filter
if ($filter_collector > 0) {
    $where_conditions[] = "es.collected_by = ?";
    $params[] = $filter_collector;
    $types .= 'i';
}

// Get months for dropdown
$sql_months = "SELECT DISTINCT 
               MONTH(paid_date) as month_num, 
               YEAR(paid_date) as year_num,
               DATE_FORMAT(paid_date, '%M %Y') as month_year
               FROM emi_schedule 
               WHERE status = 'paid' 
               GROUP BY YEAR(paid_date), MONTH(paid_date)
               ORDER BY year_num DESC, month_num DESC";
$months_result = $conn->query($sql_months);
$months = [];
while ($row = $months_result->fetch_assoc()) {
    $months[] = $row;
}

// Build WHERE clause
$where_clause = implode(' AND ', $where_conditions);

// Fetch summary stats
$total_payments = 0;
$total_amount = 0;
$total_cash = 0;
$total_upi = 0;
$total_both = 0;

if (!empty($where_clause)) {
    $sql_summary = "
        SELECT 
            COUNT(es.id) AS total_payments,
            SUM(es.emi_amount) AS total_amount,
            SUM(CASE WHEN es.payment_type = 'cash' THEN es.emi_amount ELSE 0 END) AS total_cash,
            SUM(CASE WHEN es.payment_type = 'upi' THEN es.emi_amount ELSE 0 END) AS total_upi,
            SUM(CASE WHEN es.payment_type = 'both' THEN es.emi_amount ELSE 0 END) AS total_both
        FROM emi_schedule es
        WHERE $where_clause";
    
    $stmt_summary = $conn->prepare($sql_summary);
    if (!empty($params)) {
        $stmt_summary->bind_param($types, ...$params);
    }
    $stmt_summary->execute();
    $result_summary = $stmt_summary->get_result();
    if ($row = $result_summary->fetch_assoc()) {
        $total_payments = $row['total_payments'];
        $total_amount = $row['total_amount'] ?? 0;
        $total_cash = $row['total_cash'] ?? 0;
        $total_upi = $row['total_upi'] ?? 0;
        $total_both = $row['total_both'] ?? 0;
    }
    $stmt_summary->close();
}

// Fetch paid EMIs list
if (!empty($where_clause)) {
    $sql = "
        SELECT 
            es.id AS emi_id,
            es.emi_amount,
            es.payment_type,
            es.cash_amount,
            es.upi_amount,
            es.paid_date,
            es.emi_due_date,
            es.emi_bill_number,
            es.undo_reason,
            m.id AS member_id,
            m.agreement_number,
            m.customer_name,
            m.customer_number,
            p.title AS plan_title,
            u.username AS collector_username,
            u.full_name AS collector_name
        FROM emi_schedule es
        JOIN members m ON es.member_id = m.id
        JOIN plans p ON m.plan_id = p.id
        LEFT JOIN users u ON es.collected_by = u.id
        WHERE $where_clause
        ORDER BY es.paid_date DESC, es.id DESC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $paid_emis = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $paid_emis[] = $row;
        }
    }
    $stmt->close();
} else {
    $paid_emis = [];
}

// Function to get month name
function getMonthName($month) {
    $months = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    return $months[$month] ?? '';
}

// Function to get payment type label
function getPaymentTypeLabel($type) {
    $labels = [
        'cash' => 'Cash',
        'upi' => 'UPI',
        'both' => 'Cash + UPI'
    ];
    return $labels[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .filter-card {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .filter-badge {
        background: linear-gradient(45deg, #6c757d, #495057);
        color: white;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
    }
    .period-btn {
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }
    .period-btn.active {
        border-color: #0d6efd;
        background-color: #e7f1ff;
    }
    .stats-card {
        border-radius: 10px;
        overflow: hidden;
        transition: transform 0.3s ease;
        border: none;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .stats-card:hover {
        transform: translateY(-5px);
    }
    .payment-type-badge {
        font-size: 0.75rem;
        padding: 4px 10px;
        border-radius: 12px;
    }
    .date-range-input {
        max-width: 150px;
    }
    .undo-badge {
        background: linear-gradient(45deg, #dc3545, #c82333);
        color: white;
        font-size: 0.7rem;
        padding: 2px 8px;
        border-radius: 10px;
        margin-left: 5px;
    }
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }
</style>
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
                $page_title = "Payment History";
                $breadcrumb_active = "Paid EMIs List";
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
                        <h3 class="mb-0">Payment History</h3>
                        <small class="text-muted">Track all collected payments and receipts</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-members.php" class="btn btn-light">Back to Members</a>
                        <a href="overdue-members.php" class="btn btn-warning ms-2">View Overdue</a>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card filter-card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i> Filter Options</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3" id="filterForm">
                            <!-- Period Filter -->
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Payment Date Period</label>
                                <div class="btn-group btn-group-sm d-flex flex-wrap" role="group">
                                    <button type="button" class="btn btn-outline-primary period-btn <?= $filter_period == 'all' ? 'active' : '' ?>" 
                                            onclick="setPeriod('all')">
                                        All Time
                                    </button>
                                    <button type="button" class="btn btn-outline-primary period-btn <?= $filter_period == 'this_week' ? 'active' : '' ?>" 
                                            onclick="setPeriod('this_week')">
                                        This Week
                                    </button>
                                    <button type="button" class="btn btn-outline-primary period-btn <?= $filter_period == 'last_week' ? 'active' : '' ?>" 
                                            onclick="setPeriod('last_week')">
                                        Last Week
                                    </button>
                                    <button type="button" class="btn btn-outline-primary period-btn <?= $filter_period == 'this_month' ? 'active' : '' ?>" 
                                            onclick="setPeriod('this_month')">
                                        This Month
                                    </button>
                                    <button type="button" class="btn btn-outline-primary period-btn <?= $filter_period == 'last_month' ? 'active' : '' ?>" 
                                            onclick="setPeriod('last_month')">
                                        Last Month
                                    </button>
                                </div>
                                <input type="hidden" name="period" id="periodInput" value="<?= $filter_period ?>">
                            </div>

                            <!-- Payment Type Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Payment Type</label>
                                <select class="form-select" name="payment_type" id="paymentTypeSelect">
                                    <option value="all" <?= $filter_payment_type == 'all' ? 'selected' : '' ?>>All Types</option>
                                    <option value="cash" <?= $filter_payment_type == 'cash' ? 'selected' : '' ?>>Cash Only</option>
                                    <option value="upi" <?= $filter_payment_type == 'upi' ? 'selected' : '' ?>>UPI Only</option>
                                    <option value="both" <?= $filter_payment_type == 'both' ? 'selected' : '' ?>>Cash + UPI</option>
                                </select>
                            </div>

                            <!-- Collector Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Collected By</label>
                                <select class="form-select" name="collector" id="collectorSelect">
                                    <option value="0">All Collectors</option>
                                    <?php foreach($collectors as $collector): ?>
                                        <option value="<?= $collector['id'] ?>" <?= $filter_collector == $collector['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($collector['full_name'] ?: $collector['username']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Month Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Specific Month</label>
                                <div class="input-group">
                                    <select class="form-select" name="month" id="monthSelect">
                                        <option value="0">Select Month</option>
                                        <?php foreach($months as $m): ?>
                                            <option value="<?= $m['month_num'] ?>" 
                                                    data-year="<?= $m['year_num'] ?>"
                                                    <?= ($filter_month == $m['month_num'] && $filter_period == 'specific_month') ? 'selected' : '' ?>>
                                                <?= $m['month_year'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="year" id="yearInput" value="<?= $filter_year ?>">
                                    <button type="button" class="btn btn-primary" onclick="applyMonthFilter()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Date Range Filter -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Custom Date Range</label>
                                <div class="input-group">
                                    <input type="date" class="form-control date-range-input" name="from_date" 
                                           value="<?= $filter_from ?>" placeholder="From Date" id="fromDate">
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control date-range-input" name="to_date" 
                                           value="<?= $filter_to ?>" placeholder="To Date" id="toDate">
                                    <button type="button" class="btn btn-primary" onclick="applyDateRangeFilter()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search me-1"></i> Apply Filters
                                        </button>
                                        
                                        <!-- Active Filter Badges -->
                                        <?php if ($filter_period != 'all' || $filter_payment_type != 'all' || $filter_collector > 0): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold">Active Filters:</span>
                                            <?php if ($filter_period != 'all'): ?>
                                                <span class="filter-badge">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?= ucfirst(str_replace('_', ' ', $filter_period)) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($filter_payment_type != 'all'): ?>
                                                <span class="filter-badge">
                                                    <i class="fas fa-money-bill-wave me-1"></i>
                                                    <?= getPaymentTypeLabel($filter_payment_type) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($filter_collector > 0): ?>
                                                <?php 
                                                $collector_name = '';
                                                foreach($collectors as $c) {
                                                    if ($c['id'] == $filter_collector) {
                                                        $collector_name = $c['full_name'] ?: $c['username'];
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <span class="filter-badge">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?= htmlspecialchars($collector_name) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($filter_period == 'specific_month' && $filter_month > 0): ?>
                                                <span class="filter-badge">
                                                    <i class="fas fa-calendar-alt me-1"></i>
                                                    <?= getMonthName($filter_month) ?> <?= $filter_year ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($filter_from) && !empty($filter_to) && $filter_period == 'custom'): ?>
                                                <span class="filter-badge">
                                                    <i class="fas fa-calendar-day me-1"></i>
                                                    <?= date('d-m-Y', strtotime($filter_from)) ?> to <?= date('d-m-Y', strtotime($filter_to)) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <a href="history-paid-list.php" class="btn btn-outline-danger">
                                        <i class="fas fa-times me-1"></i> Clear All
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card border-primary">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Payments</h6>
                                <h2 class="text-primary mb-0"><?= $total_payments; ?></h2>
                                <small class="text-muted">Collected EMIs</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card border-success">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Amount</h6>
                                <h2 class="text-success mb-0">₹<?= number_format($total_amount, 2); ?></h2>
                                <small class="text-muted">Collected amount</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card border-info">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Cash</h6>
                                <h4 class="text-info mb-0">₹<?= number_format($total_cash, 2); ?></h4>
                                <small class="text-muted">Cash payments</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card border-warning">
                            <div class="card-body text-center">
                                <h6 class="text-muted">UPI</h6>
                                <h4 class="text-warning mb-0">₹<?= number_format($total_upi, 2); ?></h4>
                                <small class="text-muted">UPI payments</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card border-secondary">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Both</h6>
                                <h4 class="text-secondary mb-0">₹<?= number_format($total_both, 2); ?></h4>
                                <small class="text-muted">Mixed payments</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Paid EMIs Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-history text-primary me-2"></i>Payment History
                        </h4>
                        <div class="d-flex gap-2">
                            <span class="badge bg-primary">
                                <?= count($paid_emis) ?> Payments
                            </span>
                            <span class="badge bg-success">
                                ₹<?= number_format($total_amount, 2) ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($paid_emis)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="paymentsTable">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>#</th>
                                            <th>Receipt Details</th>
                                            <th>Member Details</th>
                                            <th>Plan</th>
                                            <th>Payment Info</th>
                                            <th>Dates</th>
                                            <th>Collected By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paid_emis as $index => $emi): 
                                            $payment_type_class = '';
                                            if ($emi['payment_type'] == 'cash') {
                                                $payment_type_class = 'bg-success';
                                            } elseif ($emi['payment_type'] == 'upi') {
                                                $payment_type_class = 'bg-primary';
                                            } else {
                                                $payment_type_class = 'bg-warning';
                                            }
                                            
                                            $is_undone = !empty($emi['undo_reason']);
                                        ?>
                                            <tr <?= $is_undone ? 'class="table-danger"' : '' ?>>
                                                <td><?= $index + 1; ?></td>
                                                <td>
                                                    <div>
                                                        <strong class="text-primary">Bill: <?= htmlspecialchars($emi['emi_bill_number'] ?: 'N/A') ?></strong><br>
                                                        <small class="text-muted">Amount: ₹<?= number_format($emi['emi_amount'], 2) ?></small>
                                                        <?php if ($is_undone): ?>
                                                            <br><span class="undo-badge">
                                                                <i class="fas fa-undo me-1"></i>Undone
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?= htmlspecialchars($emi['customer_name']); ?></strong><br>
                                                        <small class="text-muted">
                                                            <i class="fas fa-file-contract"></i> <?= htmlspecialchars($emi['agreement_number']); ?><br>
                                                            <i class="fas fa-phone"></i> <?= htmlspecialchars($emi['customer_number']); ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($emi['plan_title']); ?></td>
                                                <td>
                                                    <div>
                                                        <span class="badge <?= $payment_type_class ?> payment-type-badge">
                                                            <?= getPaymentTypeLabel($emi['payment_type']) ?>
                                                        </span>
                                                        <?php if ($emi['payment_type'] == 'both'): ?>
                                                            <br>
                                                            <small class="text-muted">
                                                                Cash: ₹<?= number_format($emi['cash_amount'], 2) ?><br>
                                                                UPI: ₹<?= number_format($emi['upi_amount'], 2) ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small>
                                                        <i class="fas fa-calendar-check text-success"></i> 
                                                        <?= date('d-m-Y', strtotime($emi['paid_date'])); ?><br>
                                                        <i class="fas fa-calendar-alt text-info"></i> 
                                                        Due: <?= date('d-m-Y', strtotime($emi['emi_due_date'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($emi['collector_name'])): ?>
                                                        <small>
                                                            <i class="fas fa-user-circle"></i> 
                                                            <?= htmlspecialchars($emi['collector_name']) ?><br>
                                                            <span class="text-muted">(@<?= htmlspecialchars($emi['collector_username']) ?>)</span>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not recorded</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="emi-schedule-member.php?id=<?= $emi['member_id']; ?>"
                                                           class="btn btn-sm btn-outline-primary" title="View Member">
                                                            <i class="fas fa-user"></i>
                                                        </a>
                                                        <a href="history-paid-list.php?print_receipt=<?= $emi['emi_id'] ?>" 
                                                           class="btn btn-sm btn-outline-info" title="Print Receipt" target="_blank">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                        <a href="https://wa.me/91<?= preg_replace('/\D/', '', $emi['customer_number']); ?>?text=<?= urlencode("Receipt - Sri Vari Chits\n\nDear " . $emi['customer_name'] . ",\nYour payment of ₹" . number_format($emi['emi_amount'], 2) . " has been received.\nBill No: " . $emi['emi_bill_number'] . "\nDate: " . date('d-m-Y', strtotime($emi['paid_date'])) . "\nPayment Type: " . getPaymentTypeLabel($emi['payment_type']) . "\n\nThank you!") ?>" 
                                                           class="btn btn-sm btn-outline-success" target="_blank" title="Send WhatsApp Receipt">
                                                            <i class="fab fa-whatsapp"></i>
                                                        </a>
                                                        <?php if (!$is_undone): ?>
                                                            <a href="pay-emi.php?undo=<?= $emi['emi_id']; ?>&member=<?= $emi['member_id']; ?>" 
                                                               class="btn btn-sm btn-outline-danger" title="Undo Payment">
                                                                <i class="fas fa-undo"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($is_undone): ?>
                                                        <small class="text-danger d-block mt-1">
                                                            <i class="fas fa-info-circle"></i> Undone: <?= htmlspecialchars($emi['undo_reason']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Export Options -->
                            <div class="mt-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted">
                                        Showing <?= count($paid_emis); ?> payment(s) totaling ₹<?= number_format($total_amount, 2) ?>
                                    </small>
                                </div>
                                <div class="btn-group">
                                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                                        <i class="fas fa-print me-1"></i> Print
                                    </button>
                                    <button class="btn btn-outline-success btn-sm" id="exportBtn">
                                        <i class="fas fa-file-excel me-1"></i> Export
                                    </button>
                                    <a href="history-paid-list.php?export_pdf=1&<?= $_SERVER['QUERY_STRING'] ?>" 
                                       class="btn btn-outline-danger btn-sm" target="_blank">
                                        <i class="fas fa-file-pdf me-1"></i> PDF
                                    </a>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                <h4 class="text-muted">No Payments Found!</h4>
                                <p class="text-muted">No payments match your current filters.</p>
                                <a href="history-paid-list.php" class="btn btn-primary mt-2">
                                    <i class="fas fa-redo me-1"></i> Clear Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Summary Report -->
                <?php if (!empty($paid_emis)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i> Payment Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6>Payment Type Distribution:</h6>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span><span class="badge bg-success me-2">●</span> Cash Payments:</span>
                                        <span class="fw-bold text-success">₹<?= number_format($total_cash, 2) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span><span class="badge bg-primary me-2">●</span> UPI Payments:</span>
                                        <span class="fw-bold text-primary">₹<?= number_format($total_upi, 2) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span><span class="badge bg-warning me-2">●</span> Mixed Payments:</span>
                                        <span class="fw-bold text-warning">₹<?= number_format($total_both, 2) ?></span>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6>Date Range Analysis:</h6>
                                <ul class="list-group list-group-flush">
                                    <?php if (!empty($paid_emis)): 
                                        $first_date = $paid_emis[count($paid_emis)-1]['paid_date'];
                                        $last_date = $paid_emis[0]['paid_date'];
                                        $date1 = new DateTime($first_date);
                                        $date2 = new DateTime($last_date);
                                        $interval = $date1->diff($date2);
                                    ?>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Period Covered:</span>
                                        <span class="fw-bold"><?= $interval->days ?> days</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Average Daily:</span>
                                        <span class="fw-bold text-info">
                                            ₹<?= $interval->days > 0 ? number_format($total_amount / $interval->days, 2) : number_format($total_amount, 2) ?>
                                        </span>
                                    </li>
                                    <?php endif; ?>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Average Payment:</span>
                                        <span class="fw-bold text-info">
                                            ₹<?= $total_payments > 0 ? number_format($total_amount / $total_payments, 2) : '0.00' ?>
                                        </span>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6>Filter Summary:</h6>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Period:</span>
                                        <span class="fw-bold"><?= ucfirst(str_replace('_', ' ', $filter_period)) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Payment Type:</span>
                                        <span class="fw-bold"><?= $filter_payment_type == 'all' ? 'All Types' : getPaymentTypeLabel($filter_payment_type) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Collector:</span>
                                        <span class="fw-bold"><?= $filter_collector == 0 ? 'All Collectors' : 'Selected' ?></span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>

            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
    <script>
    // JavaScript functions for filter handling
    function setPeriod(period) {
        document.getElementById('periodInput').value = period;
        // Reset other date inputs when selecting quick period
        if (period !== 'custom' && period !== 'specific_month') {
            document.getElementById('fromDate').value = '';
            document.getElementById('toDate').value = '';
            document.getElementById('monthSelect').value = '0';
        }
        // Submit the form
        document.getElementById('filterForm').submit();
    }
    
    function applyMonthFilter() {
        const monthSelect = document.getElementById('monthSelect');
        const yearInput = document.getElementById('yearInput');
        const selectedOption = monthSelect.options[monthSelect.selectedIndex];
        
        if (monthSelect.value > 0) {
            document.getElementById('periodInput').value = 'specific_month';
            yearInput.value = selectedOption.getAttribute('data-year');
            document.getElementById('filterForm').submit();
        }
    }
    
    function applyDateRangeFilter() {
        const fromDate = document.getElementById('fromDate').value;
        const toDate = document.getElementById('toDate').value;
        
        if (fromDate && toDate) {
            document.getElementById('periodInput').value = 'custom';
            document.getElementById('filterForm').submit();
        } else {
            alert('Please select both from and to dates.');
        }
    }
    
    // Export functionality
    document.getElementById('exportBtn').addEventListener('click', function() {
        let table = document.getElementById('paymentsTable');
        let rows = table.querySelectorAll('tr');
        let csv = [];
        
        for (let i = 0; i < rows.length; i++) {
            let row = [], cols = rows[i].querySelectorAll('td, th');
            
            for (let j = 0; j < cols.length; j++) {
                // Remove icons and badges for clean export
                let text = cols[j].innerText.replace(/[\n\r]+|[\s]{2,}/g, ' ').trim();
                row.push('"' + text + '"');
            }
            
            csv.push(row.join(','));
        }
        
        let csvContent = csv.join('\n');
        let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        let url = URL.createObjectURL(blob);
        let link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', 'payment_history_<?= date('Y-m-d') ?>.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });
    
    // Set max date for date inputs to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('fromDate').max = today;
    document.getElementById('toDate').max = today;
    
    // Auto-submit when filters change
    document.getElementById('paymentTypeSelect').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.getElementById('collectorSelect').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    </script>
</body>
</html>