<?php
// emi-schedule-member.php - MODIFIED FOR SITE NUMBER HIGHLIGHTING AND BID WINNER
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'includes/db.php';

$member_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($member_id == 0) {
    $_SESSION['error'] = "Invalid member.";
    header("Location: manage-members.php");
    exit;
}

// Handle WhatsApp for specific EMI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_emi_whatsapp'])) {
    $emi_id = intval($_POST['emi_id'] ?? 0);
    $customer_number = $_POST['customer_number'] ?? '';
    
    if (!empty($customer_number) && $emi_id > 0) {
        // Fetch EMI details
        $sql_emi = "SELECT es.*, m.customer_name, m.agreement_number, p.title AS plan_title
                    FROM emi_schedule es
                    JOIN members m ON es.member_id = m.id
                    JOIN plans p ON m.plan_id = p.id
                    WHERE es.id = ? AND es.member_id = ?";
        $stmt = $conn->prepare($sql_emi);
        $stmt->bind_param("ii", $emi_id, $member_id);
        $stmt->execute();
        $emi = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($emi) {
            // Generate message based on EMI status
            if ($emi['status'] === 'paid') {
                $message = generatePaidWhatsAppMessage($emi);
            } else {
                $message = generateUnpaidWhatsAppMessage($emi);
            }
            
            // Format WhatsApp number
            $whatsapp_number = formatWhatsAppNumber($customer_number);
            $whatsapp_url = "https://wa.me/{$whatsapp_number}?text=" . urlencode($message);
            
            // Redirect to WhatsApp
            header("Location: " . $whatsapp_url);
            exit;
        }
    }
    $_SESSION['error'] = "Unable to send WhatsApp message.";
    header("Location: emi-schedule-member.php?id=$member_id");
    exit;
}

// Handle Print Report for specific EMI
if (isset($_GET['print_emi'])) {
    $emi_id = intval($_GET['print_emi'] ?? 0);
    generateEmiReceipt($member_id, $emi_id);
    exit;
}

// Handle Print Report (Full)
if (isset($_GET['print_report'])) {
    generatePrintReport($member_id);
    exit;
}

// Handle Early Bid Winner Payment (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['early_payment'])) {
    $early_amount = floatval($_POST['early_amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $bill_number = trim($_POST['bill_number'] ?? '');
    $collected_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    
    if ($early_amount > 0 && !empty($bill_number)) {
        $stmt = $conn->prepare("UPDATE members SET 
                                early_big_winner_payment = ?, 
                                early_payment_date = ?, 
                                early_payment_bill_number = ?,
                                early_payment_collected_by = ?
                                WHERE id = ?");
        $stmt->bind_param("dssii", $early_amount, $payment_date, $bill_number, $collected_by, $member_id);
        
        if ($stmt->execute()) {
            // Mark all remaining EMIs as paid
            $update_emis = $conn->prepare("UPDATE emi_schedule 
                                           SET status = 'paid', 
                                               paid_date = ?,
                                               emi_bill_number = CONCAT('EARLY-', ?),
                                               collected_by = ?
                                           WHERE member_id = ? AND status = 'unpaid'");
            $update_emis->bind_param("ssii", $payment_date, $bill_number, $collected_by, $member_id);
            $update_emis->execute();
            $update_emis->close();
            
            $_SESSION['success'] = "Early Bid Winner payment recorded successfully! Amount: ₹" . number_format($early_amount, 2);
        } else {
            $_SESSION['error'] = "Failed to record early payment.";
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "Invalid payment amount or missing bill number.";
    }
    header("Location: emi-schedule-member.php?id=$member_id");
    exit;
}

// Handle Bid Winner declaration (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['declare_winner'])) {
    $winner_amount = floatval($_POST['winner_amount'] ?? 0);
    $winner_date = $_POST['winner_date'] ?? '';
    $winner_number = trim($_POST['winner_number'] ?? '');
    $collected_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

    if ($winner_amount > 0 && !empty($winner_date)) {
        $winner_number = !empty($winner_number) ? $winner_number : null;
        
        // Update winner details
        $stmt = $conn->prepare("UPDATE members SET 
                                winner_amount = ?, 
                                winner_date = ?, 
                                winner_number = ?,
                                collected_by = ?
                                WHERE id = ?");
        $stmt->bind_param("dssii", $winner_amount, $winner_date, $winner_number, $collected_by, $member_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Bid Winner declared successfully! Amount: ₹" . number_format($winner_amount, 2) . " on " . date('d-m-Y', strtotime($winner_date));
        } else {
            $_SESSION['error'] = "Failed to declare winner.";
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "Invalid winner amount or date.";
    }
    header("Location: emi-schedule-member.php?id=$member_id");
    exit;
}

// Fetch member details
$sql_member = "SELECT m.*, p.title AS plan_title, p.total_months, p.total_received_amount
               FROM members m
               JOIN plans p ON m.plan_id = p.id
               WHERE m.id = ?";
$stmt = $conn->prepare($sql_member);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member_result = $stmt->get_result();
$member = $member_result->fetch_assoc();
$stmt->close();

if (!$member) {
    $_SESSION['error'] = "Member not found.";
    header("Location: manage-members.php");
    exit;
}

// Check if it's a weekly plan
$is_weekly_plan = (strpos($member['plan_title'], 'Weekly') !== false || strpos($member['plan_title'], 'Weeks') !== false);
$period_type = $is_weekly_plan ? 'weeks' : 'months';
$period_label = $is_weekly_plan ? 'Week' : 'Month';

// Fetch EMI schedule with collector info
$sql_emi = "SELECT es.*, 
            u.username as collected_by_username,
            u.full_name as collected_by_name
            FROM emi_schedule es
            LEFT JOIN users u ON es.collected_by = u.id
            WHERE es.member_id = ?
            ORDER BY es.emi_due_date ASC";
$stmt = $conn->prepare($sql_emi);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$emi_result = $stmt->get_result();
$emis = [];
$total_installment = 0;
$total_collected = 0;
$paid_count = 0;
$unpaid_count = 0;
$next_unpaid_emi = null;
while ($row = $emi_result->fetch_assoc()) {
    $total_installment += $row['emi_amount'];
    if ($row['status'] === 'paid') {
        $total_collected += $row['emi_amount'];
        $paid_count++;
    } else {
        $unpaid_count++;
        if ($next_unpaid_emi === null) {
            $next_unpaid_emi = $row;
        }
    }
    $emis[] = $row;
}
$stmt->close();

$total_pending = $total_installment - $total_collected;
$early_payment = $member['early_big_winner_payment'] ?? 0;

// If early payment exists, add it to collected amount
if ($early_payment > 0) {
    $total_collected += $early_payment;
    $total_pending = max(0, $total_pending - $early_payment);
}

// Fetch plan details
$sql_details = "SELECT installment, withdrawal_eligible, month_number
                FROM plan_details
                WHERE plan_id = ?
                ORDER BY month_number ASC";
$stmt_details = $conn->prepare($sql_details);
$stmt_details->bind_param("i", $member['plan_id']);
$stmt_details->execute();
$result_details = $stmt_details->get_result();
$plan_details = [];
$eligible_amounts = [];
while ($row = $result_details->fetch_assoc()) {
    $plan_details[] = $row;
    $eligible_amounts[] = $row['withdrawal_eligible'] ?? 0;
}
$stmt_details->close();

// Calculate remaining amount if member becomes bid winner early
$plan_total_amount = $member['total_received_amount'] ?? 0;
$remaining_amount = $plan_total_amount - $total_collected;

// Get Bid Winner eligible amounts list
$bid_winner_eligible_list = [];
if (!empty($eligible_amounts)) {
    foreach ($eligible_amounts as $index => $amount) {
        if ($amount > 0) {
            $bid_winner_eligible_list[] = [
                'month' => $index + 1,
                'amount' => $amount
            ];
        }
    }
}

// Get last eligible amount (Bid Winner amount)
$bid_winner_amount = !empty($eligible_amounts) ? end($eligible_amounts) : 0;

// Get site number and determine which EMI to highlight
$site_number = $member['bid_winner_site_number'] ?? '';
$highlight_emi_index = -1;

if (!empty($site_number)) {
    preg_match('/\d+/', $site_number, $matches);
    if (!empty($matches)) {
        $site_num = intval($matches[0]);
        if ($site_num >= 1 && $site_num <= count($emis)) {
            $highlight_emi_index = $site_num - 1;
        }
    }
}

// Get who declared the winner (if any)
$winner_collector = null;
if (!empty($member['winner_amount'])) {
    $sql_collector = "SELECT u.username, u.full_name 
                      FROM users u 
                      WHERE u.id = (SELECT collected_by FROM members WHERE id = ?)";
    $stmt_collector = $conn->prepare($sql_collector);
    $stmt_collector->bind_param("i", $member_id);
    $stmt_collector->execute();
    $result_collector = $stmt_collector->get_result();
    $winner_collector = $result_collector->fetch_assoc();
    $stmt_collector->close();
}

// Get who collected early payment (if any)
$early_payment_collector = null;
if (!empty($member['early_payment_collected_by'])) {
    $sql_early = "SELECT u.username, u.full_name 
                  FROM users u 
                  WHERE u.id = ?";
    $stmt_early = $conn->prepare($sql_early);
    $stmt_early->bind_param("i", $member['early_payment_collected_by']);
    $stmt_early->execute();
    $result_early = $stmt_early->get_result();
    $early_payment_collector = $result_early->fetch_assoc();
    $stmt_early->close();
}

// Conditions
$can_close_plan = ($unpaid_count == 0 && empty($member['winner_amount']));
$can_early_payment = ($unpaid_count > 0 && $remaining_amount > 0 && empty($member['winner_amount']) && empty($member['early_big_winner_payment']));

// Helper functions
function formatWhatsAppNumber($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (substr($phone, 0, 1) === '0') $phone = substr($phone, 1);
    if (substr($phone, 0, 2) !== '91' && strlen($phone) === 10) $phone = '91' . $phone;
    return $phone;
}

function generatePaidWhatsAppMessage($emi) {
    $message = "✅ *Payment Receipt - Sri Vari Chits Private Limited* ✅\n\n";
    $message .= "Dear *{$emi['customer_name']}*,\n\n";
    $message .= "Your payment has been successfully recorded.\n\n";
    $message .= "• *Agreement No:* {$emi['agreement_number']}\n";
    $message .= "• *Plan:* {$emi['plan_title']}\n";
    $message .= "• *Installment Amount:* ₹" . number_format($emi['emi_amount'], 2) . "\n";
    $message .= "• *Bill Number:* {$emi['emi_bill_number']}\n";
    $message .= "• *Payment Date:* " . date('d-m-Y', strtotime($emi['paid_date'])) . "\n";
    $message .= "• *Payment Type:* " . ucfirst($emi['payment_type']) . "\n";
    
    if ($emi['payment_type'] == 'both') {
        $message .= "• *Cash Amount:* ₹" . number_format($emi['cash_amount'], 2) . "\n";
        $message .= "• *UPI Amount:* ₹" . number_format($emi['upi_amount'], 2) . "\n";
    }
    
    $message .= "\nThank you for your payment!\n\n";
    $message .= "────────────────────\n";
    $message .= "✅ *கட்டண ரசீது - ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட்* ✅\n\n";
    $message .= "அன்புள்ள *{$emi['customer_name']}*,\n\n";
    $message .= "உங்கள் கட்டணம் வெற்றிகரமாக பதிவு செய்யப்பட்டது.\n\n";
    $message .= "• *ஒப்பந்த எண்:* {$emi['agreement_number']}\n";
    $message .= "• *திட்டம்:* {$emi['plan_title']}\n";
    $message .= "• *தவணை தொகை:* ₹" . number_format($emi['emi_amount'], 2) . "\n";
    $message .= "• *பில் எண்:* {$emi['emi_bill_number']}\n";
    $message .= "• *கட்டண தேதி:* " . date('d-m-Y', strtotime($emi['paid_date'])) . "\n";
    $message .= "• *கட்டண முறை:* " . ($emi['payment_type'] == 'cash' ? 'பணம்' : ($emi['payment_type'] == 'upi' ? 'UPI' : 'இரண்டும்')) . "\n";
    
    if ($emi['payment_type'] == 'both') {
        $message .= "• *பணத் தொகை:* ₹" . number_format($emi['cash_amount'], 2) . "\n";
        $message .= "• *UPI தொகை:* ₹" . number_format($emi['upi_amount'], 2) . "\n";
    }
    
    $message .= "\nஉங்கள் கட்டணத்திற்கு நன்றி!\n";
    $message .= "📞 தொடர்பு: +91 1234567890\n";
    $message .= "📍 ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட்";
    
    return $message;
}

function generateUnpaidWhatsAppMessage($emi) {
    $is_overdue = (strtotime($emi['emi_due_date']) < time());
    $due_date = date('d-m-Y', strtotime($emi['emi_due_date']));
    
    $message = "🔔 *Payment Reminder - Sri Vari Chits Private Limited* 🔔\n\n";
    $message .= "Dear *{$emi['customer_name']}*,\n\n";
    
    if ($is_overdue) {
        $message .= "This is an overdue payment reminder.\n\n";
    } else {
        $message .= "This is a payment reminder.\n\n";
    }
    
    $message .= "• *Agreement No:* {$emi['agreement_number']}\n";
    $message .= "• *Plan:* {$emi['plan_title']}\n";
    $message .= "• *Installment Amount:* ₹" . number_format($emi['emi_amount'], 2) . "\n";
    $message .= "• *Due Date:* $due_date\n";
    
    if ($is_overdue) {
        $overdue_days = floor((time() - strtotime($emi['emi_due_date'])) / (60 * 60 * 24));
        $message .= "• *Overdue Days:* $overdue_days days\n";
        $message .= "• *Status:* ⚠️ Overdue\n\n";
        $message .= "Please make the payment immediately to avoid any inconvenience.\n";
    } else {
        $message .= "• *Status:* ⏳ Pending\n\n";
        $message .= "Please make the payment on or before the due date.\n";
    }
    
    $message .= "\n────────────────────\n";
    $message .= "🔔 *கட்டண நினைவூட்டல் - ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட்* 🔔\n\n";
    $message .= "அன்புள்ள *{$emi['customer_name']}*,\n\n";
    
    if ($is_overdue) {
        $message .= "இது ஒரு காலாவதியான கட்டண நினைவூட்டல்.\n\n";
    } else {
        $message .= "இது ஒரு கட்டண நினைவூட்டல்.\n\n";
    }
    
    $message .= "• *ஒப்பந்த எண்:* {$emi['agreement_number']}\n";
    $message .= "• *திட்டம்:* {$emi['plan_title']}\n";
    $message .= "• *தவணை தொகை:* ₹" . number_format($emi['emi_amount'], 2) . "\n";
    $message .= "• *கடைசி தேதி:* $due_date\n";
    
    if ($is_overdue) {
        $overdue_days = floor((time() - strtotime($emi['emi_due_date'])) / (60 * 60 * 24));
        $message .= "• *காலாவதி நாட்கள்:* $overdue_days நாட்கள்\n";
        $message .= "• *நிலை:* ⚠️ காலாவதியானது\n\n";
        $message .= "தயவு செய்து உடனடியாக கட்டணத்தை செலுத்துங்கள்.\n";
    } else {
        $message .= "• *நிலை:* ⏳ நிலுவையில்\n\n";
        $message .= "தயவு செய்து கடைசி தேதிக்குள் கட்டணத்தை செலுத்துங்கள்.\n";
    }
    
    $message .= "📞 தொடர்பு: +91 1234567890\n";
    $message .= "📍 ஸ்ரீ வரி சிட்ஸ் பிரைவேட் லிமிடெட்";
    
    return $message;
}

function generateEmiReceipt($member_id, $emi_id) {
    global $conn, $member;
    
    require_once('includes/fpdf.php');
    
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
    $pdf->Output('D', 'Receipt-' . $emi['emi_bill_number'] . '.pdf');
    exit;
}

function generatePrintReport($member_id) {
    global $conn, $member, $emis, $plan_details, $total_collected, $total_pending, $paid_count, $unpaid_count, $bid_winner_amount;
    
    require_once('includes/fpdf.php');
    
    // Create PDF
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'SRI VARI CHITS PRIVATE LIMITED', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Member Payment Schedule Report', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Member Details
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Member Information', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    
    $pdf->Cell(40, 8, 'Member Name:', 0, 0);
    $pdf->Cell(0, 8, $member['customer_name'], 0, 1);
    
    $pdf->Cell(40, 8, 'Agreement No:', 0, 0);
    $pdf->Cell(0, 8, $member['agreement_number'], 0, 1);
    
    $pdf->Cell(40, 8, 'Phone:', 0, 0);
    $pdf->Cell(0, 8, $member['customer_number'], 0, 1);
    
    $pdf->Cell(40, 8, 'Plan:', 0, 0);
    $pdf->Cell(0, 8, $member['plan_title'], 0, 1);
    
    $pdf->Cell(40, 8, 'Total Plan Amount:', 0, 0);
    $pdf->Cell(0, 8, '₹' . number_format($member['total_received_amount'], 2), 0, 1);
    
    $pdf->Cell(40, 8, 'Bid Winner Amount:', 0, 0);
    $pdf->Cell(0, 8, '₹' . number_format($bid_winner_amount ?? 0, 2), 0, 1);
    $pdf->Ln(5);
    
    // Summary
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Payment Summary', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    
    $pdf->Cell(60, 8, 'Total Collected:', 0, 0);
    $pdf->Cell(0, 8, '₹' . number_format($total_collected, 2), 0, 1);
    
    $pdf->Cell(60, 8, 'Total Pending:', 0, 0);
    $pdf->Cell(0, 8, '₹' . number_format($total_pending, 2), 0, 1);
    
    $pdf->Cell(60, 8, 'Paid Installments:', 0, 0);
    $pdf->Cell(0, 8, $paid_count . ' out of ' . count($emis), 0, 1);
    
    $pdf->Cell(60, 8, 'Unpaid Installments:', 0, 0);
    $pdf->Cell(0, 8, $unpaid_count, 0, 1);
    $pdf->Ln(5);
    
    // EMI Schedule Table Header
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(15, 10, 'Sr.', 1, 0, 'C');
    $pdf->Cell(35, 10, 'Due Date', 1, 0, 'C');
    $pdf->Cell(40, 10, 'Amount (₹)', 1, 0, 'C');
    $pdf->Cell(40, 10, 'Eligible Amount (₹)', 1, 0, 'C');
    $pdf->Cell(35, 10, 'Status', 1, 0, 'C');
    $pdf->Cell(25, 10, 'Paid Date', 1, 1, 'C');
    
    // EMI Schedule Data
    $pdf->SetFont('Arial', '', 10);
    $counter = 1;
    foreach ($emis as $emi) {
        $status = $emi['status'] === 'paid' ? 'Paid' : 'Unpaid';
        $paid_date = !empty($emi['paid_date']) ? date('d-m-Y', strtotime($emi['paid_date'])) : '';
        $eligible_amount = isset($plan_details[$counter-1]) ? $plan_details[$counter-1]['withdrawal_eligible'] : 0;
        
        $pdf->Cell(15, 8, $counter, 1, 0, 'C');
        $pdf->Cell(35, 8, date('d-m-Y', strtotime($emi['emi_due_date'])), 1, 0, 'C');
        $pdf->Cell(40, 8, number_format($emi['emi_amount'], 2), 1, 0, 'R');
        $pdf->Cell(40, 8, number_format($eligible_amount, 2), 1, 0, 'R');
        $pdf->Cell(35, 8, $status, 1, 0, 'C');
        $pdf->Cell(25, 8, $paid_date, 1, 1, 'C');
        $counter++;
    }
    
    // Footer
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
    $pdf->Cell(0, 10, 'Report ID: ' . time(), 0, 1, 'C');
    
    // Output PDF
    $pdf->Output('D', 'Sri-Vari-Chits-Member-Report-' . $member['agreement_number'] . '.pdf');
    exit;
}

// Safe date formatting function
function formatDate($date_string) {
    if (empty($date_string) || $date_string == '0000-00-00') {
        return 'N/A';
    }
    try {
        return date('d-m-Y', strtotime($date_string));
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .site-highlight {
        background-color: #d1ecf1 !important;
        border-left: 4px solid #0dcaf0 !important;
        font-weight: 500;
    }
    .site-badge-display {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1;
        color: #fff;
        background: linear-gradient(45deg, #20c997, #0dcaf0);
        border-radius: 0.5rem;
        margin-bottom: 0.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .bid-winner-trophy {
        color: #ffc107;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
    }
    .site-number-marker {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        background-color: #0dcaf0;
        color: white;
        border-radius: 50%;
        font-size: 0.75rem;
        font-weight: bold;
        margin-right: 0.5rem;
    }
    .action-icons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }
    .action-icons .btn {
        padding: 3px 8px;
        font-size: 12px;
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
                $page_title = "Payment Schedule";
                $breadcrumb_active = htmlspecialchars($member['customer_name']);
                include 'includes/breadcrumb.php';
                ?>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row align-items-center mb-3">
                    <div class="col">
                        <h3 class="mb-0">Payment Schedule – <?php echo htmlspecialchars($member['customer_name']); ?></h3>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <small class="text-muted">
                                Agreement: <?php echo htmlspecialchars($member['agreement_number']); ?> |
                                Plan: <?php echo htmlspecialchars($member['plan_title']); ?> |
                                Total Plan Amount: ₹<?php echo number_format($plan_total_amount, 2); ?>
                            </small>
                            <?php if (!empty($site_number)): ?>
                                <span class="site-badge-display">
                                    <i class="fas fa-map-marker-alt me-1"></i> Seat: <?php echo htmlspecialchars($site_number); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <a href="manage-members.php" class="btn btn-light">Back to Members</a>
                        <a href="emi-schedule-member.php?id=<?php echo $member_id; ?>&print_report=1" class="btn btn-info ms-2" target="_blank">
                            <i class="fas fa-print"></i> Full Report
                        </a>
                    </div>
                </div>

                <!-- Site Number Indicator -->
                <?php if (!empty($site_number) && $highlight_emi_index >= 0): ?>
                <div class="alert alert-info d-flex align-items-center mb-4">
                    <i class="fas fa-info-circle fa-2x me-3"></i>
                    <div>
                        <h6 class="mb-1">Site Number: <strong><?php echo htmlspecialchars($site_number); ?></strong></h6>
                        <p class="mb-0">EMI #<?php echo ($highlight_emi_index + 1); ?> is highlighted below as it corresponds to site number <?php echo htmlspecialchars($site_number); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- EARLY BID WINNER PAYMENT SECTION -->
                <?php if ($can_early_payment): ?>
                <div class="card mb-4 border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-money-bill-wave"></i> Early Bid Winner Payment
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Member can become Bid Winner early! Remaining amount to complete plan: 
                            <strong>₹<?php echo number_format($remaining_amount, 2); ?></strong>
                        </div>
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="early_payment" value="1">
                            <div class="col-md-3">
                                <label class="form-label">Payment Amount (₹)</label>
                                <input type="number" step="0.01" name="early_amount" class="form-control" 
                                       value="<?php echo $remaining_amount; ?>" required>
                                <small class="text-muted">Remaining: ₹<?php echo number_format($remaining_amount, 2); ?></small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payment Date</label>
                                <input type="date" name="payment_date" class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Bill Number</label>
                                <input type="text" name="bill_number" class="form-control" 
                                       placeholder="BILL-001" required>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="fas fa-check-circle"></i> Record Early Payment
                                </button>
                            </div>
                        </form>
                        <small class="text-muted mt-2 d-block">
                            <i class="fas fa-exclamation-triangle"></i> This will mark all remaining EMIs as paid and allow member to become Bid Winner.
                        </small>
                    </div>
                </div>
                <?php endif; ?>

                <!-- BID WINNER SECTION -->
                <div id="bid-winner-section" class="card mb-4 <?php echo !empty($member['winner_amount']) ? 'border-success' : 'border-primary'; ?>">
                    <div class="card-header <?php echo !empty($member['winner_amount']) ? 'bg-success text-white' : 'bg-primary text-white'; ?>">
                        <h5 class="mb-0">
                            <i class="fas fa-trophy bid-winner-trophy"></i> Bid Winner Status
                            <?php if ($bid_winner_amount > 0): ?>
                                <span class="badge bg-light text-dark float-end">
                                    Eligible: ₹<?php echo number_format($bid_winner_amount, 2); ?>
                                </span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body py-3">
                        <?php if (!empty($member['winner_amount']) && !empty($member['winner_date'])): ?>
                            <div class="text-center">
                                <h3 class="text-success fw-bold mb-1">₹<?php echo number_format($member['winner_amount'], 2); ?></h3>
                                <p class="mb-1">
                                    Date: <?php echo formatDate($member['winner_date']); ?>
                                    <?php if (!empty($member['winner_number'])): ?>
                                        | Winner Number: <strong><?php echo htmlspecialchars($member['winner_number']); ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($winner_collector)): ?>
                                        <br>Declared by: <?php echo htmlspecialchars($winner_collector['full_name'] ?? $winner_collector['username']); ?>
                                    <?php endif; ?>
                                </p>
                                <p class="text-success mb-0"><i class="fas fa-check-circle"></i> Bid Winner Declared</p>
                            </div>
                        <?php elseif (!empty($member['early_big_winner_payment']) && $member['early_big_winner_payment'] > 0): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-money-bill-wave"></i> 
                                Early Bid Winner Payment of ₹<?php echo number_format($member['early_big_winner_payment'], 2); ?> 
                                received on <?php echo formatDate($member['early_payment_date']); ?>.
                                <?php if (!empty($member['early_payment_bill_number'])): ?>
                                    <br>Bill: <?php echo htmlspecialchars($member['early_payment_bill_number']); ?>
                                <?php endif; ?>
                                <?php if (!empty($early_payment_collector)): ?>
                                    <br>Collected by: <?php echo htmlspecialchars($early_payment_collector['full_name'] ?? $early_payment_collector['username']); ?>
                                <?php endif; ?>
                            </div>
                            <form id="declareForm" method="POST">
                                <input type="hidden" name="declare_winner" value="1">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label">Bid Winner Amount (₹)</label>
                                        <input type="number" step="0.01" name="winner_amount" class="form-control" 
                                               value="<?php echo $bid_winner_amount; ?>" readonly>
                                        <small class="text-success">Eligible amount from plan</small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Winner Date</label>
                                        <input type="date" name="winner_date" class="form-control" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Winner Number (Optional)</label>
                                        <input type="text" name="winner_number" class="form-control" 
                                               placeholder="e.g. Auction/Ticket No.">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="fas fa-trophy"></i> Declare
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- Show Eligible Amounts List -->
                            <form id="declareForm" method="POST">
                                <input type="hidden" name="declare_winner" value="1">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label">Bid Winner Amount (₹)</label>
                                        <select class="form-control" name="winner_amount" required>
                                            <option value="">Select Eligible Amount</option>
                                            <?php if (!empty($bid_winner_eligible_list)): ?>
                                                <?php foreach ($bid_winner_eligible_list as $eligible): ?>
                                                    <option value="<?php echo $eligible['amount']; ?>" 
                                                            <?php echo $eligible['month'] == count($bid_winner_eligible_list) ? 'selected' : ''; ?>>
                                                        Month <?php echo $eligible['month']; ?>: ₹<?php echo number_format($eligible['amount'], 2); ?>
                                                        <?php echo $eligible['month'] == count($bid_winner_eligible_list) ? ' (Final)' : ''; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="<?php echo $bid_winner_amount; ?>">
                                                    ₹<?php echo number_format($bid_winner_amount, 2); ?>
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                        <small class="text-muted">Select from eligible amounts</small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Winner Date</label>
                                        <input type="date" name="winner_date" class="form-control" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Winner Number (Optional)</label>
                                        <input type="text" name="winner_number" class="form-control" 
                                               placeholder="e.g. Auction/Ticket No.">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="fas fa-trophy"></i> Declare
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Report Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Total Plan Amount</h6>
                                <h3 class="text-primary mb-0">₹<?php echo number_format($plan_total_amount, 2); ?></h3>
                                <small>Expected total</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Collected Amount</h6>
                                <h3 class="text-success mb-0">₹<?php echo number_format($total_collected, 2); ?></h3>
                                <small>
                                    <?php echo $paid_count; ?> EMIs 
                                    <?php echo $early_payment > 0 ? '+ Early ₹' . number_format($early_payment, 2) : ''; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Pending Amount</h6>
                                <h3 class="text-warning mb-0">₹<?php echo number_format($total_pending, 2); ?></h3>
                                <small><?php echo $unpaid_count; ?> EMIs pending</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <h6 class="text-muted">Bid Winner Amount</h6>
                                <h3 class="text-info mb-0">₹<?php echo number_format($bid_winner_amount, 2); ?></h3>
                                <small>Eligible after all payments</small>
                                <?php if (!empty($site_number)): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-info">
                                            <i class="fas fa-map-marker-alt"></i> Site: <?php echo htmlspecialchars($site_number); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Table with Actions -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Payment Schedule</h4>
                            <div>
                                <span class="badge bg-<?php echo $is_weekly_plan ? 'info' : 'primary'; ?>">
                                    <?php echo $is_weekly_plan ? 'Weekly Payments' : 'Monthly EMIs'; ?>
                                </span>
                                <?php if ($bid_winner_amount > 0): ?>
                                    <span class="badge bg-success ms-2">
                                        Bid Winner: ₹<?php echo number_format($bid_winner_amount, 2); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($site_number)): ?>
                                    <span class="badge bg-info ms-2">
                                        <i class="fas fa-map-marker-alt"></i> Site: <?php echo htmlspecialchars($site_number); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Installment (₹)</th>
                                        <th>Eligible Amount (₹)</th>
                                        <th>Due Date</th>
                                        <th>Paid Date</th>
                                        <th>Bill Number</th>
                                        <th>Payment Type</th>
                                        <th>Collected By (Username)</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($emis)): 
                                        $sr = 1; 
                                        foreach ($emis as $index => $emi):
                                            $is_overdue = (!$emi['paid_date'] && strtotime($emi['emi_due_date']) < time());
                                            $payment_type_display = '';
                                            if ($emi['payment_type'] == 'cash') {
                                                $payment_type_display = '<span class="badge bg-success">Cash</span>';
                                            } elseif ($emi['payment_type'] == 'upi') {
                                                $payment_type_display = '<span class="badge bg-primary">UPI</span>';
                                            } elseif ($emi['payment_type'] == 'both') {
                                                $payment_type_display = '<span class="badge bg-warning">Both</span><br>
                                                <small>Cash: ₹' . number_format($emi['cash_amount'] ?? 0, 2) . '</small><br>
                                                <small>UPI: ₹' . number_format($emi['upi_amount'] ?? 0, 2) . '</small>';
                                            }
                                            
                                            $is_bid_winner_eligible = ($index == count($emis) - 1);
                                            $eligible_amount = isset($plan_details[$index]) ? $plan_details[$index]['withdrawal_eligible'] : 0;
                                            
                                            $is_site_highlighted = ($index === $highlight_emi_index);
                                            $row_class = '';
                                            if ($is_site_highlighted) {
                                                $row_class = 'site-highlight';
                                            } elseif ($is_overdue) {
                                                $row_class = 'table-danger';
                                            } elseif ($is_bid_winner_eligible && $emi['status'] == 'paid') {
                                                $row_class = 'table-success';
                                            }
                                            
                                            $collected_by_display = '';
                                            if (!empty($emi['collected_by_username'])) {
                                                $collected_by_display = htmlspecialchars($emi['collected_by_username']);
                                                if (!empty($emi['collected_by_name']) && $emi['collected_by_name'] != $emi['collected_by_username']) {
                                                    $collected_by_display .= '<br><small class="text-muted">(' . htmlspecialchars($emi['collected_by_name']) . ')</small>';
                                                }
                                            } else {
                                                $collected_by_display = '<span class="text-muted">-</span>';
                                            }
                                    ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>
                                                    <?php if ($is_site_highlighted): ?>
                                                        <span class="site-number-marker" title="Site Number: <?php echo htmlspecialchars($site_number); ?>">
                                                            <?php echo $highlight_emi_index + 1; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php echo $sr; ?>
                                                    <?php if ($is_bid_winner_eligible): ?>
                                                        <br><span class="badge bg-success">Bid Winner Eligible</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong>₹<?php echo number_format($emi['emi_amount'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($eligible_amount > 0): ?>
                                                        <span class="text-<?php echo $is_bid_winner_eligible ? 'success fw-bold' : 'info'; ?>">
                                                            ₹<?php echo number_format($eligible_amount, 2); ?>
                                                        </span>
                                                        <?php if ($is_bid_winner_eligible): ?>
                                                            <br><small class="text-success">Final Eligible Amount</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo formatDate($emi['emi_due_date']); ?>
                                                    <?php if ($is_overdue): ?>
                                                        <br><small class="text-danger">Overdue</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($emi['paid_date'])): ?>
                                                        <span class="text-success">
                                                            <?php echo formatDate($emi['paid_date']); ?>
                                                        </span>
                                                        <?php if (strtotime($emi['paid_date']) > strtotime($emi['emi_due_date'])): ?>
                                                            <br><small class="text-warning">Late Payment</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($emi['emi_bill_number'])): ?>
                                                        <span class="badge bg-light text-dark">
                                                            <?php echo htmlspecialchars($emi['emi_bill_number']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $payment_type_display; ?></td>
                                                <td>
                                                    <?php echo $collected_by_display; ?>
                                                </td>
                                                <td>
                                                    <?php if ($emi['status'] === 'paid'): ?>
                                                        <span class="badge bg-success">Paid</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-<?php echo $is_overdue ? 'danger' : 'warning'; ?>">
                                                            <?php echo $is_overdue ? 'Overdue' : 'Unpaid'; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-icons">
                                                        <?php if ($emi['status'] === 'paid'): ?>
                                                            <!-- WhatsApp Button for Paid EMI -->
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="send_emi_whatsapp" value="1">
                                                                <input type="hidden" name="emi_id" value="<?php echo $emi['id']; ?>">
                                                                <input type="hidden" name="customer_number" value="<?php echo htmlspecialchars($member['customer_number']); ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Send Payment Receipt via WhatsApp">
                                                                    <i class="fab fa-whatsapp"></i>
                                                                </button>
                                                            </form>
                                                            
                                                          
                                                            
                                                            <!-- Undo Payment Button -->
                                                            <a href="pay-emi.php?undo=<?php echo $emi['id']; ?>&member=<?php echo $member_id; ?>"
                                                               class="btn btn-sm btn-outline-warning" title="Undo Payment">
                                                                <i class="fas fa-undo"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <!-- WhatsApp Button for Unpaid EMI -->
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="send_emi_whatsapp" value="1">
                                                                <input type="hidden" name="emi_id" value="<?php echo $emi['id']; ?>">
                                                                <input type="hidden" name="customer_number" value="<?php echo htmlspecialchars($member['customer_number']); ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Send Payment Reminder via WhatsApp">
                                                                    <i class="fab fa-whatsapp"></i>
                                                                </button>
                                                            </form>
                                                            
                                                            <!-- Pay EMI Button -->
                                                            <a href="pay-emi.php?emi_id=<?php echo $emi['id']; ?>&member=<?php echo $member_id; ?>"
                                                               class="btn btn-sm btn-<?php echo $is_overdue ? 'danger' : 'success'; ?>"
                                                               title="Mark as Paid">
                                                                <i class="fas fa-rupee-sign"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php 
                                            $sr++; 
                                        endforeach;
                                    else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                No payment schedule found for this member.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary Footer -->
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Payment Summary</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Total EMIs:</span>
                                        <strong><?php echo count($emis); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Paid EMIs:</span>
                                        <strong class="text-success"><?php echo $paid_count; ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Unpaid EMIs:</span>
                                        <strong class="text-warning"><?php echo $unpaid_count; ?></strong>
                                    </div>
                                    <?php if ($early_payment > 0): ?>
                                    <div class="d-flex justify-content-between">
                                        <span>Early Payment:</span>
                                        <strong class="text-info">₹<?php echo number_format($early_payment, 2); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($site_number)): ?>
                                    <div class="d-flex justify-content-between">
                                        <span>Site Number:</span>
                                        <strong class="text-info"><?php echo htmlspecialchars($site_number); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Amount Summary</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Plan Total:</span>
                                        <strong>₹<?php echo number_format($plan_total_amount, 2); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Collected:</span>
                                        <strong class="text-success">₹<?php echo number_format($total_collected, 2); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Balance:</span>
                                        <strong class="text-warning">₹<?php echo number_format($total_pending, 2); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <h6 class="mb-2">Bid Winner Details</h6>
                                    <?php if ($bid_winner_amount > 0): ?>
                                        <div class="mb-2">
                                            <small class="text-muted">Final Eligible Amount:</small><br>
                                            <strong class="text-success">₹<?php echo number_format($bid_winner_amount, 2); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php 
                                    if ($unpaid_count > 0):
                                        $next_unpaid = null;
                                        foreach ($emis as $emi) {
                                            if ($emi['status'] === 'unpaid') {
                                                $next_unpaid = $emi;
                                                break;
                                            }
                                        }
                                    ?>
                                        <div class="mb-2">
                                            <small class="text-muted">Next Payment:</small><br>
                                            <strong>₹<?php echo number_format($next_unpaid['emi_amount'] ?? 0, 2); ?></strong><br>
                                            <small class="text-muted">
                                                Due: <?php echo !empty($next_unpaid) ? formatDate($next_unpaid['emi_due_date']) : '-'; ?>
                                            </small>
                                        </div>
                                        <?php if ($next_unpaid): ?>
                                            <a href="pay-emi.php?emi_id=<?php echo $next_unpaid['id']; ?>&member=<?php echo $member_id; ?>"
                                               class="btn btn-sm btn-primary w-100">
                                                <i class="fas fa-rupee-sign"></i> Pay Next EMI
                                            </a>
                                        <?php endif; ?>
                                    <?php elseif ($can_early_payment): ?>
                                        <div class="text-center text-warning">
                                            <i class="fas fa-money-bill-wave fa-2x mb-2"></i>
                                            <p class="mb-2">Collect Early Payment:</p>
                                            <strong>₹<?php echo number_format($remaining_amount, 2); ?></strong>
                                        </div>
                                    <?php elseif ($can_close_plan && $bid_winner_amount > 0): ?>
                                        <div class="text-center text-success">
                                            <i class="fas fa-trophy fa-2x mb-2 bid-winner-trophy"></i>
                                            <p class="mb-0">Ready to declare</p>
                                            <strong>Bid Winner: ₹<?php echo number_format($bid_winner_amount, 2); ?></strong>
                                            <br>
                                            <button type="button" class="btn btn-success btn-sm mt-2" 
                                                    onclick="document.getElementById('declareForm').submit();">
                                                <i class="fas fa-trophy"></i> Declare Now
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
</body>
</html>