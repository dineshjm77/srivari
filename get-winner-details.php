<?php
// ajax/get-winner-details.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized access']));
}

$winner_id = isset($_GET['winner_id']) ? intval($_GET['winner_id']) : 0;

if ($winner_id <= 0) {
    die('<div class="alert alert-danger">Invalid winner ID</div>');
}

// Get winner details
$sql = "SELECT 
            m.id,
            m.agreement_number,
            m.customer_name,
            m.customer_number as phone,
            m.customer_number2 as phone2,
            m.nominee_name,
            m.nominee_number,
            m.customer_address,
            m.emi_date,
            m.winner_amount,
            m.winner_date,
            m.winner_number,
            m.paid_date,
            m.payment_method,
            m.transaction_no,
            m.payment_notes,
            p.title as plan_title,
            p.total_received_amount as plan_total,
            u.full_name as declared_by,
            m.collected_by,
            m.created_at
        FROM members m
        JOIN plans p ON m.plan_id = p.id
        LEFT JOIN users u ON m.collected_by = u.id
        WHERE m.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $winner_id);
$stmt->execute();
$result = $stmt->get_result();
$winner = $result->fetch_assoc();

if (!$winner) {
    die('<div class="alert alert-danger">Winner not found</div>');
}

// Get EMI payment history
$sql_emi = "SELECT 
                es.emi_due_date,
                es.paid_date,
                es.emi_amount,
                es.emi_bill_number,
                es.payment_type,
                es.upi_amount,
                es.cash_amount,
                u.full_name as collected_by_name
            FROM emi_schedule es
            LEFT JOIN users u ON es.collected_by = u.id
            WHERE es.member_id = ? AND es.status = 'paid'
            ORDER BY es.paid_date DESC";

$stmt_emi = $conn->prepare($sql_emi);
$stmt_emi->bind_param('i', $winner_id);
$stmt_emi->execute();
$result_emi = $stmt_emi->get_result();
$emi_payments = [];
$total_paid = 0;
while ($row = $result_emi->fetch_assoc()) {
    $emi_payments[] = $row;
    $total_paid += $row['emi_amount'];
}
?>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">Winner Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">Agreement Number:</th>
                        <td><?= htmlspecialchars($winner['agreement_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Customer Name:</th>
                        <td><?= htmlspecialchars($winner['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?= htmlspecialchars($winner['phone']) ?></td>
                    </tr>
                    <?php if (!empty($winner['phone2'])): ?>
                    <tr>
                        <th>Alternate Phone:</th>
                        <td><?= htmlspecialchars($winner['phone2']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Address:</th>
                        <td><?= nl2br(htmlspecialchars($winner['customer_address'] ?? 'N/A')) ?></td>
                    </tr>
                    <tr>
                        <th>Nominee Name:</th>
                        <td><?= htmlspecialchars($winner['nominee_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Nominee Phone:</th>
                        <td><?= htmlspecialchars($winner['nominee_number']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">Winner & Plan Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">Plan:</th>
                        <td><?= htmlspecialchars($winner['plan_title']) ?></td>
                    </tr>
                    <tr>
                        <th>Plan Amount:</th>
                        <td class="text-success fw-bold">₹<?= number_format($winner['plan_total'], 2) ?></td>
                    </tr>
                    <tr>
                        <th>Winner Amount:</th>
                        <td class="text-success fw-bold">₹<?= number_format($winner['winner_amount'], 2) ?></td>
                    </tr>
                    <tr>
                        <th>Winner Date:</th>
                        <td><?= date('d M Y', strtotime($winner['winner_date'])) ?></td>
                    </tr>
                    <tr>
                        <th>Winner Number:</th>
                        <td>
                            <?php if (!empty($winner['winner_number'])): ?>
                                <span class="badge bg-info"><?= htmlspecialchars($winner['winner_number']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Payment Status:</th>
                        <td>
                            <?php if (!empty($winner['paid_date'])): ?>
                                <span class="badge bg-success">Paid</span>
                                (<?= date('d M Y', strtotime($winner['paid_date'])) ?>)
                            <?php else: ?>
                                <span class="badge bg-warning">Unpaid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($winner['paid_date'])): ?>
                    <tr>
                        <th>Payment Method:</th>
                        <td><?= ucfirst($winner['payment_method'] ?? 'N/A') ?></td>
                    </tr>
                    <?php if (!empty($winner['transaction_no'])): ?>
                    <tr>
                        <th>Transaction No:</th>
                        <td><?= htmlspecialchars($winner['transaction_no']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    <tr>
                        <th>Declared By:</th>
                        <td><?= htmlspecialchars($winner['declared_by'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Member Since:</th>
                        <td><?= date('d M Y', strtotime($winner['created_at'])) ?></td>
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
                        <th>Amount</th>
                        <th>Bill No</th>
                        <th>Payment Type</th>
                        <th>Collected By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emi_payments as $index => $payment): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= date('d M Y', strtotime($payment['emi_due_date'])) ?></td>
                        <td><?= date('d M Y', strtotime($payment['paid_date'])) ?></td>
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
                <tfoot>
                    <tr class="table-light">
                        <td colspan="3" class="text-end fw-bold">Total EMI Paid:</td>
                        <td class="text-success fw-bold">₹<?= number_format($total_paid, 2) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($winner['payment_notes'])): ?>
<div class="card mt-3">
    <div class="card-header">
        <h5 class="card-title mb-0">Payment Notes</h5>
    </div>
    <div class="card-body">
        <p class="mb-0"><?= nl2br(htmlspecialchars($winner['payment_notes'])) ?></p>
    </div>
</div>
<?php endif; ?>

<div class="mt-3 text-center">
    <button type="button" class="btn btn-primary" onclick="window.print()">
        <i class="fas fa-print me-1"></i> Print Details
    </button>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
        Close
    </button>
</div>