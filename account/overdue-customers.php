<?php
// overdue-members.php - List of Members with Overdue Payments
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Fetch summary stats
$total_overdue_members = 0;
$total_overdue_amount = 0;

$sql_summary = "
    SELECT 
        COUNT(DISTINCT es.member_id) AS overdue_members,
        SUM(es.emi_amount) AS overdue_amount
    FROM emi_schedule es
    WHERE es.status = 'unpaid'
      AND es.emi_due_date < CURDATE()";

$result_summary = $conn->query($sql_summary);
if ($result_summary && $row = $result_summary->fetch_assoc()) {
    $total_overdue_members = $row['overdue_members'];
    $total_overdue_amount = $row['overdue_amount'] ?? 0;
}

// Fetch overdue members list
$sql = "
    SELECT 
        m.id AS member_id,
        m.agreement_number,
        m.customer_name,
        m.customer_number,
        p.title AS plan_title,
        COUNT(es.id) AS overdue_count,
        SUM(es.emi_amount) AS overdue_amount,
        MAX(es.emi_due_date) AS latest_due_date
    FROM members m
    JOIN plans p ON m.plan_id = p.id
    JOIN emi_schedule es ON es.member_id = m.id
    WHERE es.status = 'unpaid'
      AND es.emi_due_date < CURDATE()
    GROUP BY m.id
    ORDER BY overdue_amount DESC, latest_due_date ASC";

$result = $conn->query($sql);
$overdue_members = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $overdue_members[] = $row;
    }
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
                $page_title = "Overdue Payments";
                $breadcrumb_active = "Overdue List";
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
                        <h3 class="mb-0">Overdue Payments List</h3>
                        <small class="text-muted">Members with unpaid EMIs past due date</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-members.php" class="btn btn-light">Back to All Members</a>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-danger shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Overdue Members</h6>
                                <h2 class="text-danger mb-0"><?= $total_overdue_members; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Overdue Amount</h6>
                                <h2 class="text-warning mb-0">₹<?= number_format($total_overdue_amount, 2); ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info shadow-sm">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Current Date</h6>
                                <h4 class="text-info mb-0"><?= date('d-m-Y'); ?></h4>
                                <small class="text-muted">Overdue if before today</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Overdue Members Table -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Members with Overdue Payments</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($overdue_members)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-danger">
                                        <tr>
                                            <th>#</th>
                                            <th>Agreement No.</th>
                                            <th>Customer Name</th>
                                            <th>Phone</th>
                                            <th>Plan</th>
                                            <th>Overdue EMIs</th>
                                            <th>Overdue Amount (₹)</th>
                                            <th>Latest Due Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdue_members as $index => $member): ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td><?= htmlspecialchars($member['agreement_number']); ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($member['customer_name']); ?></strong>
                                                </td>
                                                <td><?= htmlspecialchars($member['customer_number']); ?></td>
                                                <td><?= htmlspecialchars($member['plan_title']); ?></td>
                                                <td>
                                                    <span class="badge bg-danger"><?= $member['overdue_count']; ?></span>
                                                </td>
                                                <td>
                                                    <strong class="text-danger">₹<?= number_format($member['overdue_amount'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <?= date('d-m-Y', strtotime($member['latest_due_date'])); ?>
                                                    <br><small class="text-danger">Overdue</small>
                                                </td>
                                                <td>
                                                    <a href="emi-schedule-member.php?id=<?= $member['member_id']; ?>"
                                                       class="btn btn-sm btn-primary">
                                                        View Schedule
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                                <h4 class="text-success">No Overdue Payments!</h4>
                                <p class="text-muted">All members are up to date.</p>
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
</body>
</html>