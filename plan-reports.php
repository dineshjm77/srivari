<?php
// plan-reports.php - Plan-wise Collection Reports
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

// Get filter parameters
$filter_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;

// Build WHERE clause for filters
$where_clause = "WHERE 1=1";
$params = [];
$types = '';

if ($filter_month > 0 && $filter_year > 0) {
    $where_clause .= " AND MONTH(es.paid_date) = ? AND YEAR(es.paid_date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= 'ii';
}

if (!empty($start_date) && !empty($end_date)) {
    $where_clause .= " AND es.paid_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

if ($plan_filter > 0) {
    $where_clause .= " AND p.id = ?";
    $params[] = $plan_filter;
    $types .= 'i';
}

// Fetch all plans for dropdown
$sql_plans = "SELECT id, title FROM plans ORDER BY title ASC";
$result_plans = $conn->query($sql_plans);
$plans = [];
while ($row = $result_plans->fetch_assoc()) {
    $plans[] = $row;
}

// Get overall statistics
$sql_overall = "SELECT 
                COUNT(DISTINCT m.id) as total_members,
                COUNT(DISTINCT p.id) as total_plans,
                SUM(es.emi_amount) as total_collected,
                COUNT(DISTINCT CASE WHEN es.status = 'paid' THEN m.id END) as paying_members,
                AVG(es.emi_amount) as avg_emi_amount
                FROM plans p
                LEFT JOIN members m ON p.id = m.plan_id
                LEFT JOIN emi_schedule es ON m.id = es.member_id
                $where_clause";

$stmt_overall = $conn->prepare($sql_overall);
if (!empty($params)) {
    $stmt_overall->bind_param($types, ...$params);
}
$stmt_overall->execute();
$result_overall = $stmt_overall->get_result();
$overall_stats = $result_overall->fetch_assoc();
$stmt_overall->close();

// Get plan-wise statistics (18 plans max)
$sql_plan_stats = "SELECT 
                  p.id,
                  p.title,
                  COUNT(DISTINCT m.id) as total_members,
                  COUNT(DISTINCT CASE WHEN es.status = 'paid' THEN m.id END) as paying_members,
                  SUM(CASE WHEN es.status = 'paid' THEN es.emi_amount ELSE 0 END) as total_collected,
                  SUM(CASE WHEN es.status = 'unpaid' THEN es.emi_amount ELSE 0 END) as total_pending,
                  AVG(es.emi_amount) as avg_emi_amount,
                  COUNT(DISTINCT es.id) as total_transactions,
                  MAX(es.paid_date) as last_payment_date
                  FROM plans p
                  LEFT JOIN members m ON p.id = m.plan_id
                  LEFT JOIN emi_schedule es ON m.id = es.member_id
                  GROUP BY p.id, p.title
                  ORDER BY total_collected DESC
                  LIMIT 18";

$result_plan_stats = $conn->query($sql_plan_stats);
$plan_stats = [];
$total_all_collected = 0;
$total_all_pending = 0;
$total_all_members = 0;

while ($row = $result_plan_stats->fetch_assoc()) {
    $plan_stats[] = $row;
    $total_all_collected += $row['total_collected'] ?? 0;
    $total_all_pending += $row['total_pending'] ?? 0;
    $total_all_members += $row['total_members'] ?? 0;
}

// Get monthly trend for chart
$sql_trend = "SELECT 
              DATE_FORMAT(es.paid_date, '%b %Y') as month_year,
              SUM(es.emi_amount) as collected_amount,
              COUNT(DISTINCT m.id) as paying_members
              FROM emi_schedule es
              JOIN members m ON es.member_id = m.id
              WHERE es.status = 'paid' AND es.paid_date IS NOT NULL
              GROUP BY DATE_FORMAT(es.paid_date, '%Y-%m')
              ORDER BY es.paid_date DESC
              LIMIT 12";

$result_trend = $conn->query($sql_trend);
$monthly_trend = [];
while ($row = $result_trend->fetch_assoc()) {
    $monthly_trend[] = $row;
}

// Get top performing members
$sql_top_members = "SELECT 
                   m.id,
                   m.customer_name,
                   m.agreement_number,
                   p.title as plan_name,
                   SUM(es.emi_amount) as total_paid,
                   COUNT(es.id) as payments_count
                   FROM members m
                   JOIN plans p ON m.plan_id = p.id
                   JOIN emi_schedule es ON m.id = es.member_id
                   WHERE es.status = 'paid'
                   GROUP BY m.id, m.customer_name, m.agreement_number, p.title
                   ORDER BY total_paid DESC
                   LIMIT 10";

$result_top_members = $conn->query($sql_top_members);
$top_members = [];
while ($row = $result_top_members->fetch_assoc()) {
    $top_members[] = $row;
}

// Generate colors for plans
function getPlanColor($index) {
    $colors = [
        'primary', 'success', 'info', 'warning', 'danger',
        'secondary', 'dark', 'primary', 'success', 'info',
        'warning', 'danger', 'secondary', 'dark', 'primary',
        'success', 'info', 'warning'
    ];
    return $colors[$index % count($colors)];
}

// Generate gradient colors
function getPlanGradient($index) {
    $gradients = [
        'linear-gradient(45deg, #667eea, #764ba2)',
        'linear-gradient(45deg, #11998e, #38ef7d)',
        'linear-gradient(45deg, #ff416c, #ff4b2b)',
        'linear-gradient(45deg, #ff9a00, #ff5e00)',
        'linear-gradient(45deg, #00c6ff, #0072ff)',
        'linear-gradient(45deg, #654ea3, #da98b4)',
        'linear-gradient(45deg, #ff5858, #f09819)',
        'linear-gradient(45deg, #8e2de2, #4a00e0)',
        'linear-gradient(45deg, #1d976c, #93f9b9)',
        'linear-gradient(45deg, #ff5e62, #ff9966)',
        'linear-gradient(45deg, #2193b0, #6dd5ed)',
        'linear-gradient(45deg, #cc2b5e, #753a88)',
        'linear-gradient(45deg, #42275a, #734b6d)',
        'linear-gradient(45deg, #de6262, #ffb88c)',
        'linear-gradient(45deg, #614385, #516395)',
        'linear-gradient(45deg, #02aab0, #00cdac)',
        'linear-gradient(45deg, #4568dc, #b06ab3)',
        'linear-gradient(45deg, #43cea2, #185a9d)'
    ];
    return $gradients[$index % count($gradients)];
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
                $page_title = "Plan-wise Reports";
                $breadcrumb_active = "Collection Reports";
                include 'includes/breadcrumb.php';
                ?>
                
                <div class="row align-items-center mb-4">
                    <div class="col">
                        <h3 class="mb-0">Plan-wise Collection Reports</h3>
                        <small class="text-muted">Track collections across all chit plans</small>
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
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $start_date; ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" 
                                       value="<?php echo $end_date; ?>">
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
                            
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i> Apply Filter
                                </button>
                            </div>
                        </form>
                        
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <a href="plan-reports.php" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-sync me-1"></i> Reset Filters
                                        </a>
                                    </div>
                                    <div>
                                        <button onclick="window.print()" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-print me-1"></i> Print Report
                                        </button>
                                        <button onclick="exportToExcel()" class="btn btn-sm btn-outline-success">
                                            <i class="fas fa-file-excel me-1"></i> Export Excel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Overall Statistics -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-primary-gradient text-white">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-hand-holding-usd fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Total Collected</h5>
                                        <h3 class="mb-0">₹<?php echo number_format($overall_stats['total_collected'] ?? 0, 2); ?></h3>
                                        <small>Across all plans</small>
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
                                        <i class="fas fa-users fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Total Members</h5>
                                        <h3 class="mb-0"><?php echo number_format($overall_stats['total_members'] ?? 0); ?></h3>
                                        <small><?php echo number_format($overall_stats['paying_members'] ?? 0); ?> active</small>
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
                                        <i class="fas fa-list-alt fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Active Plans</h5>
                                        <h3 class="mb-0"><?php echo number_format($overall_stats['total_plans'] ?? 0); ?></h3>
                                        <small>Running chit groups</small>
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
                                        <i class="fas fa-rupee-sign fs-1"></i>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="text-white-50 fw-normal">Avg EMI Amount</h5>
                                        <h3 class="mb-0">₹<?php echo number_format($overall_stats['avg_emi_amount'] ?? 0, 2); ?></h3>
                                        <small>Per member per month</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plan-wise Statistics (18 boxes) -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="card-title mb-3">Plan-wise Collection Summary</h4>
                        <p class="text-muted">Showing <?php echo count($plan_stats); ?> plans. Click on plan to view detailed member list.</p>
                    </div>
                    
                    <?php if (!empty($plan_stats)): ?>
                        <?php foreach ($plan_stats as $index => $plan): 
                            $collection_rate = ($plan['total_collected'] + $plan['total_pending']) > 0 
                                ? ($plan['total_collected'] / ($plan['total_collected'] + $plan['total_pending']) * 100) 
                                : 0;
                            $plan_color = getPlanColor($index);
                            $plan_gradient = getPlanGradient($index);
                        ?>
                        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                            <div class="card plan-card shadow-sm plan-card-clickable" 
                                 data-plan-id="<?php echo $plan['id']; ?>"
                                 data-plan-title="<?php echo htmlspecialchars($plan['title']); ?>"
                                 style="border-radius: 8px; overflow: hidden;">
                                <div class="card-header text-white" style="background: <?php echo $plan_gradient; ?>; border-radius: 8px 8px 0 0;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0 text-truncate" title="<?php echo htmlspecialchars($plan['title']); ?>">
                                            <?php echo htmlspecialchars($plan['title']); ?>
                                        </h6>
                                        <span class="badge bg-light text-dark"><?php echo $index + 1; ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-3">
                                        <div class="plan-icon mb-2">
                                            <i class="fas fa-money-bill-wave fa-2x" style="color: <?php echo $plan_color == 'primary' ? '#667eea' : ''; ?>"></i>
                                        </div>
                                        <h4 class="text-<?php echo $plan_color; ?> mb-0">
                                            ₹<?php echo number_format($plan['total_collected'] ?? 0, 2); ?>
                                        </h4>
                                        <small class="text-muted">Collected</small>
                                    </div>
                                    
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <small class="text-muted">Members</small>
                                            <h6 class="mb-0"><?php echo $plan['total_members'] ?? 0; ?></h6>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Pending</small>
                                            <h6 class="mb-0 text-danger">₹<?php echo number_format($plan['total_pending'] ?? 0, 0); ?></h6>
                                        </div>
                                    </div>
                                    
                                    <div class="progress mt-3" style="height: 6px;">
                                        <div class="progress-bar bg-<?php echo $plan_color; ?>" 
                                             style="width: <?php echo min($collection_rate, 100); ?>%">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small>Collection</small>
                                        <small><?php echo number_format($collection_rate, 1); ?>%</small>
                                    </div>
                                </div>
                                <div class="card-footer bg-transparent text-center py-2">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-alt me-1"></i>
                                        Last: <?php echo !empty($plan['last_payment_date']) ? date('d M', strtotime($plan['last_payment_date'])) : 'N/A'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                                <h5>No Plan Data Available</h5>
                                <p class="text-muted">No collection data found for the selected filters.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Charts and Detailed Reports -->
                <div class="row mb-4">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Monthly Collection Trend</h4>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="height: 300px;">
                                    <canvas id="collectionTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Top Performing Members</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Member</th>
                                                <th>Plan</th>
                                                <th>Total Paid</th>
                                                <th>Payments</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($top_members)): ?>
                                                <?php foreach ($top_members as $index => $member): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="avatar-sm me-2">
                                                                    <div class="avatar-title bg-light text-primary rounded-circle">
                                                                        <i class="fas fa-user"></i>
                                                                    </div>
                                                                </div>
                                                                <div>
                                                                    <h6 class="mb-0"><?php echo htmlspecialchars($member['customer_name']); ?></h6>
                                                                    <small class="text-muted"><?php echo $member['agreement_number']; ?></small>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($member['plan_name']); ?></td>
                                                        <td class="text-success fw-bold">
                                                            ₹<?php echo number_format($member['total_paid'], 2); ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo $member['payments_count']; ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-3 text-muted">
                                                        No payment data available
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

                <!-- Detailed Plan Report Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Detailed Plan-wise Report</h4>
                            <small class="text-muted">Sorted by highest collections</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="planReportTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Plan Name</th>
                                        <th>Total Members</th>
                                        <th>Active Members</th>
                                        <th>Total Collected (₹)</th>
                                        <th>Total Pending (₹)</th>
                                        <th>Avg EMI (₹)</th>
                                        <th>Collection Rate</th>
                                        <th>Last Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($plan_stats)): ?>
                                        <?php foreach ($plan_stats as $index => $plan): 
                                            $collection_rate = ($plan['total_collected'] + $plan['total_pending']) > 0 
                                                ? ($plan['total_collected'] / ($plan['total_collected'] + $plan['total_pending']) * 100) 
                                                : 0;
                                        ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($plan['title']); ?></strong>
                                                </td>
                                                <td><?php echo $plan['total_members']; ?></td>
                                                <td>
                                                    <?php echo $plan['paying_members']; ?>
                                                    <small class="text-muted d-block">
                                                        <?php echo $plan['total_members'] > 0 
                                                            ? round(($plan['paying_members'] / $plan['total_members'] * 100), 1) 
                                                            : 0; ?>% active
                                                    </small>
                                                </td>
                                                <td class="text-success fw-bold">
                                                    ₹<?php echo number_format($plan['total_collected'], 2); ?>
                                                </td>
                                                <td class="text-danger">
                                                    ₹<?php echo number_format($plan['total_pending'], 2); ?>
                                                </td>
                                                <td>₹<?php echo number_format($plan['avg_emi_amount'] ?? 0, 2); ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="progress flex-grow-1" style="height: 8px;">
                                                            <div class="progress-bar 
                                                                <?php echo $collection_rate >= 80 ? 'bg-success' : 
                                                                   ($collection_rate >= 50 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                                style="width: <?php echo min($collection_rate, 100); ?>%">
                                                            </div>
                                                        </div>
                                                        <span class="ms-2"><?php echo number_format($collection_rate, 1); ?>%</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo !empty($plan['last_payment_date']) 
                                                        ? date('d M Y', strtotime($plan['last_payment_date'])) 
                                                        : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" 
                                                                class="btn btn-sm btn-outline-primary view-members-btn"
                                                                data-plan-id="<?php echo $plan['id']; ?>"
                                                                data-plan-title="<?php echo htmlspecialchars($plan['title']); ?>"
                                                                title="View Members">
                                                            <i class="fas fa-users"></i>
                                                        </button>
                                                        <button type="button" 
                                                                class="btn btn-sm btn-outline-info"
                                                                onclick="viewPlanDetails(<?php echo $plan['id']; ?>)"
                                                                title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="collection-reports.php?plan_id=<?php echo $plan['id']; ?>" 
                                                           class="btn btn-sm btn-outline-success"
                                                           title="Collection Report">
                                                            <i class="fas fa-chart-line"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                No plan data available for the selected filters.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2">TOTAL</th>
                                        <th><?php echo $total_all_members; ?></th>
                                        <th>
                                            <?php echo array_sum(array_column($plan_stats, 'paying_members')); ?>
                                            <small class="text-muted d-block">
                                                <?php echo $total_all_members > 0 
                                                    ? round((array_sum(array_column($plan_stats, 'paying_members')) / $total_all_members * 100), 1) 
                                                    : 0; ?>% active
                                            </small>
                                        </th>
                                        <th class="text-success">₹<?php echo number_format($total_all_collected, 2); ?></th>
                                        <th class="text-danger">₹<?php echo number_format($total_all_pending, 2); ?></th>
                                        <th>
                                            ₹<?php echo count($plan_stats) > 0 
                                                ? number_format(array_sum(array_column($plan_stats, 'avg_emi_amount')) / count($plan_stats), 2) 
                                                : '0.00'; ?>
                                        </th>
                                        <th>
                                            <?php 
                                            $total_all_rate = ($total_all_collected + $total_all_pending) > 0 
                                                ? ($total_all_collected / ($total_all_collected + $total_all_pending) * 100) 
                                                : 0;
                                            ?>
                                            <span class="fw-bold"><?php echo number_format($total_all_rate, 1); ?>%</span>
                                        </th>
                                        <th>-</th>
                                        <th>-</th>
                                    </tr>
                                </tfoot>
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
    
    <!-- Modal for Plan Members -->
    <div class="modal fade" id="planMembersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="planModalTitle">Plan Members</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="planMembersContent" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .plan-card-clickable {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .plan-card-clickable:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2) !important;
        }
        
        .plan-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(0,0,0,0.05);
        }
        
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
        // Initialize Chart
        document.addEventListener('DOMContentLoaded', function() {
            // Collection Trend Chart
            const trendCtx = document.getElementById('collectionTrendChart').getContext('2d');
            const months = <?php echo json_encode(array_column(array_reverse($monthly_trend), 'month_year')); ?>;
            const amounts = <?php echo json_encode(array_column(array_reverse($monthly_trend), 'collected_amount')); ?>;
            
            if (months.length > 0 && amounts.length > 0) {
                const trendChart = new Chart(trendCtx, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Collection Amount (₹)',
                            data: amounts,
                            backgroundColor: 'rgba(102, 126, 234, 0.7)',
                            borderColor: '#667eea',
                            borderWidth: 2,
                            borderRadius: 5,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return '₹' + context.parsed.y.toLocaleString('en-IN', {minimumFractionDigits: 2});
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
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
            } else {
                document.querySelector('.chart-container').innerHTML = '<div class="text-center py-5"><p class="text-muted">No trend data available</p></div>';
            }
            
            // Add click event listeners to plan cards
            document.querySelectorAll('.plan-card-clickable').forEach(card => {
                card.addEventListener('click', function() {
                    const planId = this.getAttribute('data-plan-id');
                    const planTitle = this.getAttribute('data-plan-title');
                    showPlanMembers(planId, planTitle);
                });
            });
            
            // Add click event listeners to view members buttons in table
            document.querySelectorAll('.view-members-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.stopPropagation(); // Prevent event bubbling
                    const planId = this.getAttribute('data-plan-id');
                    const planTitle = this.getAttribute('data-plan-title');
                    showPlanMembers(planId, planTitle);
                });
            });
            
            // Plan card hover effects
            document.querySelectorAll('.plan-card-clickable').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.2)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '';
                });
            });
        });
        
        // Show plan members in modal
        function showPlanMembers(planId, planTitle) {
            const modalTitle = document.getElementById('planModalTitle');
            const modalContent = document.getElementById('planMembersContent');
            
            // Set modal title
            modalTitle.textContent = 'Members - ' + planTitle;
            
            // Show loading
            modalContent.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading members...</p>
                </div>
            `;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('planMembersModal'));
            modal.show();
            
            // Load members via AJAX
            fetch(`ajax/get-plan-members.php?plan_id=${planId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(data => {
                    modalContent.innerHTML = data;
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error loading members. Please try again.
                        </div>
                    `;
                });
        }
        
        // View plan details
        function viewPlanDetails(planId) {
            window.location.href = `chit-group-details.php?id=${planId}`;
        }
        
        // Export to Excel
        function exportToExcel() {
            // Create a simple CSV export
            const table = document.getElementById('planReportTable');
            const rows = table.querySelectorAll('tr');
            let csv = [];
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                for (let j = 0; j < cols.length; j++) {
                    // Remove icons and badges for clean export
                    let text = cols[j].innerText.replace(/[₹%]/g, '').trim();
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
            a.setAttribute('download', `plan-reports-${new Date().toISOString().split('T')[0]}.csv`);
            a.style.visibility = 'hidden';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>