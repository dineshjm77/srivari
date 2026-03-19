<?php
// bid-winner-reports.php - Bid Winner Reports (MODIFIED FOR COLLECTION BALANCE)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

// Get filter parameters
$filter_month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build WHERE clause for filters
$where_clause = "WHERE m.winner_amount > 0 AND m.winner_date IS NOT NULL";
$params = [];
$types = '';

if ($filter_month > 0 && $filter_year > 0) {
    $where_clause .= " AND MONTH(m.winner_date) = ? AND YEAR(m.winner_date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= 'ii';
}

if (!empty($start_date) && !empty($end_date)) {
    $where_clause .= " AND m.winner_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

if ($plan_filter > 0) {
    $where_clause .= " AND m.plan_id = ?";
    $params[] = $plan_filter;
    $types .= 'i';
}

// Handle payment status filter
if ($status_filter == 'paid') {
    $where_clause .= " AND m.paid_date IS NOT NULL";
} elseif ($status_filter == 'unpaid') {
    $where_clause .= " AND m.paid_date IS NULL";
}

// Fetch all plans for dropdown
$sql_plans = "SELECT id, title FROM plans ORDER BY title ASC";
$result_plans = $conn->query($sql_plans);
$plans = [];
while ($row = $result_plans->fetch_assoc()) {
    $plans[] = $row;
}

// Get bid winner statistics
$sql_stats = "SELECT 
                COUNT(DISTINCT m.id) as total_winners,
                SUM(m.winner_amount) as total_winner_amount,
                COUNT(DISTINCT CASE WHEN m.paid_date IS NOT NULL THEN m.id END) as paid_winners,
                SUM(CASE WHEN m.paid_date IS NOT NULL THEN m.winner_amount ELSE 0 END) as total_paid_amount,
                AVG(m.winner_amount) as avg_winner_amount,
                MIN(m.winner_date) as first_winner_date,
                MAX(m.winner_date) as last_winner_date
              FROM members m
              $where_clause";

$stmt_stats = $conn->prepare($sql_stats);
if (!empty($params)) {
    $stmt_stats->bind_param($types, ...$params);
}
$stmt_stats->execute();
$result_stats = $stmt_stats->get_result();
$stats = $result_stats->fetch_assoc();
$stmt_stats->close();

// Get total collection amount and available balance
$sql_total_collection = "SELECT 
                        SUM(es.emi_amount) as total_collected
                        FROM emi_schedule es
                        WHERE es.status = 'paid'";

$result_total_collection = $conn->query($sql_total_collection);
$total_collection = $result_total_collection->fetch_assoc()['total_collected'] ?? 0;

// Calculate available balance after bid winner settlements
$total_paid_to_winners = $stats['total_paid_amount'] ?? 0;
$total_winner_liability = $stats['total_winner_amount'] ?? 0;
$available_balance = $total_collection - $total_paid_to_winners;
$remaining_liability = $total_winner_liability - $total_paid_to_winners;

// Get bid winner details with plan information
$sql_winners = "SELECT 
                m.id,
                m.customer_name,
                m.agreement_number,
                m.customer_number as phone,
                m.customer_address as address,
                m.winner_amount,
                m.winner_date,
                m.winner_number,
                m.paid_date,
                m.payment_method,
                m.transaction_no,
                p.title as plan_title,
                p.total_received_amount as plan_total,
                u.full_name as declared_by,
                m.collected_by,
                -- Calculate member's total payments
                (SELECT SUM(es.emi_amount) FROM emi_schedule es 
                 WHERE es.member_id = m.id AND es.status = 'paid') as member_total_paid,
                -- Calculate member's available balance (payments - winner amount)
                (SELECT SUM(es.emi_amount) FROM emi_schedule es 
                 WHERE es.member_id = m.id AND es.status = 'paid') - m.winner_amount as member_balance_after_winner
              FROM members m
              JOIN plans p ON m.plan_id = p.id
              LEFT JOIN users u ON m.collected_by = u.id
              $where_clause
              ORDER BY m.winner_date DESC, m.winner_amount DESC";

$stmt_winners = $conn->prepare($sql_winners);
if (!empty($params)) {
    $stmt_winners->bind_param($types, ...$params);
}
$stmt_winners->execute();
$result_winners = $stmt_winners->get_result();
$winners = [];
$total_member_payments = 0;
while ($row = $result_winners->fetch_assoc()) {
    $winners[] = $row;
    $total_member_payments += $row['member_total_paid'] ?? 0;
}
$stmt_winners->close();

// Get monthly winner trend for chart
$sql_trend = "SELECT 
              DATE_FORMAT(m.winner_date, '%b %Y') as month_year,
              COUNT(DISTINCT m.id) as winner_count,
              SUM(m.winner_amount) as total_amount
              FROM members m
              WHERE m.winner_amount > 0 AND m.winner_date IS NOT NULL
              GROUP BY DATE_FORMAT(m.winner_date, '%Y-%m')
              ORDER BY m.winner_date DESC
              LIMIT 12";

$result_trend = $conn->query($sql_trend);
$monthly_trend = [];
while ($row = $result_trend->fetch_assoc()) {
    $monthly_trend[] = $row;
}

// Get plan-wise winner summary
$sql_plan_summary = "SELECT 
                    p.id,
                    p.title,
                    COUNT(DISTINCT m.id) as winner_count,
                    SUM(m.winner_amount) as total_amount,
                    AVG(m.winner_amount) as avg_amount,
                    MIN(m.winner_date) as first_winner,
                    MAX(m.winner_date) as last_winner,
                    -- Calculate plan's impact on balance
                    (SELECT SUM(es.emi_amount) FROM emi_schedule es 
                     JOIN members m2 ON es.member_id = m2.id 
                     WHERE m2.plan_id = p.id AND es.status = 'paid') as plan_total_collected,
                    (SELECT SUM(es.emi_amount) FROM emi_schedule es 
                     JOIN members m2 ON es.member_id = m2.id 
                     WHERE m2.plan_id = p.id AND es.status = 'paid') - 
                    COALESCE(SUM(m.winner_amount), 0) as plan_balance_after_winners
                    FROM plans p
                    LEFT JOIN members m ON p.id = m.plan_id 
                    AND m.winner_amount > 0 
                    AND m.winner_date IS NOT NULL
                    GROUP BY p.id, p.title
                    HAVING winner_count > 0
                    ORDER BY total_amount DESC";

$result_plan_summary = $conn->query($sql_plan_summary);
$plan_summary = [];
while ($row = $result_plan_summary->fetch_assoc()) {
    $plan_summary[] = $row;
}

// Calculate total unpaid amount
$total_unpaid = $stats['total_winner_amount'] - $stats['total_paid_amount'];

// Helper function to check payment status
function isWinnerPaid($winner) {
    return !empty($winner['paid_date']);
}

// Calculate percentages
$utilization_percentage = $total_collection > 0 ? ($total_paid_to_winners / $total_collection * 100) : 0;
$liability_percentage = $total_collection > 0 ? ($total_winner_liability / $total_collection * 100) : 0;
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
                $page_title = "Bid Winner Reports";
                $breadcrumb_active = "Bid Winner Analysis";
                include 'includes/breadcrumb.php';
                ?>
                
                <div class="row align-items-center mb-4">
                    <div class="col">
                        <h3 class="mb-0">Bid Winner Reports</h3>
                        <small class="text-muted">Track bid winners and their impact on collection balance</small>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Month</label>
                                <select class="form-control" name="month">
                                    <option value="0">All Months</option>
                                    <?php for($m=1; $m<=12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Year</label>
                                <select class="form-control" name="year">
                                    <?php for($y=2020; $y<=date('Y'); $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Plan</label>
                                <select class="form-control" name="plan_id">
                                    <option value="0">All Plans</option>
                                    <?php foreach ($plans as $plan): ?>
                                        <option value="<?php echo $plan['id']; ?>" <?php echo $plan_filter == $plan['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($plan['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-control" name="status">
                                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Winners</option>
                                    <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid Only</option>
                                    <option value="unpaid" <?php echo $status_filter == 'unpaid' ? 'selected' : ''; ?>>Unpaid Only</option>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mt-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-1"></i> Apply Filters
                                        </button>
                                        <a href="bid-winner-reports.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-sync me-1"></i> Reset
                                        </a>
                                    </div>
                                    <div>
                                        <button onclick="window.print()" class="btn btn-outline-primary">
                                            <i class="fas fa-print me-1"></i> Print
                                        </button>
                                        <button onclick="exportToExcel()" class="btn btn-outline-success">
                                            <i class="fas fa-file-excel me-1"></i> Export Excel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Statistics Cards with Balance Information -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-primary-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-hand-holding-usd fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Total Collection</h5>
                                        <h3 class="mb-0">₹<?php echo number_format($total_collection, 2); ?></h3>
                                        <small>All collected EMIs</small>
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
                                        <h5 class="text-white-50 fw-normal">Available Balance</h5>
                                        <h3 class="mb-0">₹<?php echo number_format($available_balance, 2); ?></h3>
                                        <small>After bid winner payments</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-warning-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-file-invoice-dollar fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Bid Winner Liability</h5>
                                        <h3 class="mb-0">₹<?php echo number_format($total_winner_liability, 2); ?></h3>
                                        <small><?php echo number_format($liability_percentage, 1); ?>% of collection</small>
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
                                        <i class="fas fa-users fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Total Winners</h5>
                                        <h3 class="mb-0"><?php echo number_format($stats['total_winners'] ?? 0); ?></h3>
                                        <small>₹<?php echo number_format($stats['avg_winner_amount'] ?? 0, 2); ?> avg</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Balance Breakdown -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Collection Balance Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6 class="text-muted">Total Collection</h6>
                                            <h3 class="text-primary mb-2">₹<?php echo number_format($total_collection, 2); ?></h3>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-primary" style="width: 100%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6 class="text-muted">Paid to Winners</h6>
                                            <h3 class="text-success mb-2">₹<?php echo number_format($total_paid_to_winners, 2); ?></h3>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo min($utilization_percentage, 100); ?>%"></div>
                                            </div>
                                            <small><?php echo number_format($utilization_percentage, 1); ?>% utilized</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6 class="text-muted">Remaining Liability</h6>
                                            <h3 class="text-warning mb-2">₹<?php echo number_format($remaining_liability, 2); ?></h3>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-warning" style="width: <?php echo min(($remaining_liability / $total_collection * 100), 100); ?>%"></div>
                                            </div>
                                            <small>To be paid to winners</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6 class="text-muted">Available Balance</h6>
                                            <h3 class="text-info mb-2">₹<?php echo number_format($available_balance, 2); ?></h3>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-info" style="width: <?php echo min(($available_balance / $total_collection * 100), 100); ?>%"></div>
                                            </div>
                                            <small>For operations & profit</small>
                                        </div>
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
                                <h4 class="card-title mb-0">Monthly Bid Winner Trend</h4>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="winnerTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Plan-wise Winners & Impact</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Plan</th>
                                                <th>Winners</th>
                                                <th>Total Amount</th>
                                                <th>Plan Collection</th>
                                                <th>Balance After</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($plan_summary)): ?>
                                                <?php foreach ($plan_summary as $plan): 
                                                    $plan_balance_impact = $plan['plan_total_collected'] ?? 0;
                                                    $plan_winner_total = $plan['total_amount'] ?? 0;
                                                    $plan_balance_after = $plan_balance_impact - $plan_winner_total;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($plan['title']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo $plan['winner_count']; ?></span>
                                                        </td>
                                                        <td class="text-success fw-bold">
                                                            ₹<?php echo number_format($plan['total_amount'], 2); ?>
                                                        </td>
                                                        <td>
                                                            ₹<?php echo number_format($plan_balance_impact, 2); ?>
                                                        </td>
                                                        <td class="<?php echo $plan_balance_after >= 0 ? 'text-info' : 'text-danger'; ?> fw-bold">
                                                            ₹<?php echo number_format($plan_balance_after, 2); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-3 text-muted">
                                                        No winner data available
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Winners Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Bid Winner Details</h4>
                            <small class="text-muted">Showing <?php echo count($winners); ?> winners</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="winnerTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Winner Details</th>
                                        <th>Plan</th>
                                        <th>Member Payments</th>
                                        <th>Winner Amount</th>
                                        <th>Balance After</th>
                                        <th>Winner Date</th>
                                        <th>Payment Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($winners)): ?>
                                        <?php foreach ($winners as $index => $winner): 
                                            $is_paid = isWinnerPaid($winner);
                                            $member_total_paid = $winner['member_total_paid'] ?? 0;
                                            $winner_amount = $winner['winner_amount'] ?? 0;
                                            $balance_after = $member_total_paid - $winner_amount;
                                        ?>
                                            <tr class="<?php echo $is_paid ? 'table-success' : 'table-warning'; ?>">
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm me-2">
                                                            <div class="avatar-title bg-light text-primary rounded-circle">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($winner['customer_name']); ?></h6>
                                                            <small class="text-muted"><?php echo $winner['agreement_number']; ?></small>
                                                            <?php if (!empty($winner['phone'])): ?>
                                                                <br><small class="text-muted"><?php echo htmlspecialchars($winner['phone']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($winner['plan_title']); ?>
                                                    <br><small class="text-muted">Plan: ₹<?php echo number_format($winner['plan_total'], 2); ?></small>
                                                </td>
                                                <td class="text-primary">
                                                    ₹<?php echo number_format($member_total_paid, 2); ?>
                                                    <?php if ($winner_amount > 0): ?>
                                                        <br><small class="text-muted"><?php echo number_format(($winner_amount / $member_total_paid * 100), 1); ?>% of payments</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-success fw-bold">
                                                    ₹<?php echo number_format($winner_amount, 2); ?>
                                                </td>
                                                <td class="<?php echo $balance_after >= 0 ? 'text-info' : 'text-danger'; ?> fw-bold">
                                                    ₹<?php echo number_format($balance_after, 2); ?>
                                                    <br><small class="text-muted">After winner payment</small>
                                                </td>
                                                <td>
                                                    <?php echo date('d M Y', strtotime($winner['winner_date'])); ?>
                                                    <?php if (!empty($winner['winner_number'])): ?>
                                                        <br><small class="badge bg-info">#<?php echo htmlspecialchars($winner['winner_number']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($is_paid): ?>
                                                        <span class="badge bg-success">Paid</span>
                                                        <?php if (!empty($winner['paid_date'])): ?>
                                                            <br><small><?php echo date('d M Y', strtotime($winner['paid_date'])); ?></small>
                                                        <?php endif; ?>
                                                        <?php if (!empty($winner['payment_method'])): ?>
                                                            <br><small class="text-muted"><?php echo ucfirst($winner['payment_method']); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Unpaid</span>
                                                        <br><small class="text-muted">Due payment</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <a href="emi-schedule-member.php?id=<?php echo $winner['id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary" title="View Schedule">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-outline-info"
                                                                onclick="viewWinnerDetails(<?php echo $winner['id']; ?>)" 
                                                                title="View Details">
                                                            <i class="fas fa-info-circle"></i>
                                                        </button>
                                                        <?php if (!$is_paid): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-success"
                                                                    onclick="markAsPaid(<?php echo $winner['id']; ?>)" 
                                                                    title="Mark as Paid">
                                                                <i class="fas fa-check-circle"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                No bid winners found for the selected filters.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3">TOTALS</th>
                                        <th class="text-primary">₹<?php echo number_format($total_member_payments, 2); ?></th>
                                        <th class="text-success">₹<?php echo number_format($total_winner_liability, 2); ?></th>
                                        <th class="text-info">₹<?php echo number_format($total_member_payments - $total_winner_liability, 2); ?></th>
                                        <th colspan="3"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <!-- Summary -->
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Winner Summary</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Total Winners:</span>
                                        <strong><?php echo number_format($stats['total_winners'] ?? 0); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Paid Winners:</span>
                                        <strong class="text-success"><?php echo number_format($stats['paid_winners'] ?? 0); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Unpaid Winners:</span>
                                        <strong class="text-warning"><?php echo number_format(($stats['total_winners'] ?? 0) - ($stats['paid_winners'] ?? 0)); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Amount Summary</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Total Collection:</span>
                                        <strong>₹<?php echo number_format($total_collection, 2); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Paid to Winners:</span>
                                        <strong class="text-success">₹<?php echo number_format($total_paid_to_winners, 2); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Remaining Liability:</span>
                                        <strong class="text-warning">₹<?php echo number_format($remaining_liability, 2); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Balance Summary</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Available Balance:</span>
                                        <strong class="text-info">₹<?php echo number_format($available_balance, 2); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Utilization Rate:</span>
                                        <strong><?php echo number_format($utilization_percentage, 1); ?>%</strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Liability Rate:</span>
                                        <strong><?php echo number_format($liability_percentage, 1); ?>%</strong>
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
    
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Modal for Winner Details -->
    <div class="modal fade" id="winnerDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Winner Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="winnerDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal for Mark as Paid -->
    <div class="modal fade" id="markPaidModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Mark as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="markPaidForm">
                        <input type="hidden" id="winnerId" name="winner_id">
                        <div class="mb-3">
                            <label class="form-label">Payment Date</label>
                            <input type="date" class="form-control" name="paid_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-control" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="upi">UPI</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction/Reference No.</label>
                            <input type="text" class="form-control" name="transaction_no" 
                                   placeholder="Optional">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="payment_notes" rows="3" 
                                      placeholder="Any additional notes..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitPayment()">Mark as Paid</button>
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
        
        .bg-warning-gradient {
            background: linear-gradient(45deg, #ff9a00, #ff5e00);
        }
        
        .bg-info-gradient {
            background: linear-gradient(45deg, #00c6ff, #0072ff);
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
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,0,0,0.02);
        }
        
        .balance-positive {
            color: #198754 !important;
        }
        
        .balance-negative {
            color: #dc3545 !important;
        }
    </style>
    
    <script>
        // Initialize Chart
        document.addEventListener('DOMContentLoaded', function() {
            // Winner Trend Chart
            const trendCtx = document.getElementById('winnerTrendChart').getContext('2d');
            const months = <?php echo json_encode(array_column(array_reverse($monthly_trend), 'month_year')); ?>;
            const counts = <?php echo json_encode(array_column(array_reverse($monthly_trend), 'winner_count')); ?>;
            const amounts = <?php echo json_encode(array_column(array_reverse($monthly_trend), 'total_amount')); ?>;
            
            const trendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'Winner Count',
                            data: counts,
                            backgroundColor: 'rgba(102, 126, 234, 0.2)',
                            borderColor: '#667eea',
                            borderWidth: 2,
                            fill: true,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Amount (₹)',
                            data: amounts,
                            backgroundColor: 'rgba(17, 153, 142, 0.2)',
                            borderColor: '#11998e',
                            borderWidth: 2,
                            fill: true,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label.includes('Amount')) {
                                        return label + ': ₹' + context.parsed.y.toLocaleString('en-IN');
                                    } else {
                                        return label + ': ' + context.parsed.y;
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Winner Count'
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Amount (₹)'
                            },
                            grid: {
                                drawOnChartArea: false,
                            },
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toLocaleString('en-IN');
                                }
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
        
        // View winner details
        function viewWinnerDetails(winnerId) {
            const modalContent = document.getElementById('winnerDetailsContent');
            
            // Show loading
            modalContent.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading winner details...</p>
                </div>
            `;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('winnerDetailsModal'));
            modal.show();
            
            // Load details via AJAX
            fetch(`ajax/get-winner-details.php?winner_id=${winnerId}`)
                .then(response => response.text())
                .then(data => {
                    modalContent.innerHTML = data;
                })
                .catch(error => {
                    modalContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error loading winner details: ${error}
                        </div>
                    `;
                });
        }
        
        // Mark as paid
        function markAsPaid(winnerId) {
            document.getElementById('winnerId').value = winnerId;
            const modal = new bootstrap.Modal(document.getElementById('markPaidModal'));
            modal.show();
        }
        
        // Submit payment
        function submitPayment() {
            const form = document.getElementById('markPaidForm');
            const formData = new FormData(form);
            
            fetch('ajax/mark-winner-paid.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error: ' + error);
            });
        }
        
        // Export to Excel
        function exportToExcel() {
            const table = document.getElementById('winnerTable');
            const rows = table.querySelectorAll('tr');
            let csv = [];
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                for (let j = 0; j < cols.length; j++) {
                    // Remove icons and badges for clean export
                    let text = cols[j].innerText.replace(/[₹]/g, '').trim();
                    row.push(`"${text}"`);
                }
                csv.push(row.join(','));
            }
            
            // Download CSV file
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('href', url);
            a.setAttribute('download', `bid-winner-reports-${new Date().toISOString().split('T')[0]}.csv`);
            a.click();
        }
    </script>
</body>
</html>