<?php
// ajax/get-plan-members.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include '../includes/db.php';

$plan_id = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
if ($plan_id == 0) {
    echo '<div class="alert alert-danger">Invalid plan selected.</div>';
    exit;
}

// Get plan details
$sql_plan = "SELECT title FROM plans WHERE id = ?";
$stmt_plan = $conn->prepare($sql_plan);
$stmt_plan->bind_param("i", $plan_id);
$stmt_plan->execute();
$plan_result = $stmt_plan->get_result();
$plan = $plan_result->fetch_assoc();
$stmt_plan->close();

if (!$plan) {
    echo '<div class="alert alert-danger">Plan not found.</div>';
    exit;
}

// Get members for this plan with their collection details
$sql_members = "SELECT 
                m.id,
                m.agreement_number,
                m.customer_name,
                m.customer_number,
                m.customer_photo,
                m.bid_winner_site_number,
                m.emi_date,
                m.winner_amount,
                m.winner_date,
                m.early_big_winner_payment,
                m.early_payment_date,
                COUNT(es.id) as total_emis,
                SUM(CASE WHEN es.status = 'paid' THEN 1 ELSE 0 END) as paid_emis,
                SUM(CASE WHEN es.status = 'paid' THEN es.emi_amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN es.status = 'unpaid' THEN es.emi_amount ELSE 0 END) as total_pending,
                MAX(es.paid_date) as last_payment_date,
                MIN(CASE WHEN es.status = 'unpaid' THEN es.emi_due_date END) as next_due_date
                FROM members m
                LEFT JOIN emi_schedule es ON m.id = es.member_id
                WHERE m.plan_id = ?
                GROUP BY m.id, m.agreement_number, m.customer_name, m.customer_number, 
                         m.customer_photo, m.bid_winner_site_number, m.emi_date,
                         m.winner_amount, m.winner_date, m.early_big_winner_payment, m.early_payment_date
                ORDER BY m.id DESC";

$stmt_members = $conn->prepare($sql_members);
$stmt_members->bind_param("i", $plan_id);
$stmt_members->execute();
$members_result = $stmt_members->get_result();
$members = [];
$total_collected = 0;
$total_pending = 0;
$total_members = 0;

while ($row = $members_result->fetch_assoc()) {
    $members[] = $row;
    $total_collected += $row['total_paid'] ?? 0;
    $total_pending += $row['total_pending'] ?? 0;
    $total_members++;
}
$stmt_members->close();

// Get plan EMI details
$sql_plan_details = "SELECT 
                    COUNT(pd.month_number) as total_months,
                    SUM(pd.installment) as total_expected_amount
                    FROM plan_details pd
                    WHERE pd.plan_id = ?";
$stmt_plan_details = $conn->prepare($sql_plan_details);
$stmt_plan_details->bind_param("i", $plan_id);
$stmt_plan_details->execute();
$plan_details_result = $stmt_plan_details->get_result();
$plan_details = $plan_details_result->fetch_assoc();
$stmt_plan_details->close();

$total_expected = $plan_details['total_expected_amount'] ?? 0;
$collection_rate = $total_expected > 0 ? ($total_collected / $total_expected * 100) : 0;
?>

<div class="plan-members-container">
    <!-- Plan Summary -->
    <div class="alert alert-info mb-4">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="mb-1"><?php echo htmlspecialchars($plan['title']); ?></h5>
                <p class="mb-0">
                    <i class="fas fa-users me-1"></i> <?php echo $total_members; ?> Members |
                    <i class="fas fa-calendar-alt me-1"></i> <?php echo $plan_details['total_months'] ?? 0; ?> Months
                </p>
            </div>
            <div class="col-md-6 text-end">
                <div class="row">
                    <div class="col-4">
                        <small class="text-muted d-block">Collected</small>
                        <strong class="text-success">₹<?php echo number_format($total_collected, 2); ?></strong>
                    </div>
                    <div class="col-4">
                        <small class="text-muted d-block">Pending</small>
                        <strong class="text-warning">₹<?php echo number_format($total_pending, 2); ?></strong>
                    </div>
                    <div class="col-4">
                        <small class="text-muted d-block">Rate</small>
                        <strong class="<?php echo $collection_rate >= 80 ? 'text-success' : ($collection_rate >= 50 ? 'text-warning' : 'text-danger'); ?>">
                            <?php echo number_format($collection_rate, 1); ?>%
                        </strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Members Table -->
    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th width="50">#</th>
                    <th width="60">Photo</th>
                    <th>Member Details</th>
                    <th>Agreement Info</th>
                    <th>Collection Details</th>
                    <th>Status</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($members)): ?>
                    <?php foreach ($members as $index => $member): 
                        $paid_percentage = $member['total_emis'] > 0 ? ($member['paid_emis'] / $member['total_emis'] * 100) : 0;
                        $is_winner = !empty($member['winner_amount']);
                        $has_early_payment = !empty($member['early_big_winner_payment']) && $member['early_big_winner_payment'] > 0;
                        $status_class = $is_winner ? 'success' : ($has_early_payment ? 'info' : ($member['total_pending'] > 0 ? 'warning' : 'primary'));
                    ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <?php if (!empty($member['customer_photo']) && file_exists($member['customer_photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($member['customer_photo']); ?>" 
                                         alt="<?php echo htmlspecialchars($member['customer_name']); ?>" 
                                         class="rounded-circle" width="40" height="40"
                                         style="object-fit: cover;">
                                <?php else: ?>
                                    <div class="avatar-sm rounded-circle bg-light d-flex align-items-center justify-content-center">
                                        <i class="fas fa-user text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <h6 class="mb-1"><?php echo htmlspecialchars($member['customer_name']); ?></h6>
                                <p class="mb-1">
                                    <i class="fas fa-phone text-muted me-1"></i>
                                    <?php echo htmlspecialchars($member['customer_number']); ?>
                                </p>
                                <?php if (!empty($member['bid_winner_site_number'])): ?>
                                    <span class="badge bg-info">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        <?php echo htmlspecialchars($member['bid_winner_site_number']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="mb-1">
                                    <small class="text-muted">Agreement No:</small><br>
                                    <strong><?php echo htmlspecialchars($member['agreement_number']); ?></strong>
                                </div>
                                <div class="mb-0">
                                    <small class="text-muted">Start Date:</small><br>
                                    <?php echo !empty($member['emi_date']) ? date('d-m-Y', strtotime($member['emi_date'])) : 'N/A'; ?>
                                </div>
                            </td>
                            <td>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Paid</small>
                                        <strong class="text-success">₹<?php echo number_format($member['total_paid'] ?? 0, 2); ?></strong>
                                        <small class="text-muted d-block"><?php echo $member['paid_emis']; ?>/<?php echo $member['total_emis']; ?> EMIs</small>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Pending</small>
                                        <strong class="text-warning">₹<?php echo number_format($member['total_pending'] ?? 0, 2); ?></strong>
                                        <?php if (!empty($member['next_due_date'])): ?>
                                            <small class="text-muted d-block">Due: <?php echo date('d-m-Y', strtotime($member['next_due_date'])); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo min($paid_percentage, 100); ?>%"></div>
                                </div>
                                <small><?php echo number_format($paid_percentage, 1); ?>% Collected</small>
                            </td>
                            <td>
                                <?php if ($is_winner): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-trophy me-1"></i> Bid Winner
                                    </span>
                                    <small class="d-block text-success mt-1">
                                        ₹<?php echo number_format($member['winner_amount'], 2); ?>
                                    </small>
                                    <?php if (!empty($member['winner_date'])): ?>
                                        <small class="d-block text-muted">
                                            <?php echo date('d-m-Y', strtotime($member['winner_date'])); ?>
                                        </small>
                                    <?php endif; ?>
                                <?php elseif ($has_early_payment): ?>
                                    <span class="badge bg-info">
                                        <i class="fas fa-money-bill-wave me-1"></i> Early Payment
                                    </span>
                                    <small class="d-block text-info mt-1">
                                        ₹<?php echo number_format($member['early_big_winner_payment'], 2); ?>
                                    </small>
                                <?php elseif ($member['total_pending'] == 0): ?>
                                    <span class="badge bg-primary">
                                        <i class="fas fa-check-circle me-1"></i> Completed
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-<?php echo $paid_percentage >= 50 ? 'warning' : 'danger'; ?>">
                                        <i class="fas fa-clock me-1"></i> In Progress
                                    </span>
                                    <small class="d-block text-muted mt-1">
                                        <?php echo $member['paid_emis']; ?> of <?php echo $member['total_emis']; ?> paid
                                    </small>
                                <?php endif; ?>
                                
                                <?php if (!empty($member['last_payment_date'])): ?>
                                    <small class="d-block text-muted mt-1">
                                        Last: <?php echo date('d-m-Y', strtotime($member['last_payment_date'])); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group-vertical" role="group">
                                    <a href="emi-schedule-member.php?id=<?php echo $member['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary mb-1" title="View EMI Schedule">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>
                                    <a href="add-member.php?edit=<?php echo $member['id']; ?>" 
                                       class="btn btn-sm btn-outline-warning mb-1" title="Edit Member">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="collection-reports.php?member_id=<?php echo $member['id']; ?>" 
                                       class="btn btn-sm btn-outline-info" title="Collection Report">
                                        <i class="fas fa-chart-line"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <i class="fas fa-users fa-2x text-muted mb-3"></i>
                            <h5>No Members Found</h5>
                            <p class="text-muted">No members have enrolled in this plan yet.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <td colspan="3" class="text-end"><strong>Total:</strong></td>
                    <td><strong><?php echo $total_members; ?> Members</strong></td>
                    <td>
                        <div class="row">
                            <div class="col-6">
                                <strong class="text-success">₹<?php echo number_format($total_collected, 2); ?></strong>
                            </div>
                            <div class="col-6">
                                <strong class="text-warning">₹<?php echo number_format($total_pending, 2); ?></strong>
                            </div>
                        </div>
                    </td>
                    <td>
                        <strong class="text-<?php echo $collection_rate >= 80 ? 'success' : ($collection_rate >= 50 ? 'warning' : 'danger'); ?>">
                            <?php echo number_format($collection_rate, 1); ?>%
                        </strong>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Statistics Summary -->
    <div class="row mt-4">
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body text-center py-3">
                    <h6 class="text-muted mb-2">Total Members</h6>
                    <h3 class="text-primary mb-0"><?php echo $total_members; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body text-center py-3">
                    <h6 class="text-muted mb-2">Total Collected</h6>
                    <h3 class="text-success mb-0">₹<?php echo number_format($total_collected, 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body text-center py-3">
                    <h6 class="text-muted mb-2">Total Pending</h6>
                    <h3 class="text-warning mb-0">₹<?php echo number_format($total_pending, 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body text-center py-3">
                    <h6 class="text-muted mb-2">Collection Rate</h6>
                    <h3 class="text-info mb-0"><?php echo number_format($collection_rate, 1); ?>%</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Actions -->
    <div class="d-flex justify-content-between mt-4">
        <div>
            <button onclick="printPlanReport()" class="btn btn-outline-primary">
                <i class="fas fa-print me-1"></i> Print Report
            </button>
            <button onclick="exportPlanMembers()" class="btn btn-outline-success">
                <i class="fas fa-file-excel me-1"></i> Export Excel
            </button>
        </div>
        <div>
            <a href="collection-reports.php?plan_id=<?php echo $plan_id; ?>" class="btn btn-primary">
                <i class="fas fa-chart-line me-1"></i> View Detailed Report
            </a>
        </div>
    </div>
</div>

<style>
.avatar-sm {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}
.progress {
    background-color: #f0f0f0;
}
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,0.02);
}
.btn-group-vertical .btn {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
</style>

<script>
function printPlanReport() {
    const printContent = document.querySelector('.plan-members-container').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Plan Members Report - <?php echo htmlspecialchars($plan['title']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th { background-color: #f8f9fa; border: 1px solid #dee2e6; padding: 8px; text-align: left; }
                td { border: 1px solid #dee2e6; padding: 8px; }
                .text-success { color: #198754; }
                .text-warning { color: #fd7e14; }
                .text-danger { color: #dc3545; }
                .badge { padding: 0.25em 0.6em; font-size: 75%; font-weight: 700; border-radius: 0.25rem; }
                .bg-success { background-color: #198754; color: white; }
                .bg-warning { background-color: #fd7e14; color: white; }
                .bg-danger { background-color: #dc3545; color: white; }
                .bg-info { background-color: #0dcaf0; color: white; }
                .text-center { text-align: center; }
                .text-end { text-align: right; }
                .mb-0 { margin-bottom: 0; }
                .mt-2 { margin-top: 0.5rem; }
                .d-block { display: block; }
            </style>
        </head>
        <body>
            <h2>Plan Members Report</h2>
            <h3><?php echo htmlspecialchars($plan['title']); ?></h3>
            <p>Generated on: <?php echo date('d-m-Y H:i:s'); ?></p>
            ${printContent}
        </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

function exportPlanMembers() {
    // Create CSV content
    let csv = [];
    
    // Add header
    csv.push(['Member Name', 'Phone', 'Agreement No', 'Site No', 'Total Paid (₹)', 'Total Pending (₹)', 'Paid EMIs', 'Total EMIs', 'Status', 'Last Payment']);
    
    // Add data rows
    <?php foreach ($members as $member): ?>
    csv.push([
        '<?php echo addslashes($member['customer_name']); ?>',
        '<?php echo $member['customer_number']; ?>',
        '<?php echo $member['agreement_number']; ?>',
        '<?php echo $member['bid_winner_site_number'] ?? ''; ?>',
        '<?php echo $member['total_paid'] ?? 0; ?>',
        '<?php echo $member['total_pending'] ?? 0; ?>',
        '<?php echo $member['paid_emis']; ?>',
        '<?php echo $member['total_emis']; ?>',
        '<?php echo !empty($member['winner_amount']) ? 'Bid Winner' : (!empty($member['early_big_winner_payment']) ? 'Early Payment' : ($member['total_pending'] == 0 ? 'Completed' : 'In Progress')); ?>',
        '<?php echo !empty($member['last_payment_date']) ? date('d-m-Y', strtotime($member['last_payment_date'])) : ''; ?>'
    ]);
    <?php endforeach; ?>
    
    // Convert to CSV string
    const csvContent = csv.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
    
    // Create download link
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'plan-members-<?php echo $plan_id; ?>-<?php echo date('Y-m-d'); ?>.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>