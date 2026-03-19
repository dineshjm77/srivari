<?php
// Enable error logging instead of displaying errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u329947844/domains/hifi11.in/public_html/finance/error_log');
// Start session for flash messages and finance_id
session_start();

// Include database connection
include 'includes/db.php';

// Get the currently selected finance_id from session (if set)
$current_finance_id = isset($_SESSION['finance_id']) ? intval($_SESSION['finance_id']) : 0;

// Build the SQL query to fetch loan data with finance name
$sql = "SELECT l.*, f.finance_name 
        FROM loans l 
        LEFT JOIN finance f ON l.finance_id = f.id";
if ($current_finance_id > 0) {
    $sql .= " WHERE l.finance_id = $current_finance_id";
}
$sql .= " ORDER BY l.created_at DESC";

$result = mysqli_query($conn, $sql);

// Check for errors in the query
if (!$result) {
    $_SESSION['error'] = "Error fetching data: " . mysqli_error($conn);
    mysqli_close($conn);
    header("Location: manage-loans.php");
    exit;
}

// Store results in an array
$loans = [];
while ($row = mysqli_fetch_assoc($result)) {
    $loans[] = $row;
}
mysqli_free_result($result);

// Close the database connection
mysqli_close($conn);
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
                    $page_title = "Manage Loans";
                    $breadcrumb_active = "Manage Loans";
                    ?>
                    <div class="row">
                        <div class="col-sm-12">
                            <div class="page-title-box d-md-flex justify-content-md-between align-items-center">
                                <h4 class="page-title"><?php echo $page_title; ?></h4>
                                <div class="">
                                    <ol class="breadcrumb mb-0">
                                        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                        <li class="breadcrumb-item active"><?php echo $breadcrumb_active; ?></li>
                                    </ol>
                                </div>                            
                            </div><!--end page-title-box-->
                        </div><!--end col-->
                    </div><!--end row-->
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

                    <div class="row align-items-center mb-3">
                        <div class="col">
                            <h3 class="mb-0">Manage Loans</h3>
                            <small class="text-muted">Manage and update loan details with ease.</small>
                        </div>
                        <div class="col-auto d-flex gap-2">
                            <a href="add-loan.php" class="btn btn-primary">
                                <i class="iconoir-plus me-1"></i> Add New Loan
                            </a>
                        </div>
                    </div>

                    <!-- Summary Cards for Mobile -->
                    <div class="d-block d-lg-none mb-4">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <div class="card bg-primary text-white">
                                    <div class="card-body p-3 text-center">
                                        <h5 class="mb-0"><?php echo count($loans); ?></h5>
                                        <small>Total Loans</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body p-3 text-center">
                                        <h5 class="mb-0">₹<?php echo number_format(array_sum(array_column($loans, 'loan_amount')), 2); ?></h5>
                                        <small>Total Amount</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Loans Cards for Mobile -->
                    <div class="d-block d-lg-none">
                        <?php if (empty($loans)): ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Loans Found</h5>
                                    <p class="text-muted mb-4">
                                        <?php echo $current_finance_id > 0 ? "No loans found for the selected finance." : "No loans have been added yet."; ?>
                                    </p>
                                    <a href="add-loan.php" class="btn btn-primary">
                                        <i class="iconoir-plus me-1"></i> Add Your First Loan
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($loans as $row): ?>
                                    <div class="col-12 mb-3">
                                        <div class="card loan-card">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <span class="badge bg-primary">LN-<?php echo $row['id']; ?></span>
                                                        <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($row['finance_name'] ?? 'N/A'); ?></span>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="fw-bold text-success">₹<?php echo number_format($row['loan_amount'], 2); ?></div>
                                                        <small class="text-muted"><?php echo $row['interest_rate']; ?>% for <?php echo $row['loan_tenure']; ?> months</small>
                                                    </div>
                                                </div>
                                                
                                                <h6 class="card-title mb-2"><?php echo htmlspecialchars($row['loan_name']); ?></h6>
                                                
                                                <?php if (!empty($row['loan_purpose']) && $row['loan_purpose'] !== 'N/A'): ?>
                                                    <p class="card-text small text-muted mb-2">
                                                        <i class="fas fa-bullseye me-1"></i>
                                                        <?php echo htmlspecialchars($row['loan_purpose']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                
                                                <div class="mb-3">
                                                    <small class="text-muted d-block">Documents Required:</small>
                                                    <div class="small"><?php echo htmlspecialchars($row['loan_documents']); ?></div>
                                                </div>
                                                
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="text-muted">
                                                        Created: <?php echo date('M j, Y', strtotime($row['created_at'])); ?>
                                                    </small>
                                                    <div class="btn-group">
                                                        <a href="edit-loan.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="las la-edit me-1"></i> Edit
                                                        </a>
                                                        <a href="delete-loan.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this loan? This action cannot be undone.')">
                                                            <i class="las la-trash me-1"></i> Delete
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- DataTable for Desktop -->
                    <div class="d-none d-lg-block">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">Loan List</h4>
                                <small class="text-muted">Showing <?php echo count($loans); ?> loans</small>
                            </div>
                            <div class="card-body">
                                <?php if (empty($loans)): ?>
                                    <div class="alert alert-info" role="alert">
                                        No loans found<?php echo $current_finance_id > 0 ? " for the selected finance." : "."; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table id="tblLoans" class="table table-striped table-bordered align-middle w-100">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>#ID</th>
                                                    <th>Finance Name</th>
                                                    <th>Loan Name</th>
                                                    <th>Loan Amount</th>
                                                    <th>Interest Rate (%)</th>
                                                    <th>Tenure (Months)</th>
                                                    <th>Documents Required</th>
                                                    <th>Loan Purpose</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- Display loan data from the array -->
                                                <?php foreach ($loans as $row): ?>
                                                    <tr data-json='{
                                                        "id": <?php echo $row['id']; ?>,
                                                        "finance_id": <?php echo $row['finance_id']; ?>,
                                                        "finance_name": "<?php echo htmlspecialchars($row['finance_name'] ?? ''); ?>",
                                                        "loan_name": "<?php echo htmlspecialchars($row['loan_name']); ?>",
                                                        "loan_amount": "<?php echo $row['loan_amount']; ?>",
                                                        "interest_rate": "<?php echo $row['interest_rate']; ?>",
                                                        "loan_tenure": <?php echo $row['loan_tenure']; ?>,
                                                        "loan_documents": "<?php echo htmlspecialchars($row['loan_documents']); ?>",
                                                        "loan_purpose": "<?php echo htmlspecialchars($row['loan_purpose'] ?? ''); ?>"
                                                    }'>
                                                        <td><?php echo "LN-" . $row['id']; ?></td>
                                                        <td><?php echo htmlspecialchars($row['finance_name'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['loan_name']); ?></td>
                                                        <td>₹<?php echo number_format($row['loan_amount'], 2); ?></td>
                                                        <td><?php echo $row['interest_rate']; ?>%</td>
                                                        <td><?php echo $row['loan_tenure']; ?> Months</td>
                                                        <td><?php echo htmlspecialchars($row['loan_documents']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['loan_purpose'] ?? 'N/A'); ?></td>
                                                        <td class="text-end">
                                                            <div class="btn-group">
                                                                <a href="edit-loan.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                                    <i class="las la-edit"></i>
                                                                </a>
                                                                <a href="delete-loan.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this loan? This action cannot be undone.')">
                                                                    <i class="las la-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div><!-- container -->

                <?php include 'includes/rightbar.php'; ?>
                <?php include 'includes/footer.php'; ?>
            </div>
            <!-- end page content -->
        </div>
        <!-- end page-wrapper -->

        <?php include 'includes/scripts.php'; ?>

        <!-- DataTables (remove if already included globally) -->
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

        <style>
            .loan-card {
                transition: transform 0.2s;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
            }
            .loan-card:hover {
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
                .page-title-box {
                    flex-direction: column;
                    align-items: flex-start !important;
                }
                .page-title-box .breadcrumb {
                    margin-top: 0.5rem;
                }
            }
            @media (max-width: 575px) {
                .btn-group .btn {
                    padding: 0.375rem 0.5rem;
                    font-size: 0.875rem;
                }
                .row.align-items-center.mb-3 {
                    flex-direction: column;
                    align-items: flex-start !important;
                }
                .row.align-items-center.mb-3 .col-auto {
                    margin-top: 1rem;
                    width: 100%;
                }
                .row.align-items-center.mb-3 .col-auto .btn {
                    width: 100%;
                    justify-content: center;
                }
            }
            @media (max-width: 480px) {
                .card-body {
                    padding: 0.75rem;
                }
                .loan-card .card-body {
                    padding: 1rem;
                }
                .badge {
                    font-size: 0.7em;
                }
            }
        </style>

        <script>
            // ===== DataTable =====
            $(document).ready(function() {
                $('#tblLoans').DataTable({
                    pageLength: 10,
                    order: [[0, 'desc']], // Sort by ID (most recent first)
                    language: {
                        search: "Search loans:",
                        lengthMenu: "Show _MENU_ loans",
                        info: "Showing _START_ to _END_ of _TOTAL_ loans",
                        infoEmpty: "No loans available",
                        infoFiltered: "(filtered from _MAX_ total loans)",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    },
                    responsive: true
                });
            });

            // Add click handler for mobile cards to show more details if needed
            document.addEventListener('DOMContentLoaded', function() {
                const loanCards = document.querySelectorAll('.loan-card');
                loanCards.forEach(card => {
                    card.addEventListener('click', function(e) {
                        // Prevent navigation if clicking on edit button
                        if (e.target.closest('a')) {
                            return;
                        }
                        // You can add expand/collapse functionality here if needed
                    });
                });
            });
        </script>
</body>
</html>