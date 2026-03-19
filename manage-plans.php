<?php
// manage-plans.php - Chit Plans List & Management
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Handle Plan Deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);

    // Check if any members are enrolled in this plan
    $check_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM members WHERE plan_id = ?");
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $member_count = $check_result->fetch_assoc()['count'];
    $check_stmt->close();

    if ($member_count > 0) {
        $_SESSION['error'] = "Cannot delete plan: $member_count member(s) enrolled. Remove members first.";
    } else {
        // Delete plan_details first
        $conn->query("DELETE FROM plan_details WHERE plan_id = $delete_id");
        // Delete plan
        $delete_stmt = $conn->prepare("DELETE FROM plans WHERE id = ?");
        $delete_stmt->bind_param("i", $delete_id);
        if ($delete_stmt->execute()) {
            $_SESSION['success'] = "Plan deleted successfully!";
        } else {
            $_SESSION['error'] = "Failed to delete plan.";
        }
        $delete_stmt->close();
    }
    header("Location: manage-plans.php");
    exit;
}

// Fetch all plans with member count
$sql = "
    SELECT 
        p.id,
        p.title,
        p.monthly_installment,
        p.total_months,
        p.total_received_amount,
        COUNT(m.id) AS member_count
    FROM plans p
    LEFT JOIN members m ON m.plan_id = p.id
    GROUP BY p.id
    ORDER BY p.title ASC";

$result = $conn->query($sql);
$plans = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $plans[] = $row;
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
                $page_title = "Manage Chit Plans";
                $breadcrumb_active = "Plans List";
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
                        <h3 class="mb-0">Chit Plans List</h3>
                        <small class="text-muted">Manage all available chit fund plans</small>
                    </div>
                    <div class="col-auto">
                        <!-- Placeholder for future Add Plan button -->
                        <a href="#" class="btn btn-primary" onclick="alert('Add/Edit Plan feature coming soon. Currently add via SQL.');">
                            <i class="fas fa-plus"></i> Add New Plan
                        </a>
                    </div>
                </div>

                <!-- Plans Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Plans</h6>
                                <h3 class="text-primary mb-0"><?= count($plans); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Enrolled Members</h6>
                                <h3 class="text-success mb-0">
                                    <?php
                                    $total_members = 0;
                                    foreach ($plans as $p) $total_members += $p['member_count'];
                                    echo $total_members;
                                    ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Active Plans</h6>
                                <h3 class="text-info mb-0"><?= count($plans); ?></h3>
                                <small>All plans are active</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Note</h6>
                                <p class="mb-0 small">Add/Edit via SQL for now</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plans Table -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">All Chit Plans</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($plans)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Plan Title</th>
                                            <th>Monthly Installment (₹)</th>
                                            <th>Tenure</th>
                                            <th>Prize Amount (₹)</th>
                                            <th>Enrolled Members</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($plans as $index => $plan): ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($plan['title']); ?></strong>
                                                </td>
                                                <td>₹<?= number_format($plan['monthly_installment'], 2); ?></td>
                                                <td><?= $plan['total_months']; ?> months</td>
                                                <td>₹<?= number_format($plan['total_received_amount'], 2); ?></td>
                                                <td>
                                                    <span class="badge bg-<?= $plan['member_count'] > 0 ? 'success' : 'secondary'; ?>">
                                                        <?= $plan['member_count']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="plan-details.php?id=<?= $plan['id']; ?>" class="btn btn-sm btn-info" title="View Details">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <a href="manage-plans.php?delete=<?= $plan['id']; ?>" class="btn btn-sm btn-danger" title="Delete Plan"
                                                       onclick="return confirm('Delete this plan? This will also delete its installment details. Cannot delete if members enrolled.');">
                                                        <i class="fas fa-trash"></i> Delete
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
                                <h4 class="text-muted">No Plans Found</h4>
                                <p>Add plans via SQL to get started.</p>
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