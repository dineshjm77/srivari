<?php
// chit-plans.php - CHIT FUND SYSTEM


// Include database
include 'includes/db.php';

// Handle plan deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Check if plan has active groups
    $check_sql = "SELECT COUNT(*) as count FROM chit_groups WHERE chit_plan_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $group_count = $check_result->fetch_assoc()['count'];
    $check_stmt->close();
    
    if ($group_count > 0) {
        $_SESSION['error'] = "Cannot delete plan with active chit groups!";
        header("Location: chit-plans.php");
        exit;
    }
    
    // Delete plan
    $delete_sql = "DELETE FROM chit_plans WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "Chit plan deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting chit plan!";
    }
    $delete_stmt->close();
    header("Location: chit-plans.php");
    exit;
}

// Toggle plan status
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $toggle_id = intval($_GET['toggle']);
    
    $sql = "UPDATE chit_plans SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $toggle_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: chit-plans.php");
    exit;
}

// Get all chit plans with monthly details count
$sql = "SELECT cp.*, 
               COUNT(pmd.id) as monthly_details_count,
               COUNT(DISTINCT cg.id) as active_groups
        FROM chit_plans cp
        LEFT JOIN plan_monthly_details pmd ON cp.id = pmd.chit_plan_id
        LEFT JOIN chit_groups cg ON cp.id = cg.chit_plan_id AND cg.status IN ('forming', 'active')
        GROUP BY cp.id
        ORDER BY cp.chit_amount ASC, cp.duration_months ASC";

$result = $conn->query($sql);
$plans = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <title>Chit Plans - SRI VARI CHITS PRIVATE LTD</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta content="Chit Fund Management System" name="description" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />

    <!-- App favicon -->
    <link rel="shortcut icon" href="assets/images/logo1.png">

    <!-- App css -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        .plan-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .plan-card.inactive {
            opacity: 0.7;
            background-color: #f8f9fa;
        }
        .plan-amount {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        .plan-duration {
            color: #6c757d;
            font-size: 14px;
        }
        .plan-features {
            list-style: none;
            padding-left: 0;
            margin-bottom: 0;
        }
        .plan-features li {
            padding: 5px 0;
            border-bottom: 1px solid #f8f9fa;
        }
        .plan-features li:last-child {
            border-bottom: none;
        }
        .plan-features i {
            width: 20px;
            color: #28a745;
        }
        .badge-active { background-color: #d1fae5; color: #065f46; }
        .badge-inactive { background-color: #fee2e2; color: #991b1b; }
        .plan-header {
            border-radius: 10px 10px 0 0;
            padding: 20px;
            color: white;
            font-weight: 600;
        }
        .bg-1l { background: linear-gradient(135deg, #667eea, #764ba2); }
        .bg-2l { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .bg-3l { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .bg-5l { background: linear-gradient(135deg, #43e97b, #38f9d7); }
        .bg-10l { background: linear-gradient(135deg, #fa709a, #fee140); }
        .bg-29m { background: linear-gradient(135deg, #30cfd0, #330867); }
        .bg-cr { background: linear-gradient(135deg, #ff0844, #ffb199); }
    </style>
</head>
<body>
    <!-- Top Bar -->
    <?php include 'includes/topbar.php'; ?>
    
    <!-- Startbar with Leftbar -->
    <div class="startbar d-print-none">
        <?php include 'includes/leftbar.php'; ?>
        <div class="startbar-overlay d-print-none"></div>

        <div class="page-wrapper">
            <!-- Page Content-->
            <div class="page-content">
                <div class="container-fluid">
                    <!-- Breadcrumb -->
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                                <h4 class="mb-sm-0">Chit Plans</h4>
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                                        <li class="breadcrumb-item active">Chit Plans</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Flash Messages -->
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['error']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <!-- Page Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <h5 class="card-title mb-0">Available Chit Plans</h5>
                                            <p class="text-muted mb-0">Manage and configure chit fund schemes</p>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <a href="add-chit-plan.php" class="btn btn-primary">
                                                <i class="fas fa-plus-circle me-2"></i> Add New Plan
                                            </a>
                                            <a href="chit-groups.php" class="btn btn-success ms-2">
                                                <i class="fas fa-layer-group me-2"></i> Manage Groups
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Plans Grid -->
                    <div class="row">
                        <?php foreach ($plans as $plan): 
                            $plan_class = '';
                            $amount = $plan['chit_amount'];
                            if ($amount <= 100000) $plan_class = 'bg-1l';
                            elseif ($amount <= 200000) $plan_class = 'bg-2l';
                            elseif ($amount <= 300000) $plan_class = 'bg-3l';
                            elseif ($amount <= 500000) $plan_class = 'bg-5l';
                            elseif ($amount <= 1000000) $plan_class = 'bg-10l';
                            elseif ($plan['duration_months'] == 29) $plan_class = 'bg-29m';
                            elseif ($amount >= 10000000) $plan_class = 'bg-cr';
                        ?>
                            <div class="col-xl-4 col-lg-6 col-md-6">
                                <div class="card plan-card <?php echo $plan['status'] == 'inactive' ? 'inactive' : ''; ?>">
                                    <div class="plan-header <?php echo $plan_class; ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-1"><?php echo htmlspecialchars($plan['plan_name']); ?></h5>
                                                <span class="badge <?php echo $plan['status'] == 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                                    <?php echo ucfirst($plan['status']); ?>
                                                </span>
                                            </div>
                                            <div class="text-end">
                                                <span class="plan-amount">
                                                    ₹<?php echo number_format($plan['chit_amount']); ?>
                                                </span>
                                                <div class="plan-duration">
                                                    <?php echo $plan['duration_months']; ?> Months
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card-body">
                                        <ul class="plan-features">
                                            <li>
                                                <i class="fas fa-rupee-sign me-2"></i>
                                                <strong>Monthly:</strong> ₹<?php echo number_format($plan['monthly_contribution']); ?>
                                            </li>
                                            <li>
                                                <i class="fas fa-hand-holding-usd me-2"></i>
                                                <strong>Receivable:</strong> ₹<?php echo number_format($plan['receivable_amount']); ?>
                                            </li>
                                            <li>
                                                <i class="fas fa-calculator me-2"></i>
                                                <strong>Total Contribution:</strong> ₹<?php echo number_format($plan['total_contribution']); ?>
                                            </li>
                                            <li>
                                                <i class="fas fa-users me-2"></i>
                                                <strong>Max Members:</strong> <?php echo $plan['max_members']; ?>
                                            </li>
                                            <li>
                                                <i class="fas fa-percentage me-2"></i>
                                                <strong>Commission:</strong> <?php echo $plan['commission_percentage']; ?>%
                                            </li>
                                        </ul>
                                        
                                        <div class="row mt-3">
                                            <div class="col-6">
                                                <div class="text-center">
                                                    <div class="text-muted small">Monthly Details</div>
                                                    <div class="fw-bold"><?php echo $plan['monthly_details_count']; ?></div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-center">
                                                    <div class="text-muted small">Active Groups</div>
                                                    <div class="fw-bold"><?php echo $plan['active_groups']; ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4 d-flex justify-content-between">
                                            <a href="view-plan.php?id=<?php echo $plan['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye me-1"></i> View
                                            </a>
                                            <a href="edit-chit-plan.php?id=<?php echo $plan['id']; ?>" class="btn btn-sm btn-outline-warning">
                                                <i class="fas fa-edit me-1"></i> Edit
                                            </a>
                                            <a href="chit-plans.php?toggle=<?php echo $plan['id']; ?>" 
                                               class="btn btn-sm btn-outline-<?php echo $plan['status'] == 'active' ? 'warning' : 'success'; ?>">
                                                <i class="fas fa-power-off me-1"></i>
                                                <?php echo $plan['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                            </a>
                                            <a href="chit-plans.php?delete=<?php echo $plan['id']; ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Are you sure you want to delete this plan?')">
                                                <i class="fas fa-trash me-1"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Summary Stats -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <h3 class="mb-1"><?php echo count($plans); ?></h3>
                                            <p class="text-muted mb-0">Total Plans</p>
                                        </div>
                                        <div class="col-md-3">
                                            <?php
                                            $active_plans = array_filter($plans, function($p) {
                                                return $p['status'] == 'active';
                                            });
                                            ?>
                                            <h3 class="mb-1"><?php echo count($active_plans); ?></h3>
                                            <p class="text-muted mb-0">Active Plans</p>
                                        </div>
                                        <div class="col-md-3">
                                            <?php
                                            $total_groups = array_sum(array_column($plans, 'active_groups'));
                                            ?>
                                            <h3 class="mb-1"><?php echo $total_groups; ?></h3>
                                            <p class="text-muted mb-0">Active Groups</p>
                                        </div>
                                        <div class="col-md-3">
                                            <?php
                                            $total_value = array_sum(array_column($plans, 'chit_amount'));
                                            ?>
                                            <h3 class="mb-1">₹<?php echo number_format($total_value); ?></h3>
                                            <p class="text-muted mb-0">Total Value</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- container -->

                <!-- Footer -->
                <footer class="footer text-center text-sm-start d-print-none">
                    <div class="container-fluid">
                        <div class="row">
                            <div class="col-12">
                                <div class="card mb-0 rounded-bottom-0">
                                    <div class="card-body">
                                        <p class="text-muted mb-0">
                                            ©
                                            <script>document.write(new Date().getFullYear())</script>
                                            Sri Vari Chits Private Ltd 
                                            <span class="text-muted d-none d-sm-inline-block float-end">
                                                Designed by Ecommer.in
                                                <i class="fas fa-heart text-danger align-middle"></i>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
            <!-- end page content -->
        </div>
        <!-- end page-wrapper -->

        <!-- Scripts -->
        <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="assets/libs/simplebar/simplebar.min.js"></script>
        <script src="assets/js/app.js"></script>

        <script>
            // Filter plans by type
            function filterPlans(type) {
                const cards = document.querySelectorAll('.plan-card');
                cards.forEach(card => {
                    const planName = card.querySelector('h5').textContent.toLowerCase();
                    let show = true;
                    
                    if (type === '10m') {
                        show = planName.includes('10 months');
                    } else if (type === '29m') {
                        show = planName.includes('29 months');
                    } else if (type === 'active') {
                        show = !card.classList.contains('inactive');
                    } else if (type === 'inactive') {
                        show = card.classList.contains('inactive');
                    }
                    
                    card.style.display = show ? 'block' : 'none';
                });
            }
            
            // Reset filters
            function resetFilters() {
                document.querySelectorAll('.plan-card').forEach(card => {
                    card.style.display = 'block';
                });
            }
        </script>
    </body>
</html>