<?php
// print-emi-receipt.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

$member_id = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
$emi_id = isset($_GET['emi_id']) ? intval($_GET['emi_id']) : 0;

if ($member_id == 0 || $emi_id == 0) {
    die("Invalid parameters.");
}

// Fetch EMI details
$sql = "SELECT es.*, m.customer_name, m.customer_number, m.agreement_number, 
               p.title AS plan_title, u.full_name AS collected_by_name
        FROM emi_schedule es
        JOIN members m ON es.member_id = m.id
        JOIN plans p ON m.plan_id = p.id
        LEFT JOIN users u ON es.collected_by = u.id
        WHERE es.id = ? AND es.member_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $emi_id, $member_id);
$stmt->execute();
$emi = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$emi) {
    die("EMI not found.");
}

require_once('includes/fpdf.php');

// Create PDF
$pdf = new FPDF();
$pdf->AddPage();

// Header
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'SRI VARI CHITS PRIVATE LIMITED', 0, 1, 'C');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 10, 'Payment Receipt', 0, 1, 'C');
$pdf->Ln(5);

// Receipt Details
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Receipt Details', 0, 1);
$pdf->SetFont('Arial', '', 11);

$pdf->Cell(60, 8, 'Receipt No:', 0, 0);
$pdf->Cell(0, 8, $emi['emi_bill_number'] ?? 'N/A', 0, 1);

$pdf->Cell(60, 8, 'Date:', 0, 0);
$pdf->Cell(0, 8, date('d-m-Y', strtotime($emi['paid_date'] ?? date('Y-m-d'))), 0, 1);
$pdf->Ln(5);

// Member Information
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Member Information', 0, 1);
$pdf->SetFont('Arial', '', 11);

$pdf->Cell(60, 8, 'Member Name:', 0, 0);
$pdf->Cell(0, 8, $emi['customer_name'], 0, 1);

$pdf->Cell(60, 8, 'Agreement No:', 0, 0);
$pdf->Cell(0, 8, $emi['agreement_number'], 0, 1);

$pdf->Cell(60, 8, 'Phone:', 0, 0);
$pdf->Cell(0, 8, $emi['customer_number'], 0, 1);

$pdf->Cell(60, 8, 'Plan:', 0, 0);
$pdf->Cell(0, 8, $emi['plan_title'], 0, 1);
$pdf->Ln(5);

// Payment Details
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Payment Details', 0, 1);
$pdf->SetFont('Arial', '', 11);

$pdf->Cell(60, 8, 'Installment Amount:', 0, 0);
$pdf->Cell(0, 8, '₹' . number_format($emi['emi_amount'], 2), 0, 1);

$pdf->Cell(60, 8, 'Payment Type:', 0, 0);
$pdf->Cell(0, 8, ucfirst($emi['payment_type']), 0, 1);

if ($emi['payment_type'] == 'both') {
    $pdf->Cell(60, 8, 'Cash Amount:', 0, 0);
    $pdf->Cell(0, 8, '₹' . number_format($emi['cash_amount'], 2), 0, 1);
    
    $pdf->Cell(60, 8, 'UPI Amount:', 0, 0);
    $pdf->Cell(0, 8, '₹' . number_format($emi['upi_amount'], 2), 0, 1);
}

$pdf->Cell(60, 8, 'Due Date:', 0, 0);
$pdf->Cell(0, 8, date('d-m-Y', strtotime($emi['emi_due_date'])), 0, 1);

$pdf->Cell(60, 8, 'Paid Date:', 0, 0);
$pdf->Cell(0, 8, date('d-m-Y', strtotime($emi['paid_date'] ?? date('Y-m-d'))), 0, 1);

if (!empty($emi['collected_by_name'])) {
    $pdf->Cell(60, 8, 'Collected By:', 0, 0);
    $pdf->Cell(0, 8, $emi['collected_by_name'], 0, 1);
}
$pdf->Ln(10);

// Total Amount
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Total Amount Paid: ₹' . number_format($emi['emi_amount'], 2), 0, 1, 'C');
$pdf->Ln(5);

// Payment Status
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Payment Status: ' . ($emi['status'] === 'paid' ? 'PAID' : 'PENDING'), 0, 1, 'C');
$pdf->Ln(10);

// Footer
$pdf->SetFont('Arial', 'I', 10);
$pdf->Cell(0, 10, 'This is a computer generated receipt.', 0, 1, 'C');
$pdf->Cell(0, 10, 'Signature: ________________________', 0, 1, 'C');
$pdf->Ln(5);
$pdf->Cell(0, 10, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');

// Output PDF
$pdf->Output('D', 'Receipt-' . ($emi['emi_bill_number'] ?? $emi_id) . '.pdf');
exit;