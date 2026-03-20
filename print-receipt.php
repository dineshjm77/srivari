<?php
// print-receipt.php - 2-Inch Thermal Print Receipt
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

$emi_id = isset($_GET['emi_id']) ? intval($_GET['emi_id']) : 0;
$member_id = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
$print_type = isset($_GET['type']) ? $_GET['type'] : 'thermal';

if ($emi_id == 0 || $member_id == 0) {
    die("Invalid request.");
}

// Fetch EMI details
$sql = "SELECT es.*, 
        m.customer_name, 
        m.customer_number,
        m.customer_number2,
        m.agreement_number,
        m.customer_address,
        m.nominee_name,
        m.nominee_number,
        p.title as plan_title,
        p.plan_type,
        u.full_name as collector_name,
        u.username as collector_username
        FROM emi_schedule es
        JOIN members m ON es.member_id = m.id
        JOIN plans p ON m.plan_id = p.id
        LEFT JOIN users u ON es.collected_by = u.id
        WHERE es.id = ? AND es.member_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $emi_id, $member_id);
$stmt->execute();
$result = $stmt->get_result();
$receipt = $result->fetch_assoc();
$stmt->close();

if (!$receipt) {
    die("Receipt not found.");
}

// Receipt details
$receipt_no = $receipt['emi_bill_number'] ?? 'RCPT-' . date('Ymd') . '-' . $emi_id;
$receipt_date = $receipt['paid_date'] ? date('d-m-Y', strtotime($receipt['paid_date'])) : date('d-m-Y');
$receipt_time = date('h:i A');
$amount = $receipt['emi_amount'];

function number_to_words_thermal($number) {
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy',
        80 => 'Eighty', 90 => 'Ninety'
    );
    
    if ($number < 21) {
        return $words[$number];
    } elseif ($number < 100) {
        $tens = floor($number / 10) * 10;
        $units = $number % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($number < 1000) {
        $hundreds = floor($number / 100);
        $remainder = $number % 100;
        return $words[$hundreds] . ' Hundred' . ($remainder ? ' and ' . number_to_words_thermal($remainder) : '');
    } else {
        return number_format($number, 2);
    }
}

$amount_words = number_to_words_thermal(floor($amount)) . ' Rupees Only';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Receipt - <?= $receipt_no; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: 'Courier New', 'Lucida Console', monospace;
        }
        
        /* 2-Inch Thermal Printer Receipt (58mm width) */
        .receipt-thermal {
            width: 58mm;
            max-width: 58mm;
            background: white;
            margin: 0 auto;
            padding: 4px 2px;
            font-family: 'Roboto Mono', monospace;
            font-size: 10px;
            line-height: 1.3;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }
        
        .receipt-content {
            padding: 0 2px;
        }
        
        /* Header Section */
        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        
        .header h2 {
            font-size: 14px;
            font-weight: bold;
            margin: 0;
            letter-spacing: 1px;
        }
        
        .header .subtitle {
            font-size: 8px;
            margin: 2px 0;
        }
        
        .header .address {
            font-size: 7px;
            margin: 1px 0;
        }
        
        .receipt-title {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            margin: 5px 0;
            letter-spacing: 1px;
        }
        
        /* Info Rows */
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
            padding: 1px 0;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        .divider {
            border-top: 1px dashed #000;
            margin: 4px 0;
        }
        
        .dotted-line {
            border-top: 1px dotted #000;
            margin: 3px 0;
        }
        
        /* Amount Section */
        .amount-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 11px;
            margin: 4px 0;
            padding: 3px 0;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
        }
        
        .amount-words {
            font-size: 8px;
            margin: 4px 0;
            text-align: justify;
        }
        
        /* Payment Type Badge */
        .payment-type {
            display: inline-block;
            padding: 1px 4px;
            background: #f0f0f0;
            border-radius: 2px;
            font-size: 8px;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px dashed #000;
            font-size: 7px;
        }
        
        .footer p {
            margin: 2px 0;
        }
        
        .signature-line {
            margin-top: 10px;
            text-align: center;
        }
        
        .signature-line hr {
            width: 40px;
            margin: 3px auto;
            border: none;
            border-top: 1px solid #000;
        }
        
        .thank-you {
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            margin: 5px 0;
        }
        
        /* Barcode / QR Style */
        .barcode {
            text-align: center;
            margin: 5px 0;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }
        
        /* Print Buttons */
        .print-buttons {
            text-align: center;
            margin-top: 15px;
            padding: 10px;
            width: 58mm;
        }
        
        .print-buttons button {
            padding: 6px 12px;
            margin: 0 3px;
            cursor: pointer;
            border: none;
            border-radius: 3px;
            font-size: 10px;
        }
        
        .btn-print {
            background: #28a745;
            color: white;
        }
        
        .btn-download {
            background: #17a2b8;
            color: white;
        }
        
        .btn-close {
            background: #6c757d;
            color: white;
        }
        
        /* Utility */
        .text-center {
            text-align: center;
        }
        
        .text-bold {
            font-weight: bold;
        }
        
        .text-small {
            font-size: 7px;
        }
        
        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            .print-buttons {
                display: none;
            }
            .receipt-thermal {
                box-shadow: none;
                padding: 0;
                margin: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div>
        <!-- 2-Inch Thermal Receipt -->
        <div class="receipt-thermal">
            <div class="receipt-content">
                <!-- Header with Logo -->
                <div class="header">
                    <h2>SHREE VARI CHITS</h2>
                    <div class="subtitle">(Private Limited)</div>
                    <div class="address">Opposite to Indian Overseas Bank, Pennagram Main Road, Indur,  Dharmapuri - 636803</div>
                    
                    <div class="address">Ph: +918667646757</div>
                </div>
                
                <!-- Receipt Title -->
                <div class="receipt-title">
                    ★ PAYMENT RECEIPT ★
                </div>
                
                <!-- Receipt Details -->
                <div class="info-row">
                    <span class="info-label">Receipt No:</span>
                    <span><?= substr($receipt_no, -12); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date:</span>
                    <span><?= $receipt_date; ?> | <?= $receipt_time; ?></span>
                </div>
                
                <div class="dotted-line"></div>
                
                <!-- Customer Details -->
                <div class="info-row">
                    <span class="info-label">A/C No:</span>
                    <span><?= htmlspecialchars($receipt['agreement_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span><?= substr(htmlspecialchars($receipt['customer_name']), 0, 20); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mobile:</span>
                    <span><?= htmlspecialchars($receipt['customer_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Plan:</span>
                    <span><?= substr(htmlspecialchars($receipt['plan_title']), 0, 18); ?></span>
                </div>
                
                <div class="dotted-line"></div>
                
                <!-- Payment Details -->
                <div class="info-row">
                    <span class="info-label">Installment:</span>
                    <span>₹ <?= number_format($amount, 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Mode:</span>
                    <span>
                        <?php 
                        if ($receipt['payment_type'] == 'cash') echo '💵 CASH';
                        elseif ($receipt['payment_type'] == 'upi') echo '📱 UPI';
                        else echo '💵 + 📱 BOTH';
                        ?>
                    </span>
                </div>
                <?php if ($receipt['payment_type'] == 'both'): ?>
                <div class="info-row">
                    <span class="info-label">Cash:</span>
                    <span>₹ <?= number_format($receipt['cash_amount'], 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">UPI:</span>
                    <span>₹ <?= number_format($receipt['upi_amount'], 2); ?></span>
                </div>
                <?php endif; ?>
                
                <!-- Total Amount -->
                <div class="amount-row">
                    <span>TOTAL PAID</span>
                    <span>₹ <?= number_format($amount, 2); ?></span>
                </div>
                
                <!-- Amount in Words -->
                <div class="amount-words">
                    <span class="text-bold">Rupees:</span> <?= $amount_words; ?>
                </div>
                
                <!-- Collector Info -->
                <div class="info-row">
                    <span class="info-label">Collected By:</span>
                    <span><?= substr(htmlspecialchars($receipt['collector_name'] ?? $receipt['collector_username'] ?? 'System'), 0, 15); ?></span>
                </div>
                
                <div class="dotted-line"></div>
                
                <!-- Barcode Style (for scanner) -->
                <div class="barcode">
                    <?= '*'; ?><?= str_pad($emi_id, 8, '0', STR_PAD_LEFT); ?><?= '*'; ?>
                </div>
                
                <!-- Thank You Message -->
                <div class="thank-you">
                    ✨ THANK YOU! ✨
                </div>
                
                <!-- Footer -->
                <div class="footer">
                    <p>Payment successfully recorded</p>
                    <p>This is a computer generated receipt</p>
                    <p>Valid without signature</p>
                </div>
                
                <!-- Signature Line -->
                <div class="signature-line">
                    <hr>
                    <span class="text-small">Authorized Signatory</span>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="print-buttons">
            <button class="btn-print" onclick="window.print()">
                🖨️ PRINT
            </button>
            <button class="btn-download" onclick="downloadReceipt()">
                📥 DOWNLOAD
            </button>
            <button class="btn-close" onclick="window.close()">
                ✖ CLOSE
            </button>
        </div>
    </div>
    
    <script>
        // Auto print when page loads (for thermal printer)
        window.onload = function() {
            // Auto print after 1 second for thermal printer
            setTimeout(function() {
                window.print();
            }, 500);
        };
        
        // Download receipt as HTML
        function downloadReceipt() {
            const receiptHTML = document.querySelector('.receipt-thermal').outerHTML;
            const blob = new Blob([receiptHTML], {type: 'text/html'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'Receipt_<?= $receipt_no; ?>.html';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>