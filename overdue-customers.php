<?php
// overdue-members.php - List of Members with Overdue Payments with Filters
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
$filter_period = isset($_GET['period']) ? $_GET['period'] : 'all'; // all, this_month, last_month, this_week, last_week, custom
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'overdue'; // all, overdue, upcoming

// Get current date info
$current_year = date('Y');
$current_month = date('m');
$current_week = date('W');

// Initialize WHERE conditions
$where_conditions = ["es.status = 'unpaid'"];
$params = [];
$types = '';

// Apply period filters
switch ($filter_period) {
    case 'this_month':
        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $where_conditions[] = "es.emi_due_date BETWEEN ? AND ?";
        $params[] = $first_day;
        $params[] = $last_day;
        $types .= 'ss';
        break;
        
    case 'last_month':
        $first_day_last = date('Y-m-01', strtotime('-1 month'));
        $last_day_last = date('Y-m-t', strtotime('-1 month'));
        $where_conditions[] = "es.emi_due_date BETWEEN ? AND ?";
        $params[] = $first_day_last;
        $params[] = $last_day_last;
        $types .= 'ss';
        break;
        
    case 'this_week':
        $monday = date('Y-m-d', strtotime('monday this week'));
        $sunday = date('Y-m-d', strtotime('sunday this week'));
        $where_conditions[] = "es.emi_due_date BETWEEN ? AND ?";
        $params[] = $monday;
        $params[] = $sunday;
        $types .= 'ss';
        break;
        
    case 'last_week':
        $monday_last = date('Y-m-d', strtotime('monday last week'));
        $sunday_last = date('Y-m-d', strtotime('sunday last week'));
        $where_conditions[] = "es.emi_due_date BETWEEN ? AND ?";
        $params[] = $monday_last;
        $params[] = $sunday_last;
        $types .= 'ss';
        break;
        
    case 'custom':
        if (!empty($filter_from) && !empty($filter_to)) {
            $where_conditions[] = "es.emi_due_date BETWEEN ? AND ?";
            $params[] = $filter_from;
            $params[] = $filter_to;
            $types .= 'ss';
        }
        break;
        
    case 'specific_month':
        if ($filter_month > 0 && $filter_year > 0) {
            $first_day_month = date("{$filter_year}-{$filter_month}-01");
            $last_day_month = date("Y-m-t", strtotime($first_day_month));
            $where_conditions[] = "es.emi_due_date BETWEEN ? AND ?";
            $params[] = $first_day_month;
            $params[] = $last_day_month;
            $types .= 'ss';
        }
        break;
        
    case 'all':
        // No date filter for 'all'
        break;
}

// Apply overdue/upcoming filter
if ($filter_status == 'overdue') {
    $where_conditions[] = "es.emi_due_date < CURDATE()";
} elseif ($filter_status == 'upcoming') {
    $where_conditions[] = "es.emi_due_date >= CURDATE()";
}
// 'all' includes both overdue and upcoming - no additional condition

// Get months for dropdown (only months with unpaid EMIs)
$sql_months = "SELECT DISTINCT 
               MONTH(emi_due_date) as month_num, 
               YEAR(emi_due_date) as year_num,
               DATE_FORMAT(emi_due_date, '%M %Y') as month_year
               FROM emi_schedule 
               WHERE status = 'unpaid' 
               GROUP BY YEAR(emi_due_date), MONTH(emi_due_date)
               ORDER BY year_num DESC, month_num DESC";
$months_result = $conn->query($sql_months);
$months = [];
while ($row = $months_result->fetch_assoc()) {
    $months[] = $row;
}

// Build WHERE clause
$where_clause = implode(' AND ', $where_conditions);

// Debug: Show the WHERE clause and params
error_log("WHERE Clause: $where_clause");
error_log("Params: " . print_r($params, true));

// Fetch summary stats
$total_overdue_members = 0;
$total_overdue_amount = 0;
$total_pending_count = 0;

if (!empty($where_clause)) {
    $sql_summary = "
        SELECT 
            COUNT(DISTINCT es.member_id) AS overdue_members,
            SUM(es.emi_amount) AS overdue_amount,
            COUNT(es.id) AS pending_count
        FROM emi_schedule es
        WHERE $where_clause";
    
    $stmt_summary = $conn->prepare($sql_summary);
    if (!empty($params)) {
        $stmt_summary->bind_param($types, ...$params);
    }
    $stmt_summary->execute();
    $result_summary = $stmt_summary->get_result();
    if ($row = $result_summary->fetch_assoc()) {
        $total_overdue_members = $row['overdue_members'];
        $total_overdue_amount = $row['overdue_amount'] ?? 0;
        $total_pending_count = $row['pending_count'];
    }
    $stmt_summary->close();
}

// Fetch overdue members list
if (!empty($where_clause)) {
    $sql = "
        SELECT 
            m.id AS member_id,
            m.agreement_number,
            m.customer_name,
            m.customer_number,
            m.customer_number2,
            p.title AS plan_title,
            COUNT(es.id) AS pending_count,
            SUM(es.emi_amount) AS pending_amount,
            MIN(es.emi_due_date) AS earliest_due_date,
            MAX(es.emi_due_date) AS latest_due_date
        FROM members m
        JOIN plans p ON m.plan_id = p.id
        JOIN emi_schedule es ON es.member_id = m.id
        WHERE $where_clause
        GROUP BY m.id
        ORDER BY pending_amount DESC, latest_due_date ASC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $overdue_members = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $overdue_members[] = $row;
        }
    }
    $stmt->close();
} else {
    $overdue_members = [];
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
    .status-badge {
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 10px;
    }
    .date-range-input {
        max-width: 150px;
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
                $page_title = "Payment Tracking";
                $breadcrumb_active = "Pending Payments";
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
                        <h3 class="mb-0">Payment Tracking</h3>
                        <small class="text-muted">Monitor and manage overdue and upcoming payments</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-members.php" class="btn btn-light">Back to All Members</a>
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
                                <label class="form-label fw-bold">Quick Period Filter</label>
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

                            <!-- Status Filter -->
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Payment Status</label>
                                <select class="form-select" name="status" id="statusSelect">
                                    <option value="all" <?= $filter_status == 'all' ? 'selected' : '' ?>>All Payments</option>
                                    <option value="overdue" <?= $filter_status == 'overdue' ? 'selected' : '' ?>>Overdue Only</option>
                                    <option value="upcoming" <?= $filter_status == 'upcoming' ? 'selected' : '' ?>>Upcoming Only</option>
                                </select>
                            </div>

                            <!-- Month Filter -->
                            <div class="col-md-4">
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
                            <div class="col-md-4">
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
                                        <?php if ($filter_period != 'all' || $filter_status != 'overdue'): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold">Active Filters:</span>
                                            <?php if ($filter_period != 'all'): ?>
                                                <span class="filter-badge">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?= ucfirst(str_replace('_', ' ', $filter_period)) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($filter_status != 'overdue'): ?>
                                                <span class="filter-badge">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    <?= ucfirst($filter_status) ?> Payments
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
                                <h6 class="text-muted">Total Members</h6>
                                <h2 class="text-primary mb-0"><?= $total_overdue_members; ?></h2>
                                <small class="text-muted">With pending payments</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card border-warning">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Amount</h6>
                                <h2 class="text-warning mb-0">₹<?= number_format($total_overdue_amount, 2); ?></h2>
                                <small class="text-muted">Pending collection</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card border-info">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Pending EMIs</h6>
                                <h2 class="text-info mb-0"><?= $total_pending_count; ?></h2>
                                <small class="text-muted">Total installments</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card border-success">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Current Date</h6>
                                <h4 class="text-success mb-0"><?= date('d-m-Y'); ?></h4>
                                <small class="text-muted">Week <?= $current_week ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Members Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">
                            <?php if ($filter_status == 'overdue'): ?>
                                <i class="fas fa-exclamation-triangle text-danger me-2"></i>Overdue Payments
                            <?php elseif ($filter_status == 'upcoming'): ?>
                                <i class="fas fa-clock text-success me-2"></i>Upcoming Payments
                            <?php else: ?>
                                <i class="fas fa-list-alt text-primary me-2"></i>All Pending Payments
                            <?php endif; ?>
                        </h4>
                        <div class="d-flex gap-2">
                            <span class="badge bg-<?= $filter_status == 'overdue' ? 'danger' : ($filter_status == 'upcoming' ? 'success' : 'primary') ?>">
                                <?= $filter_status == 'all' ? 'All Payments' : ($filter_status == 'overdue' ? 'Overdue' : 'Upcoming') ?>
                            </span>
                            <span class="badge bg-secondary">
                                <?= count($overdue_members) ?> Members
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($overdue_members)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="paymentsTable">
                                    <thead class="table-<?= $filter_status == 'overdue' ? 'danger' : ($filter_status == 'upcoming' ? 'success' : 'primary') ?>">
                                        <tr>
                                            <th>#</th>
                                            <th>Agreement No.</th>
                                            <th>Customer Details</th>
                                            <th>Plan</th>
                                            <th>Pending EMIs</th>
                                            <th>Amount (₹)</th>
                                            <th>Due Dates</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdue_members as $index => $member): 
                                            $earliest_date = strtotime($member['earliest_due_date']);
                                            $latest_date = strtotime($member['latest_due_date']);
                                            $today = time();
                                            $is_overdue = $latest_date < $today;
                                            $is_upcoming = $earliest_date > $today;
                                            $is_mixed = $earliest_date < $today && $latest_date > $today;
                                            
                                            // Calculate days difference
                                            if ($is_overdue) {
                                                $days_diff = floor(($today - $latest_date) / (60 * 60 * 24));
                                                $days_text = $days_diff . ' day' . ($days_diff != 1 ? 's' : '') . ' overdue';
                                            } elseif ($is_upcoming) {
                                                $days_diff = floor(($earliest_date - $today) / (60 * 60 * 24));
                                                $days_text = $days_diff . ' day' . ($days_diff != 1 ? 's' : '') . ' left';
                                            } else {
                                                $days_text = 'Mixed dates';
                                            }
                                        ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($member['agreement_number']); ?></strong>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?= htmlspecialchars($member['customer_name']); ?></strong><br>
                                                        <small class="text-muted">
                                                            <i class="fas fa-phone"></i> <?= htmlspecialchars($member['customer_number']); ?>
                                                            <?php if (!empty($member['customer_number2'])): ?>
                                                                <br><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($member['customer_number2']); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($member['plan_title']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?= $is_overdue ? 'danger' : ($is_upcoming ? 'success' : 'warning') ?>">
                                                        <?= $member['pending_count']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong class="text-<?= $is_overdue ? 'danger' : ($is_upcoming ? 'success' : 'warning') ?>">
                                                        ₹<?= number_format($member['pending_amount'], 2); ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <small>
                                                        <i class="fas fa-calendar-check"></i> 
                                                        <?= date('d-m-Y', $earliest_date); ?><br>
                                                        <i class="fas fa-calendar-times"></i> 
                                                        <?= date('d-m-Y', $latest_date); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($is_overdue): ?>
                                                        <span class="badge bg-danger status-badge">
                                                            <i class="fas fa-exclamation-triangle"></i> Overdue
                                                        </span>
                                                    <?php elseif ($is_upcoming): ?>
                                                        <span class="badge bg-success status-badge">
                                                            <i class="fas fa-clock"></i> Upcoming
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning status-badge">
                                                            <i class="fas fa-history"></i> Mixed
                                                        </span>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small class="text-muted"><?= $days_text ?></small>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <a href="emi-schedule-member.php?id=<?= $member['member_id']; ?>"
                                                           class="btn btn-sm btn-primary" title="View Schedule">
                                                            <i class="fas fa-calendar-alt"></i>
                                                        </a>
                                                        <a href="pay-emi.php?member=<?= $member['member_id']; ?>" 
                                                           class="btn btn-sm btn-success" title="Collect Payment">
                                                            <i class="fas fa-rupee-sign"></i>
                                                        </a>
                                                        <a href="https://wa.me/91<?= preg_replace('/\D/', '', $member['customer_number']); ?>?text=<?= urlencode("Dear " . $member['customer_name'] . ", This is a reminder for your pending payment of ₹" . number_format($member['pending_amount'], 2) . " for agreement " . $member['agreement_number'] . ". Please make the payment at your earliest convenience. - Sri Vari Chits") ?>" 
                                                           class="btn btn-sm btn-outline-success" target="_blank" title="Send WhatsApp">
                                                            <i class="fab fa-whatsapp"></i>
                                                        </a>
                                                    </div>
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
                                        Showing <?= count($overdue_members); ?> member(s) with 
                                        <?= $total_pending_count; ?> pending EMI(s)
                                    </small>
                                </div>
                                <div class="btn-group">
                                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                                        <i class="fas fa-print me-1"></i> Print
                                    </button>
                                    <button class="btn btn-outline-success btn-sm" id="exportBtn">
                                        <i class="fas fa-file-excel me-1"></i> Export
                                    </button>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                                <h4 class="text-success">No Payments Found!</h4>
                                <p class="text-muted">No payments match your current filters.</p>
                                <a href="overdue-members.php" class="btn btn-primary mt-2">
                                    <i class="fas fa-redo me-1"></i> Clear Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Summary Report -->
                <?php if (!empty($overdue_members)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i> Summary Report</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Filter Summary:</h6>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Period:</span>
                                        <span class="fw-bold"><?= ucfirst(str_replace('_', ' ', $filter_period)) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Status:</span>
                                        <span class="fw-bold"><?= ucfirst($filter_status) ?> Payments</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Date Range:</span>
                                        <span class="fw-bold">
                                            <?php if ($filter_period == 'specific_month'): ?>
                                                <?= getMonthName($filter_month) ?> <?= $filter_year ?>
                                            <?php elseif (!empty($filter_from) && !empty($filter_to) && $filter_period == 'custom'): ?>
                                                <?= date('d M Y', strtotime($filter_from)) ?> - <?= date('d M Y', strtotime($filter_to)) ?>
                                            <?php else: ?>
                                                All Dates
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Financial Summary:</h6>
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Total Members:</span>
                                        <span class="fw-bold"><?= $total_overdue_members ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Total Pending EMIs:</span>
                                        <span class="fw-bold"><?= $total_pending_count ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Total Amount:</span>
                                        <span class="fw-bold text-danger">₹<?= number_format($total_overdue_amount, 2) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span>Average per Member:</span>
                                        <span class="fw-bold text-info">
                                            ₹<?= $total_overdue_members > 0 ? number_format($total_overdue_amount / $total_overdue_members, 2) : '0.00' ?>
                                        </span>
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
        link.setAttribute('download', 'pending_payments_<?= date('Y-m-d') ?>.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });
    
    // Set max date for date inputs to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('fromDate').max = today;
    document.getElementById('toDate').max = today;
    
    // Auto-submit when status changes
    document.getElementById('statusSelect').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    // Initialize date inputs with default values if empty
    document.addEventListener('DOMContentLoaded', function() {
        const fromDate = document.getElementById('fromDate');
        const toDate = document.getElementById('toDate');
        
        if (!fromDate.value && !toDate.value && <?= $filter_period == 'custom' ? 'true' : 'false' ?>) {
            // Set default to last 30 days if custom period is selected but no dates
            const thirtyDaysAgo = new Date();
            thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
            fromDate.value = thirtyDaysAgo.toISOString().split('T')[0];
            toDate.value = today;
        }
    });
    </script>
</body>
</html>