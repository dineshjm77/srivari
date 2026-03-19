<?php
// ajax/get-winner-details.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include '../includes/db.php';

$winner_id = isset($_GET['winner_id']) ? intval($_GET['winner_id']) : 0;
if ($winner_id == 0) {
    echo '<div class="alert alert-danger">Invalid winner selected.</div>';
    exit;
}

// Get winner details
$sql_winner = "SELECT 
                m.*,
                p.title as plan_title,
                p.total_received_amount as plan_total,
                u.full_name as declared_by,
                (SELECT SUM(es.emi_amount) FROM emi_schedule es 
                 WHERE es.member_id = m.id AND es.status = 'paid') as total_paid,
                (SELECT SUM(es.emi_amount) FROM emi_schedule es 
                 WHERE es.member_id = m.id AND es.status = 'unpaid') as total_pending,
                (SELECT COUNT(es.id) FROM emi_schedule es 
                 WHERE es.member_id = m.id AND es.status = 'paid') as paid_emis_count,
                (SELECT COUNT(es.id) FROM emi_schedule es 
                 WHERE es.member_id = m.id) as total_emis_count
              FROM members m
              JOIN plans p ON m.plan_id = p.id
              LEFT JOIN users u ON m.collected_by = u.id
              WHERE m.id = ? AND m.winner_amount > 0";

$stmt = $conn->prepare($sql_winner);
$stmt->bind_param("i", $winner_id);
$stmt->execute();
$result = $stmt->get_result();
$winner = $result->fetch_assoc();
$stmt->close();

if (!$winner) {
    echo '<div class="alert alert-danger">Winner not found.</div>';
    exit;
}

// Calculate balances
$total_paid = $winner['total_paid'] ?? 0;
$winner_amount = $winner['winner_amount'] ?? 0;
$balance_after = $total_paid - $winner_amount;
$paid_percentage = $winner['total_emis_count'] > 0 ? 
                  ($winner['paid_emis_count'] / $winner['total_emis_count'] * 100) : 0;
?>

<div class="winner-details">
    <div class="row mb-4">
        <div class="col-md-6">
            <h5>Winner Information</h5>
            <table class="table table-sm">
                <tr>
                    <th width="40%">Name:</th>
                    <td><?php echo htmlspecialchars($winner['customer_name']); ?></td>
                </tr>
                <tr>
                    <th>Agreement No:</th>
                    <td><?php echo htmlspecialchars($winner['agreement_number']); ?></td>
                </tr>
                <tr>
                    <th>Phone:</th>
                    <td><?php echo htmlspecialchars($winner['customer_number']); ?></td>
                </tr>
                <?php if (!empty($winner['customer_number2'])): ?>
                <tr>
                    <th>Alternate Phone:</th>
                    <td><?php echo htmlspecialchars($winner['customer_number2']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($winner['bid_winner_site_number'])): ?>
                <tr>
                    <th>Site Number:</th>
                    <td><span class="badge bg-info"><?php echo htmlspecialchars($winner['bid_winner_site_number']); ?></span></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <div class="col-md-6">
            <h5>Plan Information</h5>
            <table class="table table-sm">
                <tr>
                    <th width="40%">Plan:</th>
                    <td><?php echo htmlspecialchars($winner['plan_title']); ?></td>
                </tr>
                <tr>
                    <th>Plan Total:</th>
                    <td class="text-primary">₹<?php echo number_format($winner['plan_total'], 2); ?></td>
                </tr>
                <tr>
                    <th>Agreement Date:</th>
                    <td><?php echo date('d-m-Y', strtotime($winner['emi_date'])); ?></td>
                </tr>
                <tr>
                    <th>Declared By:</th>
                    <td><?php echo !empty($winner['declared_by']) ? htmlspecialchars($winner['declared_by']) : 'N/A'; ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">Payment Summary</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <small class="text-muted d-block">Total Paid</small>
                            <h4 class="text-primary">₹<?php echo number_format($total_paid, 2); ?></h4>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Pending</small>
                            <h4 class="text-warning">₹<?php echo number_format($winner['total_pending'] ?? 0, 2); ?></h4>
                        </div>
                    </div>
                    <div class="progress mt-3" style="height: 10px;">
                        <div class="progress-bar bg-success" style="width: <?php echo min($paid_percentage, 100); ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <small>Collection Progress</small>
                        <small><?php echo number_format($paid_percentage, 1); ?>%</small>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted">
                            <?php echo $winner['paid_emis_count']; ?> of <?php echo $winner['total_emis_count']; ?> EMIs paid
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0">Bid Winner Details</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <small class="text-muted d-block">Winner Amount</small>
                            <h4 class="text-success">₹<?php echo number_format($winner_amount, 2); ?></h4>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Balance After</small>
                            <h4 class="<?php echo $balance_after >= 0 ? 'text-info' : 'text-danger'; ?>">
                                ₹<?php echo number_format($balance_after, 2); ?>
                            </h4>
                        </div>
                    </div>
                    <div class="mt-3">
                        <table class="table table-sm">
                            <tr>
                                <th width="50%">Winner Date:</th>
                                <td><?php echo date('d-m-Y', strtotime($winner['winner_date'])); ?></td>
                            </tr>
                            <?php if (!empty($winner['winner_number'])): ?>
                            <tr>
                                <th>Winner Number:</th>
                                <td><?php echo htmlspecialchars($winner['winner_number']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Payment Status:</th>
                                <td>
                                    <?php if (!empty($winner['paid_date'])): ?>
                                        <span class="badge bg-success">Paid</span>
                                        on <?php echo date('d-m-Y', strtotime($winner['paid_date'])); ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($winner['payment_method'])): ?>
                            <tr>
                                <th>Payment Method:</th>
                                <td><?php echo ucfirst($winner['payment_method']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($winner['transaction_no'])): ?>
                            <tr>
                                <th>Transaction No:</th>
                                <td><?php echo htmlspecialchars($winner['transaction_no']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between">
                <div>
                    <a href="emi-schedule-member.php?id=<?php echo $winner_id; ?>" class="btn btn-primary">
                        <i class="fas fa-calendar-alt me-1"></i> View EMI Schedule
                    </a>
                    <a href="add-member.php?edit=<?php echo $winner_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit me-1"></i> Edit Member
                    </a>
                </div>
                <div>
                    <?php if (empty($winner['paid_date'])): ?>
                    <button type="button" class="btn btn-success" onclick="markAsPaid(<?php echo $winner_id; ?>)">
                        <i class="fas fa-check-circle me-1"></i> Mark as Paid
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>