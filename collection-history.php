<?php
// collection-history.php - Collection History with Filters (Fixed)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

// Safe string function
function safe_html($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function safe_js($str) {
    return addslashes(safe_html($str));
}

// Get filter parameters
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$collector_id = isset($_GET['collector']) ? intval($_GET['collector']) : 0;
$payment_mode = isset($_GET['payment_mode']) ? $_GET['payment_mode'] : '';

// Set date ranges based on filter type
$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-d', strtotime('first day of this month'));
$month_end = date('Y-m-d', strtotime('last day of this month'));

switch($filter_type) {
    case 'today':
        $start_date = $today;
        $end_date = $today;
        break;
    case 'week':
        $start_date = $week_start;
        $end_date = $week_end;
        break;
    case 'month':
        $start_date = $month_start;
        $end_date = $month_end;
        break;
    case 'custom':
        if (empty($start_date) || empty($end_date)) {
            $start_date = $month_start;
            $end_date = $month_end;
        }
        break;
    default:
        $start_date = $today;
        $end_date = $today;
}

// Build query
$sql = "SELECT es.*, 
        m.customer_name, 
        m.agreement_number,
        m.customer_number,
        m.plan_id,
        p.title as plan_title,
        u.full_name as collector_name,
        u.username as collector_username
        FROM emi_schedule es
        JOIN members m ON es.member_id = m.id
        JOIN plans p ON m.plan_id = p.id
        LEFT JOIN users u ON es.collected_by = u.id
        WHERE es.status = 'paid' 
        AND es.paid_date BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = "ss";

// Add collector filter
if ($collector_id > 0) {
    $sql .= " AND es.collected_by = ?";
    $params[] = $collector_id;
    $types .= "i";
}

// Add payment mode filter
if (!empty($payment_mode)) {
    $sql .= " AND es.payment_type = ?";
    $params[] = $payment_mode;
    $types .= "s";
}

$sql .= " ORDER BY es.paid_date DESC, es.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$collections = [];
$total_amount = 0;
$cash_total = 0;
$upi_total = 0;
$both_total = 0;

while ($row = $result->fetch_assoc()) {
    $collections[] = $row;
    $total_amount += $row['emi_amount'];
    
    if ($row['payment_type'] == 'cash') {
        $cash_total += $row['cash_amount'];
    } elseif ($row['payment_type'] == 'upi') {
        $upi_total += $row['upi_amount'];
    } elseif ($row['payment_type'] == 'both') {
        $both_total += ($row['cash_amount'] + $row['upi_amount']);
    }
}
$stmt->close();

// Get collectors list for filter
$collectors = [];
$collector_sql = "SELECT DISTINCT u.id, u.full_name, u.username 
                  FROM users u 
                  JOIN emi_schedule es ON es.collected_by = u.id 
                  WHERE es.status = 'paid'
                  ORDER BY u.full_name";
$collector_result = $conn->query($collector_sql);
while ($row = $collector_result->fetch_assoc()) {
    $collectors[] = $row;
}

// Get summary by date
$summary_sql = "SELECT DATE(paid_date) as collection_date, 
                       COUNT(*) as count,
                       SUM(emi_amount) as total,
                       SUM(CASE WHEN payment_type = 'cash' THEN cash_amount ELSE 0 END) as cash_total,
                       SUM(CASE WHEN payment_type = 'upi' THEN upi_amount ELSE 0 END) as upi_total,
                       SUM(CASE WHEN payment_type = 'both' THEN (cash_amount + upi_amount) ELSE 0 END) as both_total
                FROM emi_schedule
                WHERE status = 'paid' 
                AND paid_date BETWEEN ? AND ?
                GROUP BY DATE(paid_date)
                ORDER BY collection_date DESC";
$stmt_summary = $conn->prepare($summary_sql);
$stmt_summary->bind_param("ss", $start_date, $end_date);
$stmt_summary->execute();
$summary_result = $stmt_summary->get_result();
$daily_summary = [];
while ($row = $summary_result->fetch_assoc()) {
    $daily_summary[] = $row;
}
$stmt_summary->close();
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .filter-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .filter-card .form-label {
        color: white;
        font-weight: 500;
    }
    .filter-card .form-control, .filter-card .form-select {
        background: rgba(255,255,255,0.9);
        border: none;
    }
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 15px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        transition: transform 0.3s;
        height: 100%;
    }
    .stat-card:hover {
        transform: translateY(-5px);
    }
    .stat-card h3 {
        margin: 10px 0;
        font-size: 24px;
        font-weight: bold;
    }
    .stat-card .stat-icon {
        font-size: 28px;
        color: #667eea;
    }
    .summary-card {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        border-left: 4px solid #0d6efd;
    }
    .btn-filter {
        background: rgba(255,255,255,0.2);
        color: white;
        border: 1px solid rgba(255,255,255,0.3);
        margin: 0 5px;
        padding: 5px 12px;
        font-size: 13px;
    }
    .btn-filter:hover, .btn-filter.active {
        background: white;
        color: #667eea;
        border-color: white;
    }
    .collection-table {
        font-size: 13px;
    }
    .collection-table th {
        background: #f8f9fa;
        white-space: nowrap;
    }
    .collection-table td {
        word-break: break-word;
        vertical-align: middle;
    }
    .badge-cash {
        background-color: #198754;
        color: white;
    }
    .badge-upi {
        background-color: #0d6efd;
        color: white;
    }
    .badge-both {
        background-color: #ffc107;
        color: black;
    }
    .whatsapp-btn-small {
        background: #25D366;
        color: white;
        border: none;
        padding: 4px 8px;
        font-size: 11px;
        border-radius: 4px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 3px;
    }
    .whatsapp-btn-small:hover {
        background: #128C7E;
    }
    .table-responsive-custom {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .customer-info {
        min-width: 150px;
    }
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        min-width: 100px;
    }
    @media (max-width: 768px) {
        .stat-card h3 {
            font-size: 18px;
        }
        .filter-card .row > div {
            margin-bottom: 10px;
        }
        .btn-filter {
            padding: 3px 8px;
            font-size: 11px;
        }
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
                $page_title = "Collection History";
                $breadcrumb_active = "Collection History";
                include 'includes/breadcrumb.php';
                ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="filter-card">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Collections</h5>
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Quick Filter</label>
                            <div>
                                <a href="?filter=today" class="btn btn-filter btn-sm <?= $filter_type == 'today' ? 'active' : '' ?>">Today</a>
                                <a href="?filter=week" class="btn btn-filter btn-sm <?= $filter_type == 'week' ? 'active' : '' ?>">This Week</a>
                                <a href="?filter=month" class="btn btn-filter btn-sm <?= $filter_type == 'month' ? 'active' : '' ?>">This Month</a>
                                <a href="?filter=custom" class="btn btn-filter btn-sm <?= $filter_type == 'custom' ? 'active' : '' ?>">Custom</a>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?= $start_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?= $end_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Collector</label>
                            <select class="form-select" name="collector">
                                <option value="0">All Collectors</option>
                                <?php foreach ($collectors as $collector): ?>
                                    <option value="<?= $collector['id']; ?>" <?= $collector_id == $collector['id'] ? 'selected' : ''; ?>>
                                        <?= safe_html($collector['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-light w-100">Apply Filter</button>
                        </div>
                    </form>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
                            <h3>₹<?= number_format($total_amount, 2); ?></h3>
                            <p class="text-muted mb-0">Total Collection</p>
                            <small><?= count($collections); ?> transactions</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                            <h3>₹<?= number_format($cash_total, 2); ?></h3>
                            <p class="text-muted mb-0">Cash Collection</p>
                            <small class="text-success"><?= $total_amount > 0 ? number_format(($cash_total/$total_amount)*100, 1) : 0; ?>% of total</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fab fa-google-pay"></i></div>
                            <h3>₹<?= number_format($upi_total, 2); ?></h3>
                            <p class="text-muted mb-0">UPI Collection</p>
                            <small class="text-info"><?= $total_amount > 0 ? number_format(($upi_total/$total_amount)*100, 1) : 0; ?>% of total</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                            <h3>₹<?= number_format($both_total, 2); ?></h3>
                            <p class="text-muted mb-0">Mixed Collection</p>
                            <small class="text-warning">Cash + UPI</small>
                        </div>
                    </div>
                </div>

                <!-- Daily Summary -->
                <?php if (!empty($daily_summary)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Daily Collection Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Transactions</th>
                                        <th>Cash (₹)</th>
                                        <th>UPI (₹)</th>
                                        <th>Mixed (₹)</th>
                                        <th>Total (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daily_summary as $day): ?>
                                    <tr>
                                        <td><?= date('d-m-Y', strtotime($day['collection_date'])); ?></td>
                                        <td><?= $day['count']; ?></td>
                                        <td>₹<?= number_format($day['cash_total'], 2); ?></td>
                                        <td>₹<?= number_format($day['upi_total'], 2); ?></td>
                                        <td>₹<?= number_format($day['both_total'], 2); ?></td>
                                        <td><strong>₹<?= number_format($day['total'], 2); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th>Total</th>
                                        <th><?= count($collections); ?></th>
                                        <th>₹<?= number_format($cash_total, 2); ?></th>
                                        <th>₹<?= number_format($upi_total, 2); ?></th>
                                        <th>₹<?= number_format($both_total, 2); ?></th>
                                        <th>₹<?= number_format($total_amount, 2); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Collection Details Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history me-2"></i>Collection Details
                            <span class="badge bg-secondary ms-2"><?= count($collections); ?> records</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($collections)): ?>
                        <div class="table-responsive-custom">
                            <table class="table table-bordered table-hover collection-table">
                                <thead>
                                    <tr>
                                        <th width="50">#</th>
                                        <th width="100">Date</th>
                                        <th width="120">Bill No.</th>
                                        <th class="customer-info">Customer</th>
                                        <th width="100">Agreement</th>
                                        <th width="150">Plan</th>
                                        <th width="100">Amount (₹)</th>
                                        <th width="100">Payment Type</th>
                                        <th width="120">Collected By</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = 1;
                                    foreach ($collections as $collection): 
                                        $customer_phone = $collection['customer_number'] ?? '';
                                        $customer_name = safe_html($collection['customer_name']);
                                        $agreement_no = safe_html($collection['agreement_number']);
                                        $plan_title = safe_html($collection['plan_title']);
                                        $bill_number = safe_html($collection['emi_bill_number']);
                                        $collector_name = safe_html($collection['collector_name']);
                                        $collector_username = safe_html($collection['collector_username']);
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $counter++; ?></td>
                                        <td><?= date('d-m-Y', strtotime($collection['paid_date'])); ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark"><?= $bill_number ?: 'N/A'; ?></span>
                                        </td>
                                        <td>
                                            <strong><?= $customer_name; ?></strong>
                                            <?php if (!empty($customer_phone)): ?>
                                                <br><small class="text-muted"><?= safe_html($customer_phone); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $agreement_no; ?></td>
                                        <td><small><?= $plan_title; ?></small></td>
                                        <td><strong>₹<?= number_format($collection['emi_amount'], 2); ?></strong></td>
                                        <td>
                                            <?php if ($collection['payment_type'] == 'cash'): ?>
                                                <span class="badge badge-cash">Cash</span>
                                                <br><small>₹<?= number_format($collection['cash_amount'], 2); ?></small>
                                            <?php elseif ($collection['payment_type'] == 'upi'): ?>
                                                <span class="badge badge-upi">UPI</span>
                                                <br><small>₹<?= number_format($collection['upi_amount'], 2); ?></small>
                                            <?php else: ?>
                                                <span class="badge badge-both">Both</span>
                                                <br><small>Cash: ₹<?= number_format($collection['cash_amount'], 2); ?></small>
                                                <br><small>UPI: ₹<?= number_format($collection['upi_amount'], 2); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $collector_name; ?>
                                            <?php if (!empty($collector_username) && $collector_username != $collector_name): ?>
                                                <br><small class="text-muted">(<?= $collector_username; ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="pay-emi.php?undo=<?= $collection['id']; ?>&member=<?= $collection['member_id']; ?>" 
                                                   class="btn btn-sm btn-warning" title="Undo Payment">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                                <?php if (!empty($customer_phone)): 
                                                    $whatsapp_number = preg_replace('/\D/', '', $customer_phone);
                                                    if (substr($whatsapp_number, 0, 1) == '0') $whatsapp_number = substr($whatsapp_number, 1);
                                                    if (strlen($whatsapp_number) == 10) $whatsapp_number = '91' . $whatsapp_number;
                                                ?>
                                                <button class="btn btn-sm whatsapp-btn-small" 
                                                        onclick="sendReceipt('<?= safe_js($customer_name); ?>', 
                                                                        '<?= safe_js($agreement_no); ?>', 
                                                                        '<?= safe_js($plan_title); ?>', 
                                                                        <?= $collection['emi_amount']; ?>, 
                                                                        '<?= safe_js($bill_number); ?>', 
                                                                        '<?= date('d-m-Y', strtotime($collection['paid_date'])); ?>', 
                                                                        '<?= safe_js($collection['payment_type']); ?>', 
                                                                        <?= $collection['cash_amount'] ?? 0; ?>, 
                                                                        <?= $collection['upi_amount'] ?? 0; ?>, 
                                                                        '<?= safe_js($collector_name); ?>', 
                                                                        '<?= safe_js($collector_username); ?>', 
                                                                        '<?= $whatsapp_number; ?>')"
                                                        title="Send WhatsApp Receipt">
                                                    <i class="fab fa-whatsapp"></i>
                                                </button>
                                                <?php endif; ?>
                                                <a href="emi-schedule-member.php?id=<?= $collection['member_id']; ?>" 
                                                   class="btn btn-sm btn-info" title="View Member">
                                                    <i class="fas fa-user"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-dark">
                                    <tr>
                                        <th colspan="6" class="text-end">Total:</th>
                                        <th><strong>₹<?= number_format($total_amount, 2); ?></strong></th>
                                        <th colspan="3"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>No collection records found for the selected period.</p>
                            <small>Try changing the filter criteria</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Export Options -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Export Options</h5>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print Report
                        </button>
                        <button class="btn btn-info" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-2"></i>Export to Excel
                        </button>
                    </div>
                </div>
            </div>

            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>

    <script>
        // Send WhatsApp Receipt
        function sendReceipt(customerName, agreementNo, planTitle, amount, billNo, paidDate, paymentType, cashAmount, upiAmount, collectorName, collectorUsername, whatsappNumber) {
            let message = `✅ *Payment Receipt - Shree Vaari Chits Private Limited* ✅\n\n`;
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
            message += `📞 Contact: +91 1234567890\n`;
            message += `📍 Shree Vaari Chits Private Limited`;
            
            const encodedMessage = encodeURIComponent(message);
            const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodedMessage}`;
            window.open(whatsappUrl, '_blank');
        }

        // Export to Excel
        function exportToExcel() {
            const table = document.querySelector('.collection-table');
            if (!table) return;
            const html = table.outerHTML;
            const blob = new Blob([html], {type: 'application/vnd.ms-excel'});
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.href = url;
            link.download = 'collection_history_' + new Date().toISOString().slice(0,10) + '.xls';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
    </script>

    <style>
        @media print {
            .startbar, .topbar, .filter-card, .btn, .action-buttons, .card-header .btn, .rightbar, footer {
                display: none !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .page-wrapper {
                margin: 0 !important;
                padding: 0 !important;
            }
            .table-responsive-custom {
                overflow: visible !important;
            }
            .collection-table {
                font-size: 10px !important;
            }
        }
    </style>
</body>
</html>