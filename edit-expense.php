<?php
// edit-expense.php - Edit Existing Expense
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
// Redirect if not logged in (adjust based on your auth logic)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'includes/db.php';

// Get expense ID
$expense_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
if ($expense_id <= 0) {
    $_SESSION['error'] = "Invalid expense ID.";
    header("Location: manage-expenses.php");
    exit;
}

// Fetch existing expense
$stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ?");
$stmt->bind_param("i", $expense_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Expense not found.";
    header("Location: manage-expenses.php");
    exit;
}

$expense = $result->fetch_assoc();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expense_date = trim($_POST['expense_date']);
    $description  = trim($_POST['description']);
    $amount       = floatval($_POST['amount']);

    // Basic validation
    if (empty($expense_date) || empty($description) || $amount <= 0) {
        $error = "All fields are required and amount must be greater than 0.";
    } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $expense_date)) {
        $error = "Invalid date format.";
    } else {
        $stmt = $conn->prepare("UPDATE expenses SET expense_date = ?, description = ?, amount = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssdi", $expense_date, $description, $amount, $expense_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Expense updated successfully!";
            header("Location: manage-expenses.php");
            exit;
        } else {
            $error = "Failed to update expense. Please try again.";
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
                $page_title = "Edit Expense";
                $breadcrumb_active = "Edit Expense";
                include 'includes/breadcrumb.php';
                ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-lg-8 col-xl-6">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">
                                    <i class="fas fa-edit me-2"></i>Edit Expense #<?= $expense['id']; ?>
                                </h4>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="expense_date" class="form-label fw-semibold">
                                            Expense Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" class="form-control" id="expense_date" name="expense_date"
                                               value="<?= htmlspecialchars($expense['expense_date']); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="description" class="form-label fw-semibold">
                                            Description <span class="text-danger">*</span>
                                        </label>
                                        <textarea class="form-control" id="description" name="description" rows="4"
                                                  placeholder="e.g. Office rent, Electricity bill, Staff salary..." required><?= htmlspecialchars($expense['description']); ?></textarea>
                                    </div>

                                    <div class="mb-4">
                                        <label for="amount" class="form-label fw-semibold">
                                            Amount (₹) <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" step="0.01" min="0.01" class="form-control form-control-lg"
                                               id="amount" name="amount" value="<?= number_format($expense['amount'], 2, '.', ''); ?>" required>
                                    </div>

                                    <div class="d-flex justify-content-between">
                                        <a href="manage-expenses.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left me-1"></i> Back to List
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Update Expense
                                        </button>
                                    </div>
                                </form>

                                <!-- Current Record Info -->
                                <div class="mt-4 pt-4 border-top">
                                    <small class="text-muted">
                                        <strong>Added on:</strong> <?= date('d M Y, h:i A', strtotime($expense['created_at'])); ?><br>
                                        <?php if ($expense['updated_at']): ?>
                                            <strong>Last updated:</strong> <?= date('d M Y, h:i A', strtotime($expense['updated_at'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>

    <script>
        // Optional: Auto-focus on description if needed
        document.getElementById('description').focus();
    </script>
</body>
</html>