<?php
// Non-Collections Report (Overdue/Pending payments)

$query = "
    SELECT 
        es.id,
        es.emi_due_date,
        es.emi_amount,
        m.customer_name,
        m.agreement_number,
        m.customer_number,
        p.title as plan_name,
        DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue,
        (SELECT COUNT(*) FROM emi_schedule es2 
         WHERE es2.member_id = m.id AND es2.status = 'paid') as paid_installments,
        (SELECT COUNT(*) FROM emi_schedule es3 
         WHERE es3.member_id = m.id) as total_installments
    FROM emi_schedule es
    JOIN members m ON es.member_id = m.id
    JOIN plans p ON m.plan_id = p.id
    WHERE es.status = 'unpaid'
    AND es.emi_due_date <= CURDATE()
";

if (!empty($plan_id)) {
    $query .= " AND m.plan_id = " . intval($plan_id);
}

if (!empty($status) && $status == 'overdue') {
    $query .= " AND es.emi_due_date < CURDATE()";
}

if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND es.emi_due_date BETWEEN '$start_date' AND '$end_date'";
}

$query .= " ORDER BY days_overdue DESC, es.emi_due_date ASC";

$result = $conn->query($query);

// Summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_overdue,
        SUM(emi_amount) as total_overdue_amount,
        AVG(emi_amount) as avg_overdue_amount,
        COUNT(DISTINCT member_id) as affected_customers,
        MAX(DATEDIFF(CURDATE(), emi_due_date)) as max_overdue_days,
        MIN(DATEDIFF(CURDATE(), emi_due_date)) as min_overdue_days
    FROM emi_schedule 
    WHERE status = 'unpaid'
    AND emi_due_date <= CURDATE()
";

$summary = $conn->query($summary_query)->fetch_assoc();

// Overdue by days range
$overdue_ranges = $conn->query("
    SELECT 
        CASE 
            WHEN DATEDIFF(CURDATE(), emi_due_date) <= 7 THEN '0-7 days'
            WHEN DATEDIFF(CURDATE(), emi_due_date) <= 30 THEN '8-30 days'
            WHEN DATEDIFF(CURDATE(), emi_due_date) <= 90 THEN '31-90 days'
            ELSE '90+ days'
        END as overdue_range,
        COUNT(*) as payment_count,
        SUM(emi_amount) as total_amount
    FROM emi_schedule 
    WHERE status = 'unpaid'
    AND emi_due_date <= CURDATE()
    GROUP BY overdue_range
    ORDER BY FIELD(overdue_range, '0-7 days', '8-30 days', '31-90 days', '90+ days')
");
?>

<h4 class="mb-4">Non-Collections (Overdue Payments) Report</h4>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Overdue</h6>
                <h2 class="text-danger mb-0">₹<?= number_format($summary['total_overdue_amount'] ?? 0, 0) ?></h2>
                <small><?= $summary['total_overdue'] ?? 0 ?> payments</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h6 class="text-muted">Affected Customers</h6>
                <h2 class="text-warning mb-0"><?= $summary['affected_customers'] ?? 0 ?></h2>
                <small>With overdue payments</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <h6 class="text-muted">Average Overdue</h6>
                <h2 class="text-info mb-0">₹<?= number_format($summary['avg_overdue_amount'] ?? 0, 0) ?></h2>
                <small>Per payment</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-purple">
            <div class="card-body text-center">
                <h6 class="text-muted">Overdue Period</h6>
                <h4 class="text-purple mb-0"><?= $summary['min_overdue_days'] ?? 0 ?> - <?= $summary['max_overdue_days'] ?? 0 ?> days</h4>
                <small>Min - Max days</small>
            </div>
        </div>
    </div>
</div>

<!-- Overdue Analysis -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Overdue Analysis by Period</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Overdue Period</th>
                        <th>No. of Payments</th>
                        <th>Total Amount</th>
                        <th>Percentage</th>
                        <th>Severity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($overdue_ranges->num_rows > 0): ?>
                        <?php $total_overdue_all = $summary['total_overdue'] ?? 1; ?>
                        <?php while ($row = $overdue_ranges->fetch_assoc()): ?>
                            <?php 
                            $percentage = round(($row['payment_count'] / $total_overdue_all) * 100, 1);
                            $severity_class = '';
                            if (strpos($row['overdue_range'], '90+') !== false) $severity_class = 'danger';
                            elseif (strpos($row['overdue_range'], '31-90') !== false) $severity_class = 'warning';
                            elseif (strpos($row['overdue_range'], '8-30') !== false) $severity_class = 'info';
                            else $severity_class = 'secondary';
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?= $severity_class ?>">
                                        <?= $row['overdue_range'] ?>
                                    </span>
                                </td>
                                <td><?= $row['payment_count'] ?> payments</td>
                                <td class="text-danger">₹<?= number_format($row['total_amount'], 2) ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress progress-thin w-100 me-2">
                                            <div class="progress-bar bg-<?= $severity_class ?>" 
                                                 style="width: <?= $percentage ?>%"></div>
                                        </div>
                                        <span><?= $percentage ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($severity_class == 'danger'): ?>
                                        <span class="badge bg-danger">Critical</span>
                                    <?php elseif ($severity_class == 'warning'): ?>
                                        <span class="badge bg-warning">High</span>
                                    <?php elseif ($severity_class == 'info'): ?>
                                        <span class="badge bg-info">Medium</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Low</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">No overdue payments</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detailed Overdue List -->
<h5 class="mb-3">Detailed Overdue Payments List</h5>
<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Customer Details</th>
                <th>Plan</th>
                <th>Due Date</th>
                <th>Days Overdue</th>
                <th>Amount</th>
                <th>Payment History</th>
                <th>Priority</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php 
                $sr = 1;
                $total_overdue = 0;
                ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php 
                    $total_overdue += $row['emi_amount'];
                    $paid_percentage = $row['total_installments'] > 0 ? 
                        round(($row['paid_installments'] / $row['total_installments']) * 100, 1) : 0;
                    
                    // Determine priority
                    if ($row['days_overdue'] > 90) {
                        $priority = 'Critical';
                        $priority_class = 'danger';
                    } elseif ($row['days_overdue'] > 30) {
                        $priority = 'High';
                        $priority_class = 'warning';
                    } elseif ($row['days_overdue'] > 7) {
                        $priority = 'Medium';
                        $priority_class = 'info';
                    } else {
                        $priority = 'Low';
                        $priority_class = 'secondary';
                    }
                    ?>
                    <tr>
                        <td><?= $sr ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['customer_name']) ?></strong><br>
                            <small class="text-muted">Agr: <?= htmlspecialchars($row['agreement_number']) ?></small><br>
                            <small class="text-muted"><?= htmlspecialchars($row['customer_number']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($row['plan_name']) ?></td>
                        <td>
                            <?= date('d M Y', strtotime($row['emi_due_date'])) ?><br>
                            <small class="text-muted"><?= date('D', strtotime($row['emi_due_date'])) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $priority_class ?>">
                                <?= $row['days_overdue'] ?> days
                            </span>
                        </td>
                        <td class="text-danger fw-bold">₹<?= number_format($row['emi_amount'], 2) ?></td>
                        <td>
                            <small class="text-muted">
                                <?= $row['paid_installments'] ?>/<?= $row['total_installments'] ?> paid
                            </small>
                            <div class="progress progress-thin mt-1">
                                <div class="progress-bar bg-success" 
                                     style="width: <?= $paid_percentage ?>%"></div>
                            </div>
                            <small><?= $paid_percentage ?>% completed</small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $priority_class ?>"><?= $priority ?></span>
                        </td>
                        <td class="table-actions">
                            <a href="pay-emi.php?emi_id=<?= $row['id'] ?>" 
                               class="btn btn-sm btn-success" title="Mark as Paid">
                                <i class="fas fa-rupee-sign"></i> Pay Now
                            </a>
                            <a href="emi-schedule-member.php?id=<?= $row['id'] ?>" 
                               class="btn btn-sm btn-outline-primary" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php $sr++; ?>
                <?php endwhile; ?>
                <!-- Total Row -->
                <tr class="table-active fw-bold">
                    <td colspan="5" class="text-end">TOTAL OVERDUE AMOUNT:</td>
                    <td class="text-danger">₹<?= number_format($total_overdue, 2) ?></td>
                    <td colspan="3"></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        <i class="fas fa-check-circle fa-2x text-success mb-3"></i>
                        <p>No overdue payments found! All payments are up to date.</p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>