<?php
// pay-emi.php - Pay EMI Page for Staff
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if staff is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: login.php');
    exit;
}

include 'includes/db.php';

$emi_id = isset($_GET['emi_id']) ? intval($_GET['emi_id']) : 0;

// Debug logging
error_log("Pay EMI - EMI ID: $emi_id");

if ($emi_id == 0) {
    $_SESSION['error'] = "Invalid EMI ID.";
    header("Location: index.php");
    exit;
}

// Fetch EMI details
$sql = "SELECT 
            es.id as emi_id,
            es.emi_due_date,
            es.emi_amount,
            es.status,
            es.member_id,
            m.agreement_number,
            m.customer_name,
            m.customer_number,
            p.title as plan_title
        FROM emi_schedule es
        JOIN members m ON es.member_id = m.id
        JOIN plans p ON m.plan_id = p.id
        WHERE es.id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error'] = "Database error. Please try again.";
    header("Location: index.php");
    exit;
}

$stmt->bind_param("i", $emi_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    error_log("EMI not found - EMI ID: $emi_id");
    $_SESSION['error'] = "EMI not found.";
    header("Location: index.php");
    exit;
}

$emi = $result->fetch_assoc();
$stmt->close();

// Get member's other pending EMIs count
$sql_pending = "SELECT COUNT(*) as pending_count 
                FROM emi_schedule 
                WHERE member_id = ? 
                AND status = 'unpaid' 
                AND id != ?";
$stmt_pending = $conn->prepare($sql_pending);
$stmt_pending->bind_param("ii", $emi['member_id'], $emi_id);
$stmt_pending->execute();
$result_pending = $stmt_pending->get_result();
$pending_stats = $result_pending->fetch_assoc();
$stmt_pending->close();

$pending_count = $pending_stats['pending_count'] ?? 0;

// Handle Payment
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $bill_number = trim($_POST['bill_number'] ?? '');
    $paid_date = $_POST['paid_date'] ?? '';
    $payment_type = $_POST['payment_type'] ?? 'cash';
    $cash_amount = floatval($_POST['cash_amount'] ?? 0);
    $upi_amount = floatval($_POST['upi_amount'] ?? 0);
    $staff_id = $_SESSION['user_id'];
    
    // Debug logging
    error_log("Payment attempt - EMI ID: $emi_id, Type: $payment_type, Cash: $cash_amount, UPI: $upi_amount, Bill: $bill_number");
    
    // Validation
    $errors = [];
    
    if (empty($bill_number)) {
        $errors[] = "Bill Number is required.";
    }
    
    if (empty($paid_date) || !strtotime($paid_date)) {
        $errors[] = "Valid Paid Date is required.";
    } elseif (strtotime($paid_date) > strtotime(date('Y-m-d'))) {
        $errors[] = "Paid Date cannot be in the future.";
    }
    
    $total_paid = 0;
    if ($payment_type == 'cash') {
        $total_paid = $cash_amount;
        if ($cash_amount <= 0) {
            $errors[] = "Cash amount must be greater than zero.";
        }
    } elseif ($payment_type == 'upi') {
        $total_paid = $upi_amount;
        if ($upi_amount <= 0) {
            $errors[] = "UPI amount must be greater than zero.";
        }
    } elseif ($payment_type == 'both') {
        $total_paid = $cash_amount + $upi_amount;
        if ($cash_amount <= 0 && $upi_amount <= 0) {
            $errors[] = "Please enter either cash or UPI amount.";
        }
    }
    
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        try {
            // Update EMI schedule
            $update_sql = "UPDATE emi_schedule SET 
                          payment_type = ?,
                          cash_amount = ?,
                          upi_amount = ?,
                          emi_bill_number = ?,
                          paid_date = ?,
                          status = 'paid',
                          collected_by = ?
                          WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            
            // Set amounts based on payment type
            $update_cash = 0;
            $update_upi = 0;
            
            if ($payment_type == 'cash') {
                $update_cash = $cash_amount;
            } elseif ($payment_type == 'upi') {
                $update_upi = $upi_amount;
            } elseif ($payment_type == 'both') {
                $update_cash = $cash_amount;
                $update_upi = $upi_amount;
            }
            
            $stmt->bind_param("sddssii", 
                $payment_type, 
                $update_cash, 
                $update_upi, 
                $bill_number, 
                $paid_date, 
                $staff_id,
                $emi_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Database update failed: " . $stmt->error);
            }
            $stmt->close();
            
            $conn->commit();
            
            // Success message
            $success_message = "Payment recorded successfully! " .
                "Customer: " . $emi['customer_name'] . ", " .
                "Bill: $bill_number, " .
                "Amount: ₹" . number_format($total_paid, 2);
            
            if ($payment_type == 'both') {
                $success_message .= " (Cash: ₹" . number_format($cash_amount, 2) . " + UPI: ₹" . number_format($upi_amount, 2) . ")";
            } elseif ($payment_type == 'cash') {
                $success_message .= " (Cash)";
            } elseif ($payment_type == 'upi') {
                $success_message .= " (UPI)";
            }
            
            $_SESSION['success'] = $success_message;
            
            // Redirect back to staff dashboard
            header("Location:index.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
            error_log("Pay EMI error: " . $e->getMessage());
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode(" ", $errors);
    }
}

$current_date = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay EMI - SRI VARI CHITS</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .payment-card {
            max-width: 800px;
            margin: 50px auto;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: none;
        }
        .payment-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 25px;
        }
        .payment-body {
            padding: 30px;
        }
        .customer-info {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .amount-input {
            font-size: 1.2rem;
            font-weight: 600;
        }
        .btn-pay {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .amount-display {
            font-size: 2.5rem;
            font-weight: 700;
            color: #28a745;
        }
    </style>
</head>
<body>
    <!-- Payment Form -->
    <div class="container">
        <div class="payment-card">
            <div class="payment-header text-center">
                <h3 class="mb-2">Pay EMI</h3>
                <p class="mb-0 opacity-75">Process payment for customer</p>
            </div>
            
            <div class="payment-body">
                <!-- Flash Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Customer Info -->
                <div class="customer-info">
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="mb-3">Customer Details</h5>
                            <div class="mb-2">
                                <strong>Name:</strong> <?php echo htmlspecialchars($emi['customer_name']); ?>
                            </div>
                            <div class="mb-2">
                                <strong>Phone:</strong> 
                                <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $emi['customer_number']); ?>" 
                                   class="text-decoration-none">
                                    <?php echo htmlspecialchars($emi['customer_number']); ?>
                                </a>
                            </div>
                            <div class="mb-2">
                                <strong>Agreement No:</strong> <?php echo htmlspecialchars($emi['agreement_number']); ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5 class="mb-3">Payment Details</h5>
                            <div class="mb-2">
                                <strong>Plan:</strong> <?php echo htmlspecialchars($emi['plan_title']); ?>
                            </div>
                            <div class="mb-2">
                                <strong>Due Date:</strong> <?php echo date('d-m-Y', strtotime($emi['emi_due_date'])); ?>
                            </div>
                            <div class="mb-2">
                                <strong>Pending EMIs:</strong> 
                                <span class="badge bg-warning"><?php echo $pending_count; ?> remaining</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Form -->
                <form method="POST" novalidate>
                    <div class="row g-3">
                        <!-- EMI Amount -->
                        <div class="col-md-12 text-center mb-4">
                            <h6 class="text-muted">EMI Amount to Pay</h6>
                            <div class="amount-display">
                                ₹<?php echo number_format($emi['emi_amount'], 2); ?>
                            </div>
                        </div>
                        
                        <!-- Payment Type -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="paymentType" name="payment_type" required onchange="togglePaymentFields()">
                                <option value="cash" <?php echo ($_POST['payment_type'] ?? 'cash') === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="upi" <?php echo ($_POST['payment_type'] ?? '') === 'upi' ? 'selected' : ''; ?>>UPI</option>
                                <option value="both" <?php echo ($_POST['payment_type'] ?? '') === 'both' ? 'selected' : ''; ?>>Both (Cash + UPI)</option>
                            </select>
                        </div>
                        
                        <!-- Bill Number -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Bill Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="bill_number" 
                                   value="<?php echo htmlspecialchars($_POST['bill_number'] ?? 'BILL-' . date('YmdHis')); ?>"
                                   placeholder="Enter bill number" required>
                        </div>
                        
                        <!-- Cash Amount -->
                        <div class="col-md-6" id="cashField">
                            <label class="form-label fw-bold">Cash Amount (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control amount-input" name="cash_amount" id="cashAmount"
                                   value="<?php echo $_POST['cash_amount'] ?? $emi['emi_amount']; ?>" 
                                   min="0.01" max="<?php echo $emi['emi_amount'] * 2; ?>" required>
                            <small class="text-muted">Enter cash amount received</small>
                        </div>
                        
                        <!-- UPI Amount -->
                        <div class="col-md-6" id="upiField" style="display: none;">
                            <label class="form-label fw-bold">UPI Amount (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control amount-input" name="upi_amount" id="upiAmount"
                                   value="<?php echo $_POST['upi_amount'] ?? $emi['emi_amount']; ?>" 
                                   min="0.01" max="<?php echo $emi['emi_amount'] * 2; ?>" required>
                            <small class="text-muted">Enter UPI amount received</small>
                        </div>
                        
                        <!-- Both Amounts -->
                        <div class="col-md-6" id="bothCashField" style="display: none;">
                            <label class="form-label fw-bold">Cash Amount (₹)</label>
                            <input type="number" step="0.01" class="form-control amount-input" name="both_cash" id="bothCash"
                                   value="<?php echo $_POST['both_cash'] ?? $emi['emi_amount'] / 2; ?>" 
                                   min="0" max="<?php echo $emi['emi_amount'] * 2; ?>">
                        </div>
                        
                        <div class="col-md-6" id="bothUpiField" style="display: none;">
                            <label class="form-label fw-bold">UPI Amount (₹)</label>
                            <input type="number" step="0.01" class="form-control amount-input" name="both_upi" id="bothUpi"
                                   value="<?php echo $_POST['both_upi'] ?? $emi['emi_amount'] / 2; ?>" 
                                   min="0" max="<?php echo $emi['emi_amount'] * 2; ?>">
                        </div>
                        
                        <!-- Paid Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Paid Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="paid_date"
                                   value="<?php echo $_POST['paid_date'] ?? $current_date; ?>"
                                   max="<?php echo $current_date; ?>" required>
                        </div>
                        
                        <!-- Total Display -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Total Amount</label>
                            <div class="alert alert-success">
                                <div class="h4 mb-0">₹<span id="totalAmount"><?php echo number_format($emi['emi_amount'], 2); ?></span></div>
                                <small id="amountStatus" class="text-success">Full payment</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="mt-5 d-flex gap-3 justify-content-center">
                        <button type="submit" class="btn btn-pay btn-lg">
                            <i class="fas fa-check-circle me-2"></i> Process Payment
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-lg">
                            <i class="fas fa-times me-2"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        togglePaymentFields();
        updateTotalAmount();
        
        // Add event listeners
        document.getElementById('cashAmount')?.addEventListener('input', updateTotalAmount);
        document.getElementById('upiAmount')?.addEventListener('input', updateTotalAmount);
        document.getElementById('bothCash')?.addEventListener('input', updateTotalAmount);
        document.getElementById('bothUpi')?.addEventListener('input', updateTotalAmount);
    });

    function togglePaymentFields() {
        const paymentType = document.getElementById('paymentType').value;
        
        // Hide all fields
        document.getElementById('cashField').style.display = 'none';
        document.getElementById('upiField').style.display = 'none';
        document.getElementById('bothCashField').style.display = 'none';
        document.getElementById('bothUpiField').style.display = 'none';
        
        // Clear required attributes
        document.getElementById('cashAmount').required = false;
        document.getElementById('upiAmount').required = false;
        
        // Show relevant fields
        if (paymentType === 'cash') {
            document.getElementById('cashField').style.display = 'block';
            document.getElementById('cashAmount').required = true;
        } else if (paymentType === 'upi') {
            document.getElementById('upiField').style.display = 'block';
            document.getElementById('upiAmount').required = true;
        } else if (paymentType === 'both') {
            document.getElementById('bothCashField').style.display = 'block';
            document.getElementById('bothUpiField').style.display = 'block';
        }
        
        updateTotalAmount();
    }

    function updateTotalAmount() {
        const paymentType = document.getElementById('paymentType').value;
        const emiAmount = <?php echo $emi['emi_amount']; ?>;
        let total = 0;
        
        if (paymentType === 'cash') {
            total = parseFloat(document.getElementById('cashAmount').value) || 0;
        } else if (paymentType === 'upi') {
            total = parseFloat(document.getElementById('upiAmount').value) || 0;
        } else if (paymentType === 'both') {
            const bothCash = parseFloat(document.getElementById('bothCash').value) || 0;
            const bothUpi = parseFloat(document.getElementById('bothUpi').value) || 0;
            total = bothCash + bothUpi;
        }
        
        // Update display
        document.getElementById('totalAmount').textContent = total.toFixed(2);
        
        // Update status
        const statusElement = document.getElementById('amountStatus');
        if (total >= emiAmount) {
            statusElement.textContent = 'Full payment';
            statusElement.className = 'text-success';
        } else if (total > 0) {
            statusElement.textContent = 'Partial payment';
            statusElement.className = 'text-warning';
        } else {
            statusElement.textContent = 'No amount entered';
            statusElement.className = 'text-danger';
        }
    }

    // Auto-fill amounts when payment type changes
    document.getElementById('paymentType').addEventListener('change', function() {
        const emiAmount = <?php echo $emi['emi_amount']; ?>;
        
        if (this.value === 'cash') {
            document.getElementById('cashAmount').value = emiAmount;
        } else if (this.value === 'upi') {
            document.getElementById('upiAmount').value = emiAmount;
        } else if (this.value === 'both') {
            document.getElementById('bothCash').value = emiAmount / 2;
            document.getElementById('bothUpi').value = emiAmount / 2;
        }
        
        updateTotalAmount();
    });
    </script>
</body>
</html>