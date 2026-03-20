<?php
// add-member.php - COMPLETE WITH DAILY/WEEKLY/MONTHLY SUPPORT (FIXED - Using calculated_amount)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Check if we're using new or old table structure
$check_columns = $conn->query("SHOW COLUMNS FROM plan_details LIKE 'period_number'");
$has_new_structure = $check_columns->num_rows > 0;

$edit_mode = isset($_GET['edit']) && is_numeric($_GET['edit']);
$edit_member_id = $edit_mode ? intval($_GET['edit']) : 0;

// Fetch all plans - with proper column handling
$plans = [];
$result_plans = $conn->query("SELECT id, title, plan_type, total_months, total_periods, monthly_installment, weekly_installment, daily_installment, period_type, total_received_amount FROM plans ORDER BY title ASC");
if ($result_plans) {
    while ($row = $result_plans->fetch_assoc()) {
        // Set default values if NULL
        $row['plan_type'] = $row['plan_type'] ?? 'monthly';
        $row['total_periods'] = $row['total_periods'] ?? $row['total_months'];
        $row['weekly_installment'] = $row['weekly_installment'] ?? 0;
        $row['daily_installment'] = $row['daily_installment'] ?? 0;
        $plans[] = $row;
    }
}

$plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
$member_data = [];
$emi_schedule = [];

if ($edit_mode) {
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->bind_param("i", $edit_member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $member_data = $result->fetch_assoc();
        $plan_id = $member_data['plan_id'];
        // Load EMI schedule
        $stmt_emi = $conn->prepare("SELECT id, emi_amount, emi_due_date, status FROM emi_schedule WHERE member_id = ? ORDER BY emi_due_date ASC");
        $stmt_emi->bind_param("i", $edit_member_id);
        $stmt_emi->execute();
        $res_emi = $stmt_emi->get_result();
        while ($row = $res_emi->fetch_assoc()) {
            $emi_schedule[] = $row;
        }
        $stmt_emi->close();
    } else {
        $_SESSION['error'] = "Member not found.";
        header("Location: manage-members.php");
        exit;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $plan_id = intval($_POST['plan_id'] ?? 0);
    $agreement_number = trim($_POST['agreement_number'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_number = trim($_POST['customer_number'] ?? '');
    $customer_number2 = trim($_POST['customer_number2'] ?? '');
    $nominee_name = trim($_POST['nominee_name'] ?? '');
    $nominee_number = trim($_POST['nominee_number'] ?? '');
    $customer_aadhar = trim($_POST['customer_aadhar'] ?? '');
    $nominee_aadhar = trim($_POST['nominee_aadhar'] ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $emi_date = trim($_POST['emi_date'] ?? '');
    $first_emi_date = trim($_POST['first_emi_date'] ?? '');
    $payment_mode = trim($_POST['payment_mode'] ?? 'monthly');
    $calculated_amount = isset($_POST['calculated_amount']) ? floatval($_POST['calculated_amount']) : 0;
    $use_custom_amount = isset($_POST['use_custom_amount']) && $_POST['use_custom_amount'] == '1';
    $bid_winner_site_number = trim($_POST['bid_winner_site_number'] ?? '');

    // Validation
    $errors = [];
    if ($plan_id <= 0) $errors[] = "Please select a Plan.";
    if (empty($agreement_number)) $errors[] = "Agreement Number is required.";
    if (empty($customer_name)) $errors[] = "Customer Name is required.";
    if (empty($customer_number)) $errors[] = "Customer Phone is required.";
    if (empty($nominee_name)) $errors[] = "Nominee Name is required.";
    if (empty($nominee_number)) $errors[] = "Nominee Phone is required.";
    if (empty($emi_date)) $errors[] = "Agreement Date is required.";
    if (empty($first_emi_date)) $errors[] = "First Payment Date is required.";
    if (!in_array($payment_mode, ['daily', 'weekly', 'monthly'])) $errors[] = "Invalid payment mode.";
    
    if ($use_custom_amount && in_array($payment_mode, ['daily', 'weekly'])) {
        if ($calculated_amount <= 0) {
            $errors[] = "Please enter a valid calculated amount for " . $payment_mode . " payments.";
        }
    }

    if (!empty($errors)) {
        $_SESSION['error'] = "• " . implode("<br>• ", $errors);
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }

    // Phone validation
    if (!preg_match('/^\d{10}$/', $customer_number)) {
        $_SESSION['error'] = "Customer phone must be exactly 10 digits.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }
    if ($customer_number2 && !preg_match('/^\d{10}$/', $customer_number2)) {
        $_SESSION['error'] = "Alternate phone must be 10 digits.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }
    if (!preg_match('/^\d{10}$/', $nominee_number)) {
        $_SESSION['error'] = "Nominee phone must be exactly 10 digits.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }

    if (strtotime($first_emi_date) < strtotime($emi_date)) {
        $_SESSION['error'] = "First payment date must be on or after agreement date.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }

    // Get plan details
    $selected_plan = null;
    foreach ($plans as $p) {
        if ($p['id'] == $plan_id) {
            $selected_plan = $p;
            break;
        }
    }
    
    if (!$selected_plan) {
        $_SESSION['error'] = "Invalid plan selected.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }

    $total_months = $selected_plan['total_months'] ?? 10;
    $total_periods = $selected_plan['total_periods'] ?? $total_months;
    $monthly_installment = $selected_plan['monthly_installment'] ?? 0;
    $plan_type = $selected_plan['plan_type'] ?? 'monthly';
    $plan_weekly = $selected_plan['weekly_installment'] ?? 0;
    $plan_daily = $selected_plan['daily_installment'] ?? 0;

    // Get monthly installment schedule from plan_details
    $period_field = $has_new_structure ? "period_number" : "month_number";
    $stmt_plan = $conn->prepare("SELECT " . $period_field . " as period_num, installment FROM plan_details WHERE plan_id = ? ORDER BY " . $period_field . " ASC");
    $stmt_plan->bind_param("i", $plan_id);
    $stmt_plan->execute();
    $result_plan = $stmt_plan->get_result();
    $monthly_installments = [];
    while ($row = $result_plan->fetch_assoc()) {
        $monthly_installments[$row['period_num']] = $row['installment'];
    }
    $stmt_plan->close();
    
    // If no plan details found, use fixed monthly amount
    if (empty($monthly_installments)) {
        for ($m = 1; $m <= $total_months; $m++) {
            $monthly_installments[$m] = $monthly_installment;
        }
    }

    // Prepare installments array based on payment mode
    $installments = [];
    $installment_dates = [];
    $total_installments_count = 0;
    $current_date = new DateTime($first_emi_date);

    if ($payment_mode == 'monthly') {
        // Monthly payments
        for ($month = 1; $month <= $total_months; $month++) {
            $amount = $monthly_installments[$month] ?? $monthly_installment;
            $installments[] = $amount;
            $installment_dates[] = $current_date->format('Y-m-d');
            $current_date->modify('+1 month');
            $total_installments_count++;
        }
        
    } elseif ($payment_mode == 'weekly') {
        // Weekly payments
        $weeks_per_month = 4;
        $weekly_amount = $use_custom_amount ? $calculated_amount : ($plan_weekly > 0 ? $plan_weekly : round($monthly_installment / 4, 2));
        
        for ($month = 1; $month <= $total_months; $month++) {
            $monthly_target = $monthly_installments[$month] ?? $monthly_installment;
            $remaining = $monthly_target;
            
            for ($w = 0; $w < $weeks_per_month; $w++) {
                if ($remaining <= 0) {
                    $installments[] = 0.00;
                } elseif ($weekly_amount >= $remaining) {
                    $installments[] = round($remaining, 2);
                    $remaining = 0;
                } else {
                    $installments[] = $weekly_amount;
                    $remaining -= $weekly_amount;
                }
                
                $installment_dates[] = $current_date->format('Y-m-d');
                $current_date->modify('+7 days');
                $total_installments_count++;
            }
        }
        
    } elseif ($payment_mode == 'daily') {
        // Daily payments - 30 days per month
        $days_per_month = 30;
        $daily_amount = $use_custom_amount ? $calculated_amount : ($plan_daily > 0 ? $plan_daily : 360);
        
        for ($month = 1; $month <= $total_months; $month++) {
            $monthly_target = $monthly_installments[$month] ?? $monthly_installment;
            $remaining = $monthly_target;
            
            // Calculate days needed
            $days_needed = ceil($monthly_target / $daily_amount);
            if ($days_needed > $days_per_month) {
                $days_needed = $days_per_month;
            }
            
            // Collection days
            for ($d = 1; $d <= $days_needed; $d++) {
                if ($d == $days_needed) {
                    $installments[] = round($remaining, 2);
                } else {
                    $installments[] = $daily_amount;
                    $remaining -= $daily_amount;
                }
                
                $installment_dates[] = $current_date->format('Y-m-d');
                $current_date->modify('+1 day');
                $total_installments_count++;
            }
            
            // No collection days
            $remaining_zero_days = $days_per_month - $days_needed;
            for ($z = 1; $z <= $remaining_zero_days; $z++) {
                $installments[] = 0.00;
                $installment_dates[] = $current_date->format('Y-m-d');
                $current_date->modify('+1 day');
                $total_installments_count++;
            }
        }
    }

    // Photo uploads
    $customer_photo = $edit_mode ? $member_data['customer_photo'] : null;
    $aadhar_photo = $edit_mode ? $member_data['aadhar_photo'] : null;
    $target_dir = "Uploads/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    if (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($ext, $allowed) && $_FILES['customer_photo']['size'] <= 5*1024*1024) {
            $customer_photo = $target_dir . 'cust_' . time() . '_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['customer_photo']['tmp_name'], $customer_photo);
        }
    }
    
    if (isset($_FILES['aadhar_photo']) && $_FILES['aadhar_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['aadhar_photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($ext, $allowed) && $_FILES['aadhar_photo']['size'] <= 5*1024*1024) {
            $aadhar_photo = $target_dir . 'aadhar_' . time() . '_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['aadhar_photo']['tmp_name'], $aadhar_photo);
        }
    }

    // Nullable fields
    $null = null;
    $cust_aadhar = !empty($customer_aadhar) ? $customer_aadhar : $null;
    $nom_aadhar = !empty($nominee_aadhar) ? $nominee_aadhar : $null;
    $cust_addr = !empty($customer_address) ? $customer_address : $null;
    $cust_num2 = !empty($customer_number2) ? $customer_number2 : $null;
    $bid_site = !empty($bid_winner_site_number) ? $bid_winner_site_number : $null;

    // Save or update member
    if ($edit_mode) {
        // UPDATE
        $sql = "UPDATE members SET
                agreement_number = ?, customer_name = ?, customer_number = ?, customer_number2 = ?,
                nominee_name = ?, nominee_number = ?, customer_aadhar = ?, nominee_aadhar = ?, customer_address = ?,
                customer_photo = ?, aadhar_photo = ?, plan_id = ?, monthly_installment = ?, calculated_amount = ?,
                emi_date = ?, payment_mode = ?, bid_winner_site_number = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $_SESSION['error'] = "Prepare failed: " . $conn->error;
            header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
            exit;
        }
        
        $calculated_amount_to_store = ($use_custom_amount && $calculated_amount > 0) ? $calculated_amount : null;
        
        $stmt->bind_param(
            "sssssssssssiddsssi",
            $agreement_number,
            $customer_name,
            $customer_number,
            $cust_num2,
            $nominee_name,
            $nominee_number,
            $cust_aadhar,
            $nom_aadhar,
            $cust_addr,
            $customer_photo,
            $aadhar_photo,
            $plan_id,
            $monthly_installment,
            $calculated_amount_to_store,
            $emi_date,
            $payment_mode,
            $bid_site,
            $edit_member_id
        );
    } else {
        // INSERT
        $sql = "INSERT INTO members
                (agreement_number, customer_name, customer_number, customer_number2,
                 nominee_name, nominee_number, customer_aadhar, nominee_aadhar, customer_address,
                 customer_photo, aadhar_photo, plan_id, monthly_installment, calculated_amount,
                 emi_date, payment_mode, bid_winner_site_number, document_charge)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $_SESSION['error'] = "Prepare failed: " . $conn->error;
            header("Location: add-member.php");
            exit;
        }
        
        $calculated_amount_to_store = ($use_custom_amount && $calculated_amount > 0) ? $calculated_amount : null;
        
        $stmt->bind_param(
            "sssssssssssiddsss",
            $agreement_number,
            $customer_name,
            $customer_number,
            $cust_num2,
            $nominee_name,
            $nominee_number,
            $cust_aadhar,
            $nom_aadhar,
            $cust_addr,
            $customer_photo,
            $aadhar_photo,
            $plan_id,
            $monthly_installment,
            $calculated_amount_to_store,
            $emi_date,
            $payment_mode,
            $bid_site
        );
    }
    
    if ($stmt->execute()) {
        $member_id = $edit_mode ? $edit_member_id : $conn->insert_id;
        
        // Rebuild EMI schedule
        if ($edit_mode) {
            $conn->query("DELETE FROM emi_schedule WHERE member_id = $member_id AND status = 'unpaid'");
        } else {
            $conn->query("DELETE FROM emi_schedule WHERE member_id = $member_id");
        }
        
        $stmt_emi = $conn->prepare("INSERT INTO emi_schedule (member_id, plan_id, emi_amount, emi_due_date, status, period_type, period_number)
                                    VALUES (?, ?, ?, ?, 'unpaid', ?, ?)");
        
        for ($i = 0; $i < count($installments); $i++) {
            $period_num = $i + 1;
            $stmt_emi->bind_param("iidsis", $member_id, $plan_id, $installments[$i], $installment_dates[$i], $payment_mode, $period_num);
            $stmt_emi->execute();
        }
        $stmt_emi->close();
        
        $_SESSION['success'] = $edit_mode ? "Member updated successfully!" : "Member added successfully!";
        header("Location: manage-members.php");
        exit;
    } else {
        $_SESSION['error'] = "Database error: " . $stmt->error;
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .plan-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        margin-left: 8px;
    }
    .badge-monthly { background-color: #0d6efd; color: white; }
    .badge-weekly { background-color: #198754; color: white; }
    .badge-daily { background-color: #ffc107; color: black; }
    
    .amount-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 15px;
    }
    
    .custom-amount-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        border-left: 4px solid #198754;
        margin-top: 10px;
    }
    
    .payment-preview {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        border: 1px solid #dee2e6;
        max-height: 300px;
        overflow-y: auto;
    }
    
    .collection-day {
        background-color: #d1e7ff;
        padding: 2px 5px;
        border-radius: 3px;
    }
    
    .no-collection-day {
        background-color: #f8d7da;
        padding: 2px 5px;
        border-radius: 3px;
        color: #721c24;
    }
    
    .info-icon {
        color: #0d6efd;
        cursor: help;
        margin-left: 5px;
    }
</style>
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
                $page_title = $edit_mode ? "Edit Member" : "Add Member";
                $breadcrumb_active = $page_title;
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
                        <h3 class="mb-0"><?= $edit_mode ? 'Edit Member' : 'Add New Member'; ?></h3>
                        <small class="text-muted">Fill member details and select payment mode</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-members.php" class="btn btn-light">Back to Members</a>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Member Information</h4>
                    </div>
                    <div class="card-body">
                        <form id="memberForm" action="add-member.php<?= $edit_mode ? '?edit=' . $edit_member_id : ''; ?>" method="POST" enctype="multipart/form-data">
                            <!-- Customer & Nominee -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <h5 class="mb-3">Customer Details</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="customer_name" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['customer_name']) : ''; ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="customer_number" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['customer_number']) : ''; ?>" required maxlength="10" pattern="\d{10}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Alternate Phone</label>
                                        <input type="text" class="form-control" name="customer_number2" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['customer_number2'] ?? '') : ''; ?>" maxlength="10" pattern="\d{10}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Aadhar Number</label>
                                        <input type="text" class="form-control" name="customer_aadhar" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['customer_aadhar'] ?? '') : ''; ?>" maxlength="12">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Address</label>
                                        <textarea class="form-control" name="customer_address" rows="3"><?= $edit_mode ? htmlspecialchars($member_data['customer_address'] ?? '') : ''; ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Customer Photo</label>
                                        <input type="file" class="form-control" name="customer_photo" accept="image/*">
                                        <?php if ($edit_mode && !empty($member_data['customer_photo'])): ?>
                                            <small><a href="<?= $member_data['customer_photo']; ?>" target="_blank">View Current</a></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Bid Winner Seat Number</label>
                                        <input type="text" class="form-control" name="bid_winner_site_number" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['bid_winner_site_number'] ?? '') : ''; ?>"
                                               placeholder="e.g., Site-001">
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5 class="mb-3">Nominee Details</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Nominee Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="nominee_name" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['nominee_name']) : ''; ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nominee Phone <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="nominee_number" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['nominee_number']) : ''; ?>" required maxlength="10" pattern="\d{10}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nominee Aadhar</label>
                                        <input type="text" class="form-control" name="nominee_aadhar" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['nominee_aadhar'] ?? '') : ''; ?>" maxlength="12">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Aadhar Photo</label>
                                        <input type="file" class="form-control" name="aadhar_photo" accept="image/*">
                                        <?php if ($edit_mode && !empty($member_data['aadhar_photo'])): ?>
                                            <small><a href="<?= $member_data['aadhar_photo']; ?>" target="_blank">View Current</a></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Plan Selection -->
                            <h5 class="mb-3">Plan & Payment Details</h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Select Plan <span class="text-danger">*</span></label>
                                    <select class="form-control" name="plan_id" id="plan_id" required onchange="updatePlanDetails()">
                                        <option value="">-- Choose Plan --</option>
                                        <?php foreach ($plans as $plan): 
                                            $plan_type_class = '';
                                            $plan_type_label = '';
                                            $period_label = 'Months';
                                            $plan_type_value = $plan['plan_type'] ?? 'monthly';
                                            
                                            if ($plan_type_value == 'weekly') {
                                                $plan_type_class = 'badge-weekly';
                                                $plan_type_label = 'Weekly';
                                                $period_label = 'Weeks';
                                            } elseif ($plan_type_value == 'daily') {
                                                $plan_type_class = 'badge-daily';
                                                $plan_type_label = 'Daily';
                                                $period_label = 'Days';
                                            } else {
                                                $plan_type_class = 'badge-monthly';
                                                $plan_type_label = 'Monthly';
                                                $period_label = 'Months';
                                            }
                                            
                                            $display_periods = $plan['total_periods'] ?? $plan['total_months'] ?? 10;
                                        ?>
                                            <option value="<?= $plan['id']; ?>" 
                                                    data-plan-type="<?= $plan_type_value; ?>"
                                                    data-total-periods="<?= $display_periods; ?>"
                                                    data-monthly="<?= $plan['monthly_installment']; ?>"
                                                    data-weekly="<?= $plan['weekly_installment']; ?>"
                                                    data-daily="<?= $plan['daily_installment']; ?>"
                                                    data-prize="<?= $plan['total_received_amount'] ?? 0; ?>"
                                                    <?= ($plan_id == $plan['id']) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($plan['title']); ?>
                                                <span class="plan-badge <?= $plan_type_class; ?>"><?= $plan_type_label; ?></span>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($plans)): ?>
                                        <small class="text-danger mt-1">No plans available. Please add plans first.</small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Application Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="agreement_number" 
                                           value="<?= $edit_mode ? htmlspecialchars($member_data['agreement_number']) : ''; ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Agreement Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="emi_date" id="emi_date"
                                           value="<?= $edit_mode ? $member_data['emi_date'] : date('Y-m-d'); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label" id="first_payment_label">First Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="first_emi_date" id="first_emi_date"
                                           value="<?= $edit_mode && !empty($emi_schedule) ? $emi_schedule[0]['emi_due_date'] : date('Y-m-d', strtotime('+1 month')); ?>" required>
                                </div>
                            </div>
                            
                            <!-- Payment Mode Selection -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="amount-card">
                                        <h6 class="text-white mb-3">Select Payment Mode</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="payment_mode" 
                                                           id="mode_monthly" value="monthly" checked onchange="updatePaymentMode()">
                                                    <label class="form-check-label text-white" for="mode_monthly">
                                                        <strong>Monthly Payment</strong><br>
                                                        <small>One payment per month</small>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="payment_mode" 
                                                           id="mode_weekly" value="weekly" onchange="updatePaymentMode()">
                                                    <label class="form-check-label text-white" for="mode_weekly">
                                                        <strong>Weekly Payment</strong><br>
                                                        <small>4 payments per month</small>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="payment_mode" 
                                                           id="mode_daily" value="daily" onchange="updatePaymentMode()">
                                                    <label class="form-check-label text-white" for="mode_daily">
                                                        <strong>Daily Payment</strong><br>
                                                        <small>30 days per month (chit system)</small>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Plan Details Summary -->
                            <div class="row mt-3" id="plan_summary" style="display: none;">
                                <div class="col-md-3">
                                    <div class="border p-3 rounded">
                                        <small class="text-muted">Plan Type</small>
                                        <h6 id="summary_plan_type">-</h6>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border p-3 rounded">
                                        <small class="text-muted">Duration</small>
                                        <h6 id="summary_duration">-</h6>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border p-3 rounded">
                                        <small class="text-muted">Monthly Amount</small>
                                        <h6 id="summary_monthly">-</h6>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border p-3 rounded">
                                        <small class="text-muted">Prize Amount</small>
                                        <h6 id="summary_prize">-</h6>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Custom Amount Section (For Weekly/Daily) -->
                            <div class="row mt-3" id="custom_amount_section" style="display: none;">
                                <div class="col-12">
                                    <div class="custom-amount-section">
                                        <div class="row align-items-center">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="use_custom_amount" 
                                                           id="use_custom_amount" value="1" onchange="toggleCustomAmount()">
                                                    <label class="form-check-label fw-bold" for="use_custom_amount">
                                                        Use Custom Amount
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" id="plan_amount_label">Plan Amount</label>
                                                <input type="text" class="form-control" id="plan_amount_display" readonly>
                                            </div>
                                            <div class="col-md-4" id="custom_amount_container" style="display: none;">
                                                <label class="form-label" id="custom_amount_label">Custom Amount <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" class="form-control" name="calculated_amount" 
                                                           id="calculated_amount" step="0.01" min="1" onchange="updatePreview()">
                                                </div>
                                                <small class="text-muted" id="custom_amount_note">Collection stops when monthly target reached</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Payment Preview -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <h6>Payment Schedule Preview</h6>
                                    <div class="payment-preview" id="payment_preview">
                                        <p class="text-muted mb-0">Select plan and payment mode to see preview</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Submit Buttons -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-lg px-4">
                                        <i class="fas fa-save me-2"></i><?= $edit_mode ? 'Update Member' : 'Save Member'; ?>
                                    </button>
                                    <a href="manage-members.php" class="btn btn-light btn-lg px-4 ms-2">Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- EMI Schedule (Edit Mode Only) -->
                <?php if ($edit_mode && !empty($emi_schedule)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Existing Payment Schedule</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Due Date</th>
                                        <th>Amount (₹)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($emi_schedule as $index => $emi): ?>
                                     <tr>
                                        <td><?= $index + 1; ?></td>
                                        <td><?= date('d-m-Y', strtotime($emi['emi_due_date'])); ?></td>
                                        <td>₹<?= number_format($emi['emi_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?= $emi['status'] == 'paid' ? 'success' : 'warning'; ?>">
                                                <?= ucfirst($emi['status']); ?>
                                            </span>
                                        </td>
                                     </tr>
                                    <?php endforeach; ?>
                                </tbody>
                             </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
    
    <?php include 'includes/scripts.php'; ?>
    
    <script>
        let currentPlanType = 'monthly';
        
        function updatePlanDetails() {
            const planSelect = document.getElementById('plan_id');
            const selected = planSelect.options[planSelect.selectedIndex];
            
            if (!selected || !selected.value) {
                document.getElementById('plan_summary').style.display = 'none';
                return;
            }
            
            currentPlanType = selected.getAttribute('data-plan-type') || 'monthly';
            const totalPeriods = parseInt(selected.getAttribute('data-total-periods')) || 0;
            const monthlyAmount = parseFloat(selected.getAttribute('data-monthly')) || 0;
            const weeklyAmount = parseFloat(selected.getAttribute('data-weekly')) || 0;
            const dailyAmount = parseFloat(selected.getAttribute('data-daily')) || 0;
            const prizeAmount = parseFloat(selected.getAttribute('data-prize')) || 0;
            
            // Update summary
            document.getElementById('plan_summary').style.display = 'flex';
            
            let planTypeDisplay = 'Monthly';
            let durationLabel = 'Months';
            
            if (currentPlanType === 'weekly') {
                planTypeDisplay = 'Weekly';
                durationLabel = 'Weeks';
            } else if (currentPlanType === 'daily') {
                planTypeDisplay = 'Daily';
                durationLabel = 'Days';
            }
            
            document.getElementById('summary_plan_type').textContent = planTypeDisplay;
            document.getElementById('summary_duration').textContent = totalPeriods + ' ' + durationLabel;
            document.getElementById('summary_monthly').textContent = '₹' + monthlyAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('summary_prize').textContent = '₹' + prizeAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
            
            updatePaymentMode();
        }
        
        function updatePaymentMode() {
            const mode = document.querySelector('input[name="payment_mode"]:checked').value;
            const customSection = document.getElementById('custom_amount_section');
            const planAmountDisplay = document.getElementById('plan_amount_display');
            const customAmountLabel = document.getElementById('custom_amount_label');
            const customAmountNote = document.getElementById('custom_amount_note');
            const firstPaymentLabel = document.getElementById('first_payment_label');
            
            if (mode === 'weekly') {
                customSection.style.display = 'block';
                const weeklyAmount = parseFloat(document.getElementById('plan_id').options[document.getElementById('plan_id').selectedIndex]?.getAttribute('data-weekly') || 0);
                const monthlyAmount = parseFloat(document.getElementById('plan_id').options[document.getElementById('plan_id').selectedIndex]?.getAttribute('data-monthly') || 0);
                const planWeekly = weeklyAmount > 0 ? weeklyAmount : (monthlyAmount / 4).toFixed(2);
                planAmountDisplay.value = '₹' + planWeekly.toFixed(2) + ' (calculated from monthly)';
                customAmountLabel.textContent = 'Custom Weekly Amount';
                customAmountNote.textContent = 'Enter custom weekly amount. Collection stops when monthly target reached.';
                firstPaymentLabel.innerHTML = 'First Weekly Payment Date <span class="text-danger">*</span>';
            } else if (mode === 'daily') {
                customSection.style.display = 'block';
                const dailyAmount = parseFloat(document.getElementById('plan_id').options[document.getElementById('plan_id').selectedIndex]?.getAttribute('data-daily') || 0);
                const monthlyAmount = parseFloat(document.getElementById('plan_id').options[document.getElementById('plan_id').selectedIndex]?.getAttribute('data-monthly') || 0);
                const planDaily = dailyAmount > 0 ? dailyAmount : (monthlyAmount / 30).toFixed(2);
                planAmountDisplay.value = '₹' + planDaily.toFixed(2) + ' (calculated from monthly)';
                customAmountLabel.textContent = 'Custom Daily Amount';
                customAmountNote.textContent = 'Enter custom daily amount. Collection stops when monthly target reached. Remaining days show as no collection.';
                firstPaymentLabel.innerHTML = 'First Daily Payment Date <span class="text-danger">*</span>';
            } else {
                customSection.style.display = 'none';
                firstPaymentLabel.innerHTML = 'First Monthly Payment Date <span class="text-danger">*</span>';
            }
            
            updatePreview();
        }
        
        function toggleCustomAmount() {
            const checkbox = document.getElementById('use_custom_amount');
            const container = document.getElementById('custom_amount_container');
            container.style.display = checkbox.checked ? 'block' : 'none';
            updatePreview();
        }
        
        function updatePreview() {
            const planSelect = document.getElementById('plan_id');
            const selected = planSelect.options[planSelect.selectedIndex];
            const mode = document.querySelector('input[name="payment_mode"]:checked').value;
            const firstDate = document.getElementById('first_emi_date').value;
            
            if (!selected || !selected.value || !firstDate) {
                document.getElementById('payment_preview').innerHTML = '<p class="text-muted mb-0">Select plan and first payment date to see preview</p>';
                return;
            }
            
            const totalPeriods = parseInt(selected.getAttribute('data-total-periods')) || 0;
            const monthlyAmount = parseFloat(selected.getAttribute('data-monthly')) || 0;
            const weeklyAmount = parseFloat(selected.getAttribute('data-weekly')) || 0;
            const dailyAmount = parseFloat(selected.getAttribute('data-daily')) || 0;
            
            const useCustom = document.getElementById('use_custom_amount')?.checked || false;
            const customAmount = parseFloat(document.getElementById('calculated_amount')?.value) || 0;
            
            let previewHTML = '<div class="small">';
            previewHTML += '<strong>Payment Schedule Preview</strong><br>';
            previewHTML += '<hr class="my-2">';
            
            if (mode === 'monthly') {
                previewHTML += '<strong>Monthly Payments:</strong><br>';
                for (let m = 1; m <= Math.min(3, totalPeriods); m++) {
                    previewHTML += '• Month ' + m + ': ₹' + monthlyAmount.toFixed(2) + '<br>';
                }
                if (totalPeriods > 3) {
                    previewHTML += '• ... and ' + (totalPeriods - 3) + ' more months<br>';
                }
                previewHTML += '<br><strong>Total Months:</strong> ' + totalPeriods;
                
            } else if (mode === 'weekly') {
                const weeklyAmt = useCustom ? customAmount : (weeklyAmount > 0 ? weeklyAmount : monthlyAmount / 4);
                previewHTML += '<strong>Weekly Payments:</strong><br>';
                previewHTML += '• Weekly amount: ₹' + weeklyAmt.toFixed(2) + '<br>';
                previewHTML += '• 4 weeks per month<br>';
                previewHTML += '• Collection stops when monthly target reached<br><br>';
                
                previewHTML += '<strong>First Month Example (₹' + monthlyAmount.toFixed(2) + ' target):</strong><br>';
                let remaining = monthlyAmount;
                for (let w = 1; w <= 4; w++) {
                    if (remaining <= 0) {
                        previewHTML += '• Week ' + w + ': <span class="no-collection-day">No collection</span><br>';
                    } else if (weeklyAmt >= remaining) {
                        previewHTML += '• Week ' + w + ': <span class="collection-day">₹' + remaining.toFixed(2) + ' (final)</span><br>';
                        remaining = 0;
                    } else {
                        previewHTML += '• Week ' + w + ': ₹' + weeklyAmt.toFixed(2) + '<br>';
                        remaining -= weeklyAmt;
                    }
                }
                
            } else if (mode === 'daily') {
                const dailyAmt = useCustom ? customAmount : (dailyAmount > 0 ? dailyAmount : 360);
                const daysNeeded = Math.ceil(monthlyAmount / dailyAmt);
                
                previewHTML += '<strong>Daily Payments (30-day chit months):</strong><br>';
                previewHTML += '• Daily amount: ₹' + dailyAmt.toFixed(2) + '<br>';
                previewHTML += '• 30 days per month<br>';
                previewHTML += '• Collection stops when target reached<br><br>';
                
                previewHTML += '<strong>First Month Example (₹' + monthlyAmount.toFixed(2) + ' target):</strong><br>';
                let remaining = monthlyAmount;
                
                for (let d = 1; d <= 30; d++) {
                    if (d <= daysNeeded) {
                        if (d == daysNeeded) {
                            previewHTML += '• Day ' + d + ': <span class="collection-day">₹' + remaining.toFixed(2) + ' (final)</span><br>';
                        } else {
                            previewHTML += '• Day ' + d + ': ₹' + dailyAmt.toFixed(2) + '<br>';
                            remaining -= dailyAmt;
                        }
                    } else {
                        previewHTML += '• Day ' + d + ': <span class="no-collection-day">No collection</span><br>';
                    }
                }
                
                previewHTML += '<br><strong>Summary:</strong> ' + daysNeeded + ' collection days, ' + (30 - daysNeeded) + ' no-collection days';
            }
            
            previewHTML += '</div>';
            document.getElementById('payment_preview').innerHTML = previewHTML;
        }
        
        // Event listeners
        document.getElementById('plan_id').addEventListener('change', updatePlanDetails);
        document.getElementById('first_emi_date').addEventListener('change', updatePreview);
        
        // Initialize
        window.addEventListener('DOMContentLoaded', function() {
            <?php if ($edit_mode && isset($member_data['payment_mode'])): ?>
            const modeRadios = document.querySelectorAll('input[name="payment_mode"]');
            modeRadios.forEach(radio => {
                if (radio.value === '<?= $member_data['payment_mode']; ?>') {
                    radio.checked = true;
                }
            });
            <?php endif; ?>
            
            updatePlanDetails();
            updatePaymentMode();
        });
    </script>
</body>
</html>