<?php
// big-winners.php - Big Winners Report & List
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Fetch all Big Winners
$sql = "
    SELECT 
        m.id AS member_id,
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

$result = $conn->query($sql);
$winners = [];
$total_winners = 0;
$total_prize_amount = 0;
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $winners[] = $row;
        $total_winners++;
        $total_prize_amount += $row['winner_amount'];
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
                $page_title = "Big Winners Report";
                $breadcrumb_active = "Big Winners";
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
                        <h3 class="mb-0">Big Winners Report</h3>
                        <small class="text-muted">List of all declared big winners across all plans</small>
                    </div>
                    <div class="col-auto">
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="fas fa-print me-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Big Winners</h6>
                                <h3 class="text-warning mb-0"><?= $total_winners; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Prize Amount Paid</h6>
                                <h3 class="text-success mb-0">₹<?= number_format($total_prize_amount, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Big Winners Table -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Big Winners List (<?= $total_winners; ?>)</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($winners)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Customer Name</th>
                                            <th>Agreement No.</th>
                                            <th>Plan</th>
                                            <th>Winning Amount (₹)</th>
                                            <th>Winning Date</th>
                                            <th>Winner Number</th>
                                            <th>Actions</th>
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
                                                <td>
                                                    <a href="emi-schedule-member.php?id=<?= $winner['member_id']; ?>" class="btn btn-sm btn-primary">
                                                        View Member
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-success">
                                        <tr>
                                            <th colspan="4">Total</th>
                                            <th class="fw-bold">₹<?= number_format($total_prize_amount, 2); ?></th>
                                            <th colspan="3"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-trophy text-muted fa-4x mb-4"></i>
                                <h4 class="text-muted">No Big Winners Declared Yet</h4>
                                <p class="text-muted">Big winners will appear here once declared.</p>
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
            .startbar, .topbar, .rightbar, .breadcrumb, .btn {
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