<?php
// plan-details.php - Detailed View of a Chit Plan + Enrolled Members
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

$plan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($plan_id == 0) {
    $_SESSION['error'] = "Invalid plan.";
    header("Location: manage-plans.php");
    exit;
}

// Fetch plan details
$sql_plan = "SELECT * FROM plans WHERE id = ?";
$stmt = $conn->prepare($sql_plan);
$stmt->bind_param("i", $plan_id);
$stmt->execute();
$plan_result = $stmt->get_result();
$plan = $plan_result->fetch_assoc();
$stmt->close();

if (!$plan) {
    $_SESSION['error'] = "Plan not found.";
    header("Location: manage-plans.php");
    exit;
}

// Detect if weekly (for display)
$is_weekly = (strpos($plan['title'], 'Weekly') !== false || strpos($plan['title'], 'Weeks') !== false);
$period_label = $is_weekly ? 'Week' : 'Month';

// Fetch plan_details (installments)
$sql_details = "SELECT month_number, installment, withdrawal_eligible
                FROM plan_details
                WHERE plan_id = ?
                ORDER BY month_number ASC";
$stmt_details = $conn->prepare($sql_details);
$stmt_details->bind_param("i", $plan_id);
$stmt_details->execute();
$result_details = $stmt_details->get_result();
$details = [];
$total_installments_paid = 0;
while ($row = $result_details->fetch_assoc()) {
    $details[] = $row;
    $total_installments_paid += $row['installment'];
}
$stmt_details->close();

// Fetch enrolled members
$sql_members = "SELECT id, agreement_number, customer_name, customer_number, emi_date
                FROM members
                WHERE plan_id = ?
                ORDER BY agreement_number ASC";
$stmt_members = $conn->prepare($sql_members);
$stmt_members->bind_param("i", $plan_id);
$stmt_members->execute();
$result_members = $stmt_members->get_result();
$members = [];
while ($row = $result_members->fetch_assoc()) {
    $members[] = $row;
}
$stmt_members->close();
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
                $page_title = "Plan Details";
                $breadcrumb_active = htmlspecialchars($plan['title']);
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
                        <h3 class="mb-0">Plan Details – <?= htmlspecialchars($plan['title']); ?></h3>
                        <small class="text-muted">Full installment schedule and enrolled members</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-plans.php" class="btn btn-light">Back to Plans List</a>
                    </div>
                </div>

                <!-- Plan Summary Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Plan Overview</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <h6 class="text-muted">Installment Amount</h6>
                                <h4>₹<?= number_format($plan['monthly_installment'], 2); ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Tenure</h6>
                                <h4><?= $plan['total_months']; ?> <?= $period_label ?><?= $plan['total_months'] != 1 ? 's' : ''; ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Prize Amount</h6>
                                <h4 class="text-success">₹<?= number_format($plan['total_received_amount'], 2); ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Total Customer Payment</h6>
                                <h4 class="text-primary">₹<?= number_format($total_installments_paid, 2); ?></h4>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-12">
                                <h6 class="text-muted">Enrolled Members</h6>
                                <h4 class="text-info"><?= count($members); ?> member<?= count($members) != 1 ? 's' : ''; ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Installment Schedule Table -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Installment Schedule</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th><?= $period_label ?> Number</th>
                                        <th>Installment Amount (₹)</th>
                                        <th>Withdrawal Eligible (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($details)): ?>
                                        <?php foreach ($details as $index => $detail): ?>
                                            <tr <?= ($detail['withdrawal_eligible'] > 0) ? 'class="table-success"' : ''; ?>>
                                                <td><?= $index + 1; ?></td>
                                                <td><strong><?= $detail['month_number']; ?></strong></td>
                                                <td>₹<?= number_format($detail['installment'], 2); ?></td>
                                                <td>
                                                    <?php if ($detail['withdrawal_eligible'] > 0): ?>
                                                        <strong class="text-success">₹<?= number_format($detail['withdrawal_eligible'], 2); ?></strong>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                No installment details found for this plan.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2">Total</th>
                                        <th>₹<?= number_format($total_installments_paid, 2); ?></th>
                                        <th>-</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Enrolled Members Table -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Enrolled Members (<?= count($members); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($members)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Agreement No.</th>
                                            <th>Customer Name</th>
                                            <th>Phone</th>
                                            <th>Agreement Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($members as $index => $member): ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td><?= htmlspecialchars($member['agreement_number']); ?></td>
                                                <td><strong><?= htmlspecialchars($member['customer_name']); ?></strong></td>
                                                <td><?= htmlspecialchars($member['customer_number']); ?></td>
                                                <td><?= date('d-m-Y', strtotime($member['emi_date'])); ?></td>
                                                <td>
                                                    <a href="emi-schedule-member.php?id=<?= $member['id']; ?>" class="btn btn-sm btn-primary">
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
                                <i class="fas fa-users text-muted fa-3x mb-3"></i>
                                <h4 class="text-muted">No Members Enrolled</h4>
                                <p class="text-muted">No customers have joined this plan yet.</p>
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