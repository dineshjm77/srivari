<?php
// collection-reports.php - Collection, Pending & Big Winner Reports (With Plan Filter)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Add after database connection
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get current user info
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? 'staff';
$current_user_name = $_SESSION['full_name'] ?? 'User';

// For staff role, only show their own collections by default
if ($current_user_role == 'staff' && $collector_filter == 0) {
    $collector_filter = $current_user_id;
}

include 'includes/db.php';

// Fetch all plans for filter dropdown
$plans_result = $conn->query("SELECT id, title FROM plans ORDER BY title ASC");
$plans = [];
$plans[] = ['id' => 0, 'title' => 'All Plans']; // Default option
while ($p = $plans_result->fetch_assoc()) {
    $plans[] = $p;
}
// Fetch collectors based on user role
if ($current_user_role == 'admin') {
    // Admin can see all staff and admin
    $sql_collectors = "SELECT id, full_name FROM users WHERE role IN ('admin', 'staff') AND status = 'active' ORDER BY full_name ASC";
} elseif ($current_user_role == 'accountant') {
    // Accountant can see all staff and admin
    $sql_collectors = "SELECT id, full_name FROM users WHERE role IN ('admin', 'staff') AND status = 'active' ORDER BY full_name ASC";
} elseif ($current_user_role == 'staff') {
    // Staff can only see themselves
    $sql_collectors = "SELECT id, full_name FROM users WHERE id = ? AND status = 'active'";
} else {
    $sql_collectors = "SELECT id, full_name FROM users WHERE id = ? AND status = 'active'";
}

$stmt_collectors = $conn->prepare($sql_collectors);
if ($current_user_role == 'staff' || $current_user_role == 'unknown') {
    $stmt_collectors->bind_param("i", $current_user_id);
}
$stmt_collectors->execute();
$result_collectors = $stmt_collectors->get_result();
$collectors = [];
while ($row = $result_collectors->fetch_assoc()) {
    $collectors[] = $row;
}
$stmt_collectors->close();
// Filters
$plan_filter = intval($_GET['plan_id'] ?? 0); // 0 = All
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');
$month_filter = $_GET['month'] ?? date('Y-m');

// Override with month if selected
if ($month_filter && strlen($month_filter) == 7) {
    $from_date = $month_filter . '-01';
    $to_date = date('Y-m-t', strtotime($month_filter . '-01'));
}

$from_date = date('Y-m-d', strtotime($from_date));
$to_date = date('Y-m-d', strtotime($to_date));

// Build WHERE clause for collections
$where_clause = "es.paid_date BETWEEN ? AND ?";
$params = [$from_date, $to_date];
$types = "ss";

if ($plan_filter > 0) {
    $where_clause .= " AND es.plan_id = ?";
    $params[] = $plan_filter;
    $types .= "i";
}

// Paid Collections
$sql_paid = "
    SELECT 
        m.customer_name,
        m.agreement_number,
        p.title AS plan_title,
        es.emi_amount,
        es.paid_date,
        es.emi_bill_number
    FROM emi_schedule es
    JOIN members m ON es.member_id = m.id
    JOIN plans p ON es.plan_id = p.id
    WHERE es.status = 'paid' AND $where_clause
    ORDER BY es.paid_date DESC";

$stmt_paid = $conn->prepare($sql_paid);
$stmt_paid->bind_param($types, ...$params);
$stmt_paid->execute();
$result_paid = $stmt_paid->get_result();
$paid_collections = [];
$total_collected = 0;
while ($row = $result_paid->fetch_assoc()) {
    $paid_collections[] = $row;
    $total_collected += $row['emi_amount'];
}
$stmt_paid->close();

// Pending/Unpaid
$where_clause_pending = "es.emi_due_date BETWEEN ? AND ?";
$params_pending = [$from_date, $to_date];
$types_pending = "ss";

if ($plan_filter > 0) {
    $where_clause_pending .= " AND es.plan_id = ?";
    $params_pending[] = $plan_filter;
    $types_pending .= "i";
}

$sql_pending = "
    SELECT 
        m.customer_name,
        m.agreement_number,
        p.title AS plan_title,
        es.emi_amount,
        es.emi_due_date,
        DATEDIFF(CURDATE(), es.emi_due_date) AS days_overdue
    FROM emi_schedule es
    JOIN members m ON es.member_id = m.id
    JOIN plans p ON es.plan_id = p.id
    WHERE es.status = 'unpaid' AND $where_clause_pending
    ORDER BY es.emi_due_date ASC";

$stmt_pending = $conn->prepare($sql_pending);
$stmt_pending->bind_param($types_pending, ...$params_pending);
$stmt_pending->execute();
$result_pending = $stmt_pending->get_result();
$pending_collections = [];
$total_pending = 0;
while ($row = $result_pending->fetch_assoc()) {
    $pending_collections[] = $row;
    $total_pending += $row['emi_amount'];
}
$stmt_pending->close();

if ($collector_filter > 0) {
    $where_clause .= " AND es.collected_by = ?";
    $params[] = $collector_filter;
    $types .= 'i';
} elseif ($current_user_role == 'staff') {
    // Staff can only see their own collections if no specific collector is selected
    $where_clause .= " AND es.collected_by = ?";
    $params[] = $current_user_id;
    $types .= 'i';
}

// Big Winners (All time - no filter for now)
$sql_winners = "
    SELECT 
        m.customer_name,
        m.agreement_number,
        p.title AS plan_title,
        m.winner_amount,
        m.winner_date,
        m.winner_number
    FROM members m
    JOIN plans p ON m.plan_id = p.id
    WHERE m.winner_amount IS NOT NULL AND m.winner_amount > 0
    ORDER BY m.winner_date DESC";

$result_winners = $conn->query($sql_winners);
$winners = [];
while ($row = $result_winners->fetch_assoc()) {
    $winners[] = $row;
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
                $page_title = "Collection Reports";
                $breadcrumb_active = "Reports";
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
                        <h3 class="mb-0">Collection Reports</h3>
                        <small class="text-muted">Paid collections, pending EMIs and big winners</small>
                    </div>
                    <div class="col-auto">
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="fas fa-print me-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Filters (Default: Current Month - <?= date('F Y'); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Plan</label>
                                <select class="form-control" name="plan_id">
                                    <?php foreach ($plans as $p): ?>
                                        <option value="<?= $p['id']; ?>" <?= $plan_filter == $p['id'] ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($p['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Month Wise</label>
                                <input type="month" class="form-control" name="month" value="<?= htmlspecialchars($month_filter); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($from_date); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($to_date); ?>">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                            </div>
                        </form>
                        <small class="text-muted mt-2 d-block">Plan filter applies to collections & pending. Big Winners show all time.</small>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Collected (Filtered)</h6>
                                <h3 class="text-success mb-0">₹<?= number_format($total_collected, 2); ?></h3>
                                <small><?= count($paid_collections); ?> payments</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Pending (Filtered)</h6>
                                <h3 class="text-danger mb-0">₹<?= number_format($total_pending, 2); ?></h3>
                                <small><?= count($pending_collections); ?> pending EMIs</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Big Winners (All Time)</h6>
                                <h3 class="text-warning mb-0"><?= count($winners); ?></h3>
                                <small>winners declared</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Paid Collections Table -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">Paid Collections (<?= count($paid_collections); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($paid_collections)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Customer</th>
                                            <th>Agreement No.</th>
                                            <th>Plan</th>
                                            <th>Amount (₹)</th>
                                            <th>Paid Date</th>
                                            <th>Bill No.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paid_collections as $index => $paid): ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td><?= htmlspecialchars($paid['customer_name']); ?></td>
                                                <td><?= htmlspecialchars($paid['agreement_number']); ?></td>
                                                <td><?= htmlspecialchars($paid['plan_title']); ?></td>
                                                <td class="text-success fw-bold">₹<?= number_format($paid['emi_amount'], 2); ?></td>
                                                <td><?= date('d-m-Y', strtotime($paid['paid_date'])); ?></td>
                                                <td><?= htmlspecialchars($paid['emi_bill_number'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-success">
                                        <tr>
                                            <th colspan="4">Total Collected</th>
                                            <th class="fw-bold">₹<?= number_format($total_collected, 2); ?></th>
                                            <th colspan="2"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                No collections received in this period.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending / Non-Collection Table -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">Non-Collection / Pending EMIs (<?= count($pending_collections); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pending_collections)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Customer</th>
                                            <th>Agreement No.</th>
                                            <th>Plan</th>
                                            <th>Amount (₹)</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_collections as $index => $pending):
                                            $overdue = $pending['days_overdue'] > 0;
                                        ?>
                                            <tr class="<?= $overdue ? 'table-danger' : 'table-warning'; ?>">
                                                <td><?= $index + 1; ?></td>
                                                <td><?= htmlspecialchars($pending['customer_name']); ?></td>
                                                <td><?= htmlspecialchars($pending['agreement_number']); ?></td>
                                                <td><?= htmlspecialchars($pending['plan_title']); ?></td>
                                                <td class="fw-bold">₹<?= number_format($pending['emi_amount'], 2); ?></td>
                                                <td><?= date('d-m-Y', strtotime($pending['emi_due_date'])); ?></td>
                                                <td>
                                                    <?php if ($overdue): ?>
                                                        <span class="badge bg-danger">Overdue (<?= $pending['days_overdue']; ?> days)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-danger">
                                        <tr>
                                            <th colspan="4">Total Pending</th>
                                            <th class="fw-bold">₹<?= number_format($total_pending, 2); ?></th>
                                            <th colspan="2"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-success">
                                <i class="fas fa-check-circle fa-3x mb-3"></i>
                                <h5>No pending EMIs in this period!</h5>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Big Winner List -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">Big Winners List (<?= count($winners); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($winners)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Customer</th>
                                            <th>Agreement No.</th>
                                            <th>Plan</th>
                                            <th>Winning Amount (₹)</th>
                                            <th>Winning Date</th>
                                            <th>Winner Number</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($winners as $index => $winner): ?>
                                            <tr class="table-success">
                                                <td><?= $index + 1; ?></td>
                                                <td><strong><?= htmlspecialchars($winner['customer_name']); ?></strong></td>
                                                <td><?= htmlspecialchars($winner['agreement_number']); ?></td>
                                                <td><?= htmlspecialchars($winner['plan_title']); ?></td>
                                                <td class="text-success fw-bold">₹<?= number_format($winner['winner_amount'], 2); ?></td>
                                                <td><?= date('d-m-Y', strtotime($winner['winner_date'])); ?></td>
                                                <td><?= htmlspecialchars($winner['winner_number'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-trophy fa-3x mb-3"></i>
                                <h5>No Big Winners Declared Yet</h5>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>

    <style>
        @media print {
            .startbar, .topbar, .rightbar, .breadcrumb, .card-header .btn {
                display: none !important;
            }
            .page-wrapper {
                margin-left: 0 !important;
            }
            body {
                padding: 20px;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</body>
</html>