<?php
// staff/emi-schedule-member.php - LIMITED VIEW FOR STAFF
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check login - Only staff can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit;
}

include '../includes/db.php';

$member_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($member_id == 0) {
    header("Location: manage-members.php");
    exit;
}

// Fetch member details
$sql_member = "SELECT m.*, p.title AS plan_title
               FROM members m
               JOIN plans p ON m.plan_id = p.id
               WHERE m.id = ?";
$stmt = $conn->prepare($sql_member);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member_result = $stmt->get_result();
$member = $member_result->fetch_assoc();
$stmt->close();

if (!$member) {
    header("Location: manage-members.php");
    exit;
}

// Fetch EMI schedule
$sql_emi = "SELECT es.* FROM emi_schedule es
            WHERE es.member_id = ?
            ORDER BY es.emi_due_date ASC";
$stmt = $conn->prepare($sql_emi);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$emi_result = $stmt->get_result();
$emis = [];
while ($row = $emi_result->fetch_assoc()) {
    $emis[] = $row;
}
$stmt->close();

// Safe date formatting
function formatDate($date_string) {
    if (empty($date_string) || $date_string == '0000-00-00') {
        return 'N/A';
    }
    try {
        return date('d-m-Y', strtotime($date_string));
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMI Schedule - SRI VARI CHITS</title>
    
    <!-- Favicon -->
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    
    <!-- Icons CSS -->
    <link rel="stylesheet" href="../assets/css/icons.min.css">
    
    <!-- App CSS -->
    <link rel="stylesheet" href="../assets/css/app.min.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Top Bar -->
    <div class="topbar d-print-none">
        <nav class="navbar navbar-expand-lg">
            <div class="container-fluid">
                <a class="navbar-brand" href="index.php">
                    <span class="logo-lg">
                        <h4 class="mb-0 text-white">SRI VARI CHITS</h4>
                        <small class="text-white-50">Staff Dashboard</small>
                    </span>
                </a>

                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item" href="index.php">
                                <i class="fas fa-home me-2"></i>Dashboard
                            </a>
                            <a class="dropdown-item" href="manage-members.php">
                                <i class="fas fa-users me-2"></i>View Members
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="../logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Header -->
                <div class="row align-items-center mb-3">
                    <div class="col">
                        <h3 class="mb-0">EMI Schedule - <?= htmlspecialchars($member['customer_name']); ?></h3>
                        <small class="text-muted">
                            Agreement: <?= htmlspecialchars($member['agreement_number']); ?> | 
                            Plan: <?= htmlspecialchars($member['plan_title']); ?>
                        </small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-members.php" class="btn btn-light">
                            <i class="fas fa-arrow-left me-1"></i> Back to Members
                        </a>
                    </div>
                </div>

                <!-- EMI Schedule Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Payment Schedule</h4>
                            <span class="badge bg-primary">
                                Total EMIs: <?= count($emis); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Installment (₹)</th>
                                        <th>Due Date</th>
                                        <th>Paid Date</th>
                                        <th>Bill Number</th>
                                        <th>Payment Type</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($emis)): $sr = 1; ?>
                                        <?php foreach ($emis as $emi):
                                            $is_overdue = (!$emi['paid_date'] && strtotime($emi['emi_due_date']) < time());
                                        ?>
                                            <tr class="<?= $is_overdue ? 'table-danger' : ''; ?>">
                                                <td><?= $sr; ?></td>
                                                <td>
                                                    <strong>₹<?= number_format($emi['emi_amount'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <?= formatDate($emi['emi_due_date']); ?>
                                                    <?php if ($is_overdue): ?>
                                                        <br><small class="text-danger">Overdue</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($emi['paid_date'])): ?>
                                                        <span class="text-success">
                                                            <?= formatDate($emi['paid_date']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($emi['emi_bill_number'])): ?>
                                                        <span class="badge bg-light text-dark">
                                                            <?= htmlspecialchars($emi['emi_bill_number']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($emi['payment_type'] == 'cash'): ?>
                                                        <span class="badge bg-success">Cash</span>
                                                    <?php elseif ($emi['payment_type'] == 'upi'): ?>
                                                        <span class="badge bg-primary">UPI</span>
                                                    <?php elseif ($emi['payment_type'] == 'both'): ?>
                                                        <span class="badge bg-warning">Both</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Not Set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($emi['status'] === 'paid'): ?>
                                                        <span class="badge bg-success">Paid</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-<?= $is_overdue ? 'danger' : 'warning'; ?>">
                                                            <?= $is_overdue ? 'Overdue' : 'Unpaid'; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php $sr++; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                No payment schedule found for this member.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary Footer -->
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Payment Summary</h6>
                                    <?php
                                    $paid_count = 0;
                                    $unpaid_count = 0;
                                    $total_amount = 0;
                                    $collected_amount = 0;
                                    
                                    foreach ($emis as $emi) {
                                        $total_amount += $emi['emi_amount'];
                                        if ($emi['status'] === 'paid') {
                                            $paid_count++;
                                            $collected_amount += $emi['emi_amount'];
                                        } else {
                                            $unpaid_count++;
                                        }
                                    }
                                    ?>
                                    <div class="d-flex justify-content-between">
                                        <span>Total EMIs:</span>
                                        <strong><?= count($emis); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Paid EMIs:</span>
                                        <strong class="text-success"><?= $paid_count; ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Unpaid EMIs:</span>
                                        <strong class="text-warning"><?= $unpaid_count; ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2">
                                        <span>Total Amount:</span>
                                        <strong>₹<?= number_format($total_amount, 2); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Collected:</span>
                                        <strong class="text-success">₹<?= number_format($collected_amount, 2); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Pending:</span>
                                        <strong class="text-danger">₹<?= number_format($total_amount - $collected_amount, 2); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Quick Actions</h6>
                                    <div class="d-grid gap-2">
                                        <a href="../pay-emi.php?member=<?= $member_id; ?>" class="btn btn-success">
                                            <i class="fas fa-rupee-sign me-1"></i> Collect Payment
                                        </a>
                                        <a href="collection-list.php" class="btn btn-primary">
                                            <i class="fas fa-cash-register me-1"></i> Go to Collection List
                                        </a>
                                        <button onclick="window.print()" class="btn btn-secondary">
                                            <i class="fas fa-print me-1"></i> Print Schedule
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
</body>
</html>