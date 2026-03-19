<?php
// add-plan.php - Add New Chit Plan
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'includes/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $monthly_installment = floatval($_POST['monthly_installment'] ?? 0);
    $total_periods = intval($_POST['total_periods'] ?? 0);
    $total_received_amount = floatval($_POST['total_received_amount'] ?? 0);

    $installments = $_POST['installment'] ?? [];
    $withdrawals = $_POST['withdrawal_eligible'] ?? [];

    // Validation
    $errors = [];
    if (empty($title)) $errors[] = "Plan title is required.";
    if ($monthly_installment <= 0) $errors[] = "Monthly/Weekly installment must be greater than 0.";
    if ($total_periods <= 0) $errors[] = "Total periods must be greater than 0.";
    if ($total_received_amount <= 0) $errors[] = "Prize amount must be greater than 0.";
    if (count($installments) != $total_periods || count($withdrawals) != $total_periods) {
        $errors[] = "All period details must be filled.";
    }

    foreach ($installments as $i => $inst) {
        if ($inst <= 0) $errors[] = "Installment for period " . ($i + 1) . " must be greater than 0.";
    }

    if (!empty($errors)) {
        $_SESSION['error'] = "• " . implode("<br>• ", $errors);
    } else {
        // Insert plan
        $stmt = $conn->prepare("INSERT INTO plans (title, monthly_installment, total_months, total_received_amount) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sdid", $title, $monthly_installment, $total_periods, $total_received_amount);
        if ($stmt->execute()) {
            $plan_id = $conn->insert_id;

            // Insert plan_details
            $stmt_detail = $conn->prepare("INSERT INTO plan_details (plan_id, month_number, installment, withdrawal_eligible) VALUES (?, ?, ?, ?)");
            for ($i = 0; $i < $total_periods; $i++) {
                $period_num = $i + 1;
                $inst = floatval($installments[$i]);
                $withdrawal = floatval($withdrawals[$i]);
                $stmt_detail->bind_param("iidd", $plan_id, $period_num, $inst, $withdrawal);
                $stmt_detail->execute();
            }
            $stmt_detail->close();

            $_SESSION['success'] = "New plan '$title' added successfully!";
            header("Location: manage-plans.php");
            exit;
        } else {
            $_SESSION['error'] = "Failed to add plan.";
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
                $page_title = "Add New Plan";
                $breadcrumb_active = "Add Plan";
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
                        <h3 class="mb-0">Add New Chit Plan</h3>
                        <small class="text-muted">Create a new chit fund plan with installment details</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-plans.php" class="btn btn-light">Back to Plans List</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">New Plan Information</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Plan Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="title" required placeholder="e.g. 5 Lakhs - 29 Months or 1 Lakh - 16 Weeks (Weekly)">
                                    <small class="text-muted">Include "(Weekly)" for weekly plans</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Nominal Installment (₹) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" class="form-control" name="monthly_installment" required min="0.01">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Prize Amount (₹) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" class="form-control" name="total_received_amount" required min="0.01">
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Total Periods <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="total_periods" name="total_periods" required min="1" onchange="generateRows()">
                                    <small class="text-muted">Number of months/weeks</small>
                                </div>
                            </div>

                            <h5 class="mb-3">Installment Details</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="details_table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Period</th>
                                            <th>Installment Amount (₹) <span class="text-danger">*</span></th>
                                            <th>Withdrawal Eligible (₹)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Rows generated by JS -->
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="2">Total Installments Paid by Customer</th>
                                            <th id="total_installments">₹0.00</th>
                                            <th>-</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div class="mt-4 text-end">
                                <button type="submit" class="btn btn-success btn-lg">Save New Plan</button>
                                <a href="manage-plans.php" class="btn btn-light btn-lg">Cancel</a>
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

    <script>
        function generateRows() {
            const total = parseInt(document.getElementById('total_periods').value) || 0;
            const tbody = document.querySelector('#details_table tbody');
            tbody.innerHTML = '';

            let totalInst = 0;

            for (let i = 1; i <= total; i++) {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${i}</td>
                    <td><strong>${i}</strong></td>
                    <td>
                        <input type="number" step="0.01" name="installment[]" class="form-control" required min="0.01" onchange="calculateTotal()">
                    </td>
                    <td>
                        <input type="number" step="0.01" name="withdrawal_eligible[]" class="form-control" min="0" value="0" onchange="calculateTotal()">
                    </td>
                `;
                tbody.appendChild(row);
            }

            calculateTotal();
        }

        function calculateTotal() {
            let total = 0;
            document.querySelectorAll('input[name="installment[]"]').forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            document.getElementById('total_installments').textContent = '₹' + total.toFixed(2);
        }
    </script>
</body>
</html>