<?php
// manage-expenses.php - Manage Expenses (Simple UI with Edit + Date Range/Year Filter)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Handle Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Expense deleted successfully!";
    } else {
        $_SESSION['error'] = "Failed to delete expense.";
    }
    $stmt->close();
    header("Location: manage-expenses.php");
    exit;
}

// Filters
$search = trim($_GET['search'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$year_filter = $_GET['year'] ?? '';

// Fetch distinct years for filter
$years_result = $conn->query("SELECT DISTINCT YEAR(expense_date) AS year FROM expenses ORDER BY year DESC");
$years = [];
while ($y = $years_result->fetch_assoc()) {
    $years[] = $y['year'];
}

// Build query
$sql = "SELECT * FROM expenses WHERE 1=1";
$params = [];
$types = '';

if ($search) {
    $sql .= " AND description LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}
if ($from_date) {
    $sql .= " AND expense_date >= ?";
    $params[] = $from_date;
    $types .= "s";
}
if ($to_date) {
    $sql .= " AND expense_date <= ?";
    $params[] = $to_date;
    $types .= "s";
}
if ($year_filter) {
    $sql .= " AND YEAR(expense_date) = ?";
    $params[] = $year_filter;
    $types .= "i";
}

$sql .= " ORDER BY expense_date DESC, created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$expenses = [];
$total_expenses = 0;
while ($row = $result->fetch_assoc()) {
    $expenses[] = $row;
    $total_expenses += $row['amount'];
}
$stmt->close();
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
                $page_title = "Manage Expenses";
                $breadcrumb_active = "Expenses";
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
                        <h3 class="mb-0">Manage Expenses</h3>
                        <small class="text-muted">View, edit, and delete recorded expenses</small>
                    </div>
                    <div class="col-auto">
                        <a href="add-expense.php" class="btn btn-primary">
                            Add Expense
                        </a>
                    </div>
                </div>

                <!-- Search & Filter -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search Description</label>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="e.g. Rent">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($from_date); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($to_date); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Year</label>
                                <select class="form-control" name="year">
                                    <option value="">All Years</option>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?= $y; ?>" <?= $year_filter == $y ? 'selected' : ''; ?>><?= $y; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Total Summary -->
                <div class="row mb-4">
                    <div class="col-md-4 offset-md-4">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Expenses</h6>
                                <h3 class="text-primary mb-0">₹<?= number_format($total_expenses, 2); ?></h3>
                                <small><?= count($expenses); ?> records</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expenses Table -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">All Expenses</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($expenses)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Amount (₹)</th>
                                            <th>Added On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expenses as $index => $expense): ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td><?= date('d-m-Y', strtotime($expense['expense_date'])); ?></td>
                                                <td><?= htmlspecialchars($expense['description']); ?></td>
                                                <td class="text-danger">₹<?= number_format($expense['amount'], 2); ?></td>
                                                <td><?= date('d-m-Y H:i', strtotime($expense['created_at'])); ?></td>
                                                <td>
                                                    <a href="edit-expense.php?edit=<?= $expense['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                        Edit
                                                    </a>
                                                    <a href="manage-expenses.php?delete=<?= $expense['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                                                       onclick="return confirm('Delete this expense?');">
                                                        Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="3">Total</th>
                                            <th class="text-danger">₹<?= number_format($total_expenses, 2); ?></th>
                                            <th colspan="2"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <h4 class="text-muted">No Expenses Found</h4>
                                <p class="text-muted">No records match your filters.</p>
                                <a href="add-expense.php" class="btn btn-primary">Add Expense</a>
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