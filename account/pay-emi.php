<?php
// pay-emi.php - WITH SIMPLE SEQUENTIAL BILL NUMBERS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

$emi_id = 0;
$member_id = 0;
$is_undo = false;

if (isset($_GET['undo']) && is_numeric($_GET['undo'])) {
    $emi_id = intval($_GET['undo']);
    $is_undo = true;
} elseif (isset($_GET['emi_id']) && is_numeric($_GET['emi_id'])) {
    $emi_id = intval($_GET['emi_id']);
}

$member_id = isset($_GET['member']) && is_numeric($_GET['member']) ? intval($_GET['member']) : 0;

if ($emi_id == 0 || $member_id == 0) {
    $_SESSION['error'] = "Invalid EMI or Member ID.";
    header("Location: manage-members.php");
    exit;
}

// Fetch EMI with member details
$sql = "SELECT es.*, m.customer_name, m.agreement_number 
        FROM emi_schedule es 
        JOIN members m ON es.member_id = m.id 
        WHERE es.id = ? AND es.member_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $emi_id, $member_id);
$stmt->execute();
$result = $stmt->get_result();
$emi = $result->fetch_assoc();
$stmt->close();

if (!$emi) {
    $_SESSION['error'] = "EMI not found.";
    header("Location: manage-members.php");
    exit;
}

// Function to get next bill number (simple sequential)
function getNextBillNumber($conn) {
    // Get the highest bill number from emi_schedule
    $sql = "SELECT emi_bill_number FROM emi_schedule 
            WHERE emi_bill_number IS NOT NULL AND emi_bill_number != ''
            ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        $last_bill = $row['emi_bill_number'];
        // Extract numeric part from bill number
        if (preg_match('/^(\d+)$/', $last_bill, $matches)) {
            $next_num = intval($matches[1]) + 1;
            return str_pad($next_num, 3, '0', STR_PAD_LEFT);
        } elseif (preg_match('/^BILL-(\d+)$/', $last_bill, $matches)) {
            $next_num = intval($matches[1]) + 1;
            return str_pad($next_num, 3, '0', STR_PAD_LEFT);
        } elseif (is_numeric($last_bill)) {
            $next_num = intval($last_bill) + 1;
            return str_pad($next_num, 3, '0', STR_PAD_LEFT);
        }
    }
    // Start from 281 as per your last bill
    return "281";
}

// Get next bill number for display
$next_bill_number = getNextBillNumber($conn);
$next_bill_number_display = $next_bill_number;

// Handle Undo with Reason
if ($is_undo && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $reason = trim($_POST['undo_reason'] ?? '');
    if (empty($reason)) {
        $_SESSION['error'] = "Please provide a reason for undoing the payment.";
        header("Location: pay-emi.php?undo=$emi_id&member=$member_id");
        exit;
    }

    $sql = "UPDATE emi_schedule 
            SET status = 'unpaid', 
                paid_date = NULL, 
                emi_bill_number = NULL,
                payment_type = 'cash',
                cash_amount = 0,
                upi_amount = 0,
                collected_by = NULL,
                undo_reason = ?
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $reason, $emi_id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Payment undone successfully. Reason: $reason";
    } else {
        $_SESSION['error'] = "Failed to undo payment.";
    }
    $stmt->close();
    header("Location: emi-schedule-member.php?id=$member_id");
    exit;
}

// Handle Payment with Types
if (!$is_undo && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $paid_date = $_POST['paid_date'] ?? date('Y-m-d');
    $bill_number = trim($_POST['bill_number'] ?? '');
    $payment_type = $_POST['payment_type'] ?? 'cash';
    $cash_amount = 0;
    $upi_amount = 0;
    $collected_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    
    // Validate bill number
    if (empty($bill_number)) {
        $_SESSION['error'] = "Bill number is required.";
        header("Location: pay-emi.php?emi_id=$emi_id&member=$member_id");
        exit;
    }
    
    // Check if bill number already exists
    $check_sql = "SELECT id FROM emi_schedule WHERE emi_bill_number = ? AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $bill_number, $emi_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $_SESSION['error'] = "Bill number $bill_number already exists. Please use a different bill number.";
        header("Location: pay-emi.php?emi_id=$emi_id&member=$member_id");
        exit;
    }
    $check_stmt->close();
    
    // Validate payment amounts
    if ($payment_type == 'cash') {
        $cash_amount = floatval($_POST['cash_amount'] ?? $emi['emi_amount']);
        $upi_amount = 0;
    } elseif ($payment_type == 'upi') {
        $cash_amount = 0;
        $upi_amount = floatval($_POST['upi_amount'] ?? $emi['emi_amount']);
    } elseif ($payment_type == 'both') {
        $cash_amount = floatval($_POST['cash_amount'] ?? 0);
        $upi_amount = floatval($_POST['upi_amount'] ?? 0);
        
        // Validate total matches EMI amount
        $total = $cash_amount + $upi_amount;
        if (abs($total - $emi['emi_amount']) > 0.01) {
            $_SESSION['error'] = "Cash + UPI amount (₹" . number_format($total, 2) . 
                                ") must equal EMI amount (₹" . number_format($emi['emi_amount'], 2) . ")";
            header("Location: pay-emi.php?emi_id=$emi_id&member=$member_id");
            exit;
        }
    }

    $sql = "UPDATE emi_schedule 
            SET status = 'paid', 
                paid_date = ?, 
                emi_bill_number = ?,
                payment_type = ?,
                cash_amount = ?,
                upi_amount = ?,
                collected_by = ?,
                undo_reason = NULL
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssddii", $paid_date, $bill_number, $payment_type, $cash_amount, $upi_amount, $collected_by, $emi_id);

    if ($stmt->execute()) {
        $type_display = ucfirst($payment_type);
        if ($payment_type == 'both') {
            $type_display = "Cash: ₹" . number_format($cash_amount, 2) . " + UPI: ₹" . number_format($upi_amount, 2);
        }
        $_SESSION['success'] = "EMI paid successfully! Bill: $bill_number | Type: $type_display";
    } else {
        $_SESSION['error'] = "Failed to record payment.";
    }
    $stmt->close();
    header("Location: emi-schedule-member.php?id=$member_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .bill-number-input {
        font-family: monospace;
        font-size: 18px;
        font-weight: bold;
    }
    .bill-suggestion {
        font-size: 12px;
        color: #6c757d;
        margin-top: 4px;
    }
    .bill-suggestion .suggested {
        color: #0d6efd;
        cursor: pointer;
        text-decoration: underline;
        font-weight: bold;
    }
    .bill-suggestion .suggested:hover {
        color: #0a58ca;
    }
    .current-bill {
        background: #e7f1ff;
        padding: 2px 6px;
        border-radius: 4px;
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
                $page_title = $is_undo ? "Undo EMI Payment" : "Pay EMI";
                $breadcrumb_active = $is_undo ? "Undo Payment" : "Record Payment";
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

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h4><?= $is_undo ? 'Undo EMI Payment' : 'Record EMI Payment'; ?></h4>
                            </div>
                            <div class="card-body">
                                <!-- EMI Information -->
                                <div class="mb-4 p-3 bg-light rounded">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Customer:</strong> <?= htmlspecialchars($emi['customer_name']); ?><br>
                                            <strong>Agreement:</strong> <?= htmlspecialchars($emi['agreement_number']); ?><br>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>EMI Amount:</strong> ₹<?= number_format($emi['emi_amount'], 2); ?><br>
                                            <strong>Due Date:</strong> <?= date('d-m-Y', strtotime($emi['emi_due_date'])); ?><br>
                                            <?php if ($is_undo && $emi['paid_date']): ?>
                                                <strong>Paid Date:</strong> <?= date('d-m-Y', strtotime($emi['paid_date'])); ?><br>
                                                <?php if ($emi['emi_bill_number']): ?>
                                                    <strong>Bill Number:</strong> <?= htmlspecialchars($emi['emi_bill_number']); ?><br>
                                                <?php endif; ?>
                                                <?php if ($emi['collected_by']): ?>
                                                    <strong>Collected By:</strong> User ID: <?= $emi['collected_by']; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <form method="POST" id="paymentForm">
                                    <?php if ($is_undo): ?>
                                        <!-- Undo Payment Form -->
                                        <div class="mb-3">
                                            <label class="form-label">Reason for Undo <span class="text-danger">*</span></label>
                                            <textarea class="form-control" name="undo_reason" rows="4" 
                                                      placeholder="e.g., Wrong entry, customer returned money, payment dispute, etc." 
                                                      required></textarea>
                                        </div>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            This will mark the EMI as unpaid and clear all payment details.
                                        </div>
                                    <?php else: ?>
                                        <!-- Payment Form with Types -->
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Bill Number <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control bill-number-input" 
                                                       name="bill_number" id="billNumber"
                                                       value="<?= $next_bill_number_display; ?>" 
                                                       placeholder="Enter bill number (e.g., 281, 282...)"
                                                       required>
                                                <div class="bill-suggestion">
                                                    Next suggested: <span class="suggested" onclick="useSuggestedBill()"><?= $next_bill_number_display; ?></span>
                                                    <br><small>Enter any number (simple sequential)</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Paid Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" name="paid_date" 
                                                       value="<?= date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Payment Type <span class="text-danger">*</span></label>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <select class="form-control" name="payment_type" id="paymentType" required>
                                                        <option value="cash" selected>Cash Payment</option>
                                                        <option value="upi">UPI Payment</option>
                                                        <option value="both">Both (Cash + UPI)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Payment Amount Sections -->
                                        <div id="cashSection" class="mb-3">
                                            <label class="form-label">Cash Amount (₹)</label>
                                            <input type="number" step="0.01" class="form-control" 
                                                   name="cash_amount" id="cashAmount"
                                                   value="<?= $emi['emi_amount']; ?>" required>
                                        </div>

                                        <div id="upiSection" class="mb-3" style="display: none;">
                                            <label class="form-label">UPI Amount (₹)</label>
                                            <input type="number" step="0.01" class="form-control" 
                                                   name="upi_amount" id="upiAmount"
                                                   value="0">
                                        </div>

                                        <div id="bothSection" class="mb-3" style="display: none;">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label class="form-label">Cash Amount (₹)</label>
                                                    <input type="number" step="0.01" class="form-control" 
                                                           name="cash_amount_both" id="cashAmountBoth"
                                                           value="0">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">UPI Amount (₹)</label>
                                                    <input type="number" step="0.01" class="form-control" 
                                                           name="upi_amount_both" id="upiAmountBoth"
                                                           value="<?= $emi['emi_amount']; ?>">
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <small class="text-muted">
                                                    Total: <span id="totalAmount">₹<?= number_format($emi['emi_amount'], 2); ?></span> | 
                                                    EMI Amount: ₹<?= number_format($emi['emi_amount'], 2); ?>
                                                </small>
                                            </div>
                                        </div>

                                        <!-- Collector Info -->
                                        <div class="alert alert-info">
                                            <i class="fas fa-user-check me-2"></i>
                                            This payment will be recorded as collected by: 
                                            <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Current User'); ?></strong>
                                            (<?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?>)
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                        <a href="emi-schedule-member.php?id=<?= $member_id; ?>" class="btn btn-secondary">Cancel</a>
                                        <button type="submit" class="btn <?= $is_undo ? 'btn-warning' : 'btn-success'; ?>">
                                            <?php if ($is_undo): ?>
                                                <i class="fas fa-undo me-2"></i> Confirm Undo Payment
                                            <?php else: ?>
                                                <i class="fas fa-check me-2"></i> Confirm Payment
                                            <?php endif; ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const paymentType = document.getElementById('paymentType');
            const cashSection = document.getElementById('cashSection');
            const upiSection = document.getElementById('upiSection');
            const bothSection = document.getElementById('bothSection');
            const cashAmount = document.getElementById('cashAmount');
            const upiAmount = document.getElementById('upiAmount');
            const cashAmountBoth = document.getElementById('cashAmountBoth');
            const upiAmountBoth = document.getElementById('upiAmountBoth');
            const totalAmount = document.getElementById('totalAmount');
            const emiAmount = <?= $emi['emi_amount']; ?>;

            function updateSections() {
                const type = paymentType.value;
                
                // Hide all sections first
                cashSection.style.display = 'none';
                upiSection.style.display = 'none';
                bothSection.style.display = 'none';
                
                // Show relevant sections
                if (type === 'cash') {
                    cashSection.style.display = 'block';
                    cashAmount.value = emiAmount;
                } else if (type === 'upi') {
                    upiSection.style.display = 'block';
                    upiAmount.value = emiAmount;
                } else if (type === 'both') {
                    bothSection.style.display = 'block';
                    cashAmountBoth.value = 0;
                    upiAmountBoth.value = emiAmount;
                    updateTotal();
                }
            }

            function updateTotal() {
                const cash = parseFloat(cashAmountBoth.value) || 0;
                const upi = parseFloat(upiAmountBoth.value) || 0;
                const total = cash + upi;
                totalAmount.textContent = '₹' + total.toFixed(2);
                
                // Highlight if total doesn't match EMI amount
                if (Math.abs(total - emiAmount) > 0.01) {
                    totalAmount.style.color = 'red';
                    totalAmount.innerHTML += ' <span class="text-danger">(Mismatch!)</span>';
                } else {
                    totalAmount.style.color = 'green';
                }
            }

            // Initialize
            updateSections();
            
            // Event listeners
            paymentType.addEventListener('change', updateSections);
            cashAmountBoth.addEventListener('input', updateTotal);
            upiAmountBoth.addEventListener('input', updateTotal);

            // Form submission validation
            document.getElementById('paymentForm').addEventListener('submit', function(e) {
                if (!paymentType) return; // Skip if undo form
                
                const type = paymentType.value;
                let isValid = true;
                
                if (type === 'both') {
                    const cash = parseFloat(cashAmountBoth.value) || 0;
                    const upi = parseFloat(upiAmountBoth.value) || 0;
                    const total = cash + upi;
                    
                    if (Math.abs(total - emiAmount) > 0.01) {
                        alert('Error: Cash + UPI amount (₹' + total.toFixed(2) + 
                              ') must equal EMI amount (₹' + emiAmount.toFixed(2) + ')');
                        isValid = false;
                    }
                    
                    // Set hidden fields for submission
                    document.querySelector('input[name="cash_amount"]').value = cash;
                    document.querySelector('input[name="upi_amount"]').value = upi;
                } else if (type === 'upi') {
                    document.querySelector('input[name="cash_amount"]').value = 0;
                }
                
                if (!isValid) {
                    e.preventDefault();
                }
            });
        });

        // Use suggested bill number
        function useSuggestedBill() {
            const suggested = '<?= $next_bill_number_display; ?>';
            document.getElementById('billNumber').value = suggested;
            document.getElementById('billNumber').focus();
        }

        // Auto-focus and select bill number
        const billInput = document.getElementById('billNumber');
        if (billInput) {
            billInput.focus();
            billInput.select();
        }
    </script>
</body>
</html>