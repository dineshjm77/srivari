<?php
// Collections Report

$query = "
    SELECT 
        es.id,
        es.emi_bill_number,
        es.paid_date,
        es.emi_amount,
        m.customer_name,
        m.agreement_number,
        m.customer_number,
        p.title as plan_name,
        es.emi_due_date,
        DATEDIFF(es.paid_date, es.emi_due_date) as days_late
    FROM emi_schedule es
    JOIN members m ON es.member_id = m.id
    JOIN plans p ON m.plan_id = p.id
    WHERE es.status = 'paid'
    AND es.paid_date BETWEEN '$start_date' AND '$end_date'
";

if (!empty($plan_id)) {
    $query .= " AND m.plan_id = " . intval($plan_id);
}

$query .= " ORDER BY es.paid_date DESC";

$result = $conn->query($query);

// Summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_payments,
        SUM(emi_amount) as total_collected,
        AVG(emi_amount) as avg_payment,
        COUNT(DISTINCT member_id) as unique_customers,
        MAX(emi_amount) as max_payment,
        MIN(emi_amount) as min_payment
    FROM emi_schedule 
    WHERE status = 'paid'
    AND paid_date BETWEEN '$start_date' AND '$end_date'
";

$summary = $conn->query($summary_query)->fetch_assoc();

// Daily collection trend
$daily_query = "
    SELECT 
        DATE(paid_date) as payment_date,
        COUNT(*) as payment_count,
        SUM(emi_amount) as daily_total
    FROM emi_schedule 
    WHERE status = 'paid'
    AND paid_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(paid_date)
    ORDER BY payment_date DESC
    LIMIT 7
";

$daily_trend = $conn->query($daily_query);
?>

<h4 class="mb-4">Collections Report</h4>
<p class="text-muted mb-4">Showing collections from <?= date('d M Y', strtotime($start_date)) ?> to <?= date('d M Y', strtotime($end_date)) ?></p>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Collections</h6>
                <h2 class="text-success mb-0">₹<?= number_format($summary['total_collected'] ?? 0, 0) ?></h2>
                <small><?= $summary['total_payments'] ?? 0 ?> payments</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted">Unique Customers</h6>
                <h2 class="text-primary mb-0"><?= $summary['unique_customers'] ?? 0 ?></h2>
                <small>Paid customers</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <h6 class="text-muted">Average Payment</h6>
                <h2 class="text-info mb-0">₹<?= number_format($summary['avg_payment'] ?? 0, 0) ?></h2>
                <small>Per payment</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h6 class="text-muted">Payment Range</h6>
                <h4 class="text-warning mb-0">₹<?= number_format($summary['min_payment'] ?? 0, 0) ?> - ₹<?= number_format($summary['max_payment'] ?? 0, 0) ?></h4>
                <small>Min - Max</small>
            </div>
        </div>
    </div>
</div>

<!-- Recent Collections -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Recent Collections (Last 7 Days)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>No. of Payments</th>
                        <th>Daily Total</th>
                        <th>Average Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($daily_trend->num_rows > 0): ?>
                        <?php while ($row = $daily_trend->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($row['payment_date'])) ?></td>
                                <td><?= $row['payment_count'] ?> payments</td>
                                <td class="text-success">₹<?= number_format($row['daily_total'], 2) ?></td>
                                <td>₹<?= number_format($row['daily_total'] / $row['payment_count'], 2) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No recent collections</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detailed Collection List -->
<h5 class="mb-3">Detailed Collection List</h5>
<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Bill No</th>
                <th>Payment Date</th>
                <th>Customer Details</th>
                <th>Plan</th>
                <th>Due Date</th>
                <th>Amount</th>
                <th>Payment Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php 
                $sr = 1;
                $total_amount = 0;
                ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php $total_amount += $row['emi_amount']; ?>
                    <tr>
                        <td><?= $sr ?></td>
                        <td>
                            <?php if (!empty($row['emi_bill_number'])): ?>
                                <span class="badge bg-light text-dark"><?= htmlspecialchars($row['emi_bill_number']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= date('d M Y', strtotime($row['paid_date'])) ?><br>
                            <small class="text-muted"><?= date('h:i A', strtotime($row['paid_date'])) ?></small>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($row['customer_name']) ?></strong><br>
                            <small class="text-muted">Agr: <?= htmlspecialchars($row['agreement_number']) ?></small><br>
                            <small class="text-muted"><?= htmlspecialchars($row['customer_number']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($row['plan_name']) ?></td>
                        <td>
                            <?= date('d M Y', strtotime($row['emi_due_date'])) ?><br>
                            <?php if ($row['days_late'] > 0): ?>
                                <small class="text-danger">Late by <?= $row['days_late'] ?> days</small>
                            <?php elseif ($row['days_late'] < 0): ?>
                                <small class="text-success">Early by <?= abs($row['days_late']) ?> days</small>
                            <?php else: ?>
                                <small class="text-success">On Time</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-success fw-bold">₹<?= number_format($row['emi_amount'], 2) ?></td>
                        <td>
                            <?php if ($row['days_late'] > 7): ?>
                                <span class="badge bg-danger">Very Late</span>
                            <?php elseif ($row['days_late'] > 0): ?>
                                <span class="badge bg-warning">Late</span>
                            <?php else: ?>
                                <span class="badge bg-success">Timely</span>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <a href="emi-schedule-member.php?id=<?= $row['id'] ?>" 
                               class="btn btn-sm btn-outline-primary" title="View Member">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="print-receipt.php?emi_id=<?= $row['id'] ?>" 
                               class="btn btn-sm btn-outline-info" title="Print Receipt" target="_blank">
                                <i class="fas fa-print"></i>
                            </a>
                        </td>
                    </tr>
                    <?php $sr++; ?>
                <?php endwhile; ?>
                <!-- Total Row -->
                <tr class="table-active fw-bold">
                    <td colspan="6" class="text-end">TOTAL COLLECTIONS:</td>
                    <td class="text-success">₹<?= number_format($total_amount, 2) ?></td>
                    <td colspan="2"></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        <i class="fas fa-money-bill-wave fa-2x mb-3"></i>
                        <p>No collection records found for the selected period</p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>