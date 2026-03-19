<?php
// Enable error logging instead of displaying errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u329947844/domains/hifi11.in/public_html/finance/error_log');
// Start session
session_start();

// Database connection
include 'includes/db.php';

// Get selected finance_id from session
$finance_id = isset($_SESSION['finance_id']) ? intval($_SESSION['finance_id']) : 0;

// Fetch all expenses (filtered by finance_id if selected)
$sql = "SELECT id, remark, amount, expense_date, category, created_at FROM expenses";
$params = [];
$types = "";
if ($finance_id > 0) {
    $sql .= " WHERE finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}
$sql .= " ORDER BY expense_date DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = mysqli_query($conn, $sql);
}

// Check for query errors
if (!$result) {
    error_log("Error fetching expenses: " . $conn->error);
    $_SESSION['error'] = "Error fetching expenses: " . $conn->error;
    $expenses = [];
} else {
    $expenses = [];
    $total_expenses = 0;
    while ($row = $result->fetch_assoc()) {
        $total_expenses += $row['amount'];
        $expenses[] = $row;
    }
    if (isset($stmt)) {
        $stmt->close();
    }
    $result->free();
}

mysqli_close($conn);

// Check if finance is selected
$finance_selected = $finance_id > 0;
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>

<body>
    <!-- Top Bar Start -->
    <?php include 'includes/topbar.php'; ?>
    <!-- Top Bar End -->

    <!-- leftbar-tab-menu -->
    <div class="startbar d-print-none">
        <?php include 'includes/leftbar-tab-menu.php'; ?> 
        <?php include 'includes/leftbar.php'; ?>
        <div class="startbar-overlay d-print-none"></div>

        <div class="page-wrapper">
            <!-- Page Content-->
            <div class="page-content">
                <div class="container-fluid">
                    <!-- Breadcrumb Start -->
                    <?php
                    $page_title = "Manage Expenses";
                    $breadcrumb_active = "Manage Expenses";
                    include 'includes/breadcrumb.php';
                    ?>
                    <!-- Breadcrumb End -->

                    <!-- Flash Messages -->
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <strong>Success!</strong> <?php echo $_SESSION['success']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Error!</strong> <?php echo $_SESSION['error']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <?php if (!$finance_selected): ?>
                        <div class="alert alert-warning" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>No Finance Selected:</strong> Please select a finance from the topbar dropdown to manage expenses. Expenses are tied to specific finances.
                            <div class="mt-2">
                                <form action="set-finance-session.php" method="post" id="financeSelectForm" class="d-inline">
                                    <select class="form-select d-inline-block w-auto me-2" name="finance_id" onchange="this.form.submit()">
                                        <option value="0">Select Finance</option>
                                        <option value="1">A Finance</option>
                                        <option value="2">B Finance</option>
                                        <option value="3">finance</option>
                                    </select>
                                </form>
                                <a href="#" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('financeSelectForm').submit();">
                                    <i class="iconoir-arrow-left me-1"></i> Select Finance
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row align-items-center mb-3">
                        <div class="col">
                            <h3 class="mb-0">Manage Expenses</h3>
                            <small class="text-muted">
                                <?php if ($finance_selected): ?>
                                    Filtered for selected finance. Total Expenses: <?php echo count($expenses); ?> | Total Amount: ₹<?php echo number_format($total_expenses, 2); ?>
                                <?php else: ?>
                                    Track and manage all business expenses. Total Expenses: <?php echo count($expenses); ?> | Total Amount: ₹<?php echo number_format($total_expenses, 2); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="col-auto">
                            <?php if ($finance_selected): ?>
                                <a href="#addExpenseModal" class="btn btn-primary" data-bs-toggle="modal">
                                    <i class="iconoir-plus me-1"></i> Add New Expense
                                </a>
                            <?php else: ?>
                                <button class="btn btn-primary disabled" disabled>
                                    <i class="iconoir-plus me-1"></i> Add New Expense (Select Finance First)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Expenses Summary Card -->
                    <?php if (!empty($expenses) && $finance_selected): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-danger text-white">
                                <h5 class="card-title mb-0">Expense Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="fw-bold text-danger fs-4">₹<?php echo number_format($total_expenses, 2); ?></div>
                                        <small class="text-muted">Total Expenses</small>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-primary fs-4"><?php echo count($expenses); ?></div>
                                        <small class="text-muted">Total Records</small>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-secondary fs-4"><?php echo date('M Y'); ?></div>
                                        <small class="text-muted">Current Period</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Expenses Table (Desktop) -->
                    <div class="card d-none d-md-block">
                        <div class="card-header">
                            <h4 class="card-title mb-0">Expense List</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="tblExpenses" class="table table-striped table-bordered align-middle w-100">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#Expense ID</th>
                                            <th>Remark</th>
                                            <th>Amount (₹)</th>
                                            <th>Date</th>
                                            <th>Category</th>
                                            <th>Created At</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($expenses)): ?>
                                            <?php foreach ($expenses as $row): ?>
                                                <?php 
                                                $category_badges = [
                                                    'Office Supplies' => 'bg-primary',
                                                    'Travel' => 'bg-warning',
                                                    'Salary' => 'bg-success',
                                                    'Marketing' => 'bg-info',
                                                    'Miscellaneous' => 'bg-secondary',
                                                    'Utilities' => 'bg-info'
                                                ];
                                                $category_class = $category_badges[$row['category']] ?? 'bg-secondary';
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-danger">EXP-<?php echo $row['id']; ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['remark']): ?>
                                                            <div class="fw-medium text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($row['remark']); ?>">
                                                                <?php echo htmlspecialchars($row['remark']); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="fw-bold text-danger">₹<?php echo number_format($row['amount'], 2); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $expense_date = new DateTime($row['expense_date']);
                                                        echo $expense_date->format('d M Y');
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $category_class; ?>">
                                                            <?php echo htmlspecialchars($row['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $created_date = new DateTime($row['created_at']);
                                                        echo $created_date->format('d M Y H:i');
                                                        ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="btn-group">
                                                            <a href="#" class="btn btn-sm btn-outline-info expense-detail-btn" data-bs-toggle="modal" data-bs-target="#expenseDetailsModal" data-expense-id="<?php echo $row['id']; ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="edit-expense.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="las la-edit"></i>
                                                            </a>
                                                            <a href="delete-expense.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this expense?')">
                                                                <i class="las la-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                                        <h5>No expenses found</h5>
                                                        <p>
                                                            <?php if ($finance_id > 0): ?>
                                                                No expenses recorded for the selected finance.
                                                            <?php else: ?>
                                                                No expenses have been added yet. Please select a finance first.
                                                            <?php endif; ?>
                                                        </p>
                                                        <?php if ($finance_selected): ?>
                                                            <a href="#addExpenseModal" class="btn btn-primary" data-bs-toggle="modal">
                                                                <i class="iconoir-plus me-1"></i> Add Your First Expense
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Expenses Cards (Mobile) -->
                    <div class="d-md-none">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Expense List</h4>
                            <?php if (!empty($expenses)): ?>
                                <span class="badge bg-danger"><?php echo count($expenses); ?> Expenses</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($expenses)): ?>
                            <?php foreach ($expenses as $row): ?>
                                <?php 
                                $category_badges = [
                                    'Office Supplies' => 'bg-primary',
                                    'Travel' => 'bg-warning',
                                    'Salary' => 'bg-success',
                                    'Marketing' => 'bg-info',
                                    'Miscellaneous' => 'bg-secondary',
                                    'Utilities' => 'bg-info'
                                ];
                                $category_class = $category_badges[$row['category']] ?? 'bg-secondary';
                                ?>
                                <div class="card mb-3">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-xs me-2">
                                                <div class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                    <i class="fas fa-receipt"></i>
                                                </div>
                                            </div>
                                            <h5 class="mb-0">EXP-<?php echo $row['id']; ?></h5>
                                        </div>
                                        <span class="badge <?php echo $category_class; ?>"><?php echo htmlspecialchars($row['category']); ?></span>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-2">
                                            <!-- Amount -->
                                            <div class="col-6">
                                                <small class="text-muted">Amount</small>
                                                <div class="fw-bold text-danger">₹<?php echo number_format($row['amount'], 2); ?></div>
                                            </div>
                                            
                                            <!-- Date -->
                                            <div class="col-6">
                                                <small class="text-muted">Date</small>
                                                <div>
                                                    <?php 
                                                    $expense_date = new DateTime($row['expense_date']);
                                                    echo $expense_date->format('d M Y');
                                                    ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Remark -->
                                            <div class="col-12">
                                                <small class="text-muted">Remark</small>
                                                <div class="fw-medium">
                                                    <?php if ($row['remark']): ?>
                                                        <?php echo htmlspecialchars($row['remark']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">No remark</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Created At -->
                                            <div class="col-12">
                                                <small class="text-muted">Created</small>
                                                <div>
                                                    <?php 
                                                    $created_date = new DateTime($row['created_at']);
                                                    echo $created_date->format('d M Y H:i');
                                                    ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Actions -->
                                            <div class="col-12 mt-3">
                                                <div class="d-flex justify-content-between gap-2">
                                                    <a href="#" class="btn btn-sm btn-outline-info flex-fill expense-detail-btn" 
                                                       data-bs-toggle="modal" data-bs-target="#expenseDetailsModal" 
                                                       data-expense-id="<?php echo $row['id']; ?>">
                                                        <i class="fas fa-eye me-1"></i> View
                                                    </a>
                                                    <a href="edit-expense.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary flex-fill">
                                                        <i class="las la-edit me-1"></i> Edit
                                                    </a>
                                                    <a href="delete-expense.php?id=<?php echo $row['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger flex-fill" 
                                                       onclick="return confirm('Are you sure you want to delete this expense?')">
                                                        <i class="las la-trash me-1"></i> Delete
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <h5>No expenses found</h5>
                                        <p>
                                            <?php if ($finance_id > 0): ?>
                                                No expenses recorded for the selected finance.
                                            <?php else: ?>
                                                No expenses have been added yet. Please select a finance first.
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($finance_selected): ?>
                                            <a href="#addExpenseModal" class="btn btn-primary mt-2" data-bs-toggle="modal">
                                                <i class="iconoir-plus me-1"></i> Add Your First Expense
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div><!-- container -->

                <!-- Add Expense Modal -->
                <div class="modal fade" id="addExpenseModal" tabindex="-1" aria-labelledby="addExpenseModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addExpenseModalLabel">Add New Expense</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <?php if ($finance_selected): ?>
                                <form id="expenseForm" action="save-expense.php" method="post" novalidate>
                                    <input type="hidden" name="finance_id" value="<?php echo $finance_id; ?>">
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <!-- Remark (optional) -->
                                            <div class="col-md-12">
                                                <label for="remark" class="form-label fw-bold">Remark <span class="text-muted">(optional)</span></label>
                                                <textarea class="form-control" id="remark" name="remark" rows="2" placeholder="e.g., Office Supplies Purchase for Q4"></textarea>
                                                <div class="invalid-feedback">Remark is too long.</div>
                                            </div>

                                            <!-- Amount (required) -->
                                            <div class="col-md-6">
                                                <label for="amount" class="form-label fw-bold">Amount (₹) <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control" id="amount" name="amount" min="0" step="0.01" placeholder="e.g., 5000.00" required>
                                                <div class="invalid-feedback">Please enter a valid amount greater than 0.</div>
                                            </div>

                                            <!-- Date (required) -->
                                            <div class="col-md-6">
                                                <label for="expense_date" class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" id="expense_date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                <div class="invalid-feedback">Please select a valid date.</div>
                                            </div>

                                            <!-- Category (required) -->
                                            <div class="col-md-12">
                                                <label for="category" class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                                <select class="form-control" id="category" name="category" required>
                                                    <option value="" disabled selected>Select a category</option>
                                                    <option value="Office Supplies">Office Supplies</option>
                                                    <option value="Travel">Travel</option>
                                                    <option value="Salary">Salary</option>
                                                    <option value="Marketing">Marketing</option>
                                                    <option value="Utilities">Utilities</option>
                                                    <option value="Miscellaneous">Miscellaneous</option>
                                                </select>
                                                <div class="invalid-feedback">Please select a category.</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save Expense</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="modal-body">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Please select a finance from the topbar dropdown before adding expenses.
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Modal for Expense Details -->
                <div class="modal fade" id="expenseDetailsModal" tabindex="-1" aria-labelledby="expenseDetailsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="expenseDetailsModalLabel">Expense Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div id="expenseDetailsContent">
                                    <div class="text-center">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading expense details...</p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rightbar -->
                <?php include 'includes/rightbar.php'; ?>
                <!-- Footer -->
                <?php include 'includes/footer.php'; ?>
            </div>
            <!-- end page content -->
        </div>
        <!-- end page-wrapper -->

        <!-- vendor / core scripts -->
        <?php include 'includes/scripts.php'; ?>

        <!-- DataTables -->
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

        <script>
            // Bootstrap client-side validation
            (function () {
                'use strict';
                const form = document.getElementById('expenseForm');
                if (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                }
            })();

            // Initialize DataTable
            $(document).ready(function() {
                $('#tblExpenses').DataTable({
                    pageLength: 10,
                    order: [[3, 'desc']], // Sort by Date descending
                    columnDefs: [
                        { orderable: false, targets: -1 } // Disable sorting on Actions column
                    ],
                    language: {
                        search: "Search expenses:",
                        lengthMenu: "Show _MENU_ expenses",
                        info: "Showing _START_ to _END_ of _TOTAL_ expenses",
                        infoEmpty: "No expenses available",
                        infoFiltered: "(filtered from _MAX_ total expenses)",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    },
                    responsive: true
                });

                // Handle click on expense detail button to fetch details via AJAX
                $('.expense-detail-btn').on('click', function(e) {
                    e.preventDefault();
                    var expenseId = $(this).data('expense-id');
                    
                    $.ajax({
                        url: 'get-expense-details.php',
                        type: 'GET',
                        data: { expense_id: expenseId },
                        dataType: 'html',
                        beforeSend: function() {
                            $('#expenseDetailsContent').html('<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading expense details...</p></div>');
                            console.log('Sending AJAX request for expense ID: ' + expenseId);
                        },
                        success: function(response) {
                            $('#expenseDetailsContent').html(response);
                            // Initialize DataTable for details if table exists
                            if ($('#expenseDetailsTable').length) {
                                $('#expenseDetailsTable').DataTable({
                                    pageLength: 10,
                                    searching: false,
                                    paging: false,
                                    info: false
                                });
                            }
                            console.log('AJAX request successful for expense ID: ' + expenseId);
                        },
                        error: function(xhr, status, error) {
                            $('#expenseDetailsContent').html('<div class="alert alert-danger">Error loading expense details: ' + error + '</div>');
                            console.error('AJAX Error Details:');
                            console.error('Status: ' + status);
                            console.error('Error: ' + error);
                            console.error('Response Text: ' + xhr.responseText);
                            console.error('Status Code: ' + xhr.status);
                            console.error('Status Text: ' + xhr.statusText);
                            console.error('URL: ' + this.url);
                        }
                    });
                });
            });
        </script>

        <style>
            /* Mobile Card Styling */
            @media (max-width: 767.98px) {
                .card {
                    border: none;
                    border-radius: 10px;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
                    margin-bottom: 1rem;
                }
                .card-header {
                    background-color: #ffffff;
                    border-bottom: 1px solid #e5e7eb;
                    padding: 1rem;
                }
                .avatar-xs {
                    width: 32px;
                    height: 32px;
                }
                .btn-group .btn {
                    padding: 0.375rem 0.75rem;
                    font-size: 0.875rem;
                }
                .badge {
                    font-size: 0.75rem;
                    padding: 0.25rem 0.5rem;
                }
                .flex-fill {
                    flex: 1;
                }
            }
            
            /* Desktop Table Styling */
            @media (min-width: 768px) {
                .d-md-block {
                    display: block !important;
                }
                .d-md-none {
                    display: none !important;
                }
            }
            
            /* Category Badge Colors */
            .bg-primary { background-color: #0d6efd !important; }
            .bg-warning { background-color: #ffc107 !important; color: #000 !important; }
            .bg-success { background-color: #198754 !important; }
            .bg-info { background-color: #0dcaf0 !important; color: #000 !important; }
            .bg-secondary { background-color: #6c757d !important; }
            
            .bg-soft-danger {
                background-color: rgba(220, 53, 69, 0.1) !important;
            }
        </style>
</body>
</html>