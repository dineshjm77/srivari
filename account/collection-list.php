<?php
// collection-list.php - Collection List with Filters
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


include 'includes/db.php';

// Get filter parameters
$filter_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
$payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : 'all';
$collector_filter = isset($_GET['collector_id']) ? intval($_GET['collector_id']) : 0;
$show_details = isset($_GET['show_details']) ? true : false;

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

if ($collector_filter > 0) {
    $where_clause .= " AND es.collected_by = ?";
    $params[] = $collector_filter;
    $types .= 'i';
}

// Fetch all plans for dropdown
$sql_plans = "SELECT id, title FROM plans ORDER BY title ASC";
$result_plans = $conn->query($sql_plans);
$plans = [];
while ($row = $result_plans->fetch_assoc()) {
    $plans[] = $row;
}

// Fetch all collectors (users)
$sql_collectors = "SELECT id, full_name FROM users WHERE role IN ('admin', 'staff') AND status = 'active' ORDER BY full_name ASC";
$result_collectors = $conn->query($sql_collectors);
$collectors = [];
while ($row = $result_collectors->fetch_assoc()) {
    $collectors[] = $row;
}

// Get overall collection statistics
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
                END) as avg_transaction,
                MIN(es.paid_date) as first_payment_date,
                MAX(es.paid_date) as last_payment_date
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

// Get daily collection summary
$sql_daily = "SELECT 
                DATE(es.paid_date) as payment_date,
                COUNT(DISTINCT es.id) as transaction_count,
                COUNT(DISTINCT es.member_id) as member_count,
                SUM(CASE WHEN es.payment_type = 'cash' THEN es.cash_amount ELSE 0 END) as cash_amount,
                SUM(CASE WHEN es.payment_type = 'upi' THEN es.upi_amount ELSE 0 END) as upi_amount,
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
              ORDER BY es.paid_date DESC";

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

// Get plan-wise collection
$sql_planwise = "SELECT 
                    p.id,
                    p.title,
                    COUNT(DISTINCT es.id) as transaction_count,
                    COUNT(DISTINCT es.member_id) as member_count,
                    SUM(CASE WHEN es.payment_type = 'cash' THEN es.cash_amount ELSE 0 END) as cash_amount,
                    SUM(CASE WHEN es.payment_type = 'upi' THEN es.upi_amount ELSE 0 END) as upi_amount,
                    SUM(CASE 
                        WHEN es.payment_type = 'cash' THEN es.cash_amount 
                        WHEN es.payment_type = 'upi' THEN es.upi_amount 
                        WHEN es.payment_type = 'both' THEN (es.cash_amount + es.upi_amount)
                        ELSE 0 
                    END) as total_amount
                FROM emi_schedule es
                JOIN members m ON es.member_id = m.id
                JOIN plans p ON m.plan_id = p.id
                $where_clause
                GROUP BY p.id, p.title
                ORDER BY total_amount DESC";

$stmt_planwise = $conn->prepare($sql_planwise);
if (!empty($params)) {
    $stmt_planwise->bind_param($types, ...$params);
}
$stmt_planwise->execute();
$result_planwise = $stmt_planwise->get_result();
$planwise_collections = [];
while ($row = $result_planwise->fetch_assoc()) {
    $planwise_collections[] = $row;
}
$stmt_planwise->close();

// Get collector-wise collection
$sql_collectorwise = "SELECT 
                        u.id,
                        u.full_name,
                        COUNT(DISTINCT es.id) as transaction_count,
                        COUNT(DISTINCT es.member_id) as member_count,
                        SUM(CASE WHEN es.payment_type = 'cash' THEN es.cash_amount ELSE 0 END) as cash_amount,
                        SUM(CASE WHEN es.payment_type = 'upi' THEN es.upi_amount ELSE 0 END) as upi_amount,
                        SUM(CASE 
                            WHEN es.payment_type = 'cash' THEN es.cash_amount 
                            WHEN es.payment_type = 'upi' THEN es.upi_amount 
                            WHEN es.payment_type = 'both' THEN (es.cash_amount + es.upi_amount)
                            ELSE 0 
                        END) as total_amount
                    FROM emi_schedule es
                    JOIN members m ON es.member_id = m.id
                    LEFT JOIN users u ON es.collected_by = u.id
                    $where_clause
                    GROUP BY u.id, u.full_name
                    ORDER BY total_amount DESC";

$stmt_collectorwise = $conn->prepare($sql_collectorwise);
if (!empty($params)) {
    $stmt_collectorwise->bind_param($types, ...$params);
}
$stmt_collectorwise->execute();
$result_collectorwise = $stmt_collectorwise->get_result();
$collectorwise_collections = [];
while ($row = $result_collectorwise->fetch_assoc()) {
    $collectorwise_collections[] = $row;
}
$stmt_collectorwise->close();

// Get detailed collection list (if show_details is true)
$detailed_collections = [];
if ($show_details) {
    $sql_details = "SELECT 
                        es.id as transaction_id,
                        es.paid_date,
                        es.emi_amount,
                        es.emi_bill_number,
                        es.payment_type,
                        es.upi_amount,
                        es.cash_amount,
                        es.emi_due_date,
                        m.id as member_id,
                        m.agreement_number,
                        m.customer_name,
                        m.customer_number,
                        p.title as plan_title,
                        u.full_name as collector_name
                    FROM emi_schedule es
                    JOIN members m ON es.member_id = m.id
                    JOIN plans p ON m.plan_id = p.id
                    LEFT JOIN users u ON es.collected_by = u.id
                    $where_clause
                    ORDER BY es.paid_date DESC, es.id DESC";

    $stmt_details = $conn->prepare($sql_details);
    if (!empty($params)) {
        $stmt_details->bind_param($types, ...$params);
    }
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    while ($row = $result_details->fetch_assoc()) {
        $detailed_collections[] = $row;
    }
    $stmt_details->close();
}

// Get collection trend for chart (last 12 months)
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
              GROUP BY DATE_FORMAT(es.paid_date, '%Y-%m')
              ORDER BY es.paid_date DESC
              LIMIT 12";

$result_trend = $conn->query($sql_trend);
$collection_trend = [];
while ($row = $result_trend->fetch_assoc()) {
    $collection_trend[] = $row;
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
                $page_title = "Collection List";
                $breadcrumb_active = "Collection Analysis";
                include 'includes/breadcrumb.php';
                ?>
                
                <div class="row align-items-center mb-4">
                    <div class="col">
                        <h3 class="mb-0">Collection List</h3>
                        <small class="text-muted">Track all EMI collections with detailed analysis</small>
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
                        <h5 class="card-title mb-0">Filters</h5>
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
        <?php for($y=2020; $y<=2030; $y++): ?>
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
                            
                            <div class="col-md-3">
                                <label class="form-label">Collector</label>
                                <select class="form-control" name="collector_id">
                                    <option value="0">All Collectors</option>
                                    <?php foreach ($collectors as $collector): ?>
                                        <option value="<?= $collector['id']; ?>" <?= $collector_filter == $collector['id'] ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($collector['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Show Details</label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="show_details" id="showDetails" <?= $show_details ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="showDetails">
                                        Show Detailed List
                                    </label>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-1"></i> Apply Filters
                                        </button>
                                        <a href="collection-list.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-sync me-1"></i> Reset
                                        </a>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">
                                            Showing: 
                                            <?= number_format($stats['total_transactions'] ?? 0); ?> transactions | 
                                            ₹<?= number_format($stats['total_collection'] ?? 0, 2); ?> collected
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
                                        <i class="fas fa-money-bill-wave fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Total Collection</h5>
                                        <h3 class="mb-0">₹<?= number_format($stats['total_collection'] ?? 0, 2); ?></h3>
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
                        <div class="card bg-success-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exchange-alt fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Transactions</h5>
                                        <h3 class="mb-0"><?= number_format($stats['total_transactions'] ?? 0); ?></h3>
                                        <small>
                                            <?= number_format($stats['total_members'] ?? 0); ?> members | 
                                            ₹<?= number_format($stats['avg_transaction'] ?? 0, 2); ?> avg
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
                                        <h5 class="text-white-50 fw-normal">Date Range</h5>
                                        <h3 class="mb-0">
                                            <?= date('d M', strtotime($start_date)); ?> - <?= date('d M', strtotime($end_date)); ?>
                                        </h3>
                                        <small>
                                            First: <?= !empty($stats['first_payment_date']) ? date('d M', strtotime($stats['first_payment_date'])) : 'N/A'; ?> | 
                                            Last: <?= !empty($stats['last_payment_date']) ? date('d M', strtotime($stats['last_payment_date'])) : 'N/A'; ?>
                                        </small>
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
                                        <i class="fas fa-chart-line fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Collection Trend</h5>
                                        <h3 class="mb-0">₹<?= number_format(end($collection_trend)['total_amount'] ?? 0, 2); ?></h3>
                                        <small>
                                            Last month: ₹<?= number_format(isset($collection_trend[1]) ? $collection_trend[1]['total_amount'] : 0, 2); ?>
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
                                <h4 class="card-title mb-0">Collection Trend (Last 12 Months)</h4>
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

                <!-- Daily Collection Summary -->
                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Daily Collection Summary</h4>
                            <small class="text-muted">Showing <?= count($daily_collections); ?> days</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Transactions</th>
                                        <th>Members</th>
                                        <th>Cash Amount</th>
                                        <th>UPI Amount</th>
                                        <th>Total Amount</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($daily_collections)): ?>
                                        <?php foreach ($daily_collections as $day): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= date('d M Y', strtotime($day['payment_date'])); ?></strong>
                                                    <br><small class="text-muted"><?= date('l', strtotime($day['payment_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary"><?= $day['transaction_count']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?= $day['member_count']; ?></span>
                                                </td>
                                                <td class="text-success fw-bold">
                                                    ₹<?= number_format($day['cash_amount'], 2); ?>
                                                </td>
                                                <td class="text-primary fw-bold">
                                                    ₹<?= number_format($day['upi_amount'], 2); ?>
                                                </td>
                                                <td class="text-success fw-bold">
                                                    ₹<?= number_format($day['total_amount'], 2); ?>
                                                </td>
                                                <td>
                                                    <a href="collection-list.php?start_date=<?= $day['payment_date']; ?>&end_date=<?= $day['payment_date']; ?>&show_details=1" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye me-1"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                No collections found for the selected filters.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($daily_collections)): ?>
                                <tfoot class="table-light">
                                    <tr>
                                        <th class="text-end">Total:</th>
                                        <th><?= array_sum(array_column($daily_collections, 'transaction_count')); ?></th>
                                        <th><?= array_sum(array_column($daily_collections, 'member_count')); ?></th>
                                        <th class="text-success fw-bold">₹<?= number_format(array_sum(array_column($daily_collections, 'cash_amount')), 2); ?></th>
                                        <th class="text-primary fw-bold">₹<?= number_format(array_sum(array_column($daily_collections, 'upi_amount')), 2); ?></th>
                                        <th class="text-success fw-bold">₹<?= number_format(array_sum(array_column($daily_collections, 'total_amount')), 2); ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Plan-wise Collection -->
                <div class="row mb-4">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Plan-wise Collection</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Plan</th>
                                                <th>Transactions</th>
                                                <th>Members</th>
                                                <th>Total Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($planwise_collections)): ?>
                                                <?php foreach ($planwise_collections as $plan): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($plan['title']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?= $plan['transaction_count']; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= $plan['member_count']; ?></span>
                                                        </td>
                                                        <td class="text-success fw-bold">
                                                            ₹<?= number_format($plan['total_amount'], 2); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-3 text-muted">
                                                        No plan-wise data available
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Collector-wise Collection</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Collector</th>
                                                <th>Transactions</th>
                                                <th>Members</th>
                                                <th>Total Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($collectorwise_collections)): ?>
                                                <?php foreach ($collectorwise_collections as $collector): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($collector['full_name'] ?? 'Unknown'); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?= $collector['transaction_count']; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= $collector['member_count']; ?></span>
                                                        </td>
                                                        <td class="text-success fw-bold">
                                                            ₹<?= number_format($collector['total_amount'], 2); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-3 text-muted">
                                                        No collector-wise data available
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

                <!-- Detailed Collection List (if show_details is true) -->
                <?php if ($show_details && !empty($detailed_collections)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Detailed Collection List</h4>
                            <small class="text-muted">Showing <?= count($detailed_collections); ?> transactions</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="detailedTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Payment Date</th>
                                        <th>Member Details</th>
                                        <th>Plan</th>
                                        <th>Due Date</th>
                                        <th>Bill No</th>
                                        <th>Payment Type</th>
                                        <th>Amount</th>
                                        <th>Collector</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detailed_collections as $index => $detail): ?>
                                        <tr>
                                            <td><?= $index + 1; ?></td>
                                            <td>
                                                <?= date('d M Y', strtotime($detail['paid_date'])); ?>
                                                <br><small class="text-muted"><?= date('h:i A', strtotime($detail['paid_date'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm me-2">
                                                        <div class="avatar-title bg-light text-primary rounded-circle">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-0"><?= htmlspecialchars($detail['customer_name']); ?></h6>
                                                        <small class="text-muted"><?= $detail['agreement_number']; ?></small>
                                                        <br><small class="text-muted"><?= htmlspecialchars($detail['customer_number']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($detail['plan_title']); ?>
                                            </td>
                                            <td>
                                                <?= date('d M Y', strtotime($detail['emi_due_date'])); ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($detail['emi_bill_number'])): ?>
                                                    <span class="badge bg-info"><?= $detail['emi_bill_number']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $detail['payment_type'] == 'cash' ? 'success' : ($detail['payment_type'] == 'upi' ? 'primary' : 'warning'); ?>">
                                                    <?= ucfirst($detail['payment_type']); ?>
                                                </span>
                                                <?php if ($detail['payment_type'] == 'both'): ?>
                                                    <br><small>Cash: ₹<?= number_format($detail['cash_amount'], 2); ?></small>
                                                    <br><small>UPI: ₹<?= number_format($detail['upi_amount'], 2); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-success fw-bold">
                                                ₹<?= number_format($detail['emi_amount'], 2); ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($detail['collector_name'] ?? 'Unknown'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
    
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        
        .bg-warning-gradient {
            background: linear-gradient(45deg, #ff9a00, #ff5e00);
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
    </style>
    
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Collection Trend Chart
            const trendCtx = document.getElementById('collectionTrendChart').getContext('2d');
            const months = <?php echo json_encode(array_column(array_reverse($collection_trend), 'month_year')); ?>;
            const amounts = <?php echo json_encode(array_column(array_reverse($collection_trend), 'total_amount')); ?>;
            
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
        
        // Export to Excel
        function exportToExcel() {
            const table = document.getElementById('detailedTable') || document.querySelector('.table');
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
            a.setAttribute('download', `collection-list-${new Date().toISOString().split('T')[0]}.csv`);
            a.click();
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
                case 'last_month':
                    const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    start = new Date(lastMonth.getFullYear(), lastMonth.getMonth(), 1).toISOString().split('T')[0];
                    end = new Date(lastMonth.getFullYear(), lastMonth.getMonth() + 1, 0).toISOString().split('T')[0];
                    break;
            }
            
            document.querySelector('input[name="start_date"]').value = start;
            document.querySelector('input[name="end_date"]').value = end;
        }
    </script>
</body>
</html>