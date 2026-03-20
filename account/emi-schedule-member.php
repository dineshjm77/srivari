<?php
// emi-schedule-member.php - COMPLETE WITH DAILY/WEEKLY/MONTHLY SUPPORT (FIXED NULL ISSUES)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

$member_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($member_id == 0) {
    $_SESSION['error'] = "Invalid member.";
    header("Location: manage-members.php");
    exit;
}

// Fetch member details
$sql_member = "SELECT m.*, p.title AS plan_title, p.total_months, p.total_periods, p.total_received_amount,
                      p.monthly_installment, p.weekly_installment, p.daily_installment, p.plan_type
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
    $_SESSION['error'] = "Member not found.";
    header("Location: manage-members.php");
    exit;
}

// Get payment mode details
$payment_mode = $member['payment_mode'] ?? 'monthly';
$plan_type = $member['plan_type'] ?? 'monthly';
$calculated_amount = $member['calculated_amount'] ?? 0;
$monthly_target = $member['monthly_installment'] ?? 0;

// Fetch EMI schedule with collector details
$sql_emi = "SELECT es.*, 
            u.username as collected_by_username,
            u.full_name as collected_by_name,
            u.role as collector_role
            FROM emi_schedule es
            LEFT JOIN users u ON es.collected_by = u.id
            WHERE es.member_id = ?
            ORDER BY es.emi_due_date ASC";
$stmt = $conn->prepare($sql_emi);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$emi_result = $stmt->get_result();

// Get all EMIs
$all_emis = [];
$total_installment = 0;
$total_collected = 0;
$paid_count = 0;
$unpaid_count = 0;
$next_unpaid_emi = null;

while ($row = $emi_result->fetch_assoc()) {
    $total_installment += $row['emi_amount'];
    if ($row['status'] === 'paid') {
        $total_collected += $row['emi_amount'];
        $paid_count++;
    } else {
        $unpaid_count++;
        if ($next_unpaid_emi === null && $row['emi_amount'] > 0) {
            $next_unpaid_emi = $row;
        }
    }
    $all_emis[] = $row;
}
$stmt->close();

// Get first EMI date
$first_emi_date = !empty($all_emis) ? $all_emis[0]['emi_due_date'] : date('Y-m-d');

// Group EMIs by payment mode
$grouped_emis = [];
$current_month = 1;
$week_count = 1;
$day_count = 1;

if ($payment_mode == 'monthly') {
    // Monthly payments - each EMI is a separate month
    foreach ($all_emis as $index => $emi) {
        if ($emi['emi_amount'] > 0) {
            $grouped_emis[] = [
                'period_number' => $current_month,
                'period_type' => 'month',
                'display_name' => 'Month ' . $current_month,
                'installments' => [$emi],
                'total_amount' => $emi['emi_amount'],
                'paid_amount' => $emi['status'] == 'paid' ? $emi['emi_amount'] : 0,
                'paid_count' => $emi['status'] == 'paid' ? 1 : 0,
                'due_date' => $emi['emi_due_date'],
                'calendar_month' => date('F Y', strtotime($emi['emi_due_date']))
            ];
            $current_month++;
        }
    }
} elseif ($payment_mode == 'weekly') {
    // Weekly payments - group by month (4 weeks per month)
    $month_emis = [];
    $month_start = null;
    $week_in_month = 0;
    $month_num = 1;
    
    foreach ($all_emis as $index => $emi) {
        if ($week_in_month == 0) {
            $month_start = $emi['emi_due_date'];
            $month_emis = [];
        }
        
        $month_emis[] = $emi;
        $week_in_month++;
        
        if ($week_in_month == 4 || $index == count($all_emis) - 1) {
            $total_month_amount = array_sum(array_column($month_emis, 'emi_amount'));
            $paid_month_amount = 0;
            $paid_week_count = 0;
            
            foreach ($month_emis as $week_emi) {
                if ($week_emi['status'] == 'paid') {
                    $paid_month_amount += $week_emi['emi_amount'];
                    $paid_week_count++;
                }
            }
            
            $grouped_emis[] = [
                'period_number' => $month_num,
                'period_type' => 'week',
                'display_name' => 'Month ' . $month_num,
                'installments' => $month_emis,
                'total_amount' => $total_month_amount,
                'paid_amount' => $paid_month_amount,
                'paid_count' => $paid_week_count,
                'due_date' => $month_start,
                'calendar_month' => date('F Y', strtotime($month_start))
            ];
            
            $month_num++;
            $week_in_month = 0;
            $month_emis = [];
        }
    }
} elseif ($payment_mode == 'daily') {
    // Daily payments - group by month (30 days per month)
    $days_per_month = 30;
    $total_months = ceil(count($all_emis) / $days_per_month);
    
    for ($month = 1; $month <= $total_months; $month++) {
        $start_index = ($month - 1) * $days_per_month;
        $month_emis = [];
        $collection_days = 0;
        $paid_amount = 0;
        $paid_days = 0;
        $month_start = null;
        
        for ($d = 0; $d < $days_per_month; $d++) {
            $index = $start_index + $d;
            if ($index >= count($all_emis)) break;
            
            $emi = $all_emis[$index];
            if ($month_start === null) {
                $month_start = $emi['emi_due_date'];
            }
            
            $month_emis[] = $emi;
            if ($emi['emi_amount'] > 0) {
                $collection_days++;
            }
            if ($emi['status'] == 'paid') {
                $paid_amount += $emi['emi_amount'];
                $paid_days++;
            }
        }
        
        if (!empty($month_emis)) {
            $total_month_amount = array_sum(array_column($month_emis, 'emi_amount'));
            
            $grouped_emis[] = [
                'period_number' => $month,
                'period_type' => 'day',
                'display_name' => 'Month ' . $month,
                'installments' => $month_emis,
                'total_amount' => $total_month_amount,
                'paid_amount' => $paid_amount,
                'paid_count' => $paid_days,
                'collection_days' => $collection_days,
                'no_collection_days' => $days_per_month - $collection_days,
                'due_date' => $month_start,
                'calendar_month' => date('F Y', strtotime($month_start))
            ];
        }
    }
}

$total_pending = $total_installment - $total_collected;
$plan_total_amount = $member['total_received_amount'] ?? 0;
$remaining_amount = $plan_total_amount - $total_collected;

// Safe string function for JavaScript
function safe_js_string($str) {
    if ($str === null || $str === '') {
        return '';
    }
    return addslashes(htmlspecialchars($str));
}

// WhatsApp Functions
function formatWhatsAppNumber($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (empty($phone)) return '';
    if (substr($phone, 0, 1) === '0') $phone = substr($phone, 1);
    if (substr($phone, 0, 2) !== '91' && strlen($phone) === 10) $phone = '91' . $phone;
    return $phone;
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .payment-period-card {
        border: 2px solid #e9ecef;
        border-radius: 12px;
        margin-bottom: 20px;
        background: #fff;
        transition: all 0.3s ease;
    }
    .payment-period-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .period-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px 20px;
        border-radius: 12px 12px 0 0;
        cursor: pointer;
    }
    .period-badge {
        background: rgba(255,255,255,0.2);
        color: white;
        font-size: 12px;
        padding: 3px 8px;
        border-radius: 4px;
        margin-left: 8px;
    }
    .payment-mode-badge {
        background: #20c997;
        color: white;
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 4px;
        margin-left: 5px;
    }
    .collection-day {
        background-color: #e7f1ff;
    }
    .no-collection-day {
        background-color: #f8d7da;
        color: #721c24;
    }
    .progress-bar-thin {
        height: 6px;
        border-radius: 3px;
    }
    .period-selector {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 15px;
    }
    .period-btn {
        margin: 0 5px 5px 0;
        font-size: 12px;
        padding: 5px 10px;
    }
    .period-details {
        display: none;
        padding: 20px;
    }
    .period-details.show {
        display: block;
    }
    .toggle-icon {
        transition: transform 0.3s ease;
        margin-right: 10px;
    }
    .toggle-icon.collapsed {
        transform: rotate(-90deg);
    }
    .calendar-day-badge {
        background: #0dcaf0;
        color: white;
        font-size: 10px;
        padding: 2px 5px;
        border-radius: 3px;
        margin-right: 5px;
    }
    .whatsapp-btn {
        background: #25D366;
        color: white;
        border: none;
        padding: 3px 8px;
        font-size: 11px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        gap: 3px;
        cursor: pointer;
    }
    .whatsapp-btn:hover {
        background: #128C7E;
        color: white;
    }
    .action-icons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }
    .action-icons .btn {
        padding: 3px 8px;
        font-size: 11px;
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
                $page_title = "Payment Schedule";
                $breadcrumb_active = htmlspecialchars($member['customer_name']);
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

                <!-- Member Summary Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Member Summary</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="flex-shrink-0">
                                        <div class="avatar-sm rounded-circle bg-light text-primary d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                            <i class="fas fa-user fa-2x"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="mb-1"><?= htmlspecialchars($member['customer_name']); ?></h5>
                                        <p class="text-muted mb-0"><?= htmlspecialchars($member['plan_title']); ?></p>
                                        <div class="mt-1">
                                            <span class="badge bg-primary text-uppercase"><?= $payment_mode; ?></span>
                                            <span class="badge bg-info text-uppercase ms-1"><?= $plan_type; ?> plan</span>
                                            <?php if (!empty($member['bid_winner_site_number'])): ?>
                                                <span class="badge bg-warning ms-1">
                                                    <i class="fas fa-map-marker-alt"></i> Site: <?= htmlspecialchars($member['bid_winner_site_number']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="row text-center">
                                    <div class="col-sm-3">
                                        <h4 class="text-primary mb-1">₹<?= number_format($plan_total_amount, 2); ?></h4>
                                        <p class="text-muted mb-0">Total Plan</p>
                                    </div>
                                    <div class="col-sm-3">
                                        <h4 class="text-success mb-1">₹<?= number_format($total_collected, 2); ?></h4>
                                        <p class="text-muted mb-0">Collected</p>
                                    </div>
                                    <div class="col-sm-3">
                                        <h4 class="text-warning mb-1">₹<?= number_format($total_pending, 2); ?></h4>
                                        <p class="text-muted mb-0">Pending</p>
                                    </div>
                                    <div class="col-sm-3">
                                        <h4 class="text-info mb-1"><?= $paid_count; ?>/<?= count($all_emis); ?></h4>
                                        <p class="text-muted mb-0">Payments</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <small class="text-muted">Agreement No:</small>
                                <span class="ms-2"><?= htmlspecialchars($member['agreement_number']); ?></span><br>
                                <small class="text-muted">First Payment:</small>
                                <span class="ms-2"><?= date('d-m-Y', strtotime($first_emi_date)); ?></span>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">Payment Frequency:</small>
                                <span class="ms-2">
                                    <?php if ($payment_mode == 'monthly'): ?>
                                        1 payment/month
                                    <?php elseif ($payment_mode == 'weekly'): ?>
                                        4 payments/month
                                    <?php else: ?>
                                        Daily (30 days/month)
                                    <?php endif; ?>
                                </span><br>
                                <small class="text-muted">Next Payment:</small>
                                <span class="ms-2">
                                    <?php if ($next_unpaid_emi): ?>
                                        ₹<?= number_format($next_unpaid_emi['emi_amount'], 2); ?> on <?= date('d-m-Y', strtotime($next_unpaid_emi['emi_due_date'])); ?>
                                    <?php else: ?>
                                        All payments completed
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Period Selector -->
                <?php if (!empty($grouped_emis)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Quick Navigation</h5>
                    </div>
                    <div class="card-body">
                        <div class="period-selector">
                            <div class="row">
                                <?php foreach ($grouped_emis as $period): ?>
                                <div class="col-md-2 col-sm-3 col-4 mb-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm w-100 period-btn" 
                                            onclick="scrollToPeriod('period-<?= $period['period_number']; ?>')">
                                        <?= $period['display_name']; ?>
                                        <?php if ($period['paid_amount'] >= $period['total_amount']): ?>
                                            <i class="fas fa-check-circle text-success ms-1"></i>
                                        <?php endif; ?>
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Period Cards -->
                <?php if (!empty($grouped_emis)): ?>
                    <?php foreach ($grouped_emis as $period): 
                        $progress = $period['total_amount'] > 0 ? ($period['paid_amount'] / $period['total_amount']) * 100 : 0;
                        $is_completed = $progress >= 100;
                    ?>
                        <div class="payment-period-card" id="period-<?= $period['period_number']; ?>">
                            <!-- Period Header -->
                            <div class="period-header" onclick="togglePeriodDetails('period-details-<?= $period['period_number']; ?>', this)">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-chevron-down toggle-icon" id="toggle-icon-<?= $period['period_number']; ?>"></i>
                                        <div>
                                            <h5 class="mb-0 text-white">
                                                <?= $period['display_name']; ?>
                                                <span class="period-badge"><?= $period['calendar_month']; ?></span>
                                                <span class="payment-mode-badge"><?= ucfirst($payment_mode); ?></span>
                                            </h5>
                                            <?php if ($payment_mode == 'daily'): ?>
                                                <small class="text-white-80">
                                                    <?= $period['collection_days']; ?> collection days | 
                                                    <?= $period['no_collection_days']; ?> no collection days
                                                </small>
                                            <?php elseif ($payment_mode == 'weekly'): ?>
                                                <small class="text-white-80">
                                                    4 weekly payments
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <h4 class="mb-0 text-white">₹<?= number_format($period['total_amount'], 2); ?></h4>
                                        <small class="text-white-80">Period Target</small>
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-white-80">
                                            Collected: ₹<?= number_format($period['paid_amount'], 2); ?> 
                                            (<?= number_format($progress, 1); ?>%)
                                        </small>
                                        <small class="text-white-80">
                                            Remaining: ₹<?= number_format($period['total_amount'] - $period['paid_amount'], 2); ?>
                                        </small>
                                    </div>
                                    <div class="progress bg-white bg-opacity-25 progress-bar-thin">
                                        <div class="progress-bar <?= $is_completed ? 'bg-success' : 'bg-info'; ?>" 
                                             role="progressbar" 
                                             style="width: <?= $progress; ?>%"
                                             aria-valuenow="<?= $progress; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Period Details -->
                            <div class="period-details" id="period-details-<?= $period['period_number']; ?>">
                                <div class="card-body">
                                    <!-- Collection Table -->
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="80">#</th>
                                                    <th width="150">Date</th>
                                                    <th width="120">Amount (₹)</th>
                                                    <th width="120">Paid Date</th>
                                                    <th width="150">Collected By</th>
                                                    <th width="100">Status</th>
                                                    <th width="150">Actions</th>
                                                  </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $counter = 1;
                                                foreach ($period['installments'] as $emi): 
                                                    $is_collection = $emi['emi_amount'] > 0;
                                                    $customer_phone = !empty($member['customer_number2']) ? $member['customer_number2'] : $member['customer_number'];
                                                    $whatsapp_number = formatWhatsAppNumber($customer_phone);
                                                    
                                                    // Safe strings for JavaScript
                                                    $safe_customer_name = safe_js_string($member['customer_name']);
                                                    $safe_agreement_no = safe_js_string($member['agreement_number']);
                                                    $safe_plan_title = safe_js_string($member['plan_title']);
                                                    $safe_bill_number = safe_js_string($emi['emi_bill_number'] ?? '');
                                                    $safe_paid_date = safe_js_string($emi['paid_date'] ?? '');
                                                    $safe_payment_type = safe_js_string($emi['payment_type'] ?? 'cash');
                                                    $safe_collector_name = safe_js_string($emi['collected_by_name'] ?? '');
                                                    $safe_collector_username = safe_js_string($emi['collected_by_username'] ?? '');
                                                ?>
                                                    <tr class="<?= $is_collection ? 'collection-day' : 'no-collection-day'; ?>">
                                                        <td class="align-middle">
                                                            <?php if ($payment_mode == 'daily'): ?>
                                                                <strong>Day <?= $counter; ?></strong>
                                                            <?php elseif ($payment_mode == 'weekly'): ?>
                                                                <strong>Week <?= $counter; ?></strong>
                                                            <?php else: ?>
                                                                <strong>Payment <?= $counter; ?></strong>
                                                            <?php endif; ?>
                                                         </td>
                                                        <td class="align-middle">
                                                            <span class="calendar-day-badge"><?= date('d', strtotime($emi['emi_due_date'])); ?></span>
                                                            <?= date('d-m-Y', strtotime($emi['emi_due_date'])); ?>
                                                            <div class="small text-muted"><?= date('l', strtotime($emi['emi_due_date'])); ?></div>
                                                         </td>
                                                        <td class="align-middle">
                                                            <?php if ($is_collection): ?>
                                                                <strong>₹<?= number_format($emi['emi_amount'], 2); ?></strong>
                                                            <?php else: ?>
                                                                <span class="text-muted fst-italic">No collection</span>
                                                            <?php endif; ?>
                                                         </td>
                                                        <td class="align-middle">
                                                            <?php if ($emi['status'] == 'paid' && !empty($emi['paid_date'])): ?>
                                                                <?= date('d-m-Y', strtotime($emi['paid_date'])); ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                         </td>
                                                        <td class="align-middle">
                                                            <?php if ($emi['status'] == 'paid' && !empty($emi['collected_by_name'])): ?>
                                                                <?= htmlspecialchars($emi['collected_by_name']); ?>
                                                                <br><small class="text-muted">(<?= htmlspecialchars($emi['collected_by_username']); ?>)</small>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                         </td>
                                                        <td class="align-middle">
                                                            <?php if ($emi['status'] == 'paid'): ?>
                                                                <span class="badge bg-success">Paid</span>
                                                            <?php elseif ($is_collection): ?>
                                                                <span class="badge bg-danger">Pending</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">No Collection</span>
                                                            <?php endif; ?>
                                                         </td>
                                                        <td class="align-middle">
                                                            <div class="action-icons">
                                                                <?php if ($is_collection && $emi['status'] == 'unpaid'): ?>
                                                                    <a href="pay-emi.php?emi_id=<?= $emi['id']; ?>&member=<?= $member_id; ?>" 
                                                                       class="btn btn-sm btn-primary" title="Mark as Paid">
                                                                        <i class="fas fa-check"></i> Pay
                                                                    </a>
                                                                    <button class="btn btn-sm whatsapp-btn" 
                                                                            onclick="sendPaymentReminder('<?= $safe_customer_name ?>', '<?= $safe_agreement_no ?>', '<?= $safe_plan_title ?>', <?= $emi['emi_amount']; ?>, '<?= $emi['emi_due_date']; ?>', '<?= $whatsapp_number; ?>')"
                                                                            title="Send WhatsApp Reminder">
                                                                        <i class="fab fa-whatsapp"></i> Remind
                                                                    </button>
                                                                <?php elseif ($emi['status'] == 'paid'): ?>
                                                                    <a href="pay-emi.php?undo=<?= $emi['id']; ?>&member=<?= $member_id; ?>"
                                                                       class="btn btn-sm btn-warning" title="Undo Payment">
                                                                        <i class="fas fa-undo"></i> Undo
                                                                    </a>
                                                                    <button class="btn btn-sm whatsapp-btn" 
                                                                            onclick="sendPaymentReceipt('<?= $safe_customer_name ?>', '<?= $safe_agreement_no ?>', '<?= $safe_plan_title ?>', <?= $emi['emi_amount']; ?>, '<?= $safe_bill_number ?>', '<?= $safe_paid_date ?>', '<?= $safe_payment_type ?>', <?= $emi['cash_amount'] ?? 0; ?>, <?= $emi['upi_amount'] ?? 0; ?>, '<?= $safe_collector_name ?>', '<?= $safe_collector_username ?>', '<?= $whatsapp_number; ?>')"
                                                                            title="Send WhatsApp Receipt">
                                                                        <i class="fab fa-whatsapp"></i> Receipt
                                                                    </button>
                                                                    <button class="btn btn-sm btn-info" 
        onclick="printReceipt(<?= $emi['id']; ?>, <?= $member_id; ?>)" 
        title="Print Receipt">
    <i class="fas fa-print"></i>
</button>
                                                                <?php endif; ?>
                                                            </div>
                                                         </td>
                                                     </tr>
                                                <?php 
                                                $counter++;
                                                endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No payment schedule found for this member.
                    </div>
                <?php endif; ?>
            </div>

            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
    <script>
        // Toggle period details
        function togglePeriodDetails(detailsId, element) {
            const details = document.getElementById(detailsId);
            const icon = element.querySelector('.toggle-icon');
            
            if (details.style.display === 'none' || !details.style.display) {
                details.style.display = 'block';
                details.classList.add('show');
                if (icon) icon.classList.remove('collapsed');
            } else {
                details.style.display = 'none';
                details.classList.remove('show');
                if (icon) icon.classList.add('collapsed');
            }
        }
        
        // Scroll to specific period
        function scrollToPeriod(periodId) {
            const periodCard = document.getElementById(periodId);
            if (periodCard) {
                periodCard.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'start' 
                });
                // Expand the period
                const details = periodCard.querySelector('.period-details');
                const icon = periodCard.querySelector('.toggle-icon');
                if (details) {
                    details.style.display = 'block';
                    details.classList.add('show');
                    if (icon) icon.classList.remove('collapsed');
                }
            }
        }

        // Print Receipt Function
    function printReceipt(emiId, memberId) {
        // Open print receipt page in new window
        const printWindow = window.open(`print-receipt.php?emi_id=${emiId}&member_id=${memberId}&type=thermal`, '_blank', 'width=400,height=600,scrollbars=yes');
        if (printWindow) {
            printWindow.focus();
        } else {
            alert('Please allow popups to print receipt');
        }
    }
        
        // Send WhatsApp Payment Receipt
        function sendPaymentReceipt(customerName, agreementNo, planTitle, amount, billNo, paidDate, paymentType, cashAmount, upiAmount, collectorName, collectorUsername, whatsappNumber) {
            let message = `✅ *Payment Receipt - Shree Vari Chits Private Limited* ✅\n\n`;
            message += `Dear *${customerName}*,\n\n`;
            message += `Your payment has been successfully recorded.\n\n`;
            message += `• *Agreement No:* ${agreementNo}\n`;
            message += `• *Plan:* ${planTitle}\n`;
            message += `• *Installment Amount:* ₹${amount.toFixed(2)}\n`;
            message += `• *Bill Number:* ${billNo || 'N/A'}\n`;
            message += `• *Payment Date:* ${paidDate || 'N/A'}\n`;
            message += `• *Payment Type:* ${paymentType.charAt(0).toUpperCase() + paymentType.slice(1)}\n`;
            message += `• *Collected By:* ${collectorName || collectorUsername || 'N/A'}\n`;
            
            if (paymentType === 'both') {
                message += `• *Cash Amount:* ₹${cashAmount.toFixed(2)}\n`;
                message += `• *UPI Amount:* ₹${upiAmount.toFixed(2)}\n`;
            }
            
            message += `\nThank you for your payment!\n\n`;
            message += `📞 Contact: +91 8667646757\n`;
            message += `📍 Shree Vari Chits Private Limited`;
            
            const encodedMessage = encodeURIComponent(message);
            const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodedMessage}`;
            window.open(whatsappUrl, '_blank');
        }
        
        // Send WhatsApp Payment Reminder
        function sendPaymentReminder(customerName, agreementNo, planTitle, amount, dueDate, whatsappNumber) {
            const isOverdue = new Date(dueDate) < new Date();
            const formattedDueDate = new Date(dueDate).toLocaleDateString('en-GB');
            let overdueDays = 0;
            
            if (isOverdue) {
                const today = new Date();
                const due = new Date(dueDate);
                overdueDays = Math.floor((today - due) / (1000 * 60 * 60 * 24));
            }
            
            let message = `🔔 *Payment Reminder - Shree Vari Chits Private Limited* 🔔\n\n`;
            message += `Dear *${customerName}*,\n\n`;
            
            if (isOverdue) {
                message += `This is an overdue payment reminder.\n\n`;
            } else {
                message += `This is a payment reminder.\n\n`;
            }
            
            message += `• *Agreement No:* ${agreementNo}\n`;
            message += `• *Plan:* ${planTitle}\n`;
            message += `• *Installment Amount:* ₹${amount.toFixed(2)}\n`;
            message += `• *Due Date:* ${formattedDueDate}\n`;
            
            if (isOverdue) {
                message += `• *Overdue Days:* ${overdueDays} days\n`;
                message += `• *Status:* ⚠️ Overdue\n\n`;
                message += `Please make the payment immediately to avoid any inconvenience.\n`;
            } else {
                message += `• *Status:* ⏳ Pending\n\n`;
                message += `Please make the payment on or before the due date.\n`;
            }
            
            message += `\n📞 Contact: +91 8667646757\n`;
            message += `📍 Shree Vari Chits Private Limited`;
            
            const encodedMessage = encodeURIComponent(message);
            const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodedMessage}`;
            window.open(whatsappUrl, '_blank');
        }
        
        // Initialize - collapse all details except first
        document.addEventListener('DOMContentLoaded', function() {
            const allDetails = document.querySelectorAll('.period-details');
            allDetails.forEach((details, index) => {
                if (index === 0) {
                    details.style.display = 'block';
                    details.classList.add('show');
                    const icon = details.closest('.payment-period-card')?.querySelector('.toggle-icon');
                    if (icon) icon.classList.remove('collapsed');
                } else {
                    details.style.display = 'none';
                    details.classList.remove('show');
                    const icon = details.closest('.payment-period-card')?.querySelector('.toggle-icon');
                    if (icon) icon.classList.add('collapsed');
                }
            });
        });
    </script>
</body>
</html>