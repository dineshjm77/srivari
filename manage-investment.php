<?php
// Start session
session_start();

// Database connection
include 'includes/db.php';

// Get selected finance_id from session
$finance_id = isset($_SESSION['finance_id']) ? intval($_SESSION['finance_id']) : 0;

// Fetch all investments (filtered by finance_id if selected)
$sql = "SELECT id, investment_name, amount, investment_date, category, remark, created_at FROM investments";
$params = [];
$types = "";
if ($finance_id > 0) {
    $sql .= " WHERE finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}
$sql .= " ORDER BY investment_date DESC";

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
    error_log("Error fetching investments: " . $conn->error);
    $_SESSION['error'] = "Error fetching investments: " . $conn->error;
    $investments = [];
} else {
    $investments = [];
    $total_investments = 0;
    while ($row = $result->fetch_assoc()) {
        $total_investments += $row['amount'];
        $investments[] = $row;
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
                    $page_title = "Manage Investments";
                    $breadcrumb_active = "Manage Investments";
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
                            <strong>No Finance Selected:</strong> Please select a finance from the topbar dropdown to manage investments. Investments are tied to specific finances.
                            <div class="mt-2">
                                <form action="set-finance-session.php" method="post" id="financeSelectForm" class="d-inline">
                                    <select class="form-select d-inline-block w-auto me-2" name="finance_id" onchange="this.form.submit()">
                                        <option value="0">Select Finance</option>
                                        <option value="1" <?php echo $finance_id == 1 ? 'selected' : ''; ?>>A Finance</option>
                                        <option value="2" <?php echo $finance_id == 2 ? 'selected' : ''; ?>>B Finance</option>
                                        <option value="3" <?php echo $finance_id == 3 ? 'selected' : ''; ?>>finance</option>
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
                            <h3 class="mb-0">Manage Investments</h3>
                            <small class="text-muted">
                                <?php if ($finance_selected): ?>
                                    Filtered for selected finance. Total Investments: <?php echo count($investments); ?> | Total Amount: ₹<?php echo number_format($total_investments, 2); ?>
                                <?php else: ?>
                                    Track and manage all business investments. Total Investments: <?php echo count($investments); ?> | Total Amount: ₹<?php echo number_format($total_investments, 2); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="col-auto">
                            <?php if ($finance_selected): ?>
                                <a href="#addInvestmentModal" class="btn btn-primary" data-bs-toggle="modal">
                                    <i class="iconoir-plus me-1"></i> Add New Investment
                                </a>
                            <?php else: ?>
                                <button class="btn btn-primary disabled" disabled>
                                    <i class="iconoir-plus me-1"></i> Add New Investment (Select Finance First)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Investments Summary Card -->
                    <?php if (!empty($investments) && $finance_selected): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="card-title mb-0">Investment Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="fw-bold text-success fs-4">₹<?php echo number_format($total_investments, 2); ?></div>
                                        <small class="text-muted">Total Investments</small>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold text-primary fs-4"><?php echo count($investments); ?></div>
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

                    <!-- Investments Cards for Mobile -->
                    <div class="d-block d-lg-none">
                        <div class="row">
                            <?php if (!empty($investments)): ?>
                                <?php foreach ($investments as $row): ?>
                                    <?php 
                                    $category_badges = [
                                        'Mutual Funds' => 'bg-primary',
                                        'Stocks' => 'bg-success',
                                        'Fixed Deposits' => 'bg-info',
                                        'Real Estate' => 'bg-warning',
                                        'Bonds' => 'bg-secondary',
                                        'Fixed Deposit' => 'bg-info',
                                        'Mutual Fund' => 'bg-primary',
                                        'Gold' => 'bg-warning',
                                        'Other' => 'bg-secondary'
                                    ];
                                    $category_class = $category_badges[$row['category']] ?? 'bg-secondary';
                                    ?>
                                    <div class="col-12 mb-3">
                                        <div class="card investment-card">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <span class="badge bg-success">INV-<?php echo $row['id']; ?></span>
                                                        <span class="badge <?php echo $category_class; ?> ms-1">
                                                            <?php echo htmlspecialchars($row['category']); ?>
                                                        </span>
                                                    </div>
                                                    <span class="fw-bold text-success">₹<?php echo number_format($row['amount'], 2); ?></span>
                                                </div>
                                                
                                                <div class="d-flex align-items-center mb-2">
                                                    <div class="flex-shrink-0 me-2">
                                                        <div class="avatar-xs">
                                                            <div class="avatar-title bg-soft-success text-success rounded-circle">
                                                                <?php echo strtoupper(substr($row['investment_name'], 0, 1)); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="fw-medium"><?php echo htmlspecialchars($row['investment_name']); ?></div>
                                                        <?php if ($row['remark']): ?>
                                                            <small class="text-muted"><?php echo htmlspecialchars($row['remark']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="d-flex justify-content-between text-muted small mb-2">
                                                    <div>
                                                        <i class="fas fa-calendar me-1"></i>
                                                        <?php 
                                                        $investment_date = new DateTime($row['investment_date']);
                                                        echo $investment_date->format('d M Y');
                                                        ?>
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?php 
                                                        $created_date = new DateTime($row['created_at']);
                                                        echo $created_date->format('d M Y H:i');
                                                        ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="d-flex justify-content-end">
                                                    <div class="btn-group">
                                                        <!-- View Button -->
                                                        <button type="button" class="btn btn-sm btn-outline-info investment-detail-btn" 
                                                                data-bs-toggle="modal" data-bs-target="#investmentDetailsModal" 
                                                                data-investment-id="<?php echo $row['id']; ?>"
                                                                title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        
                                                        <!-- Edit Button -->
                                                        <a href="edit-investment.php?id=<?php echo $row['id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary" 
                                                           title="Edit Investment">
                                                            <i class="las la-edit"></i>
                                                        </a>  
                                                        
                                                        <!-- Delete Button -->
                                                        <a href="delete-investment.php?id=<?php echo $row['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger delete-investment" 
                                                           title="Delete Investment"
                                                           onclick="return confirm('Are you sure you want to delete investment: <?php echo addslashes($row['investment_name']); ?>?')">
                                                            <i class="las la-trash"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="card">
                                        <div class="card-body text-center py-4">
                                            <div class="text-muted">
                                                <i class="fas fa-chart-line fa-2x mb-2"></i>
                                                <h5>No investments found</h5>
                                                <p>
                                                    <?php if ($finance_id > 0): ?>
                                                        No investments recorded for the selected finance.
                                                    <?php else: ?>
                                                        No investments have been added yet. Please select a finance first.
                                                    <?php endif; ?>
                                                </p>
                                                <?php if ($finance_selected): ?>
                                                    <a href="#addInvestmentModal" class="btn btn-primary" data-bs-toggle="modal">
                                                        <i class="iconoir-plus me-1"></i> Add Your First Investment
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Investments Table for Desktop -->
                    <div class="d-none d-lg-block">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Investment List</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="tblInvestments" class="table table-striped table-bordered align-middle w-100">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#Investment ID</th>
                                                <th>Investment Name</th>
                                                <th>Amount (₹)</th>
                                                <th>Investment Date</th>
                                                <th>Category</th>
                                                <th>Created At</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($investments)): ?>
                                                <?php foreach ($investments as $row): ?>
                                                    <?php 
                                                    $category_badges = [
                                                        'Mutual Funds' => 'bg-primary',
                                                        'Stocks' => 'bg-success',
                                                        'Fixed Deposits' => 'bg-info',
                                                        'Real Estate' => 'bg-warning',
                                                        'Bonds' => 'bg-secondary',
                                                        'Fixed Deposit' => 'bg-info',
                                                        'Mutual Fund' => 'bg-primary',
                                                        'Gold' => 'bg-warning',
                                                        'Other' => 'bg-secondary'
                                                    ];
                                                    $category_class = $category_badges[$row['category']] ?? 'bg-secondary';
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-success">INV-<?php echo $row['id']; ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="flex-shrink-0 me-2">
                                                                    <div class="avatar-xs">
                                                                        <div class="avatar-title bg-soft-success text-success rounded-circle">
                                                                            <?php echo strtoupper(substr($row['investment_name'], 0, 1)); ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <div class="fw-medium"><?php echo htmlspecialchars($row['investment_name']); ?></div>
                                                                    <?php if ($row['remark']): ?>
                                                                        <small class="text-muted"><?php echo htmlspecialchars($row['remark']); ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="fw-bold text-success">₹<?php echo number_format($row['amount'], 2); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $investment_date = new DateTime($row['investment_date']);
                                                            echo $investment_date->format('d M Y');
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
                                                                <!-- View Button -->
                                                                <button type="button" class="btn btn-sm btn-outline-info investment-detail-btn" 
                                                                        data-bs-toggle="modal" data-bs-target="#investmentDetailsModal" 
                                                                        data-investment-id="<?php echo $row['id']; ?>"
                                                                        title="View Details">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                
                                                                <!-- Edit Button -->
                                                                <a href="edit-investment.php?id=<?php echo $row['id']; ?>" 
                                                                   class="btn btn-sm btn-outline-primary" 
                                                                   title="Edit Investment">
                                                                    <i class="las la-edit"></i>
                                                                </a>  
                                                                
                                                                <!-- Delete Button -->
                                                                <a href="delete-investment.php?id=<?php echo $row['id']; ?>" 
                                                                   class="btn btn-sm btn-outline-danger delete-investment" 
                                                                   title="Delete Investment"
                                                                   onclick="return confirm('Are you sure you want to delete investment: <?php echo addslashes($row['investment_name']); ?>?')">
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
                                                            <i class="fas fa-chart-line fa-2x mb-2"></i>
                                                            <h5>No investments found</h5>
                                                            <p>
                                                                <?php if ($finance_id > 0): ?>
                                                                    No investments recorded for the selected finance.
                                                                <?php else: ?>
                                                                    No investments have been added yet. Please select a finance first.
                                                                <?php endif; ?>
                                                            </p>
                                                            <?php if ($finance_selected): ?>
                                                                <a href="#addInvestmentModal" class="btn btn-primary" data-bs-toggle="modal">
                                                                    <i class="iconoir-plus me-1"></i> Add Your First Investment
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
                    </div>
                </div><!-- container -->

                <!-- Add Investment Modal -->
                <div class="modal fade" id="addInvestmentModal" tabindex="-1" aria-labelledby="addInvestmentModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addInvestmentModalLabel">Add New Investment</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <?php if ($finance_selected): ?>
                                <form id="investmentForm" action="save-investment.php" method="post" novalidate>
                                    <input type="hidden" name="finance_id" value="<?php echo $finance_id; ?>">
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <!-- Investment Name (required) -->
                                            <div class="col-md-12">
                                                <label for="investment_name" class="form-label fw-bold">Investment Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="investment_name" name="investment_name" placeholder="e.g., HDFC Mutual Fund SIP" required>
                                                <div class="invalid-feedback">Investment name is required.</div>
                                            </div>

                                            <!-- Amount (required) -->
                                            <div class="col-md-6">
                                                <label for="amount" class="form-label fw-bold">Amount (₹) <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control" id="amount" name="amount" min="0" step="0.01" placeholder="e.g., 100000.00" required>
                                                <div class="invalid-feedback">Please enter a valid amount greater than 0.</div>
                                            </div>

                                            <!-- Investment Date (required) -->
                                            <div class="col-md-6">
                                                <label for="investment_date" class="form-label fw-bold">Investment Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" id="investment_date" name="investment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                <div class="invalid-feedback">Please select a valid date.</div>
                                            </div>

                                            <!-- Category (required) -->
                                            <div class="col-md-6">
                                                <label for="category" class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                                <select class="form-control" id="category" name="category" required>
                                                    <option value="" disabled selected>Select a category</option>
                                                    <option value="Fixed Deposit">Fixed Deposit</option>
                                                    <option value="Mutual Fund">Mutual Fund</option>
                                                    <option value="Stocks">Stocks</option>
                                                    <option value="Real Estate">Real Estate</option>
                                                    <option value="Gold">Gold</option>
                                                    <option value="Bonds">Bonds</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                                <div class="invalid-feedback">Please select a category.</div>
                                            </div>

                                            <!-- Remark (optional) -->
                                            <div class="col-md-6">
                                                <label for="remark" class="form-label fw-bold">Remark <span class="text-muted">(optional)</span></label>
                                                <textarea class="form-control" id="remark" name="remark" rows="2" placeholder="e.g., Long-term growth plan, 5-year horizon"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save Investment</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="modal-body">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Please select a finance from the topbar dropdown before adding investments.
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Modal for Investment Details -->
                <div class="modal fade" id="investmentDetailsModal" tabindex="-1" aria-labelledby="investmentDetailsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="investmentDetailsModalLabel">Investment Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div id="investmentDetailsContent">
                                    <div class="text-center">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading investment details...</p>
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

        <style>
            .investment-card {
                transition: transform 0.2s;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
            }
            .investment-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .card {
                border: none;
                border-radius: 10px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
                margin-bottom: 1.5rem;
            }
            .card-header {
                background-color: #ffffff;
                border-bottom: 1px solid #e5e7eb;
                padding: 1rem;
                border-radius: 10px 10px 0 0;
            }
            .btn-group .btn {
                margin-right: 0.25rem;
            }
            .btn-group .btn:last-child {
                margin-right: 0;
            }
            .avatar-title {
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 600;
                font-size: 14px;
            }
            @media (max-width: 767px) {
                .btn-group {
                    width: 100%;
                }
                .btn-group .btn {
                    flex: 1;
                    margin-bottom: 0.5rem;
                }
                .card-body {
                    padding: 1rem;
                }
            }
            @media (max-width: 575px) {
                .btn-group .btn {
                    padding: 0.375rem 0.5rem;
                    font-size: 0.875rem;
                }
            }
        </style>

        <script>
            // Bootstrap client-side validation
            (function () {
                'use strict';
                const form = document.getElementById('investmentForm');
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
                $('#tblInvestments').DataTable({
                    pageLength: 10,
                    order: [[3, 'desc']], // Sort by Investment Date descending
                    columnDefs: [
                        { orderable: false, targets: -1 } // Disable sorting on Actions column
                    ],
                    language: {
                        search: "Search investments:",
                        lengthMenu: "Show _MENU_ investments",
                        info: "Showing _START_ to _END_ of _TOTAL_ investments",
                        infoEmpty: "No investments available",
                        infoFiltered: "(filtered from _MAX_ total investments)",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    },
                    responsive: true
                });

                // Enhanced delete confirmation for both table and cards
                const deleteLinks = document.querySelectorAll('.delete-investment');
                deleteLinks.forEach(link => {
                    link.addEventListener('click', function(e) {
                        let investmentName;
                        // Check if we're in table view or card view
                        const tableRow = this.closest('tr');
                        if (tableRow) {
                            investmentName = tableRow.querySelector('td:nth-child(2) .fw-medium').textContent;
                        } else {
                            const card = this.closest('.investment-card');
                            investmentName = card.querySelector('.fw-medium').textContent;
                        }
                        
                        if (!confirm(`Are you sure you want to delete the investment "${investmentName}"? This action cannot be undone.`)) {
                            e.preventDefault();
                        }
                    });
                });

                // Handle click on investment detail button to fetch details via AJAX (for both table and cards)
                $('.investment-detail-btn').on('click', function(e) {
                    e.preventDefault();
                    var investmentId = $(this).data('investment-id');
                    
                    $.ajax({
                        url: 'get-investment-details.php',
                        type: 'GET',
                        data: { investment_id: investmentId },
                        dataType: 'html',
                        beforeSend: function() {
                            $('#investmentDetailsContent').html('<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading investment details...</p></div>');
                        },
                        success: function(response) {
                            $('#investmentDetailsContent').html(response);
                        },
                        error: function(xhr, status, error) {
                            $('#investmentDetailsContent').html('<div class="alert alert-danger">Error loading investment details: ' + error + '</div>');
                        }
                    });
                });

                // Auto-format amount input
                const amountInput = document.getElementById('amount');
                if (amountInput) {
                    amountInput.addEventListener('blur', function() {
                        const value = parseFloat(this.value);
                        if (!isNaN(value) && value >= 0) {
                            this.value = value.toFixed(2);
                        }
                    });
                }

                // Set max date to today for investment date
                const investmentDateInput = document.getElementById('investment_date');
                if (investmentDateInput) {
                    const today = new Date().toISOString().split('T')[0];
                    investmentDateInput.max = today;
                }
            });
        </script>
</body>
</html>