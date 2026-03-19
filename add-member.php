<?php
// add-member.php - UPDATED WITH BID WINNER SITE NUMBER
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';
$edit_mode = isset($_GET['edit']) && is_numeric($_GET['edit']);
$edit_member_id = $edit_mode ? intval($_GET['edit']) : 0;

// Fetch all plans
$plans = [];
$result_plans = $conn->query("SELECT id, title, total_months, monthly_installment FROM plans ORDER BY title ASC");
if ($result_plans) {
    while ($row = $result_plans->fetch_assoc()) {
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
    $emi_dates = isset($_POST['emi_due_dates']) ? $_POST['emi_due_dates'] : [];
    $bid_winner_site_number = trim($_POST['bid_winner_site_number'] ?? ''); // NEW FIELD

    // Validation
    $errors = [];
    if ($plan_id <= 0) $errors[] = "Please select a Plan.";
    if (empty($agreement_number)) $errors[] = "Agreement Number is required.";
    if (empty($customer_name)) $errors[] = "Customer Name is required.";
    if (empty($customer_number)) $errors[] = "Customer Phone is required.";
    if (empty($nominee_name)) $errors[] = "Nominee Name is required.";
    if (empty($nominee_number)) $errors[] = "Nominee Phone is required.";
    if (empty($emi_date)) $errors[] = "Agreement Date is required.";
    if (empty($first_emi_date)) $errors[] = "First EMI Date is required.";

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
        $_SESSION['error'] = "First EMI date must be on or after agreement date.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }

    // Get plan details
    $total_periods = 0;
    $monthly_installment = 0;
    $is_weekly = false;
    foreach ($plans as $p) {
        if ($p['id'] == $plan_id) {
            $total_periods = $p['total_months'];
            $monthly_installment = $p['monthly_installment'] ?? 0;
            // Check if it's a weekly plan
            $is_weekly = (strpos($p['title'], 'Weekly') !== false || strpos($p['title'], 'Weeks') !== false);
            break;
        }
    }
    
    if ($total_periods == 0) {
        $_SESSION['error'] = "Invalid plan selected.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
    }

    // Fetch installments
    $installments = [];
    if ($is_weekly) {
        // For weekly plans, fetch all installments (they're stored as weeks in month_number)
        $stmt_inst = $conn->prepare("SELECT installment FROM plan_details WHERE plan_id = ? ORDER BY month_number ASC");
    } else {
        // For monthly plans
        $stmt_inst = $conn->prepare("SELECT installment FROM plan_details WHERE plan_id = ? ORDER BY month_number ASC");
    }
    
    $stmt_inst->bind_param("i", $plan_id);
    $stmt_inst->execute();
    $res_inst = $stmt_inst->get_result();
    while ($row = $res_inst->fetch_assoc()) {
        $installments[] = $row['installment'];
    }
    $stmt_inst->close();

    if (count($installments) != $total_periods) {
        $_SESSION['error'] = "Plan installment data incomplete.";
        header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
        exit;
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
    $bid_site = !empty($bid_winner_site_number) ? $bid_winner_site_number : $null; // NEW FIELD

    // Save or update member
    if ($edit_mode) {
        // UPDATE: 15 fields + id = 16 parameters (added bid_winner_site_number)
        $sql = "UPDATE members SET
                agreement_number = ?, customer_name = ?, customer_number = ?, customer_number2 = ?,
                nominee_name = ?, nominee_number = ?, customer_aadhar = ?, nominee_aadhar = ?, customer_address = ?,
                customer_photo = ?, aadhar_photo = ?, plan_id = ?, monthly_installment = ?, emi_date = ?,
                bid_winner_site_number = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $_SESSION['error'] = "Prepare failed: " . $conn->error;
            header("Location: add-member.php" . ($edit_mode ? "?edit=$edit_member_id" : ""));
            exit;
        }
        
        // Type string: "sssssssssssidsi" (12s + i + d + s + i = 16 characters)
        $stmt->bind_param(
            "sssssssssssidssi",
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
            $emi_date,
            $bid_site,
            $edit_member_id
        );
    } else {
        // INSERT: 16 fields (including document_charge as hardcoded 0.00)
        $sql = "INSERT INTO members
                (agreement_number, customer_name, customer_number, customer_number2,
                 nominee_name, nominee_number, customer_aadhar, nominee_aadhar, customer_address,
                 customer_photo, aadhar_photo, plan_id, monthly_installment, emi_date, 
                 bid_winner_site_number, document_charge)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $_SESSION['error'] = "Prepare failed: " . $conn->error;
            header("Location: add-member.php");
            exit;
        }
        
        // INSERT has 15 parameters (16th is hardcoded 0.00)
        // Type string: "sssssssssssidss" (12s + i + d + s + s = 15 characters)
        $stmt->bind_param(
            "sssssssssssidss",
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
            $emi_date,
            $bid_site
        );
    }
    
    if ($stmt->execute()) {
        $member_id = $edit_mode ? $edit_member_id : $conn->insert_id;
        
        // Rebuild EMI schedule
        $conn->query("DELETE FROM emi_schedule WHERE member_id = $member_id AND status = 'unpaid'");
        
        $stmt_emi = $conn->prepare("INSERT INTO emi_schedule (member_id, plan_id, emi_amount, emi_due_date, status)
                                    VALUES (?, ?, ?, ?, 'unpaid')");
        
        for ($i = 0; $i < $total_periods; $i++) {
            if ($is_weekly) {
                // For weekly plans, add weeks instead of months
                $due_date = $edit_mode && isset($emi_dates[$i]) ? $emi_dates[$i] : date('Y-m-d', strtotime($first_emi_date . " +$i weeks"));
            } else {
                // For monthly plans
                $due_date = $edit_mode && isset($emi_dates[$i]) ? $emi_dates[$i] : date('Y-m-d', strtotime($first_emi_date . " +$i months"));
            }
            $amount = $installments[$i];
            $stmt_emi->bind_param("iids", $member_id, $plan_id, $amount, $due_date);
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
                        <small class="text-muted">Fill member details and select chit plan</small>
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
                        <form id="memberForm" action="add-member.php<?= $edit_mode ? '?edit=' . $edit_member_id : ''; ?>" method="POST" enctype="multipart/form-data" novalidate>
                            <!-- Customer & Nominee -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <h5 class="mb-3">Customer Details</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="customer_name" value="<?= $edit_mode ? htmlspecialchars($member_data['customer_name']) : (isset($_POST['customer_name']) ? htmlspecialchars($_POST['customer_name']) : ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="customer_number" value="<?= $edit_mode ? htmlspecialchars($member_data['customer_number']) : (isset($_POST['customer_number']) ? htmlspecialchars($_POST['customer_number']) : ''); ?>" required maxlength="10" pattern="\d{10}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Alternate Phone (optional)</label>
                                        <input type="text" class="form-control" name="customer_number2" value="<?= $edit_mode ? htmlspecialchars($member_data['customer_number2'] ?? '') : (isset($_POST['customer_number2']) ? htmlspecialchars($_POST['customer_number2']) : ''); ?>" maxlength="10" pattern="\d{10}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Aadhar (optional)</label>
                                        <input type="text" class="form-control" name="customer_aadhar" value="<?= $edit_mode ? htmlspecialchars($member_data['customer_aadhar'] ?? '') : (isset($_POST['customer_aadhar']) ? htmlspecialchars($_POST['customer_aadhar']) : ''); ?>" maxlength="12">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Address (optional)</label>
                                        <textarea class="form-control" name="customer_address" rows="3"><?= $edit_mode ? htmlspecialchars($member_data['customer_address'] ?? '') : (isset($_POST['customer_address']) ? htmlspecialchars($_POST['customer_address']) : ''); ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Customer Photo (optional)</label>
                                        <input type="file" class="form-control" name="customer_photo" accept="image/*">
                                        <?php if ($edit_mode && !empty($member_data['customer_photo'])): ?>
                                            <small><a href="<?= $member_data['customer_photo']; ?>" target="_blank">View Current</a></small>
                                        <?php endif; ?>
                                    </div>
                                    <!-- NEW FIELD: Bid Winner Site Number -->
                                    <div class="mb-3">
                                        <label class="form-label">Bid Winner Seat Number (optional)</label>
                                        <input type="text" class="form-control" name="bid_winner_site_number" 
                                               value="<?= $edit_mode ? htmlspecialchars($member_data['bid_winner_site_number'] ?? '') : (isset($_POST['bid_winner_site_number']) ? htmlspecialchars($_POST['bid_winner_site_number']) : ''); ?>"
                                               placeholder="e.g., Site-001, Plot-123">
                                        <small class="text-muted">Site/Plot number if this member is a bid winner</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5 class="mb-3">Nominee Details</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Nominee Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="nominee_name" value="<?= $edit_mode ? htmlspecialchars($member_data['nominee_name']) : (isset($_POST['nominee_name']) ? htmlspecialchars($_POST['nominee_name']) : ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nominee Phone <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="nominee_number" value="<?= $edit_mode ? htmlspecialchars($member_data['nominee_number']) : (isset($_POST['nominee_number']) ? htmlspecialchars($_POST['nominee_number']) : ''); ?>" required maxlength="10" pattern="\d{10}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nominee Aadhar (optional)</label>
                                        <input type="text" class="form-control" name="nominee_aadhar" value="<?= $edit_mode ? htmlspecialchars($member_data['nominee_aadhar'] ?? '') : (isset($_POST['nominee_aadhar']) ? htmlspecialchars($_POST['nominee_aadhar']) : ''); ?>" maxlength="12">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Aadhar Photo (optional)</label>
                                        <input type="file" class="form-control" name="aadhar_photo" accept="image/*">
                                        <?php if ($edit_mode && !empty($member_data['aadhar_photo'])): ?>
                                            <small><a href="<?= $member_data['aadhar_photo']; ?>" target="_blank">View Current</a></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Plan Details -->
                            <h5 class="mb-3">Plan & Agreement Details</h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Select Plan <span class="text-danger">*</span></label>
                                    <select class="form-control" name="plan_id" id="plan_id" required>
                                        <option value="">-- Choose Plan --</option>
                                        <?php foreach ($plans as $plan): 
                                            $is_weekly_plan = (strpos($plan['title'], 'Weekly') !== false || strpos($plan['title'], 'Weeks') !== false);
                                            $period_type = $is_weekly_plan ? 'weeks' : 'months';
                                        ?>
                                            <option value="<?= $plan['id']; ?>" 
                                                    data-tenure="<?= $plan['total_months']; ?>"
                                                    data-period-type="<?= $period_type; ?>"
                                                    <?= ($plan_id == $plan['id']) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($plan['title']); ?> 
                                                (<?= $plan['total_months']; ?> <?= $period_type ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($plans)): ?>
                                        <small class="text-danger mt-1">No plans available. Please add plans first.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Application Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="agreement_number" value="<?= $edit_mode ? htmlspecialchars($member_data['agreement_number']) : (isset($_POST['agreement_number']) ? htmlspecialchars($_POST['agreement_number']) : ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Agreement Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="emi_date"
                                           value="<?= $edit_mode ? $member_data['emi_date'] : (isset($_POST['emi_date']) ? htmlspecialchars($_POST['emi_date']) : ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">First Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="first_emi_date" id="first_emi_date"
                                           value="<?= $edit_mode && !empty($emi_schedule) ? $emi_schedule[0]['emi_due_date'] : (isset($_POST['first_emi_date']) ? htmlspecialchars($_POST['first_emi_date']) : ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Plan Tenure</label>
                                    <input type="text" class="form-control" id="plan_tenure" readonly>
                                    <small class="text-muted" id="period_type_label"></small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Plan Type</label>
                                    <input type="text" class="form-control" id="plan_type" readonly>
                                </div>
                                
                                <div class="col-12 pt-3">
                                    <button type="submit" class="btn btn-primary"><?= $edit_mode ? 'Update Member' : 'Save Member'; ?></button>
                                    <a href="manage-members.php" class="btn btn-light">Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- EMI Schedule (Edit Mode Only) -->
                <?php if ($edit_mode && !empty($emi_schedule)): 
                    $is_weekly_plan_edit = (strpos($member_data['plan_title'] ?? '', 'Weekly') !== false || strpos($member_data['plan_title'] ?? '', 'Weeks') !== false);
                    $period_type_edit = $is_weekly_plan_edit ? 'weeks' : 'months';
                ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h4 class="card-title mb-0">Payment Schedule</h4>
                            <small>Edit due dates if needed (amounts are fixed by plan)</small>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Payment Amount (₹)</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($emi_schedule as $i => $emi): ?>
                                            <tr>
                                                <td><?= $i + 1; ?> (<?= $period_type_edit ?>)</td>
                                                <td><?= number_format($emi['emi_amount'], 2); ?></td>
                                                <td>
                                                    <input type="date" class="form-control" name="emi_due_dates[]" value="<?= $emi['emi_due_date']; ?>" <?= $emi['status'] == 'paid' ? 'readonly' : ''; ?> required>
                                                </td>
                                                <td><?= ucfirst($emi['status']); ?></td>
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
        document.getElementById('plan_id')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const tenure = selected.getAttribute('data-tenure') || '';
            const periodType = selected.getAttribute('data-period-type') || 'months';
            const planTitle = selected.text;
            
            document.getElementById('plan_tenure').value = tenure;
            document.getElementById('period_type_label').textContent = periodType;
            
            // Set plan type
            let planType = 'Monthly Plan';
            if (planTitle.includes('Weekly') || planTitle.includes('Weeks')) {
                planType = 'Weekly Plan';
            }
            document.getElementById('plan_type').value = planType;
            
            // Update label for first payment date
            const firstPaymentLabel = periodType === 'weeks' ? 'First Weekly Payment Date' : 'First EMI Date';
            document.querySelector('label[for="first_emi_date"]').textContent = firstPaymentLabel + ' *';
        });
        
        window.addEventListener('DOMContentLoaded', function() {
            const planSelect = document.getElementById('plan_id');
            if (planSelect) {
                const selected = planSelect.options[planSelect.selectedIndex];
                if (selected) {
                    const tenure = selected.getAttribute('data-tenure') || '';
                    const periodType = selected.getAttribute('data-period-type') || 'months';
                    const planTitle = selected.text;
                    
                    document.getElementById('plan_tenure').value = tenure;
                    document.getElementById('period_type_label').textContent = periodType;
                    
                    // Set plan type
                    let planType = 'Monthly Plan';
                    if (planTitle.includes('Weekly') || planTitle.includes('Weeks')) {
                        planType = 'Weekly Plan';
                    }
                    document.getElementById('plan_type').value = planType;
                    
                    // Update label for first payment date
                    const firstPaymentLabel = periodType === 'weeks' ? 'First Weekly Payment Date' : 'First EMI Date';
                    document.querySelector('label[for="first_emi_date"]').textContent = firstPaymentLabel + ' *';
                }
            }
        });
    </script>
</body>
</html>