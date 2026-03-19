<?php
// Enable error logging instead of displaying errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u329947844/domains/hifi11.in/public_html/finance/error_log');

// Start session
session_start();

// Database connection
include 'includes/db.php';

// Include FPDF with error checking
$fpdf_path = 'includes/fpdf.php';
if (file_exists($fpdf_path) && is_readable($fpdf_path)) {
    require_once $fpdf_path;
} else {
    error_log("FPDF library not found at $fpdf_path");
}

// Get selected finance_id from session
$finance_id = isset($_SESSION['finance_id']) ? intval($_SESSION['finance_id']) : 0;

// Get current month, year, and date
$current_month = date('m');
$current_year = date('Y');
$current_date = date('Y-m-d');

// Handle filter from GET parameters
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month; // Allow 'all' or month number
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Validate selected month and year
if ($selected_month !== 'all' && ($selected_month < 1 || $selected_month > 12)) {
    $selected_month = $current_month;
}
if ($selected_year < 2000 || $selected_year > date('Y') + 1) {
    $selected_year = $current_year;
}

// Validate date range
if ($start_date && $end_date) {
    $start_date_obj = DateTime::createFromFormat('Y-m-d', $start_date);
    $end_date_obj = DateTime::createFromFormat('Y-m-d', $end_date);
    $max_date = (new DateTime())->modify('+1 year')->format('Y-m-d');
    
    if (!$start_date_obj || !$end_date_obj || $start_date > $end_date || $end_date > $max_date) {
        $start_date = '';
        $end_date = '';
        $_SESSION['error'] = "Invalid date range. Please select valid dates.";
    }
}

// Function to calculate overdue charges
function calculateOverdueCharges($emi_amount, $due_date, $current_date) {
    if ($due_date >= $current_date) {
        return 0;
    }
    
    $date_diff = (strtotime($current_date) - strtotime($due_date)) / (60 * 60 * 24);
    $date_diff = max(0, $date_diff);
    
    // ₹2 per ₹1000 per day
    $overdue_charges = ($emi_amount / 1000) * 2 * $date_diff;
    return round($overdue_charges, 2);
}

// Function to generate WhatsApp message with PDF link in English and Tamil
function generateWhatsAppMessageWithPDF($row, $is_paid, $pdf_url = '', $pdf_failed = false) {
    // English Message
    $message = "Dear " . htmlspecialchars($row['customer_name']) . ",\n\n";
    
    if ($is_paid) {
        $message .= "Thank you for your payment! Here are your payment details:\n";
        $message .= "Agreement No: " . htmlspecialchars($row['agreement_number']) . "\n";
        $message .= "Bill No: " . htmlspecialchars($row['emi_bill_number'] ?: '-') . "\n";
        $message .= "EMI Amount: ₹" . number_format($row['emi_amount'], 2) . "\n";
        if ($row['overdue_charges'] > 0) {
            $message .= "Overdue Charges: ₹" . number_format($row['overdue_charges'], 2) . "\n";
        }
        $message .= "Total Paid: ₹" . number_format($row['emi_amount'] + $row['overdue_charges'], 2) . "\n";
        $message .= "Paid Date: " . date('d M Y', strtotime($row['paid_date'])) . "\n";
    } else {
        $message .= "Your EMI payment reminder:\n";
        $message .= "Agreement No: " . htmlspecialchars($row['agreement_number']) . "\n";
        $message .= "Bill No: " . htmlspecialchars($row['emi_bill_number'] ?: '-') . "\n";
        $message .= "EMI Amount: ₹" . number_format($row['emi_amount'], 2) . "\n";
        $overdue_charges = calculateOverdueCharges($row['emi_amount'], $row['emi_due_date'], date('Y-m-d'));
        if ($overdue_charges > 0) {
            $message .= "Overdue Charges: ₹" . number_format($overdue_charges, 2) . "\n";
        }
        $message .= "Total Payable: ₹" . number_format($row['emi_amount'] + $overdue_charges, 2) . "\n";
        $message .= "Due Date: " . date('d M Y', strtotime($row['emi_due_date'])) . "\n";
    }
    
    if (!$pdf_failed && !empty($pdf_url)) {
        $message .= "Download detailed receipt: " . $pdf_url . "\n";
    }
    
    $message .= "\nThank you for your business!\nSRI SELVAGANAPATHI AUTO FINANCE\n";

    // Separator
    $message .= "\n---\n";

    // Tamil Message
    $message .= "அன்புள்ள " . htmlspecialchars($row['customer_name']) . ",\n\n";
    
    if ($is_paid) {
        $message .= "உங்கள் கட்டணத்திற்கு நன்றி! உங்கள் கட்டண விவரங்கள்:\n";
        $message .= "ஒப்பந்த எண்: " . htmlspecialchars($row['agreement_number']) . "\n";
        $message .= "பில் எண்: " . htmlspecialchars($row['emi_bill_number'] ?: '-') . "\n";
        $message .= "EMI தொகை: ₹" . number_format($row['emi_amount'], 2) . "\n";
        if ($row['overdue_charges'] > 0) {
            $message .= "தாமதக் கட்டணங்கள்: ₹" . number_format($row['overdue_charges'], 2) . "\n";
        }
        $message .= "மொத்தம் செலுத்தப்பட்டது: ₹" . number_format($row['emi_amount'] + $row['overdue_charges'], 2) . "\n";
        $message .= "செலுத்தப்பட்ட தேதி: " . date('d M Y', strtotime($row['paid_date'])) . "\n";
    } else {
        $message .= "உங்கள் EMI கட்டண நினைவூட்டல்:\n";
        $message .= "ஒப்பந்த எண்: " . htmlspecialchars($row['agreement_number']) . "\n";
        $message .= "பில் எண்: " . htmlspecialchars($row['emi_bill_number'] ?: '-') . "\n";
        $message .= "EMI தொகை: ₹" . number_format($row['emi_amount'], 2) . "\n";
        $overdue_charges = calculateOverdueCharges($row['emi_amount'], $row['emi_due_date'], date('Y-m-d'));
        if ($overdue_charges > 0) {
            $message .= "தாமதக் கட்டணங்கள்: ₹" . number_format($overdue_charges, 2) . "\n";
        }
        $message .= "மொத்தம் செலுத்த வேண்டியவை: ₹" . number_format($row['emi_amount'] + $overdue_charges, 2) . "\n";
        $message .= "கடைசி தேதி: " . date('d M Y', strtotime($row['emi_due_date'])) . "\n";
    }
    
    if (!$pdf_failed && !empty($pdf_url)) {
        $message .= "விரிவான ரசீதை பதிவிறக்கவும்: " . $pdf_url . "\n";
    }
    
   

    return urlencode($message);
}

// Function to generate PDF with print-friendly bill format
function generateBillPDF($row, $is_paid, $filename) {
    global $current_date;
    if (!class_exists('FPDF')) {
        error_log("FPDF class not available for generating PDF for EMI ID: " . $row['emi_id']);
        return false;
    }

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 10);

    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'SRI Sabarivasa Finance', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, 'EMI Bill Statement', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Date: ' . date('d M Y', strtotime($current_date)), 0, 1, 'C');
    $pdf->Ln(5);

    // Bill Details Table
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Bill Details', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, "Customer Name: " . htmlspecialchars($row['customer_name']), 0, 1);
    $pdf->Cell(0, 7, "Agreement No: " . htmlspecialchars($row['agreement_number']), 0, 1);
    $pdf->Cell(0, 7, "Bill No: " . htmlspecialchars($row['emi_bill_number'] ?: '-'), 0, 1);
    $pdf->Cell(0, 7, "Principal Amount: ₹" . number_format($row['principal_amount'], 2), 0, 1);
    $pdf->Cell(0, 7, "Interest Amount: ₹" . number_format($row['interest_amount'], 2), 0, 1);
    $pdf->Cell(0, 7, "EMI Amount: ₹" . number_format($row['emi_amount'], 2), 0, 1);
    $pdf->Cell(0, 7, "Overdue Charges: " . ($row['overdue_charges'] > 0 ? "₹" . number_format($row['overdue_charges'], 2) : "₹0.00"), 0, 1);

    if ($is_paid) {
        $paid_date = new DateTime($row['paid_date']);
        $pdf->Cell(0, 7, "Paid Date: " . $paid_date->format('d M Y'), 0, 1);
        $pdf->Cell(0, 7, "Total Paid: ₹" . number_format($row['emi_amount'] + $row['overdue_charges'], 2), 0, 1);
    } else {
        $due_date = new DateTime($row['emi_due_date']);
        $overdue_days = isset($row['overdue_days']) ? $row['overdue_days'] : 0;
        $pdf->Cell(0, 7, "Due Date: " . $due_date->format('d M Y'), 0, 1);
        if ($overdue_days > 0) {
            $pdf->Cell(0, 7, "Overdue Days: " . $overdue_days, 0, 1);
        }
        $pdf->Cell(0, 7, "Total Payable: ₹" . number_format($row['emi_amount'] + ($row['calculated_overdue_charges'] ?? 0), 2), 0, 1);
    }

    // Footer
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, "Thank you for your business! Please contact us for any queries.", 0, 1, 'C');
    $pdf->Cell(0, 5, "SRI Sabarivasa Finance | Contact: 9500595040 | [Website URL]", 0, 1, 'C');

    // Ensure directory exists
    $dir = dirname($filename);
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    
    try {
        $pdf->Output('F', $filename);
        return true;
    } catch (Exception $e) {
        error_log("PDF generation error: " . $e->getMessage());
        return false;
    }
}

// Fetch all EMI schedules
$sql_emi = "SELECT e.id AS emi_id, c.id AS customer_id, c.customer_name, c.customer_number, c.agreement_number, 
                   e.principal_amount, e.interest_amount, e.emi_amount, e.emi_due_date, 
                   e.status, e.paid_date, e.overdue_charges, e.emi_bill_number,
                   l.loan_name, c.finance_id
            FROM emi_schedule e
            JOIN customers c ON e.customer_id = c.id
            JOIN loans l ON c.loan_id = l.id
            WHERE 1=1";

$params = [];
$types = "";
if ($start_date && $end_date) {
    $sql_emi .= " AND e.emi_due_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
} elseif ($selected_month === 'all') {
    $sql_emi .= " AND YEAR(e.emi_due_date) = ?";
    $params[] = $selected_year;
    $types .= "i";
} else {
    $sql_emi .= " AND MONTH(e.emi_due_date) = ? AND YEAR(e.emi_due_date) = ?";
    $params[] = $selected_month;
    $params[] = $selected_year;
    $types .= "ii";
}
if ($finance_id > 0) {
    $sql_emi .= " AND c.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

$sql_emi .= " ORDER BY e.emi_due_date ASC";

$stmt_emi = $conn->prepare($sql_emi);
if (!$stmt_emi) {
    error_log("Error preparing EMI query: " . $conn->error);
    $_SESSION['error'] = "Error preparing EMI query: " . $conn->error;
    $emi_records = [];
} else {
    if (!empty($params)) {
        $stmt_emi->bind_param($types, ...$params);
    }
    $stmt_emi->execute();
    $result_emi = $stmt_emi->get_result();

    if (!$result_emi) {
        error_log("Error fetching EMI data: " . $conn->error);
        $_SESSION['error'] = "Error fetching EMI data: " . $conn->error;
        $emi_records = [];
    } else {
        $emi_records = [];
        while ($row = $result_emi->fetch_assoc()) {
            // Calculate overdue charges dynamically for unpaid EMIs past due
            $emi_due_date = $row['emi_due_date'];
            $emi_amount = $row['emi_amount'];
            $overdue_days = 0;
            $calculated_overdue_charges = $row['overdue_charges'] ?: 0;

            if ($row['status'] === 'unpaid' && $emi_due_date < $current_date) {
                $date_diff = (strtotime($current_date) - strtotime($emi_due_date)) / (60 * 60 * 24);
                $overdue_days = max(0, floor($date_diff));
                $calculated_overdue_charges = calculateOverdueCharges($emi_amount, $emi_due_date, $current_date);
                
                // Update overdue charges in database
                $update_sql = "UPDATE emi_schedule SET overdue_charges = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("di", $calculated_overdue_charges, $row['emi_id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                $row['overdue_charges'] = $calculated_overdue_charges;
            }

            $row['overdue_days'] = $overdue_days;
            $row['calculated_overdue_charges'] = $calculated_overdue_charges;
            $emi_records[] = $row;
        }
        $result_emi->free();
    }
    $stmt_emi->close();
}

// Calculate totals
$total_principal = 0;
$total_interest = 0;
$total_overdue = 0;
$total_expected = 0;
foreach ($emi_records as $row) {
    $total_principal += $row['principal_amount'];
    $total_interest += $row['interest_amount'];
    $total_overdue += $row['calculated_overdue_charges'];
    $total_expected += $row['emi_amount'] + $row['calculated_overdue_charges'];
}

mysqli_close($conn);

// Set breadcrumb title based on filter
if ($start_date && $end_date) {
    $breadcrumb_active = "EMI Schedule for " . (new DateTime($start_date))->format('d M Y') . " - " . (new DateTime($end_date))->format('d M Y');
} elseif ($selected_month === 'all') {
    $breadcrumb_active = "EMI Schedule for " . $selected_year . " (All Months)";
} else {
    $breadcrumb_active = "EMI Schedule for " . date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year));
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>

<body>
  <!-- Top Bar Start -->
  <?php include 'includes/topbar.php'; ?>
  <!-- Top Bar End -->

  <!-- leftbar-tab-menu -->
  <div class="startbar d-print-none">
     <?php include 'includes/leftbar-tab-menu.php'; ?> 
    <?php include 'includes/leftbar.php'; ?>
    <div class="startbar-overlay d-print-none"></div>

    <div class="page-wrapper">
      <!-- Page Content-->
      <div class="page-content">
        <div class="container-fluid">
          <!-- Breadcrumb Start -->
          <?php
          $page_title = "EMI Schedule Report";
          include 'includes/breadcrumb.php';
          ?>
          <!-- Breadcrumb End -->

          <!-- Flash Messages -->
          <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
              <strong>Success!</strong> <?php echo $_SESSION['success']; ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
          <?php endif; ?>
          <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <strong>Error!</strong> <?php echo $_SESSION['error']; ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
          <?php endif; ?>

          <!-- Search Input for Mobile -->
          <div class="d-md-none mb-3">
            <div class="input-group">
              <input type="text" id="mobileSearch" class="form-control" placeholder="Search by Ag.No, Bill No, Name, or Phone">
              <button class="btn btn-outline-secondary" type="button" onclick="$('#mobileSearch').val('').trigger('keyup');">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>

          <div class="row align-items-center mb-3">
            <div class="col">
              <h3 class="mb-0"><?php echo $breadcrumb_active; ?></h3>
              <small class="text-muted">
                <?php if ($finance_id > 0): ?>
                  Filtered for selected finance. Total EMIs: <?php echo count($emi_records); ?>
                <?php else: ?>
                  View all EMI schedules for the selected period. Total EMIs: <?php echo count($emi_records); ?>
                <?php endif; ?>
              </small>
            </div>
            <div class="col-auto">
              <a href="reports.php" class="btn btn-light">
                <i class="iconoir-arrow-left me-1"></i> Back to Reports
              </a>
              <a href="calendar.php" class="btn btn-light ms-2">View Calendar</a>
            </div>
          </div>

          <!-- Filter Form -->
          <div class="card mb-4">
            <div class="card-header bg-light">
              <h5 class="card-title mb-0">Filter EMI Schedule</h5>
            </div>
            <div class="card-body">
              <form method="GET" action="">
                <div class="row g-3">
                  <div class="col-md-3">
                    <label for="month" class="form-label">Select Month</label>
                    <select class="form-control" id="month" name="month">
                      <option value="all" <?php echo ($selected_month === 'all') ? 'selected' : ''; ?>>All Months</option>
                      <?php for ($m = 1; $m <= 12; $m++) { ?>
                        <option value="<?php echo $m; ?>" <?php echo ($m == $selected_month) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                      <?php } ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="year" class="form-label">Select Year</label>
                    <select class="form-control" id="year" name="year">
                      <?php for ($y = 2020; $y <= date('Y') + 1; $y++) { ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $selected_year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                      <?php } ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                  </div>
                  <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                  </div>
                  <div class="col-md-12 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filter</button>
                    <?php if (isset($_GET['month']) || isset($_GET['year']) || $start_date || $end_date): ?>
                      <a href="" class="btn btn-outline-secondary">Clear Filter</a>
                    <?php endif; ?>
                  </div>
                </div>
              </form>
            </div>
          </div>

          <?php if (!empty($emi_records)): ?>
            <!-- EMI Totals -->
            <div class="card mb-4">
              <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">EMI Summary</h5>
              </div>
              <div class="card-body">
                <div class="row text-center">
                  <div class="col-md-3">
                    <div class="fw-bold text-success fs-4">₹<?php echo number_format($total_principal, 2); ?></div>
                    <small class="text-muted">Total Principal</small>
                  </div>
                  <div class="col-md-3">
                    <div class="fw-bold text-warning fs-4">₹<?php echo number_format($total_interest, 2); ?></div>
                    <small class="text-muted">Total Interest</small>
                  </div>
                  <div class="col-md-3">
                    <div class="fw-bold text-danger fs-4">₹<?php echo number_format($total_overdue, 2); ?></div>
                    <small class="text-muted">Total Overdue</small>
                  </div>
                  <div class="col-md-3">
                    <div class="fw-bold text-primary fs-4">₹<?php echo number_format($total_expected, 2); ?></div>
                    <small class="text-muted">Total Expected</small>
                  </div>
                </div>
              </div>
            </div>

            <!-- EMI Schedule Table (Desktop) -->
            <div class="card d-none d-md-block">
              <div class="card-header">
                <h5 class="card-title mb-0">EMI Schedule Details</h5>
              </div>
              <div class="card-body">
                <div class="table-responsive">
                  <table id="tblEmiSchedule" class="table table-striped table-bordered align-middle w-100">
                    <thead class="table-light">
                      <tr>
                        <th>Ag.No</th>
                        <th>Bill No</th>
                        <th>Customer Name</th>
                        <th>Customer Number</th>
                        <th>Loan Name</th>
                        <th>Principal (₹)</th>
                        <th>Interest (₹)</th>
                        <th>EMI (₹)</th>
                        <th>Overdue (₹)</th>
                        <th>Due Date</th>
                        <th>Overdue Days</th>
                        <th>Status</th>
                        <th>Paid Date</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($emi_records as $row): ?>
                        <?php 
                        $status_display = $row['status'] === 'paid' ? 'Paid' : ($row['overdue_days'] > 0 ? 'Overdue' : 'Pending');
                        $status_class = $row['status'] === 'paid' ? 'success' : ($row['overdue_days'] > 0 ? 'danger' : 'warning');
                        $overdue_charges_display = $row['calculated_overdue_charges'] > 0 ? $row['calculated_overdue_charges'] : 0;

                        // Generate PDF for each row
                        $pdf_filename = '';
                        $pdf_path = '';
                        $pdf_url = '';
                        $pdf_failed = false;

                        if (class_exists('FPDF')) {
                            $pdf_filename = 'bill_' . $row['emi_id'] . '_' . time() . '.pdf';
                            $pdf_path = '/home/u329947844/domains/hifi11.in/public_html/finance/pdfs/' . $pdf_filename;
                            $pdf_url = 'https://hifi11.in/finance/pdfs/' . $pdf_filename;

                            if (!generateBillPDF($row, $row['status'] === 'paid', $pdf_path)) {
                                $pdf_failed = true;
                                error_log("Failed to generate PDF for EMI ID: " . $row['emi_id']);
                            }
                        } else {
                            $pdf_failed = true;
                        }
                        ?>
                        <tr class="table-<?php echo $status_class; ?>">
                          <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars($row['agreement_number']); ?></span></td>
                          <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars($row['emi_bill_number'] ?: '-'); ?></span></td>
                          <td>
                            <div class="d-flex align-items-center">
                              <div class="flex-shrink-0 me-2">
                                <div class="avatar-xs">
                                  <div class="avatar-title bg-soft-<?php echo $status_class; ?> text-<?php echo $status_class; ?> rounded-circle">
                                    <?php echo strtoupper(substr($row['customer_name'], 0, 1)); ?>
                                  </div>
                                </div>
                              </div>
                              <div class="flex-grow-1">
                                <?php echo htmlspecialchars($row['customer_name']); ?>
                              </div>
                            </div>
                          </td>
                          <td>
                            <a href="tel:<?php echo $row['customer_number']; ?>" class="text-decoration-none">
                              <i class="fas fa-phone-alt me-1 text-muted"></i>
                              <?php echo htmlspecialchars($row['customer_number']); ?>
                            </a>
                          </td>
                          <td>
                            <span class="badge bg-soft-secondary">
                              <?php echo htmlspecialchars($row['loan_name']); ?>
                            </span>
                          </td>
                          <td><span class="fw-bold text-success">₹<?php echo number_format($row['principal_amount'], 2); ?></span></td>
                          <td><span class="fw-bold text-warning">₹<?php echo number_format($row['interest_amount'], 2); ?></span></td>
                          <td><span class="fw-bold text-primary">₹<?php echo number_format($row['emi_amount'], 2); ?></span></td>
                          <td>
                            <?php if ($overdue_charges_display > 0): ?>
                              <span class="fw-bold text-danger">₹<?php echo number_format($overdue_charges_display, 2); ?></span>
                            <?php else: ?>
                              <span class="text-muted">-</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php 
                            $due_date = new DateTime($row['emi_due_date']);
                            echo $due_date->format('d M Y');
                            ?>
                          </td>
                          <td>
                            <span class="badge bg-<?php echo ($row['overdue_days'] > 0 ? 'danger' : 'secondary'); ?>">
                              <?php echo $row['overdue_days']; ?> days
                            </span>
                          </td>
                          <td>
                            <span class="badge bg-<?php echo $status_class; ?>">
                              <?php echo $status_display; ?>
                            </span>
                          </td>
                          <td>
                            <?php if ($row['paid_date']): ?>
                              <?php 
                              $paid_date = new DateTime($row['paid_date']);
                              echo $paid_date->format('d M Y');
                              ?>
                            <?php else: ?>
                              <span class="text-muted">-</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-end">
                            <div class="btn-group">
                              <a href="emi-schedule.php?customer_id=<?php echo $row['customer_id']; ?>" class="btn btn-sm btn-outline-info" title="View Schedule">
                                <i class="fas fa-eye"></i>
                              </a>
                              <?php if ($row['status'] === 'unpaid'): ?>
                                <a href="pay-emi.php?emi_id=<?php echo $row['emi_id']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                                   class="btn btn-sm btn-outline-success" 
                                   onclick="return confirm('Mark this EMI as paid? This will collect ₹<?php echo number_format($row['emi_amount'] + $overdue_charges_display, 2); ?>')"
                                   title="Pay Now">
                                  <i class="fas fa-check"></i>
                                </a>
                              <?php endif; ?>
                              <a href="https://wa.me/<?php echo htmlspecialchars($row['customer_number']); ?>?text=<?php echo generateWhatsAppMessageWithPDF($row, $row['status'] === 'paid', $pdf_url, $pdf_failed); ?>" 
                                 class="btn btn-sm btn-outline-success" title="Send Bill<?php echo $pdf_failed ? '' : ' PDF'; ?> via WhatsApp" target="_blank">
                                <i class="fab fa-whatsapp"></i>
                              </a>
                              <?php if (!$pdf_failed): ?>
                                <a href="<?php echo $pdf_url; ?>" class="btn btn-sm btn-outline-primary" title="View PDF" target="_blank">
                                  <i class="fas fa-file-pdf"></i>
                                </a>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- EMI Schedule Cards (Mobile) -->
            <div class="d-md-none">
              <?php if (!empty($emi_records)): ?>
                <div class="row" id="emiCards">
                  <?php foreach ($emi_records as $row): ?>
                    <?php 
                    $status_display = $row['status'] === 'paid' ? 'Paid' : ($row['overdue_days'] > 0 ? 'Overdue' : 'Pending');
                    $status_class = $row['status'] === 'paid' ? 'success' : ($row['overdue_days'] > 0 ? 'danger' : 'warning');
                    $overdue_charges_display = $row['calculated_overdue_charges'] > 0 ? $row['calculated_overdue_charges'] : 0;

                    // Generate PDF for each row
                    $pdf_filename = '';
                    $pdf_path = '';
                    $pdf_url = '';
                    $pdf_failed = false;

                    if (class_exists('FPDF')) {
                        $pdf_filename = 'bill_' . $row['emi_id'] . '_' . time() . '.pdf';
                        $pdf_path = '/home/u329947844/domains/hifi11.in/public_html/finance/pdfs/' . $pdf_filename;
                        $pdf_url = 'https://hifi11.in/finance/pdfs/' . $pdf_filename;

                        if (!generateBillPDF($row, $row['status'] === 'paid', $pdf_path)) {
                            $pdf_failed = true;
                            error_log("Failed to generate PDF for EMI ID: " . $row['emi_id']);
                        }
                    } else {
                        $pdf_failed = true;
                    }
                    ?>
                    <div class="card mb-3 border-<?php echo $status_class; ?> emi-card-item"
                         data-agreement-number="<?php echo htmlspecialchars(strtolower($row['agreement_number'])); ?>"
                         data-bill-number="<?php echo htmlspecialchars(strtolower($row['emi_bill_number'] ?: '')); ?>"
                         data-customer-name="<?php echo htmlspecialchars(strtolower($row['customer_name'])); ?>"
                         data-customer-number="<?php echo htmlspecialchars(strtolower($row['customer_number'])); ?>">
                      <div class="card-header d-flex justify-content-between align-items-start bg-<?php echo $status_class; ?> text-white py-2">
                        <h5 class="mb-0"><?php echo htmlspecialchars($row['customer_name']); ?></h5>
                        <span class="badge bg-light text-<?php echo $status_class; ?>">
                          <?php echo $status_display; ?>
                          <?php if ($row['overdue_days'] > 0): ?>
                            <br><small><?php echo $row['overdue_days']; ?> days</small>
                          <?php endif; ?>
                        </span>
                      </div>
                      <div class="card-body">
                        <div class="row g-2">
                          <div class="col-6">
                            <small class="text-muted">Customer</small>
                            <div class="fw-bold text-truncate"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                          </div>
                          <div class="col-6">
                            <small class="text-muted">Number</small>
                            <div>
                              <a href="tel:<?php echo $row['customer_number']; ?>" class="text-decoration-none">
                                <i class="fas fa-phone-alt me-1 text-muted"></i>
                                <?php echo htmlspecialchars($row['customer_number']); ?>
                              </a>
                            </div>
                          </div>
                          <div class="col-6">
                            <small class="text-muted">Agreement No</small>
                            <div class="fw-bold text-truncate"><?php echo htmlspecialchars($row['agreement_number']); ?></div>
                          </div>
                          <div class="col-6">
                            <small class="text-muted">Bill No</small>
                            <div class="fw-bold text-truncate"><?php echo htmlspecialchars($row['emi_bill_number'] ?: '-'); ?></div>
                          </div>
                          <div class="col-12">
                            <small class="text-muted">Loan</small>
                            <div class="text-truncate"><?php echo htmlspecialchars($row['loan_name']); ?></div>
                          </div>
                          <div class="col-4">
                            <small class="text-muted">Principal</small>
                            <div class="fw-bold text-success">₹<?php echo number_format($row['principal_amount'], 2); ?></div>
                          </div>
                          <div class="col-4">
                            <small class="text-muted">Interest</small>
                            <div class="fw-bold text-warning">₹<?php echo number_format($row['interest_amount'], 2); ?></div>
                          </div>
                          <div class="col-4">
                            <small class="text-muted">EMI</small>
                            <div class="fw-bold text-primary">₹<?php echo number_format($row['emi_amount'], 2); ?></div>
                          </div>
                          <div class="col-6">
                            <small class="text-muted">Overdue</small>
                            <div class="fw-bold text-danger">
                              <?php echo ($overdue_charges_display > 0 ? '₹' . number_format($overdue_charges_display, 2) : '-'); ?>
                            </div>
                          </div>
                          <div class="col-6">
                            <small class="text-muted">Due Date</small>
                            <div>
                              <?php 
                              $due_date = new DateTime($row['emi_due_date']);
                              echo $due_date->format('d M Y');
                              ?>
                            </div>
                          </div>
                          <div class="col-6">
                            <small class="text-muted">Overdue Days</small>
                            <div><span class="badge bg-<?php echo ($row['overdue_days'] > 0 ? 'danger' : 'secondary'); ?>"><?php echo $row['overdue_days']; ?> days</span></div>
                          </div>
                          <div class="col-6">
                            <small class="text-muted">Paid Date</small>
                            <div><?php echo ($row['paid_date'] ? (new DateTime($row['paid_date']))->format('d M Y') : '-'); ?></div>
                          </div>
                          <div class="col-12">
                            <small class="text-muted">Total Payable</small>
                            <div class="fw-bold fs-5 text-success">₹<?php echo number_format($row['emi_amount'] + $overdue_charges_display, 2); ?></div>
                          </div>
                        </div>
                        <div class="d-flex justify-content-end mt-3 gap-2">
                          <a href="emi-schedule.php?customer_id=<?php echo $row['customer_id']; ?>" class="btn btn-sm btn-outline-info" title="View Schedule">
                            <i class="fas fa-eye"></i>
                          </a>
                          <?php if ($row['status'] === 'unpaid'): ?>
                            <a href="pay-emi.php?emi_id=<?php echo $row['emi_id']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                               class="btn btn-sm btn-outline-success" 
                               onclick="return confirm('Mark this EMI as paid? This will collect ₹<?php echo number_format($row['emi_amount'] + $overdue_charges_display, 2); ?>')"
                               title="Pay Now">
                              <i class="fas fa-check"></i>
                            </a>
                          <?php endif; ?>
                          <a href="https://wa.me/<?php echo htmlspecialchars($row['customer_number']); ?>?text=<?php echo generateWhatsAppMessageWithPDF($row, $row['status'] === 'paid', $pdf_url, $pdf_failed); ?>" 
                             class="btn btn-sm btn-outline-success" title="Send Bill<?php echo $pdf_failed ? '' : ' PDF'; ?> via WhatsApp" target="_blank">
                            <i class="fab fa-whatsapp"></i>
                          </a>
                          <?php if (!$pdf_failed): ?>
                            <a href="<?php echo $pdf_url; ?>" class="btn btn-sm btn-outline-primary" title="View PDF" target="_blank">
                              <i class="fas fa-file-pdf"></i>
                            </a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="card">
                  <div class="card-body text-center py-5">
                    <div class="text-muted">
                      <i class="fas fa-info-circle fa-2x mb-2"></i>
                      <h5>No EMI records found</h5>
                      <p class="mb-0">No EMI schedules for the selected period.</p>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </div>

          <?php else: ?>
            <div class="alert alert-info" role="alert">
              <i class="fas fa-info-circle me-2"></i>
              No EMI records found for the selected period.
            </div>
          <?php endif; ?>

        </div><!-- container -->

        <?php include 'includes/rightbar.php'; ?>
        <?php include 'includes/footer.php'; ?>
      </div>
      <!-- end page content -->
    </div>
    <!-- end page-wrapper -->

    <?php include 'includes/scripts.php'; ?>

    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

    <style>
      /* Mobile Card Styling */
      .card {
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: transform 0.2s;
        overflow: hidden;
      }
      
      .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      }
      
      .avatar-xs {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
      }
      
      /* Status Colors */
      .bg-danger { background-color: #dc3545 !important; }
      .bg-warning { background-color: #ffc107 !important; }
      .bg-success { background-color: #28a745 !important; }
      .bg-info { background-color: #17a2b8 !important; }
      .bg-soft-danger { background-color: rgba(220, 53, 69, 0.1) !important; }
      .bg-soft-warning { background-color: rgba(255, 193, 7, 0.1) !important; }
      .bg-soft-success { background-color: rgba(40, 167, 69, 0.1) !important; }
      .bg-soft-info { background-color: rgba(23, 162, 184, 0.1) !important; }
      .text-danger { color: #dc3545 !important; }
      .text-warning { color: #ffc107 !important; }
      .text-success { color: #28a745 !important; }
      .text-info { color: #17a2b8 !important; }
      .border-danger { border-color: #dc3545 !important; }
      .border-warning { border-color: #ffc107 !important; }
      .border-success { border-color: #28a745 !important; }
      
      /* Mobile Responsive */
      @media (max-width: 767.98px) {
        .container-fluid {
          padding-left: 12px;
          padding-right: 12px;
        }
        
        .card-body {
          padding: 0.75rem;
        }
        
        .card-header {
          padding: 0.5rem 0.75rem;
        }
        
        .card-header h5 {
          font-size: 1rem;
          max-width: 200px;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
        
        .btn {
          padding: 0.4rem 0.6rem;
          font-size: 0.8rem;
        }
        
        .badge {
          font-size: 0.7rem;
          padding: 0.2rem 0.4rem;
        }
        
        .row.align-items-center.mb-3 {
          flex-direction: column;
          align-items: flex-start !important;
        }
        
        .row.align-items-center.mb-3 .col-auto {
          margin-top: 0.5rem;
          width: 100%;
        }
        
        .row.align-items-center.mb-3 .col-auto .btn {
          width: 100%;
          justify-content: center;
        }
        
        .row.g-2 > [class*="col-"] {
          margin-bottom: 0.5rem;
        }
      }
      
      @media (max-width: 575.98px) {
        .page-title-box {
          flex-direction: column;
          align-items: flex-start !important;
        }
        
        .page-title-box .breadcrumb {
          margin-top: 0.5rem;
        }
        
        .card .card-body {
          padding: 0.5rem;
        }
      }
      
      /* Prevent horizontal overflow */
      .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      
      /* Ensure proper spacing */
      .mb-3 {
        margin-bottom: 1rem !important;
      }
      
      .mb-4 {
        margin-bottom: 1.5rem !important;
      }
    </style>

    <script>
      // Suppress DataTables alert pop-ups
      $.fn.dataTable.ext.errMode = 'none';

      // Log DataTables errors to console
      $('#tblEmiSchedule').on('error.dt', function(e, settings, techNote, message) {
        console.error('DataTables error: ', message);
      });

      // Initialize DataTables
      $(document).ready(function() {
        var emiTable = $('#tblEmiSchedule').DataTable({
          pageLength: 100,
          lengthMenu: [10, 25, 50, 100],
          order: [[9, 'asc']], // Sort by Due Date
          columnDefs: [
            { orderable: false, targets: -1 } // Disable sorting on Actions column
          ],
          language: {
            search: "Search by Ag.No, Bill No, Name, or Phone:",
            lengthMenu: "Show _MENU_ EMIs",
            info: "Showing _START_ to _END_ of _TOTAL_ EMIs",
            infoEmpty: "No EMIs available",
            infoFiltered: "(filtered from _MAX_ total EMIs)",
            paginate: {
              first: "First",
              last: "Last",
              next: "Next",
              previous: "Previous"
            }
          },
          responsive: true,
          initComplete: function() {
            var table = this.api();
            $('#tblEmiSchedule_filter input').off().on('keyup', function() {
              var searchTerm = this.value.toLowerCase();
              table.rows().every(function() {
                var data = this.data();
                var agreementNumber = data[0] ? data[0].toString().toLowerCase() : '';
                var billNumber = data[1] ? data[1].toString().toLowerCase() : '';
                var customerName = data[2] ? data[2].toString().toLowerCase() : '';
                var customerNumber = data[3] ? data[3].toString().toLowerCase() : '';
                if (agreementNumber.includes(searchTerm) || 
                    billNumber.includes(searchTerm) ||
                    customerName.includes(searchTerm) || 
                    customerNumber.includes(searchTerm)) {
                  this.nodes().to$().show();
                } else {
                  this.nodes().to$().hide();
                }
              });
            });
          }
        });

        // Mobile search functionality
        $('#mobileSearch').on('keyup', function() {
          var searchTerm = $(this).val().toLowerCase();
          $('.emi-card-item').each(function() {
            var agreementNumber = $(this).data('agreement-number').toLowerCase();
            var billNumber = $(this).data('bill-number').toLowerCase();
            var customerName = $(this).data('customer-name').toLowerCase();
            var customerNumber = $(this).data('customer-number').toLowerCase();
            if (agreementNumber.includes(searchTerm) || 
                billNumber.includes(searchTerm) ||
                customerName.includes(searchTerm) || 
                customerNumber.includes(searchTerm)) {
              $(this).show();
            } else {
              $(this).hide();
            }
          });
        });
      });
    </script>

</body>
</html>