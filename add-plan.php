<?php
// add-plan.php - Create/Edit Chit Fund Plans (With Daily Support)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

$edit_mode = isset($_GET['edit']) && is_numeric($_GET['edit']);
$plan_id = $edit_mode ? intval($_GET['edit']) : 0;
$plan_data = [];
$plan_details = [];

// Load existing plan data for editing
if ($edit_mode) {
    $stmt = $conn->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $plan_data = $result->fetch_assoc();
        
        // Load plan details - using period_number instead of month_number
        $stmt_details = $conn->prepare("SELECT * FROM plan_details WHERE plan_id = ? ORDER BY period_number ASC");
        $stmt_details->bind_param("i", $plan_id);
        $stmt_details->execute();
        $result_details = $stmt_details->get_result();
        
        while ($row = $result_details->fetch_assoc()) {
            $plan_details[] = $row;
        }
        $stmt_details->close();
    } else {
        $_SESSION['error'] = "Plan not found.";
        header("Location: manage-plans.php");
        exit;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title'] ?? '');
    $plan_type = $_POST['plan_type'] ?? 'monthly'; // monthly, weekly, daily
    $total_months = intval($_POST['total_months'] ?? 0);
    $monthly_installment = floatval($_POST['monthly_installment'] ?? 0);
    $total_received_amount = floatval($_POST['total_received_amount'] ?? 0);
    $weekly_installment = floatval($_POST['weekly_installment'] ?? 0);
    $daily_installment = floatval($_POST['daily_installment'] ?? 0);
    
    // Validation
    $errors = [];
    if (empty($title)) $errors[] = "Plan title is required.";
    if ($total_months <= 0) $errors[] = "Total months must be greater than 0.";
    if ($monthly_installment <= 0) $errors[] = "Monthly installment must be greater than 0.";
    if ($total_received_amount <= 0) $errors[] = "Total received amount must be greater than 0.";
    
    // Check if weekly/daily amounts are provided
    if ($weekly_installment < 0) $errors[] = "Weekly installment cannot be negative.";
    if ($daily_installment < 0) $errors[] = "Daily installment cannot be negative.";
    
    if (!empty($errors)) {
        $_SESSION['error'] = "• " . implode("<br>• ", $errors);
        header("Location: add-plan.php" . ($edit_mode ? "?edit=$plan_id" : ""));
        exit;
    }
    
    // Handle plan details based on plan type
    $period_details = [];
    $period_type = $plan_type == 'weekly' ? 'week' : ($plan_type == 'daily' ? 'day' : 'month');
    
    for ($i = 1; $i <= $total_months; $i++) {
        $installment_key = "month_{$i}_installment";
        $withdrawal_key = "month_{$i}_withdrawal";
        
        $installment = isset($_POST[$installment_key]) ? floatval($_POST[$installment_key]) : $monthly_installment;
        $withdrawal = isset($_POST[$withdrawal_key]) ? floatval($_POST[$withdrawal_key]) : 0;
        
        $period_details[] = [
            'period' => $i,
            'installment' => $installment,
            'withdrawal' => $withdrawal,
            'weekly_amount' => $weekly_installment,
            'daily_amount' => $daily_installment,
            'period_type' => $period_type
        ];
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Save or update plan
        if ($edit_mode) {
            $stmt = $conn->prepare("UPDATE plans SET 
                title = ?, 
                plan_type = ?,
                total_months = ?, 
                monthly_installment = ?, 
                total_received_amount = ?,
                weekly_installment = ?,
                daily_installment = ?,
                period_type = ?
                WHERE id = ?");
            
            $stmt->bind_param("ssiddddsi", 
                $title,
                $plan_type,
                $total_months,
                $monthly_installment,
                $total_received_amount,
                $weekly_installment,
                $daily_installment,
                $period_type,
                $plan_id
            );
        } else {
            $stmt = $conn->prepare("INSERT INTO plans 
                (title, plan_type, total_months, monthly_installment, total_received_amount, weekly_installment, daily_installment, period_type) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("ssidddds", 
                $title,
                $plan_type,
                $total_months,
                $monthly_installment,
                $total_received_amount,
                $weekly_installment,
                $daily_installment,
                $period_type
            );
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save plan: " . $stmt->error);
        }
        
        $plan_id = $edit_mode ? $plan_id : $conn->insert_id;
        $stmt->close();
        
        // Delete existing plan details
        $conn->query("DELETE FROM plan_details WHERE plan_id = $plan_id");
        
        // Insert new plan details - using period_number column (not month_number)
        $stmt_detail = $conn->prepare("INSERT INTO plan_details 
            (plan_id, period_number, installment, withdrawal_eligible, weekly_amount, daily_amount, period_type, is_collection_day) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        
        foreach ($period_details as $detail) {
            $stmt_detail->bind_param("iidddds",
                $plan_id,
                $detail['period'],
                $detail['installment'],
                $detail['withdrawal'],
                $detail['weekly_amount'],
                $detail['daily_amount'],
                $detail['period_type']
            );
            
            if (!$stmt_detail->execute()) {
                throw new Exception("Failed to save plan details: " . $stmt_detail->error);
            }
        }
        
        $stmt_detail->close();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = $edit_mode ? "Plan updated successfully!" : "Plan created successfully!";
        header("Location: manage-plans.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        header("Location: add-plan.php" . ($edit_mode ? "?edit=$plan_id" : ""));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .plan-type-card {
        cursor: pointer;
        transition: all 0.3s;
        border: 2px solid #dee2e6;
        padding: 15px;
        border-radius: 8px;
        text-align: center;
        height: 100%;
    }
    .plan-type-card:hover {
        border-color: #0d6efd;
        background-color: #f8f9fa;
    }
    .plan-type-card.active {
        border-color: #0d6efd;
        background-color: #e7f1ff;
    }
    .plan-type-card input[type="radio"] {
        margin-right: 5px;
    }
    .plan-details-container {
        max-height: 500px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }
    .detail-row {
        padding: 10px;
        border-bottom: 1px solid #eee;
        transition: background-color 0.2s;
    }
    .detail-row:hover {
        background-color: #f8f9fa;
    }
    .detail-row:last-child {
        border-bottom: none;
    }
    .summary-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    .summary-item {
        background: rgba(255, 255, 255, 0.1);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 10px;
    }
    .highlight {
        border-left: 4px solid #0d6efd;
        background: #e7f1ff !important;
    }
    .fixed-amounts-card {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        border-left: 4px solid #198754;
    }
    .section-title {
        border-bottom: 2px solid #0d6efd;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }
    .badge-weekly {
        background-color: #198754;
        color: white;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 11px;
        margin-left: 8px;
    }
    .badge-daily {
        background-color: #ffc107;
        color: black;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 11px;
        margin-left: 8px;
    }
    .period-indicator {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
        background-color: #6c757d;
        color: white;
        margin-left: 8px;
    }
    .per-period-amount {
        font-size: 11px;
        color: #0d6efd;
        margin-top: 2px;
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
                $page_title = $edit_mode ? "Edit Plan" : "Add New Plan";
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
                        <h3 class="mb-0"><?= $edit_mode ? 'Edit Chit Fund Plan' : 'Create New Chit Fund Plan'; ?></h3>
                        <small class="text-muted">Define plan structure with monthly, weekly, and daily options</small>
                    </div>
                    <div class="col-auto">
                        <a href="manage-plans.php" class="btn btn-light">Back to Plans</a>
                    </div>
                </div>
                
                <form id="planForm" action="add-plan.php<?= $edit_mode ? '?edit=' . $plan_id : ''; ?>" method="POST" novalidate>
                    
                    <!-- Plan Type Selection -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4 class="card-title mb-0">Plan Type Selection</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="plan-type-card <?= (!isset($plan_data['plan_type']) || $plan_data['plan_type'] == 'monthly') ? 'active' : '' ?>" onclick="selectPlanType('monthly')">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="plan_type" id="plan_type_monthly" value="monthly" <?= (!isset($plan_data['plan_type']) || $plan_data['plan_type'] == 'monthly') ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-bold" for="plan_type_monthly">Monthly Plan</label>
                                        </div>
                                        <p class="text-muted small mb-0">Standard monthly installments</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="plan-type-card <?= (isset($plan_data['plan_type']) && $plan_data['plan_type'] == 'weekly') ? 'active' : '' ?>" onclick="selectPlanType('weekly')">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="plan_type" id="plan_type_weekly" value="weekly" <?= (isset($plan_data['plan_type']) && $plan_data['plan_type'] == 'weekly') ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-bold" for="plan_type_weekly">Weekly Plan <span class="badge-weekly">4 weeks/month</span></label>
                                        </div>
                                        <p class="text-muted small mb-0">Weekly installments (4 weeks per month)</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="plan-type-card <?= (isset($plan_data['plan_type']) && $plan_data['plan_type'] == 'daily') ? 'active' : '' ?>" onclick="selectPlanType('daily')">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="plan_type" id="plan_type_daily" value="daily" <?= (isset($plan_data['plan_type']) && $plan_data['plan_type'] == 'daily') ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-bold" for="plan_type_daily">Daily Plan <span class="badge-daily">30 days/month</span></label>
                                        </div>
                                        <p class="text-muted small mb-0">Daily installments (30 days per month)</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Basic Plan Information -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4 class="card-title mb-0">Basic Plan Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Plan Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="title" id="plan_title"
                                           value="<?= $edit_mode ? htmlspecialchars($plan_data['title']) : (isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''); ?>"
                                           placeholder="e.g., 1 Lakh - 10 Months" required>
                                    <small class="text-muted plan-title-hint" id="titleHint">Descriptive name for the plan</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" id="monthsLabel">Total Months <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="total_months" id="total_months" 
                                           min="1" max="60"
                                           value="<?= $edit_mode ? $plan_data['total_months'] : (isset($_POST['total_months']) ? htmlspecialchars($_POST['total_months']) : '10'); ?>" 
                                           required onchange="generateMonthlyDetails()">
                                    <small class="text-muted" id="monthsHint">Duration in months</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" id="installmentLabel">Base Monthly Installment <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" class="form-control" name="monthly_installment" id="monthly_installment" 
                                               step="0.01" min="1"
                                               value="<?= $edit_mode ? $plan_data['monthly_installment'] : (isset($_POST['monthly_installment']) ? htmlspecialchars($_POST['monthly_installment']) : '7300'); ?>" 
                                               required oninput="updateMonthlyInstallments()">
                                    </div>
                                    <small class="text-muted" id="installmentHint">Standard monthly payment amount</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Total Received Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" class="form-control" name="total_received_amount" id="total_received_amount" 
                                               step="0.01" min="1"
                                               value="<?= $edit_mode ? $plan_data['total_received_amount'] : (isset($_POST['total_received_amount']) ? htmlspecialchars($_POST['total_received_amount']) : '89500'); ?>" 
                                               required oninput="updateSummary()">
                                    </div>
                                    <small class="text-muted">Total amount member will receive</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fixed Amounts for Different Frequencies -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4 class="card-title mb-0">Fixed Amounts for Different Payment Frequencies</h4>
                            <small class="text-muted">These amounts will be used when members choose weekly or daily payment mode</small>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="fixed-amounts-card">
                                        <h6 class="mb-3">Weekly Installment</h6>
                                        <div class="input-group mb-3">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" class="form-control" name="weekly_installment" id="weekly_installment"
                                                   step="0.01" min="0"
                                                   value="<?= $edit_mode ? $plan_data['weekly_installment'] : (isset($_POST['weekly_installment']) ? htmlspecialchars($_POST['weekly_installment']) : '2250'); ?>"
                                                   oninput="updateSummary()">
                                        </div>
                                        <small class="text-muted">
                                            Fixed weekly amount (4 weeks per month).<br>
                                            If 0, system will calculate automatically.
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="fixed-amounts-card">
                                        <h6 class="mb-3">Daily Installment</h6>
                                        <div class="input-group mb-3">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" class="form-control" name="daily_installment" id="daily_installment"
                                                   step="0.01" min="0"
                                                   value="<?= $edit_mode ? $plan_data['daily_installment'] : (isset($_POST['daily_installment']) ? htmlspecialchars($_POST['daily_installment']) : '360'); ?>"
                                                   oninput="updateSummary()">
                                        </div>
                                        <small class="text-muted">
                                            Fixed daily amount (30 days per month).<br>
                                            If 0, system will calculate automatically.
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-card">
                                        <h6 class="mb-3 text-white">Quick Calculation</h6>
                                        <div class="summary-item">
                                            <small class="text-white">Monthly: <span id="calc_monthly">₹7,300.00</span></small><br>
                                            <small class="text-white">Weekly: <span id="calc_weekly">₹1,825.00</span></small><br>
                                            <small class="text-white">Daily: <span id="calc_daily">₹243.33</span></small>
                                        </div>
                                        <small class="text-white">Calculated based on monthly amount</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Plan Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4 class="card-title mb-0">
                                <span id="detailsTitle">Monthly Plan Details</span>
                                <small class="text-muted" id="detailsSubtitle">(Optional - Monthly installment variations)</small>
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <span id="detailsInfo">You can specify different monthly installment amounts and withdrawal eligibility amounts for each month. If left blank, the base monthly installment will be used for all months.</span>
                            </div>
                            
                            <div id="plan_details_container">
                                <div class="plan-details-container">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th width="100"><span id="periodHeader">Month #</span></th>
                                                    <th><span id="installmentHeader">Monthly Installment (₹)</span></th>
                                                    <th>Withdrawal Eligible (₹)</th>
                                                </tr>
                                            </thead>
                                            <tbody id="monthly_details_body">
                                                <!-- Rows will be generated by JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="fill_same_amounts">
                                    <i class="fas fa-copy me-1"></i>Fill all with same amount
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="clear_details">
                                    <i class="fas fa-eraser me-1"></i>Clear all
                                </button>
                                <span class="float-end" id="total_periods_info">
                                    Total Periods: <strong id="total_periods_count">10</strong>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Plan Summary -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4 class="card-title mb-0">Plan Summary</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <strong>Plan Overview:</strong>
                                        <ul class="mt-2">
                                            <li id="summary_title">Title: <span class="text-muted">Not specified</span></li>
                                            <li id="summary_duration">Duration: <span class="text-muted">0 months</span></li>
                                            <li id="summary_monthly">Monthly: <span class="text-muted">₹0.00</span></li>
                                            <li id="summary_weekly">Weekly: <span class="text-muted">₹0.00</span></li>
                                            <li id="summary_daily">Daily: <span class="text-muted">₹0.00</span></li>
                                            <li id="summary_total">Total Payout: <span class="text-muted">₹0.00</span></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Important:</strong> Once a plan is created and members are enrolled, 
                                        you cannot change the total months or payment structure significantly.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <a href="manage-plans.php" class="btn btn-light btn-lg px-4">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary btn-lg px-4">
                                    <i class="fas fa-save me-2"></i>
                                    <?= $edit_mode ? 'Update Plan' : 'Create Plan'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
    
    <?php include 'includes/scripts.php'; ?>
    <script>
        let currentPlanType = '<?= isset($plan_data['plan_type']) ? $plan_data['plan_type'] : 'monthly' ?>';
        let existingDetails = <?= json_encode($plan_details) ?>;
        
        function selectPlanType(type) {
            currentPlanType = type;
            
            // Update UI
            document.querySelectorAll('.plan-type-card').forEach(card => {
                card.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Update radio buttons
            document.getElementById('plan_type_' + type).checked = true;
            
            // Update labels based on plan type
            updateLabelsForType(type);
            
            // Regenerate details
            generateMonthlyDetails();
        }
        
        function updateLabelsForType(type) {
            const titleHint = document.getElementById('titleHint');
            const monthsLabel = document.getElementById('monthsLabel');
            const monthsHint = document.getElementById('monthsHint');
            const installmentLabel = document.getElementById('installmentLabel');
            const installmentHint = document.getElementById('installmentHint');
            const detailsTitle = document.getElementById('detailsTitle');
            const detailsSubtitle = document.getElementById('detailsSubtitle');
            const detailsInfo = document.getElementById('detailsInfo');
            const periodHeader = document.getElementById('periodHeader');
            const installmentHeader = document.getElementById('installmentHeader');
            
            if (type === 'weekly') {
                titleHint.textContent = 'e.g., 1 Lakh - 52 Weeks Plan';
                monthsLabel.innerHTML = 'Total Months <span class="text-danger">*</span>';
                monthsHint.textContent = 'Duration in months (1-60) - Each month has 4 weeks';
                installmentLabel.innerHTML = 'Base Weekly Installment <span class="text-danger">*</span>';
                installmentHint.textContent = 'Amount per week (4 weeks per month)';
                detailsTitle.textContent = 'Weekly Plan Details';
                detailsSubtitle.textContent = '(Optional - Weekly variations per month)';
                detailsInfo.textContent = 'You can specify different weekly installment amounts for each month. The weekly amount will be divided across 4 weeks.';
                periodHeader.textContent = 'Month #';
                installmentHeader.textContent = 'Monthly Target (₹)';
            } else if (type === 'daily') {
                titleHint.textContent = 'e.g., 1 Lakh - 300 Days Plan';
                monthsLabel.innerHTML = 'Total Months <span class="text-danger">*</span>';
                monthsHint.textContent = 'Duration in months (1-60) - Each month has 30 days';
                installmentLabel.innerHTML = 'Base Daily Installment <span class="text-danger">*</span>';
                installmentHint.textContent = 'Amount per day (30 days per month)';
                detailsTitle.textContent = 'Daily Plan Details';
                detailsSubtitle.textContent = '(Optional - Daily variations per month)';
                detailsInfo.textContent = 'You can specify different daily installment amounts for each month. The daily amount will be applied for 30 days per month.';
                periodHeader.textContent = 'Month #';
                installmentHeader.textContent = 'Monthly Target (₹)';
            } else {
                titleHint.textContent = 'e.g., 1 Lakh - 10 Months Plan';
                monthsLabel.innerHTML = 'Total Months <span class="text-danger">*</span>';
                monthsHint.textContent = 'Duration of the chit fund in months (1-60)';
                installmentLabel.innerHTML = 'Base Monthly Installment <span class="text-danger">*</span>';
                installmentHint.textContent = 'Standard monthly payment amount';
                detailsTitle.textContent = 'Monthly Plan Details';
                detailsSubtitle.textContent = '(Optional - Monthly installment variations)';
                detailsInfo.textContent = 'You can specify different monthly installment amounts and withdrawal eligibility amounts for each month. If left blank, the base monthly installment will be used for all months.';
                periodHeader.textContent = 'Month #';
                installmentHeader.textContent = 'Monthly Installment (₹)';
            }
        }
        
        // Generate monthly details rows
        function generateMonthlyDetails() {
            const totalMonths = parseInt(document.getElementById('total_months').value) || 10;
            const monthlyAmount = parseFloat(document.getElementById('monthly_installment').value) || 7300;
            const container = document.getElementById('monthly_details_body');
            
            let html = '';
            for (let i = 1; i <= totalMonths; i++) {
                let installment = monthlyAmount;
                let withdrawal = 0;
                
                <?php if ($edit_mode && !empty($plan_details)): ?>
                    // Pre-fill with existing data
                    const existingMonth = <?= json_encode(array_column($plan_details, null, 'period_number')); ?>;
                    if (existingMonth[i]) {
                        installment = existingMonth[i].installment;
                        withdrawal = existingMonth[i].withdrawal_eligible;
                    }
                <?php endif; ?>
                
                // Calculate per-period amount based on plan type
                let perPeriodText = '';
                if (currentPlanType === 'weekly') {
                    perPeriodText = `<div class="per-period-amount">₹${(installment/4).toFixed(2)} per week</div>`;
                } else if (currentPlanType === 'daily') {
                    perPeriodText = `<div class="per-period-amount">₹${(installment/30).toFixed(2)} per day</div>`;
                }
                
                // Period indicator
                let periodIndicator = '';
                if (currentPlanType === 'weekly') {
                    periodIndicator = `<span class="period-indicator">4 weeks</span>`;
                } else if (currentPlanType === 'daily') {
                    periodIndicator = `<span class="period-indicator">30 days</span>`;
                }
                
                html += `
                <tr class="detail-row ${i === 1 ? 'highlight' : ''}">
                    <td class="align-middle">
                        <strong>Month ${i}</strong> ${periodIndicator}
                    </td>
                    <td>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control monthly-installment" 
                                   name="month_${i}_installment" 
                                   step="0.01" min="0"
                                   value="${installment}"
                                   onchange="updateInstallment(this, ${i})">
                        </div>
                        ${perPeriodText}
                    </td>
                    <td>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control withdrawal-amount" 
                                   name="month_${i}_withdrawal" 
                                   step="0.01" min="0"
                                   value="${withdrawal}"
                                   onchange="updateSummary()">
                        </div>
                    </td>
                </tr>
                `;
            }
            
            container.innerHTML = html;
            document.getElementById('total_periods_count').textContent = totalMonths + ' months';
            updateSummary();
        }
        
        function updateInstallment(input, month) {
            const value = parseFloat(input.value) || 0;
            const perPeriodDiv = input.closest('td').querySelector('.per-period-amount');
            
            if (perPeriodDiv) {
                if (currentPlanType === 'weekly') {
                    perPeriodDiv.textContent = `₹${(value/4).toFixed(2)} per week`;
                } else if (currentPlanType === 'daily') {
                    perPeriodDiv.textContent = `₹${(value/30).toFixed(2)} per day`;
                }
            }
            
            updateSummary();
        }
        
        function updateMonthlyInstallments() {
            const baseAmount = parseFloat(document.getElementById('monthly_installment').value) || 0;
            document.querySelectorAll('.monthly-installment').forEach(input => {
                input.value = baseAmount;
                const perPeriodDiv = input.closest('td').querySelector('.per-period-amount');
                if (perPeriodDiv) {
                    if (currentPlanType === 'weekly') {
                        perPeriodDiv.textContent = `₹${(baseAmount/4).toFixed(2)} per week`;
                    } else if (currentPlanType === 'daily') {
                        perPeriodDiv.textContent = `₹${(baseAmount/30).toFixed(2)} per day`;
                    }
                }
            });
            updateSummary();
        }
        
        // Update calculation summary
        function updateSummary() {
            const title = document.getElementById('plan_title').value || 'Not specified';
            const totalMonths = parseInt(document.getElementById('total_months').value) || 0;
            const monthlyAmount = parseFloat(document.getElementById('monthly_installment').value) || 0;
            const weeklyAmount = parseFloat(document.getElementById('weekly_installment').value) || 0;
            const dailyAmount = parseFloat(document.getElementById('daily_installment').value) || 0;
            const totalAmount = parseFloat(document.getElementById('total_received_amount').value) || 0;
            
            // Calculate based on plan type
            let weeklyCalc, dailyCalc, monthlyCalc;
            
            if (currentPlanType === 'weekly') {
                monthlyCalc = monthlyAmount;
                weeklyCalc = weeklyAmount > 0 ? weeklyAmount : (monthlyAmount / 4).toFixed(2);
                dailyCalc = (weeklyCalc / 7).toFixed(2);
            } else if (currentPlanType === 'daily') {
                monthlyCalc = monthlyAmount;
                dailyCalc = dailyAmount > 0 ? dailyAmount : (monthlyAmount / 30).toFixed(2);
                weeklyCalc = (dailyCalc * 7).toFixed(2);
            } else {
                monthlyCalc = monthlyAmount;
                weeklyCalc = weeklyAmount > 0 ? weeklyAmount : (monthlyAmount / 4).toFixed(2);
                dailyCalc = dailyAmount > 0 ? dailyAmount : (monthlyAmount / 30).toFixed(2);
            }
            
            // Update summary display
            document.getElementById('summary_title').innerHTML = `Title: <span class="text-muted">${title}</span>`;
            document.getElementById('summary_duration').innerHTML = `Duration: <span class="text-muted">${totalMonths} months</span>`;
            document.getElementById('summary_monthly').innerHTML = `Monthly: <span class="text-muted">₹${monthlyCalc.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>`;
            document.getElementById('summary_weekly').innerHTML = `Weekly: <span class="text-muted">₹${parseFloat(weeklyCalc).toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>`;
            document.getElementById('summary_daily').innerHTML = `Daily: <span class="text-muted">₹${parseFloat(dailyCalc).toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>`;
            document.getElementById('summary_total').innerHTML = `Total Payout: <span class="text-muted">₹${totalAmount.toLocaleString('en-In', {minimumFractionDigits: 2})}</span>`;
            
            // Update quick calculation
            document.getElementById('calc_monthly').textContent = `₹${monthlyCalc.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
            document.getElementById('calc_weekly').textContent = `₹${parseFloat(weeklyCalc).toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
            document.getElementById('calc_daily').textContent = `₹${parseFloat(dailyCalc).toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
        }
        
        // Fill all with same amounts
        document.getElementById('fill_same_amounts').addEventListener('click', function() {
            const baseAmount = parseFloat(document.getElementById('monthly_installment').value) || 0;
            const withdrawalAmount = parseFloat(prompt("Enter withdrawal eligible amount for all months (₹):", "0")) || 0;
            
            if (baseAmount > 0) {
                const installmentInputs = document.querySelectorAll('.monthly-installment');
                const withdrawalInputs = document.querySelectorAll('.withdrawal-amount');
                
                installmentInputs.forEach((input, index) => {
                    input.value = baseAmount;
                    const perPeriodDiv = input.closest('td').querySelector('.per-period-amount');
                    if (perPeriodDiv) {
                        if (currentPlanType === 'weekly') {
                            perPeriodDiv.textContent = `₹${(baseAmount/4).toFixed(2)} per week`;
                        } else if (currentPlanType === 'daily') {
                            perPeriodDiv.textContent = `₹${(baseAmount/30).toFixed(2)} per day`;
                        }
                    }
                });
                
                withdrawalInputs.forEach(input => {
                    input.value = withdrawalAmount;
                });
                
                updateSummary();
                
                let periodType = 'months';
                if (currentPlanType === 'weekly') periodType = 'months (weekly)';
                else if (currentPlanType === 'daily') periodType = 'months (daily)';
                
                alert(`All ${periodType} set to ₹${baseAmount.toFixed(2)} monthly target with ₹${withdrawalAmount.toFixed(2)} withdrawal eligibility.`);
            }
        });
        
        // Clear all details
        document.getElementById('clear_details').addEventListener('click', function() {
            if (confirm('Are you sure you want to clear all monthly details?')) {
                const installmentInputs = document.querySelectorAll('.monthly-installment');
                const withdrawalInputs = document.querySelectorAll('.withdrawal-amount');
                
                installmentInputs.forEach(input => {
                    input.value = '';
                    const perPeriodDiv = input.closest('td').querySelector('.per-period-amount');
                    if (perPeriodDiv) {
                        if (currentPlanType === 'weekly') {
                            perPeriodDiv.textContent = `₹0.00 per week`;
                        } else if (currentPlanType === 'daily') {
                            perPeriodDiv.textContent = `₹0.00 per day`;
                        }
                    }
                });
                
                withdrawalInputs.forEach(input => {
                    input.value = '';
                });
                
                updateSummary();
            }
        });
        
        // Event listeners
        document.getElementById('total_months').addEventListener('change', generateMonthlyDetails);
        document.getElementById('monthly_installment').addEventListener('input', updateMonthlyInstallments);
        document.getElementById('weekly_installment').addEventListener('input', updateSummary);
        document.getElementById('daily_installment').addEventListener('input', updateSummary);
        document.getElementById('total_received_amount').addEventListener('input', updateSummary);
        document.getElementById('plan_title').addEventListener('input', updateSummary);
        
        // Initialize
        window.addEventListener('DOMContentLoaded', function() {
            generateMonthlyDetails();
            updateSummary();
        });
    </script>
</body>
</html>