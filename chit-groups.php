<?php
// chit-groups.php - Chit Groups (Plans) Management with Stats
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Fetch all chit groups (plans) with statistics
$sql = "
    SELECT 
        p.id,
        p.title,
        p.monthly_installment,
        p.total_months,
        p.total_received_amount,
        COUNT(m.id) AS member_count,
        COALESCE(SUM(CASE WHEN es.status = 'paid' THEN es.emi_amount ELSE 0 END), 0) AS total_collected,
        COALESCE(SUM(es.emi_amount), 0) AS total_due,
        COUNT(es.id) AS total_emis,
        COUNT(CASE WHEN es.status = 'paid' THEN 1 END) AS paid_emis
    FROM plans p
    LEFT JOIN members m ON m.plan_id = p.id
    LEFT JOIN emi_schedule es ON es.member_id = m.id
    GROUP BY p.id
    ORDER BY p.title ASC";

$result = $conn->query($sql);
$groups = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
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
                $page_title = "Chit Groups";
                $breadcrumb_active = "Groups List";
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
                        <h3 class="mb-0">Chit Groups Management</h3>
                        <small class="text-muted">Overview of all active chit groups and their performance</small>
                    </div>
                    <div class="col-auto">
                        <a href="add-plan.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Group
                        </a>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Groups</h6>
                                <h3 class="text-primary mb-0"><?= count($groups); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Members</h6>
                                <h3 class="text-success mb-0">
                                    <?php
                                    $total_members = array_sum(array_column($groups, 'member_count'));
                                    echo $total_members;
                                    ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Collected</h6>
                                <h3 class="text-info mb-0">
                                    ₹<?= number_format(array_sum(array_column($groups, 'total_collected')), 2); ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Due</h6>
                                <h3 class="text-warning mb-0">
                                    ₹<?= number_format(array_sum(array_column($groups, 'total_due')), 2); ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Groups Table -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">All Chit Groups</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($groups)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Group Name</th>
                                            <th>Chit Value (₹)</th>
                                            <th>Installment (₹)</th>
                                            <th>Tenure</th>
                                            <th>Members</th>
                                            <th>Collected (₹)</th>
                                            <th>Due (₹)</th>
                                            <th>Completion</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($groups as $index => $group): 
                                            $completion = $group['total_emis'] > 0 ? round(($group['paid_emis'] / $group['total_emis']) * 100, 1) : 0;
                                        ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($group['title']); ?></strong>
                                                </td>
                                                <td class="text-success fw-bold">₹<?= number_format($group['total_received_amount'], 2); ?></td>
                                                <td>₹<?= number_format($group['monthly_installment'], 2); ?></td>
                                                <td><?= $group['total_months']; ?> periods</td>
                                                <td>
                                                    <span class="badge bg-<?= $group['member_count'] > 0 ? 'success' : 'secondary'; ?>">
                                                        <?= $group['member_count']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-success">₹<?= number_format($group['total_collected'], 2); ?></td>
                                                <td class="text-warning">₹<?= number_format($group['total_due'] - $group['total_collected'], 2); ?></td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar <?= $completion >= 100 ? 'bg-success' : 'bg-info'; ?>" 
                                                             style="width: <?= $completion; ?>%">
                                                            <?= $completion; ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="plan-details.php?id=<?= $group['id']; ?>" class="btn btn-sm btn-primary" title="View Details">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-folder-open text-muted fa-3x mb-3"></i>
                                <h4 class="text-muted">No Chit Groups Found</h4>
                                <p>Add your first chit group to get started.</p>
                                <a href="add-plan.php" class="btn btn-primary">Add New Group</a>
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