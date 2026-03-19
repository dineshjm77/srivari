<?php
// add-expense.php - Add New Expense
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? '';

    // Validation
    $errors = [];
    if (empty($description)) $errors[] = "Description is required.";
    if ($amount <= 0) $errors[] = "Amount must be greater than 0.";
    if (empty($expense_date)) $errors[] = "Expense date is required.";

    if (!empty($errors)) {
        $_SESSION['error'] = "• " . implode("<br>• ", $errors);
    } else {
        $stmt = $conn->prepare("INSERT INTO expenses (description, amount, expense_date) VALUES (?, ?, ?)");
        $stmt->bind_param("sds", $description, $amount, $expense_date);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Expense added successfully!";
            header("Location: manage-expenses.php");
            exit;
        } else {
            $_SESSION['error'] = "Failed to add expense.";
        }
        $stmt->close();
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
                $page_title = "Add Expense";
                $breadcrumb_active = "Add Expense";
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
                        <h3 class="mb-0">Add New Expense</h3>
                        <small class="text-muted">Record operational or other expenses</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-expenses.php" class="btn btn-light">Back to Expenses</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Expense Details</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Description <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="description" required placeholder="e.g. Office Rent, Commission">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Amount (₹) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" class="form-control" name="amount" required min="0.01">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Expense Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="expense_date" required value="<?= date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="mt-4 text-end">
                                <button type="submit" class="btn btn-success btn-lg">Save Expense</button>
                                <a href="manage-expenses.php" class="btn btn-light btn-lg">Cancel</a>
                            </div>
                        </form>
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