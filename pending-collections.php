<?php
// pending-collections.php - Pending Collections List (Default: Today)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

// Safe string function
function safe_html($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Get filter parameters - Default to 'today'
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$member_filter = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;

// Set date ranges based on filter type
$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-d', strtotime('first day of this month'));
$month_end = date('Y-m-d', strtotime('last day of this month'));

switch($filter_type) {
    case 'all':
        $start_date = '';
        $end_date = '';
        break;
    case 'today':
        $start_date = $today;
        $end_date = $today;
        break;
    case 'week':
        $start_date = $week_start;
        $end_date = $week_end;
        break;
    case 'month':
        $start_date = $month_start;
        $end_date = $month_end;
        break;
    case 'overdue':
        $start_date = '';
        $end_date = '';
        break;
    default:
        $start_date = $today;
        $end_date = $today;
}

// Build query for pending collections
$sql = "SELECT es.*, 
        m.customer_name, 
        m.customer_number,
        m.customer_number2,
        m.agreement_number,
        m.customer_address,
        m.bid_winner_site_number,
        p.title as plan_title,
        p.plan_type,
        p.total_periods,
        p.monthly_installment,
        p.weekly_installment,
        p.daily_installment
        FROM emi_schedule es
        JOIN members m ON es.member_id = m.id
        JOIN plans p ON m.plan_id = p.id
        WHERE es.status = 'unpaid' 
        AND es.emi_amount > 0";

$params = [];
$types = "";

// Add date filters
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND es.emi_due_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
} elseif ($filter_type == 'overdue') {
    $sql .= " AND es.emi_due_date < CURDATE()";
}

// Add member filter
if ($member_filter > 0) {
    $sql .= " AND m.id = ?";
    $params[] = $member_filter;
    $types .= "i";
}

// Add plan filter
if ($plan_filter > 0) {
    $sql .= " AND p.id = ?";
    $params[] = $plan_filter;
    $types .= "i";
}

$sql .= " ORDER BY es.emi_due_date ASC, m.customer_name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$pending_collections = [];
$total_pending_amount = 0;
$overdue_count = 0;
$today_due_count = 0;
$upcoming_count = 0;

while ($row = $result->fetch_assoc()) {
    $pending_collections[] = $row;
    $total_pending_amount += $row['emi_amount'];
    
    $due_date = strtotime($row['emi_due_date']);
    $today_date = strtotime(date('Y-m-d'));
    
    if ($due_date < $today_date) {
        $overdue_count++;
    } elseif ($due_date == $today_date) {
        $today_due_count++;
    } else {
        $upcoming_count++;
    }
}
$stmt->close();

// Get members list for filter
$members = [];
$member_sql = "SELECT id, customer_name, agreement_number FROM members ORDER BY customer_name ASC";
$member_result = $conn->query($member_sql);
while ($row = $member_result->fetch_assoc()) {
    $members[] = $row;
}

// Get plans list for filter
$plans = [];
$plan_sql = "SELECT id, title, plan_type FROM plans ORDER BY title ASC";
$plan_result = $conn->query($plan_sql);
while ($row = $plan_result->fetch_assoc()) {
    $plans[] = $row;
}

// Get summary by date
$summary_sql = "SELECT DATE(emi_due_date) as due_date, 
                       COUNT(*) as count,
                       SUM(emi_amount) as total,
                       SUM(CASE WHEN emi_due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
                       SUM(CASE WHEN emi_due_date = CURDATE() THEN 1 ELSE 0 END) as today_count
                FROM emi_schedule
                WHERE status = 'unpaid' AND emi_amount > 0";
                
if ($filter_type == 'today') {
    $summary_sql .= " AND emi_due_date = CURDATE()";
} elseif ($filter_type == 'week') {
    $summary_sql .= " AND emi_due_date BETWEEN '$week_start' AND '$week_end'";
} elseif ($filter_type == 'month') {
    $summary_sql .= " AND emi_due_date BETWEEN '$month_start' AND '$month_end'";
} elseif ($filter_type == 'overdue') {
    $summary_sql .= " AND emi_due_date < CURDATE()";
} elseif (!empty($start_date) && !empty($end_date)) {
    $summary_sql .= " AND emi_due_date BETWEEN '$start_date' AND '$end_date'";
}

$summary_sql .= " GROUP BY DATE(emi_due_date) ORDER BY due_date ASC";
$summary_result = $conn->query($summary_sql);
$daily_summary = [];
while ($row = $summary_result->fetch_assoc()) {
    $daily_summary[] = $row;
}

// Set page title based on filter
$page_title = "Pending Collections";
if ($filter_type == 'today') {
    $page_title = "Today's Pending Collections";
} elseif ($filter_type == 'week') {
    $page_title = "This Week's Pending Collections";
} elseif ($filter_type == 'month') {
    $page_title = "This Month's Pending Collections";
} elseif ($filter_type == 'overdue') {
    $page_title = "Overdue Collections";
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .filter-card {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .filter-card .form-label {
        color: white;
        font-weight: 500;
    }
    .filter-card .form-control, .filter-card .form-select {
        background: rgba(255,255,255,0.9);
        border: none;
    }
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 15px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        transition: transform 0.3s;
        height: 100%;
    }
    .stat-card:hover {
        transform: translateY(-5px);
    }
    .stat-card h3 {
        margin: 10px 0;
        font-size: 24px;
        font-weight: bold;
    }
    .stat-card .stat-icon {
        font-size: 28px;
    }
    .stat-card.overdue .stat-icon { color: #dc3545; }
    .stat-card.today .stat-icon { color: #ffc107; }
    .stat-card.upcoming .stat-icon { color: #17a2b8; }
    .stat-card.total .stat-icon { color: #28a745; }
    .btn-filter {
        background: rgba(255,255,255,0.2);
        color: white;
        border: 1px solid rgba(255,255,255,0.3);
        margin: 0 5px;
        padding: 5px 12px;
        font-size: 13px;
        text-decoration: none;
        display: inline-block;
    }
    .btn-filter:hover, .btn-filter.active {
        background: white;
        color: #dc3545;
        border-color: white;
        text-decoration: none;
    }
    .pending-table {
        font-size: 13px;
    }
    .pending-table th {
        background: #f8f9fa;
        white-space: nowrap;
    }
    .pending-table td {
        vertical-align: middle;
    }
    .overdue-row {
        background-color: #fff3cd !important;
        border-left: 4px solid #ffc107;
    }
    .today-row {
        background-color: #d1ecf1 !important;
        border-left: 4px solid #17a2b8;
    }
    .badge-overdue {
        background-color: #dc3545;
        color: white;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 11px;
    }
    .badge-today {
        background-color: #ffc107;
        color: black;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 11px;
    }
    .badge-upcoming {
        background-color: #17a2b8;
        color: white;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 11px;
    }
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        min-width: 100px;
    }
    .whatsapp-btn-small {
        background: #25D366;
        color: white;
        border: none;
        padding: 4px 8px;
        font-size: 11px;
        border-radius: 4px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 3px;
    }
    .whatsapp-btn-small:hover {
        background: #128C7E;
        color: white;
    }
    .info-banner {
        background: #e7f1ff;
        border-left: 4px solid #0d6efd;
        padding: 10px 15px;
        margin-bottom: 20px;
        border-radius: 8px;
    }
    @media (max-width: 768px) {
        .stat-card h3 {
            font-size: 18px;
        }
        .filter-card .row > div {
            margin-bottom: 10px;
        }
        .btn-filter {
            padding: 3px 8px;
            font-size: 11px;
            margin: 0 2px;
        }
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
                $breadcrumb_active = "Pending Collections";
                include 'includes/breadcrumb.php';
                ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Info Banner for Today's View -->
                <?php if ($filter_type == 'today'): ?>
                <div class="info-banner">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Showing today's pending collections (<?= date('d-m-Y'); ?>)</strong>
                    <br><small>Use filters above to view other periods</small>
                </div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="filter-card">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Pending Collections</h5>
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Quick Filter</label>
                            <div>
                                <a href="?filter=all" class="btn-filter btn-sm <?= $filter_type == 'all' ? 'active' : '' ?>">All Pending</a>
                                <a href="?filter=overdue" class="btn-filter btn-sm <?= $filter_type == 'overdue' ? 'active' : '' ?>">Overdue</a>
                                <a href="?filter=today" class="btn-filter btn-sm <?= $filter_type == 'today' ? 'active' : '' ?>">Due Today</a>
                                <a href="?filter=week" class="btn-filter btn-sm <?= $filter_type == 'week' ? 'active' : '' ?>">This Week</a>
                                <a href="?filter=month" class="btn-filter btn-sm <?= $filter_type == 'month' ? 'active' : '' ?>">This Month</a>
                            </div>
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
                            <label class="form-label">Member</label>
                            <select class="form-select" name="member_id">
                                <option value="0">All Members</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?= $member['id']; ?>" <?= $member_filter == $member['id'] ? 'selected' : ''; ?>>
                                        <?= safe_html($member['customer_name']); ?> (<?= safe_html($member['agreement_number']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Plan</label>
                            <select class="form-select" name="plan_id">
                                <option value="0">All Plans</option>
                                <?php foreach ($plans as $plan): ?>
                                    <option value="<?= $plan['id']; ?>" <?= $plan_filter == $plan['id'] ? 'selected' : ''; ?>>
                                        <?= safe_html($plan['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-light w-100">Apply</button>
                        </div>
                    </form>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card total">
                            <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
                            <h3>₹<?= number_format($total_pending_amount, 2); ?></h3>
                            <p class="text-muted mb-0">Total Pending</p>
                            <small><?= count($pending_collections); ?> pending payments</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card overdue">
                            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <h3><?= $overdue_count; ?></h3>
                            <p class="text-muted mb-0">Overdue Payments</p>
                            <small class="text-danger">Past due date</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card today">
                            <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                            <h3><?= $today_due_count; ?></h3>
                            <p class="text-muted mb-0">Due Today</p>
                            <small class="text-warning">Need attention</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card upcoming">
                            <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                            <h3><?= $upcoming_count; ?></h3>
                            <p class="text-muted mb-0">Upcoming</p>
                            <small>Future due dates</small>
                        </div>
                    </div>
                </div>

                <!-- Daily Summary -->
                <?php if (!empty($daily_summary)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Pending Collection Summary by Date</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Due Date</th>
                                        <th>Pending Count</th>
                                        <th>Total Amount (₹)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daily_summary as $day): 
                                        $due_date = strtotime($day['due_date']);
                                        $today_date = strtotime(date('Y-m-d'));
                                        $status_class = '';
                                        $status_text = '';
                                        
                                        if ($due_date < $today_date) {
                                            $status_class = 'text-danger';
                                            $status_text = 'Overdue';
                                        } elseif ($due_date == $today_date) {
                                            $status_class = 'text-warning';
                                            $status_text = 'Due Today';
                                        } else {
                                            $status_class = 'text-info';
                                            $status_text = 'Upcoming';
                                        }
                                    ?>
                                    <tr>
                                        <td><?= date('d-m-Y', strtotime($day['due_date'])); ?> (<?= date('l', strtotime($day['due_date'])); ?>)</td>
                                        <td><?= $day['count']; ?></td>
                                        <td><strong>₹<?= number_format($day['total'], 2); ?></strong></td>
                                        <td class="<?= $status_class; ?>"><?= $status_text; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th>Total</th>
                                        <th><?= count($pending_collections); ?></th>
                                        <th>₹<?= number_format($total_pending_amount, 2); ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pending Collections Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-clock me-2"></i><?= $page_title; ?>
                            <span class="badge bg-danger ms-2"><?= count($pending_collections); ?> pending</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pending_collections)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover pending-table">
                                <thead>
                                    <tr>
                                        <th width="50">#</th>
                                        <th width="100">Due Date</th>
                                        <th>Customer</th>
                                        <th width="100">Agreement</th>
                                        <th width="150">Plan</th>
                                        <th width="100">Amount (₹)</th>
                                        <th width="80">Status</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = 1;
                                    foreach ($pending_collections as $collection): 
                                        $due_date = strtotime($collection['emi_due_date']);
                                        $today_date = strtotime(date('Y-m-d'));
                                        $row_class = '';
                                        $status_badge = '';
                                        
                                        if ($due_date < $today_date) {
                                            $row_class = 'overdue-row';
                                            $status_badge = '<span class="badge-overdue">Overdue</span>';
                                        } elseif ($due_date == $today_date) {
                                            $row_class = 'today-row';
                                            $status_badge = '<span class="badge-today">Due Today</span>';
                                        } else {
                                            $status_badge = '<span class="badge-upcoming">Upcoming</span>';
                                        }
                                        
                                        $customer_name = safe_html($collection['customer_name']);
                                        $customer_phone = safe_html($collection['customer_number']);
                                        $agreement_no = safe_html($collection['agreement_number']);
                                        $plan_title = safe_html($collection['plan_title']);
                                        
                                        // Get per period amount display
                                        $per_period = '';
                                        if ($collection['plan_type'] == 'daily') {
                                            $daily_amt = $collection['daily_installment'] ?? 0;
                                            $per_period = '<small class="text-muted">₹' . number_format($daily_amt > 0 ? $daily_amt : $collection['emi_amount'] / 30, 2) . '/day</small>';
                                        } elseif ($collection['plan_type'] == 'weekly') {
                                            $weekly_amt = $collection['weekly_installment'] ?? 0;
                                            $per_period = '<small class="text-muted">₹' . number_format($weekly_amt > 0 ? $weekly_amt : $collection['emi_amount'] / 4, 2) . '/week</small>';
                                        } else {
                                            $per_period = '<small class="text-muted">Monthly</small>';
                                        }
                                    ?>
                                    <tr class="<?= $row_class; ?>">
                                        <td class="text-center"><?= $counter++; ?></td>
                                        <td>
                                            <strong><?= date('d-m-Y', $due_date); ?></strong>
                                            <br><small><?= date('l', $due_date); ?></small>
                                        </td>
                                        <td>
                                            <strong><?= $customer_name; ?></strong>
                                            <?php if (!empty($customer_phone)): ?>
                                                <br><small class="text-muted">📞 <?= $customer_phone; ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($collection['bid_winner_site_number'])): ?>
                                                <br><small class="text-info">🏠 Site: <?= safe_html($collection['bid_winner_site_number']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $agreement_no; ?></td>
                                        <td>
                                            <?= $plan_title; ?>
                                            <br><?= $per_period; ?>
                                        </td>
                                        <td>
                                            <strong class="text-danger">₹<?= number_format($collection['emi_amount'], 2); ?></strong>
                                        </td>
                                        <td><?= $status_badge; ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="pay-emi.php?emi_id=<?= $collection['id']; ?>&member=<?= $collection['member_id']; ?>" 
                                                   class="btn btn-sm btn-success" title="Collect Payment">
                                                    <i class="fas fa-rupee-sign"></i> Collect
                                                </a>
                                                <?php if (!empty($customer_phone)): 
                                                    $whatsapp_number = preg_replace('/\D/', '', $customer_phone);
                                                    if (substr($whatsapp_number, 0, 1) == '0') $whatsapp_number = substr($whatsapp_number, 1);
                                                    if (strlen($whatsapp_number) == 10) $whatsapp_number = '91' . $whatsapp_number;
                                                ?>
                                                <button class="btn btn-sm whatsapp-btn-small" 
                                                        onclick="sendReminder('<?= safe_html($customer_name); ?>', 
                                                                        '<?= safe_html($agreement_no); ?>', 
                                                                        '<?= safe_html($plan_title); ?>', 
                                                                        <?= $collection['emi_amount']; ?>, 
                                                                        '<?= $collection['emi_due_date']; ?>', 
                                                                        '<?= $whatsapp_number; ?>')"
                                                        title="Send WhatsApp Reminder">
                                                    <i class="fab fa-whatsapp"></i> Remind
                                                </button>
                                                <?php endif; ?>
                                                <a href="emi-schedule-member.php?id=<?= $collection['member_id']; ?>" 
                                                   class="btn btn-sm btn-info" title="View Schedule">
                                                    <i class="fas fa-calendar-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-dark">
                                    <tr>
                                        <th colspan="5" class="text-end">Total Pending:</th>
                                        <th><strong>₹<?= number_format($total_pending_amount, 2); ?></strong></th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success text-center">
                            <i class="fas fa-check-circle fa-2x mb-2"></i>
                            <p>No pending collections found!</p>
                            <small>All payments are up to date</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Export Options -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Export Options</h5>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print Report
                        </button>
                        <button class="btn btn-info" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-2"></i>Export to Excel
                        </button>
                        <button class="btn btn-warning" onclick="sendBulkReminders()">
                            <i class="fab fa-whatsapp me-2"></i>Send Bulk Reminders
                        </button>
                    </div>
                </div>
            </div>

            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>

    <script>
        // Send WhatsApp Reminder
        function sendReminder(customerName, agreementNo, planTitle, amount, dueDate, whatsappNumber) {
            const isOverdue = new Date(dueDate) < new Date();
            const formattedDueDate = new Date(dueDate).toLocaleDateString('en-GB');
            let overdueDays = 0;
            
            if (isOverdue) {
                const today = new Date();
                const due = new Date(dueDate);
                overdueDays = Math.floor((today - due) / (1000 * 60 * 60 * 24));
            }
            
            let message = `🔔 *Payment Reminder - Shree Vaari Chits Private Limited* 🔔\n\n`;
            message += `Dear *${customerName}*,\n\n`;
            
            if (isOverdue) {
                message += `This is an overdue payment reminder.\n\n`;
            } else {
                message += `This is a payment reminder.\n\n`;
            }
            
            message += `• *Agreement No:* ${agreementNo}\n`;
            message += `• *Plan:* ${planTitle}\n`;
            message += `• *Installment Amount:* ₹${amount.toFixed(2)}\n`;
            message += `• *Due Date:* ${formattedDueDate}\n`;
            
            if (isOverdue) {
                message += `• *Overdue Days:* ${overdueDays} days\n`;
                message += `• *Status:* ⚠️ Overdue\n\n`;
                message += `Please make the payment immediately to avoid any inconvenience.\n`;
            } else {
                message += `• *Status:* ⏳ Pending\n\n`;
                message += `Please make the payment on or before the due date.\n`;
            }
            
            message += `\n📞 Contact: +91 1234567890\n`;
            message += `📍 Shree Vaari Chits Private Limited`;
            
            const encodedMessage = encodeURIComponent(message);
            const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodedMessage}`;
            window.open(whatsappUrl, '_blank');
        }

        // Export to Excel
        function exportToExcel() {
            const table = document.querySelector('.pending-table');
            if (!table) return;
            const html = table.outerHTML;
            const blob = new Blob([html], {type: 'application/vnd.ms-excel'});
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.href = url;
            link.download = 'pending_collections_' + new Date().toISOString().slice(0,10) + '.xls';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
        
        // Send Bulk Reminders
        function sendBulkReminders() {
            const remindButtons = document.querySelectorAll('.whatsapp-btn-small');
            if (remindButtons.length === 0) {
                alert('No pending collections to send reminders for.');
                return;
            }
            
            if (confirm(`Send reminders to ${remindButtons.length} customers? This will open ${remindButtons.length} WhatsApp windows.`)) {
                remindButtons.forEach(button => {
                    button.click();
                    setTimeout(() => {}, 500);
                });
            }
        }
    </script>

    <style>
        @media print {
            .startbar, .topbar, .filter-card, .btn, .action-buttons, .card-header .btn, .rightbar, footer {
                display: none !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .page-wrapper {
                margin: 0 !important;
                padding: 0 !important;
            }
            .pending-table {
                font-size: 10px !important;
            }
            .overdue-row, .today-row {
                background-color: #f8f9fa !important;
            }
        }
    </style>
</body>
</html>