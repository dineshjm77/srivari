<?php
// ajax/get-customer-details.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized access']));
}

$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customer_id <= 0) {
    die('<div class="alert alert-danger">Invalid customer ID</div>');
}

// Get customer details
$sql = "SELECT 
            m.*,
            p.title as plan_title,
            p.total_months,
            p.total_received_amount as plan_total,
            u.full_name as collected_by
        FROM members m
        JOIN plans p ON m.plan_id = p.id
        LEFT JOIN users u ON m.collected_by = u.id
        WHERE m.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result->fetch_assoc();

if (!$customer) {
    die('<div class="alert alert-danger">Customer not found</div>');
}

// Get EMI payment history
$sql_emi = "SELECT 
                es.*,
                u.full_name as collected_by_name
            FROM emi_schedule es
            LEFT JOIN users u ON es.collected_by = u.id
            WHERE es.member_id = ?
            ORDER BY es.emi_due_date DESC";

$stmt_emi = $conn->prepare($sql_emi);
$stmt_emi->bind_param('i', $customer_id);
$stmt_emi->execute();
$result_emi = $stmt_emi->get_result();
$emi_payments = [];
$total_paid = 0;
$total_pending = 0;
while ($row = $result_emi->fetch_assoc()) {
    $emi_payments[] = $row;
    if ($row['status'] == 'paid') {
        $total_paid += $row['emi_amount'];
    } else {
        $total_pending += $row['emi_amount'];
    }
}

// Calculate statistics
$paid_count = count(array_filter($emi_payments, function($p) { return $p['status'] == 'paid'; }));
$pending_count = count(array_filter($emi_payments, function($p) { return $p['status'] == 'unpaid'; }));
$progress_percentage = $customer['total_months'] > 0 ? round(($paid_count / $customer['total_months']) * 100) : 0;
?>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">Customer Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">Agreement Number:</th>
                        <td><?= htmlspecialchars($customer['agreement_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Customer Name:</th>
                        <td><?= htmlspecialchars($customer['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Primary Phone:</th>
                        <td><?= htmlspecialchars($customer['customer_number']) ?></td>
                    </tr>
                    <?php if (!empty($customer['customer_number2'])): ?>
                    <tr>
                        <th>Alternate Phone:</th>
                        <td><?= htmlspecialchars($customer['customer_number2']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Address:</th>
                        <td><?= nl2br(htmlspecialchars($customer['customer_address'] ?? 'N/A')) ?></td>
                    </tr>
                    <tr>
                        <th>Nominee Name:</th>
                        <td><?= htmlspecialchars($customer['nominee_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Nominee Phone:</th>
                        <td><?= htmlspecialchars($customer['nominee_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Aadhar Number:</th>
                        <td><?= htmlspecialchars($customer['customer_aadhar'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Nominee Aadhar:</th>
                        <td><?= htmlspecialchars($customer['nominee_aadhar'] ?? 'N/A') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">Plan & Payment Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">Plan:</th>
                        <td><?= htmlspecialchars($customer['plan_title']) ?></td>
                    </tr>
                    <tr>
                        <th>Plan Amount:</th>
                        <td class="text-success fw-bold">₹<?= number_format($customer['plan_total'], 2) ?></td>
                    </tr>
                    <tr>
                        <th>Monthly EMI:</th>
                        <td class="text-success fw-bold">₹<?= number_format($customer['monthly_installment'], 2) ?></td>
                    </tr>
                    <tr>
                        <th>EMI Date:</th>
                        <td><?= date('d', strtotime($customer['emi_date'])) ?> of every month</td>
                    </tr>
                    <tr>
                        <th>Total Months:</th>
                        <td><?= $customer['total_months'] ?> months</td>
                    </tr>
                    <tr>
                        <th>Progress:</th>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1 me-2">
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar 
                                            <?= $progress_percentage >= 100 ? 'bg-success' : 
                                               ($progress_percentage >= 50 ? 'bg-info' : 'bg-warning'); ?>" 
                                            role="progressbar" 
                                            style="width: <?= min($progress_percentage, 100); ?>%">
                                        </div>
                                    </div>
                                </div>
                                <div style="width: 40px;">
                                    <small><?= $progress_percentage; ?>%</small>
                                </div>
                            </div>
                            <small class="text-muted"><?= $paid_count ?> of <?= $customer['total_months'] ?> months paid</small>
                        </td>
                    </tr>
                    <tr>
                        <th>Paid Amount:</th>
                        <td class="text-success fw-bold">₹<?= number_format($total_paid, 2) ?></td>
                    </tr>
                    <tr>
                        <th>Pending Amount:</th>
                        <td class="text-warning fw-bold">₹<?= number_format($total_pending, 2) ?></td>
                    </tr>
                    <?php if (!empty($customer['winner_amount'])): ?>
                    <tr>
                        <th>Winner Amount:</th>
                        <td class="text-success fw-bold">₹<?= number_format($customer['winner_amount'], 2) ?></td>
                    </tr>
                    <tr>
                        <th>Winner Date:</th>
                        <td><?= date('d M Y', strtotime($customer['winner_date'])) ?></td>
                    </tr>
                    <tr>
                        <th>Winner Number:</th>
                        <td><?= $customer['winner_number'] ?? 'N/A' ?></td>
                    </tr>
                    <tr>
                        <th>Payment Status:</th>
                        <td>
                            <?php if (!empty($customer['paid_date'])): ?>
                                <span class="badge bg-success">Paid</span>
                                (<?= date('d M Y', strtotime($customer['paid_date'])) ?>)
                            <?php else: ?>
                                <span class="badge bg-warning">Unpaid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Member Since:</th>
                        <td><?= date('d M Y', strtotime($customer['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <th>Registered By:</th>
                        <td><?= htmlspecialchars($customer['collected_by'] ?? 'N/A') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($emi_payments)): ?>
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">EMI Payment History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Due Date</th>
                        <th>Paid Date</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Bill No</th>
                        <th>Payment Type</th>
                        <th>Collected By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emi_payments as $index => $payment): ?>
                    <tr class="<?= $payment['status'] == 'paid' ? 'table-success' : 'table-warning'; ?>">
                        <td><?= $index + 1 ?></td>
                        <td><?= date('d M Y', strtotime($payment['emi_due_date'])) ?></td>
                        <td>
                            <?php if (!empty($payment['paid_date'])): ?>
                                <?= date('d M Y', strtotime($payment['paid_date'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($payment['status'] == 'paid'): ?>
                                <span class="badge bg-success">Paid</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Unpaid</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-success fw-bold">₹<?= number_format($payment['emi_amount'], 2) ?></td>
                        <td><?= $payment['emi_bill_number'] ?? 'N/A' ?></td>
                        <td>
                            <?= ucfirst($payment['payment_type']) ?>
                            <?php if ($payment['payment_type'] == 'both'): ?>
                                <br><small>UPI: ₹<?= number_format($payment['upi_amount'], 2) ?></small>
                                <br><small>Cash: ₹<?= number_format($payment['cash_amount'], 2) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($payment['collected_by_name'] ?? 'N/A') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mt-3 text-center">
    <a href="emi-schedule-member.php?id=<?= $customer_id ?>" class="btn btn-primary">
        <i class="fas fa-calendar-alt me-1"></i> View Full Schedule
    </a>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
        Close
    </button>
</div>