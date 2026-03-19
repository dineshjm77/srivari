<?php
// staff/index.php - Staff Dashboard with Collection List
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check login - Only staff can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: login.php');
    exit;
}

include 'includes/db.php';

$today = date('Y-m-d');
$staff_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'unpaid';
$date_filter = isset($_GET['date']) ? $_GET['date'] : $today;
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
$month_filter = isset($_GET['month']) ? $_GET['month'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : 'all';

// Build query
$sql = "SELECT es.id, es.emi_amount, es.emi_due_date, es.paid_date, es.status, es.payment_type,
               m.id as member_id, m.customer_name, m.customer_number, m.agreement_number,
               p.title AS plan_title,
               (SELECT COUNT(*) FROM emi_schedule es2 
                WHERE es2.member_id = m.id AND es2.emi_due_date <= es.emi_due_date) as emi_number
        FROM emi_schedule es
        JOIN members m ON es.member_id = m.id
        JOIN plans p ON m.plan_id = p.id
        WHERE es.status = ?";

$params = [$status_filter];
$types = "s";

// Add date filter
if ($status_filter == 'unpaid') {
    if (!empty($month_filter)) {
        // Month filter for unpaid (due_date)
        $sql .= " AND MONTH(es.emi_due_date) = ? AND YEAR(es.emi_due_date) = ?";
        $month_parts = explode('-', $month_filter);
        $params[] = $month_parts[1]; // month
        $params[] = $month_parts[0]; // year
        $types .= "ii";
    } else {
        $sql .= " AND es.emi_due_date <= ?";
        $params[] = $date_filter;
        $types .= "s";
    }
} else {
    if (!empty($month_filter)) {
        // Month filter for paid (paid_date)
        $sql .= " AND MONTH(es.paid_date) = ? AND YEAR(es.paid_date) = ?";
        $month_parts = explode('-', $month_filter);
        $params[] = $month_parts[1];
        $params[] = $month_parts[0];
        $types .= "ii";
    } else {
        $sql .= " AND DATE(es.paid_date) = ?";
        $params[] = $date_filter;
        $types .= "s";
    }
}

// Add payment type filter for paid status
if ($status_filter == 'paid' && $payment_type != 'all') {
    $sql .= " AND es.payment_type = ?";
    $params[] = $payment_type;
    $types .= "s";
}

// Add plan filter
if ($plan_filter > 0) {
    $sql .= " AND m.plan_id = ?";
    $params[] = $plan_filter;
    $types .= "i";
}

// Add search filter
if (!empty($search)) {
    $sql .= " AND (m.customer_name LIKE ? OR m.customer_number LIKE ? OR m.agreement_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

$sql .= " ORDER BY es.emi_due_date ASC, m.customer_name ASC";

// Prepare and execute
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    die("Query preparation failed: " . $conn->error);
}

// Get totals for display
$current_month = date('Y-m');
$total_sql = "SELECT 
                SUM(CASE WHEN status = 'unpaid' AND emi_due_date <= ? THEN emi_amount ELSE 0 END) as total_unpaid,
                SUM(CASE WHEN status = 'paid' AND DATE(paid_date) = ? THEN emi_amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = 'unpaid' AND MONTH(emi_due_date) = MONTH(?) AND YEAR(emi_due_date) = YEAR(?) THEN emi_amount ELSE 0 END) as month_unpaid,
                SUM(CASE WHEN status = 'paid' AND MONTH(paid_date) = MONTH(?) AND YEAR(paid_date) = YEAR(?) THEN emi_amount ELSE 0 END) as month_paid
              FROM emi_schedule";
$total_stmt = $conn->prepare($total_sql);
$today_date = date('Y-m-d');
$total_stmt->bind_param("ssssss", $today_date, $today_date, $current_month, $current_month, $current_month, $current_month);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$totals = $total_result->fetch_assoc();
$total_stmt->close();

// Get plans for filter dropdown
$plans_result = $conn->query("SELECT id, title FROM plans ORDER BY title ASC");
$plans = [];
while ($row = $plans_result->fetch_assoc()) {
    $plans[] = $row;
}

// Get months for filter dropdown (last 12 months)
$months = [];
for ($i = 0; $i < 12; $i++) {
    $month = date('Y-m', strtotime("-$i months"));
    $months[] = [
        'value' => $month,
        'display' => date('F Y', strtotime($month))
    ];
}

// Function to get payment type label
function getPaymentTypeLabel($type) {
    $labels = [
        'cash' => 'Cash',
        'upi' => 'UPI',
        'both' => 'Cash + UPI'
    ];
    return $labels[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SRI VARI CHITS - Staff Collection</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: none;
            margin-bottom: 20px;
        }
        .card-header {
            background-color: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
        }
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
        }
        .badge-unpaid {
            background-color: #ffc107;
            color: #212529;
        }
        .badge-paid {
            background-color: #28a745;
            color: white;
        }
        .badge-overdue {
            background-color: #dc3545;
            color: white;
        }
        .badge-cash {
            background-color: #28a745;
            color: white;
        }
        .badge-upi {
            background-color: #007bff;
            color: white;
        }
        .badge-both {
            background-color: #6c757d;
            color: white;
        }
        .pay-btn {
            padding: 5px 15px;
            font-size: 0.875rem;
        }
        .summary-card {
            border-left: 4px solid;
        }
        .summary-unpaid {
            border-left-color: #ffc107;
        }
        .summary-paid {
            border-left-color: #28a745;
        }
        .user-info {
            background-color: rgba(255,255,255,0.1);
            padding: 10px 15px;
            border-radius: 5px;
        }
        
        /* Mobile Card View */
        .collection-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: white;
        }
        .card-label {
            font-weight: 600;
            color: #495057;
            min-width: 100px;
        }
        .card-value {
            color: #212529;
        }
        .card-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            align-items: center;
        }
        .card-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .action-buttons .btn {
            min-width: 36px;
        }
        
        /* Responsive table container */
        @media (max-width: 768px) {
            .table-container {
                display: none;
            }
            .card-container {
                display: block;
            }
            .action-buttons {
                justify-content: center;
                gap: 8px;
            }
        }
        
        @media (min-width: 769px) {
            .table-container {
                display: block;
            }
            .card-container {
                display: none;
            }
        }
        
        /* Filter form responsive */
        @media (max-width: 768px) {
            .filter-form .col-md-2,
            .filter-form .col-md-3 {
                margin-bottom: 15px;
            }
        }
        
        /* WhatsApp button */
        .btn-whatsapp {
            background-color: #25D366;
            border-color: #25D366;
            color: white;
        }
        .btn-whatsapp:hover {
            background-color: #128C7E;
            border-color: #128C7E;
            color: white;
        }
        
        /* Filter badges */
        .filter-badge {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }
            .card-container {
                display: none !important;
            }
            .table-container {
                display: block !important;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2 class="mb-1">SRI VARI CHITS</h2>
                    <p class="mb-0 opacity-75">Staff Collection Management</p>
                </div>
                <div class="col-md-6 text-end">
                    <div class="user-info d-inline-block">
                        <i class="fas fa-user-circle me-2"></i>
                        <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?></strong>
                        <span class="badge bg-light text-dark ms-2">Staff</span>
                        <a href="../logout.php" class="btn btn-sm btn-outline-light ms-3">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card summary-card summary-unpaid">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Pending</h6>
                                <h3 class="text-warning mb-0">₹<?php echo number_format($totals['total_unpaid'] ?? 0, 2); ?></h3>
                            </div>
                            <div class="icon-circle bg-warning text-white rounded-circle p-3">
                                <i class="fas fa-clock fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card summary-paid">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Today's Collection</h6>
                                <h3 class="text-success mb-0">₹<?php echo number_format($totals['total_paid'] ?? 0, 2); ?></h3>
                            </div>
                            <div class="icon-circle bg-success text-white rounded-circle p-3">
                                <i class="fas fa-rupee-sign fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card summary-unpaid">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Month Pending</h6>
                                <h3 class="text-warning mb-0">₹<?php echo number_format($totals['month_unpaid'] ?? 0, 2); ?></h3>
                            </div>
                            <div class="icon-circle bg-warning text-white rounded-circle p-3">
                                <i class="fas fa-calendar-alt fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card summary-paid">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Month Collection</h6>
                                <h3 class="text-success mb-0">₹<?php echo number_format($totals['month_paid'] ?? 0, 2); ?></h3>
                            </div>
                            <div class="icon-circle bg-success text-white rounded-circle p-3">
                                <i class="fas fa-calendar-check fa-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Filter Collections</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 filter-form">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="statusSelect">
                            <option value="unpaid" <?php echo $status_filter == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="date" 
                               value="<?php echo htmlspecialchars($date_filter); ?>" id="dateInput">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select class="form-select" name="month" id="monthSelect">
                            <option value="">Select Month</option>
                            <?php foreach ($months as $month): ?>
                                <option value="<?php echo $month['value']; ?>" 
                                    <?php echo $month_filter == $month['value'] ? 'selected' : ''; ?>>
                                    <?php echo $month['display']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Plan</label>
                        <select class="form-select" name="plan_id" id="planSelect">
                            <option value="">All Plans</option>
                            <?php foreach ($plans as $plan): ?>
                                <option value="<?php echo $plan['id']; ?>" 
                                    <?php echo $plan_filter == $plan['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($plan['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($status_filter == 'paid'): ?>
                    <div class="col-md-2">
                        <label class="form-label">Payment Type</label>
                        <select class="form-select" name="payment_type" id="paymentTypeSelect">
                            <option value="all" <?php echo $payment_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="cash" <?php echo $payment_type == 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="upi" <?php echo $payment_type == 'upi' ? 'selected' : ''; ?>>UPI</option>
                            <option value="both" <?php echo $payment_type == 'both' ? 'selected' : ''; ?>>Both</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-<?php echo $status_filter == 'paid' ? '2' : '4'; ?>">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Search by Name, Phone, or Application No" 
                                   value="<?php echo htmlspecialchars($search); ?>" id="searchInput">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Active Filter Badges -->
                    <div class="col-12">
                        <div class="d-flex align-items-center flex-wrap mt-2">
                            <span class="me-2 fw-bold">Active Filters:</span>
                            <?php if ($status_filter != 'unpaid'): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Status: <?php echo $status_filter == 'paid' ? 'Paid' : 'Unpaid'; ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($month_filter)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    Month: <?php echo date('F Y', strtotime($month_filter . '-01')); ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($date_filter != $today && empty($month_filter)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-calendar-day me-1"></i>
                                    Date: <?php echo date('d-m-Y', strtotime($date_filter)); ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($plan_filter > 0): ?>
                                <?php 
                                $plan_name = '';
                                foreach($plans as $p) {
                                    if ($p['id'] == $plan_filter) {
                                        $plan_name = $p['title'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="filter-badge">
                                    <i class="fas fa-chart-pie me-1"></i>
                                    Plan: <?php echo htmlspecialchars($plan_name); ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($status_filter == 'paid' && $payment_type != 'all'): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-money-bill-wave me-1"></i>
                                    Payment: <?php echo getPaymentTypeLabel($payment_type); ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($search)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-search me-1"></i>
                                    Search: "<?php echo htmlspecialchars($search); ?>"
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($status_filter != 'unpaid' || !empty($month_filter) || $date_filter != $today || $plan_filter > 0 || ($status_filter == 'paid' && $payment_type != 'all') || !empty($search)): ?>
                                <a href="index.php" class="btn btn-sm btn-outline-danger ms-2">
                                    <i class="fas fa-times me-1"></i> Clear All
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()">
                                <i class="fas fa-redo me-1"></i> Reset Filters
                            </button>
                            <a href="index.php?status=unpaid&date=<?php echo $today; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-clock me-1"></i> Today's Unpaid
                            </a>
                            <a href="index.php?status=paid&date=<?php echo $today; ?>" class="btn btn-outline-success">
                                <i class="fas fa-rupee-sign me-1"></i> Today's Paid
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Collection List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <?php 
                    $title = $status_filter == 'paid' ? 'Paid Collections' : 'Unpaid Collections';
                    if (!empty($month_filter)) {
                        $month_display = date('F Y', strtotime($month_filter . '-01'));
                        $title .= " - " . $month_display;
                    } elseif ($date_filter != $today) {
                        $title .= " - " . date('d-m-Y', strtotime($date_filter));
                    }
                    echo $title;
                    ?>
                    <span class="badge bg-primary ms-2"><?php echo $result->num_rows; ?> records</span>
                </h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <button class="btn btn-sm btn-outline-success no-print" onclick="exportToCSV()">
                        <i class="fas fa-file-excel me-1"></i> Export
                    </button>
                </div>
            </div>
            
            <!-- Desktop Table View -->
            <div class="table-container">
                <div class="card-body p-0">
                    <?php if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="collectionsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Member Name</th>
                                        <th>Phone Number</th>
                                        <th>Application No</th>
                                        <th>Plan</th>
                                        <th>EMI #</th>
                                        <th>EMI Amount</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $sr = 1; while ($row = $result->fetch_assoc()): 
                                        $is_overdue = ($row['status'] == 'unpaid' && strtotime($row['emi_due_date']) < time());
                                        $member_id = $row['member_id'] ?? 0;
                                        
                                        // Prepare WhatsApp message
                                        $whatsapp_message = '';
                                        if ($row['status'] == 'unpaid') {
                                            $whatsapp_message = urlencode("Dear " . $row['customer_name'] . ",\n\nThis is a reminder for your pending payment of ₹" . number_format($row['emi_amount'], 2) . " for agreement " . $row['agreement_number'] . ".\nDue Date: " . date('d-m-Y', strtotime($row['emi_due_date'])) . "\n\nPlease make the payment at your earliest convenience.\n\nThank you,\nSri Vari Chits");
                                        } else {
                                            $whatsapp_message = urlencode("Receipt - Sri Vari Chits\n\nDear " . $row['customer_name'] . ",\nYour payment of ₹" . number_format($row['emi_amount'], 2) . " has been received.\nAgreement No: " . $row['agreement_number'] . "\nDate: " . date('d-m-Y', strtotime($row['paid_date'])) . "\nPayment Type: " . getPaymentTypeLabel($row['payment_type']) . "\n\nThank you!");
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo $sr++; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong>
                                            </td>
                                            <td>
                                                <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $row['customer_number']); ?>" 
                                                   class="text-decoration-none">
                                                    <?php echo htmlspecialchars($row['customer_number']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($row['agreement_number']); ?></span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($row['plan_title']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">#<?php echo $row['emi_number']; ?></span>
                                            </td>
                                            <td>
                                                <strong class="text-primary">₹<?php echo number_format($row['emi_amount'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo date('d-m-Y', strtotime($row['emi_due_date'])); ?>
                                                <?php if ($is_overdue): ?>
                                                    <br><small class="text-danger">Overdue</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($row['status'] == 'paid'): ?>
                                                    <span class="badge badge-paid">Paid</span>
                                                    <?php if (!empty($row['paid_date'])): ?>
                                                        <br><small><?php echo date('d-m-Y', strtotime($row['paid_date'])); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['payment_type'])): ?>
                                                        <br><span class="badge badge-<?php echo $row['payment_type']; ?>">
                                                            <?php echo getPaymentTypeLabel($row['payment_type']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-<?php echo $is_overdue ? 'overdue' : 'unpaid'; ?>">
                                                        <?php echo $is_overdue ? 'Overdue' : 'Unpaid'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if ($row['status'] == 'unpaid'): ?>
                                                        <a href="pay-emi.php?emi_id=<?php echo $row['id']; ?>&member_id=<?php echo $member_id; ?>" 
                                                           class="btn btn-sm btn-success" title="Collect Payment">
                                                            <i class="fas fa-rupee-sign"></i>
                                                        </a>
                                                        <a href="https://wa.me/91<?php echo preg_replace('/\D/', '', $row['customer_number']); ?>?text=<?php echo $whatsapp_message; ?>" 
                                                           class="btn btn-sm btn-whatsapp" title="Send WhatsApp Reminder" target="_blank">
                                                            <i class="fab fa-whatsapp"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="https://wa.me/91<?php echo preg_replace('/\D/', '', $row['customer_number']); ?>?text=<?php echo $whatsapp_message; ?>" 
                                                           class="btn btn-sm btn-whatsapp" title="Send WhatsApp Receipt" target="_blank">
                                                            <i class="fab fa-whatsapp"></i>
                                                        </a>
                                                        
                                                    <?php endif; ?>
                                                    <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $row['customer_number']); ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Call Customer">
                                                        <i class="fas fa-phone"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                            <h5>No collections found</h5>
                            <p class="text-muted">No <?php echo $status_filter; ?> collections for the selected filters.</p>
                            <a href="index.php" class="btn btn-primary">
                                View Today's Collections
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Mobile Card View -->
            <div class="card-container">
                <div class="card-body">
                    <?php if ($result->num_rows > 0): 
                        // Reset pointer to beginning of result set
                        $result->data_seek(0);
                        $sr = 1; 
                        while ($row = $result->fetch_assoc()): 
                            $is_overdue = ($row['status'] == 'unpaid' && strtotime($row['emi_due_date']) < time());
                            $member_id = $row['member_id'] ?? 0;
                            
                            // Prepare WhatsApp message
                            $whatsapp_message = '';
                            if ($row['status'] == 'unpaid') {
                                $whatsapp_message = urlencode("Dear " . $row['customer_name'] . ",\n\nThis is a reminder for your pending payment of ₹" . number_format($row['emi_amount'], 2) . " for agreement " . $row['agreement_number'] . ".\nDue Date: " . date('d-m-Y', strtotime($row['emi_due_date'])) . "\n\nPlease make the payment at your earliest convenience.\n\nThank you,\nSri Vari Chits");
                            } else {
                                $whatsapp_message = urlencode("Receipt - Sri Vari Chits\n\nDear " . $row['customer_name'] . ",\nYour payment of ₹" . number_format($row['emi_amount'], 2) . " has been received.\nAgreement No: " . $row['agreement_number'] . "\nDate: " . date('d-m-Y', strtotime($row['paid_date'])) . "\nPayment Type: " . getPaymentTypeLabel($row['payment_type']) . "\n\nThank you!");
                            }
                    ?>
                        <div class="collection-card">
                            <div class="card-row">
                                <span class="card-label">#<?php echo $sr++; ?></span>
                                <span class="card-value fw-bold"><?php echo htmlspecialchars($row['customer_name']); ?></span>
                            </div>
                            
                            <div class="card-row">
                                <span class="card-label">Phone</span>
                                <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $row['customer_number']); ?>" 
                                   class="text-decoration-none card-value">
                                    <?php echo htmlspecialchars($row['customer_number']); ?>
                                </a>
                            </div>
                            
                            <div class="card-row">
                                <span class="card-label">Application</span>
                                <span class="card-value"><?php echo htmlspecialchars($row['agreement_number']); ?></span>
                            </div>
                            
                            <div class="card-row">
                                <span class="card-label">Plan</span>
                                <span class="card-value"><?php echo htmlspecialchars($row['plan_title']); ?></span>
                            </div>
                            
                            <div class="card-row">
                                <span class="card-label">EMI #</span>
                                <span class="badge bg-info">#<?php echo $row['emi_number']; ?></span>
                            </div>
                            
                            <div class="card-row">
                                <span class="card-label">Amount</span>
                                <span class="card-value fw-bold text-primary">₹<?php echo number_format($row['emi_amount'], 2); ?></span>
                            </div>
                            
                            <div class="card-row">
                                <span class="card-label">Due Date</span>
                                <span class="card-value">
                                    <?php echo date('d-m-Y', strtotime($row['emi_due_date'])); ?>
                                    <?php if ($is_overdue): ?>
                                        <span class="text-danger ms-1">(Overdue)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <div class="card-row">
                                <span class="card-label">Status</span>
                                <?php if ($row['status'] == 'paid'): ?>
                                    <span class="card-status badge-paid">Paid</span>
                                    <?php if (!empty($row['paid_date'])): ?>
                                        <small class="text-muted">on <?php echo date('d-m-Y', strtotime($row['paid_date'])); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($row['payment_type'])): ?>
                                        <br><span class="badge badge-<?php echo $row['payment_type']; ?> mt-1">
                                            <?php echo getPaymentTypeLabel($row['payment_type']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="card-status badge-<?php echo $is_overdue ? 'overdue' : 'unpaid'; ?>">
                                        <?php echo $is_overdue ? 'Overdue' : 'Unpaid'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-row mt-3">
                                <div class="action-buttons w-100">
                                    <?php if ($row['status'] == 'unpaid'): ?>
                                        <a href="pay-emi.php?emi_id=<?php echo $row['id']; ?>&member_id=<?php echo $member_id; ?>" 
                                           class="btn btn-success flex-fill">
                                            <i class="fas fa-rupee-sign me-1"></i> Pay Now
                                        </a>
                                        <a href="https://wa.me/91<?php echo preg_replace('/\D/', '', $row['customer_number']); ?>?text=<?php echo $whatsapp_message; ?>" 
                                           class="btn btn-whatsapp flex-fill" target="_blank">
                                            <i class="fab fa-whatsapp me-1"></i> WhatsApp
                                        </a>
                                    <?php else: ?>
                                        <a href="https://wa.me/91<?php echo preg_replace('/\D/', '', $row['customer_number']); ?>?text=<?php echo $whatsapp_message; ?>" 
                                           class="btn btn-whatsapp flex-fill" target="_blank">
                                            <i class="fab fa-whatsapp me-1"></i> Receipt
                                        </a>
                                        
                                    <?php endif; ?>
                                    <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $row['customer_number']); ?>" 
                                       class="btn btn-outline-primary flex-fill">
                                        <i class="fas fa-phone me-1"></i> Call
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                            <h5>No collections found</h5>
                            <p class="text-muted">No <?php echo $status_filter; ?> collections for the selected filters.</p>
                            <a href="index.php" class="btn btn-primary">
                                View Today's Collections
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-4 mb-4 text-muted no-print">
            <small>SRI VARI CHITS Staff Collection System &copy; <?php echo date('Y'); ?></small>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-refresh for unpaid list every 30 seconds
        <?php if ($status_filter == 'unpaid' && empty($month_filter)): ?>
        setTimeout(function() {
            window.location.reload();
        }, 30000);
        <?php endif; ?>

        // Toggle between date and month filter
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('dateInput');
            const monthSelect = document.getElementById('monthSelect');
            const statusSelect = document.getElementById('statusSelect');
            const paymentTypeSelect = document.getElementById('paymentTypeSelect');
            
            // When date is selected, clear month
            if (dateInput) {
                dateInput.addEventListener('change', function() {
                    if (monthSelect) monthSelect.value = '';
                });
            }
            
            // When month is selected, clear date
            if (monthSelect) {
                monthSelect.addEventListener('change', function() {
                    if (dateInput) dateInput.value = '';
                });
            }
            
            // When status changes to paid, show payment type filter
            if (statusSelect) {
                statusSelect.addEventListener('change', function() {
                    this.form.submit();
                });
            }
            
            // Auto-submit filters on change
            const planSelect = document.getElementById('planSelect');
            if (planSelect) {
                planSelect.addEventListener('change', function() {
                    this.form.submit();
                });
            }
            
            if (paymentTypeSelect) {
                paymentTypeSelect.addEventListener('change', function() {
                    this.form.submit();
                });
            }
        });
        
        // Reset all filters
        function resetFilters() {
            window.location.href = 'index.php';
        }
        
        // Export to CSV
        function exportToCSV() {
            let table = document.getElementById('collectionsTable');
            if (!table) {
                table = document.querySelector('.table');
            }
            
            if (table) {
                let rows = table.querySelectorAll('tr');
                let csv = [];
                
                for (let i = 0; i < rows.length; i++) {
                    let row = [], cols = rows[i].querySelectorAll('td, th');
                    
                    for (let j = 0; j < cols.length; j++) {
                        // Remove icons and badges for clean export
                        let text = cols[j].innerText.replace(/[\n\r]+|[\s]{2,}/g, ' ').trim();
                        row.push('"' + text + '"');
                    }
                    
                    csv.push(row.join(','));
                }
                
                let csvContent = csv.join('\n');
                let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                let url = URL.createObjectURL(blob);
                let link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'collections_<?php echo date('Y-m-d'); ?>.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
        
        // Quick filter buttons
        function setDateFilter(days) {
            const date = new Date();
            if (days > 0) {
                date.setDate(date.getDate() + days);
            } else if (days < 0) {
                date.setDate(date.getDate() + days);
            }
            const dateStr = date.toISOString().split('T')[0];
            document.getElementById('dateInput').value = dateStr;
            document.getElementById('monthSelect').value = '';
            document.getElementById('statusSelect').value = 'unpaid';
            document.forms[0].submit();
        }
    </script>
</body>
</html>