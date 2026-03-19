<?php
// Enable error logging instead of displaying errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u329947844/domains/hifi11.in/public_html/finance/error_log');
// Start session
session_start();
// Database connection
include 'includes/db.php';

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($customer_id == 0) {
    $_SESSION['error'] = "Error: Customer ID is missing.";
    header("Location: manage-customers.php");
    exit;
}

// Fetch customer details
$sql_customer = "SELECT c.*, l.loan_name, f.finance_name 
                 FROM customers c 
                 LEFT JOIN loans l ON c.loan_id = l.id 
                 LEFT JOIN finance f ON c.finance_id = f.id 
                 WHERE c.id = ?";
$stmt_customer = $conn->prepare($sql_customer);
$stmt_customer->bind_param("i", $customer_id);
$stmt_customer->execute();
$result_customer = $stmt_customer->get_result();
$customer = $result_customer->fetch_assoc();
$stmt_customer->close();

if (!$customer) {
    $_SESSION['error'] = "Customer not found.";
    header("Location: manage-customers.php");
    exit;
}

// Fetch EMI schedule
$sql_emi = "SELECT id AS emi_id, emi_amount, principal_amount, interest_amount, emi_due_date, status, overdue_charges, emi_bill_number
            FROM emi_schedule 
            WHERE customer_id = ? 
            ORDER BY emi_due_date ASC";
$stmt_emi = $conn->prepare($sql_emi);
$stmt_emi->bind_param("i", $customer_id);
$stmt_emi->execute();
$result_emi = $stmt_emi->get_result();
$emis = [];
while ($row = $result_emi->fetch_assoc()) {
    $emis[] = $row;
}
$stmt_emi->close();

// Calculate total principal and interest
$total_principal = 0;
$total_interest = 0;
foreach ($emis as $emi) {
    $total_principal += $emi['principal_amount'];
    $total_interest += $emi['interest_amount'];
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Printout - <?php echo htmlspecialchars($customer['customer_name']); ?></title>
    <style>
        @page {
            size: A4;
            margin: 30px; /* No margins */
            padding: 0;
        }
        body {
            font-family: 'Times New Roman', serif;
            font-size: 12pt;
            color: #000;
            line-height: 1.2;
            background: #fff;
            margin: 0;
            padding: 0;
            width: 210mm; /* A4 width */
            height: 297mm; /* A4 height */
        }
        .print-container {
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 5mm; /* Small internal padding */
            box-sizing: border-box;
        }
        .header {
            text-align: center;
            margin-bottom: 3mm;
            padding-bottom: 2mm;
        }
        .company-name {
            font-size: 16pt;
            font-weight: bold;
            margin: 0;
            text-transform: uppercase;
            text-decoration: underline;
        }
        .company-address {
            font-size: 10pt;
            margin: 1mm 0;
        }
        .document-title {
            font-size: 14pt;
            font-weight: bold;
            margin: 2mm 0;
            text-transform: uppercase;
            text-decoration: underline;
        }
        .content-section {
            margin-bottom: 3mm;
        }
        .section-title {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 1mm;
            text-decoration: underline;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1mm;
            margin-bottom: 2mm;
        }
        .detail-item {
            display: flex;
        }
        .detail-label {
            font-weight: bold;
            min-width: 45mm;
        }
        .detail-value {
            flex-grow: 1;
            border-bottom: 1px solid #000;
            padding-left: 2mm;
        }
        .emi-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
            font-size: 8pt; /* Smaller font for table */
        }
        .emi-table th, .emi-table td {
            border: 1px solid #000;
            padding: 1mm;
            text-align: center;
        }
        .emi-table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .signature-section {
            margin-top: 5mm;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 45%;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 10mm;
            padding-top: 1mm;
        }
        .print-button {
            text-align: center;
            margin-top: 5mm;
        }
        .print-button button {
            padding: 2mm 8mm;
            font-size: 12pt;
            background-color: #000;
            color: #fff;
            border: none;
            border-radius: 1mm;
            cursor: pointer;
        }
        .print-button button:hover {
            background-color: #333;
        }
        @media print {
            .print-button {
                display: none;
            }
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                margin: 0 !important;
                padding: 0 !important;
            }
            .print-container {
                padding: 3mm; /* Even smaller padding for print */
            }
        }
        .checkbox-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5mm;
            margin-top: 2mm;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
        }
        .checkbox-box {
            width: 3mm;
            height: 3mm;
            border: 1px solid #000;
            margin-right: 1mm;
        }
        .large-textarea {
            min-height: 12mm;
            border: 1px solid #000;
            padding: 1mm;
            margin-bottom: 1mm;
        }
        .total-section {
            margin-top: 3mm;
            padding: 2mm;
            border: 1px solid #000;
            background-color: #f9f9f9;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1mm;
        }
        .total-label {
            font-weight: bold;
        }
        .total-value {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="header">
            <div class="company-name">SRI SELVAGANAPATHI AUTO FINANCE</div>
            
        </div>

        <div class="document-title">Loan Agreement</div>

        <div class="content-section">
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Agreement No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['agreement_number']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Agreement Date:</div>
                    <div class="detail-value">
                        <?php 
                        $agreement_date = !empty($customer['emi_date']) ? $customer['emi_date'] : date('Y-m-d');
                        echo (new DateTime($agreement_date))->format('d - m - y'); 
                        ?>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Loan Amount:</div>
                    <div class="detail-value">₹<?php echo number_format($customer['loan_amount'], 2); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Cheque No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['cheque_number'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Principal Amount:</div>
                    <div class="detail-value">₹<?php echo number_format($customer['principal_amount'] ?? $customer['loan_amount'], 2); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Interest:</div>
                    <div class="detail-value"><?php echo $customer['interest_rate']; ?>%</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">No of Dues:</div>
                    <div class="detail-value"><?php echo $customer['loan_tenure']; ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">1st Due Date:</div>
                    <div class="detail-value">
                        <?php 
                        $first_emi_date = !empty($customer['emi_date']) ? $customer['emi_date'] : date('Y-m-d');
                        echo (new DateTime($first_emi_date))->format('d - m - y'); 
                        ?>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Last Due Date:</div>
                    <div class="detail-value"><?php 
                        $lastDueDate = new DateTime($customer['emi_date']);
                        $lastDueDate->modify('+' . ($customer['loan_tenure'] - 1) . ' months');
                        echo $lastDueDate->format('d - m - y');
                    ?></div>
                </div>
            </div>
        </div>

        <div class="content-section">
            <div class="section-title">Hirer Name & Address:</div>
            <div class="large-textarea">
                <?php echo htmlspecialchars($customer['customer_name']); ?><br>
                <?php echo htmlspecialchars($customer['customer_address'] ?? 'N/A'); ?>
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Phone:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['customer_number']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Cell No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['customer_number']); ?></div>
                </div>
            </div>
        </div>

        <div class="content-section">
            <div class="section-title">Guarantor Name & Address:</div>
            <div class="large-textarea">
                <?php echo htmlspecialchars($customer['nominee_name'] ?? 'N/A'); ?><br>
                <?php echo htmlspecialchars($customer['customer_address'] ?? 'N/A'); ?>
            </div>
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Phone:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['nominee_number'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Cell No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['nominee_number'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <div class="content-section">
            <div class="section-title">Vehicle Details:</div>
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Type of vehicle:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['vehicle_type'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Reg No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['vehicle_number'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Date of Manufacture:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['manufacture_date'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Vehicle colour:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['vehicle_color'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Model:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['vehicle_model'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Eng.No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['engine_no'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Chass No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['chassis_no'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Key No:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['key_no'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <div class="content-section">
            <div class="section-title">Additional Details:</div>
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Dealer Name:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['dealer_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Invoice date:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['invoice_date'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Invoice value:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($customer['invoice_value'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Vehicle Insurance Date:</div>
                    <div class="detail-value">From ______ To ______</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Permit Date:</div>
                    <div class="detail-value">From ______ To ______</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Tax Details:</div>
                    <div class="detail-value">______</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Documents charge:</div>
                    <div class="detail-value">₹<?php echo number_format($customer['document_charge'], 2); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Vehicle RTO Tax amount:</div>
                    <div class="detail-value">From ______ To ______</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Fc Date:</div>
                    <div class="detail-value">______</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Which Partner Recurrent:</div>
                    <div class="detail-value">______</div>
                </div>
            </div>
        </div>

        <div class="content-section">
            <div class="section-title">Documents:</div>
            <div class="checkbox-list">
                <div class="checkbox-item"><div class="checkbox-box"></div> RC Book</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Insurance Copy</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Permit</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Ration Card Xerox</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Voter ID</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> House Tax Receipt</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> land Tax Receipt</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Pan Card xerox</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Sale Deed</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Vehicle Purchase Agreement Copy</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Party Photo -8</div>
                <div class="checkbox-item"><div class="checkbox-box"></div> Gurantor Photo -1</div>
            </div>
        </div>

        <div class="content-section">
            <div class="section-title">EMI Payment Schedule:</div>
            <table class="emi-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>EM Paid</th>
                        <th>Principle Amount Due</th>
                        <th>Interest</th>
                        <th>Total EMI</th>
                        <th>Other Charges</th>
                        <th>Receipt No</th>
                        <th>Paid Date</th>
                        <th>Signature of Customer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Display EMI schedule from database
                    if (!empty($emis)): 
                        foreach ($emis as $index => $emi): 
                            $total_emi = $emi['principal_amount'] + $emi['interest_amount'];
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo $emi['status'] === 'paid' ? '✓' : ''; ?></td>
                        <td>₹<?php echo number_format($emi['principal_amount'], 2); ?></td>
                        <td>₹<?php echo number_format($emi['interest_amount'], 2); ?></td>
                        <td>₹<?php echo number_format($total_emi, 2); ?></td>
                        <td></td>
                        <td><?php echo $emi['emi_bill_number'] ?? ''; ?></td>
                        <td>
                            <?php 
                            if ($emi['status'] === 'paid') {
                                echo (new DateTime($emi['emi_due_date']))->format('d-m-y');
                            } else {
                                echo '';
                            }
                            ?>
                        </td>
                        <td></td>
                    </tr>
                    <?php 
                        endforeach; 
                    else: 
                        // Show empty rows if no EMI data
                        for ($i = 1; $i <= $customer['loan_tenure']; $i++): 
                    ?>
                    <tr>
                        <td><?php echo $i; ?></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                    <?php endfor; endif; ?>
                </tbody>
            </table>

          

        <div class="signature-section">
            <div class="signature-box">
                <div>Customer Signature</div>
                <div class="signature-line"></div>
            </div>
            <div class="signature-box">
                <div>Company Signature</div>
                <div class="signature-line"></div>
            </div>
        </div>

        <div class="print-button">
            <button onclick="window.print()">Print Document</button>
        </div>
    </div>
</body>
</html>