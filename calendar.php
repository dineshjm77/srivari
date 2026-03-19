<?php
// Enable error logging instead of displaying errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u329947844/domains/hifi11.in/public_html/finance/error_log');
// Start session
session_start();

// Database connection
include 'includes/db.php';

// Get selected finance_id from session
$finance_id = isset($_SESSION['finance_id']) ? intval($_SESSION['finance_id']) : 0;

// Get current month and year
$current_month = date('m');
$current_year = date('Y');
$current_date = date('Y-m-d');

// Handle filter from GET parameters
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : $current_month;
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

// Validate selected month and year
if ($selected_month < 1 || $selected_month > 12) {
    $selected_month = $current_month;
}
if ($selected_year < 2000 || $selected_year > date('Y') + 1) {
    $selected_year = $current_year;
}

// Check if finance is selected
$finance_selected = $finance_id > 0;

// Build WHERE clause with finance filtering
$where_clause = "WHERE MONTH(e.emi_due_date) = ? AND YEAR(e.emi_due_date) = ?";
$params = [$selected_month, $selected_year];
$types = "ii";

if ($finance_selected) {
    $where_clause .= " AND e.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

// Fetch EMI schedules for the selected month/year
$sql = "SELECT e.id AS emi_id, c.id AS customer_id, c.customer_name, c.customer_number, 
               e.principal_amount, e.interest_amount, e.emi_amount, 
               e.emi_due_date, e.status, e.paid_date, e.overdue_charges,
               l.loan_name, f.finance_name
        FROM emi_schedule e
        JOIN customers c ON e.customer_id = c.id
        JOIN loans l ON c.loan_id = l.id
        JOIN finance f ON e.finance_id = f.id
        $where_clause
        ORDER BY e.emi_due_date";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    die("Error preparing query: " . $conn->error);
}

if (!$result) {
    die("Error fetching EMI data: " . $conn->error);
}

// Prepare calendar events and calculate totals
$events = [];
$total_principal_paid = 0;
$total_interest_paid = 0;
$total_overdue_paid = 0;
$total_collected_paid = 0;
$total_principal_unpaid = 0;
$total_interest_unpaid = 0;
$total_overdue_unpaid = 0;
$total_expected_unpaid = 0;
$total_paid_count = 0;
$total_pending_count = 0;
$total_overdue_count = 0;

// Group events by date for mobile view
$events_by_date = [];

while ($row = $result->fetch_assoc()) {
    $emi_due_date = $row['emi_due_date'];
    $emi_amount = $row['emi_amount'];
    $status = $row['status'];
    $overdue_charges = $row['overdue_charges'] ?: 0;

    // Calculate overdue charges for non-paid EMIs
    if ($status != 'paid' && $emi_due_date < $current_date) {
        $date_diff = (strtotime($current_date) - strtotime($emi_due_date)) / (60 * 60 * 24);
        $overdue_charges = ($emi_amount / 1000) * 2 * $date_diff; // ₹2 per ₹1000 per day
    }

    // Determine status display
    $status_display = $status == 'paid' ? 'Paid' : ($emi_due_date < $current_date ? 'Overdue' : 'Pending');
    
    // Count by status
    if ($status == 'paid') {
        $total_paid_count++;
    } else if ($emi_due_date < $current_date) {
        $total_overdue_count++;
    } else {
        $total_pending_count++;
    }

    // Prepare event for FullCalendar
    $event = [
        'id' => $row['emi_id'],
        'title' => 'EMI-' . $row['emi_id'] . ': ' . htmlspecialchars($row['customer_name']),
        'start' => $emi_due_date,
        'className' => $status == 'paid' ? 'bg-success' : ($emi_due_date < $current_date ? 'bg-danger' : 'bg-warning'),
        'extendedProps' => [
            'customer_id' => $row['customer_id'],
            'customer_name' => htmlspecialchars($row['customer_name']),
            'customer_number' => htmlspecialchars($row['customer_number']),
            'loan_name' => htmlspecialchars($row['loan_name']),
            'finance_name' => htmlspecialchars($row['finance_name']),
            'principal_amount' => $row['principal_amount'],
            'interest_amount' => $row['interest_amount'],
            'emi_amount' => $row['emi_amount'],
            'overdue_charges' => $overdue_charges,
            'status' => $status_display,
            'paid_date' => $row['paid_date'] ?: '-'
        ]
    ];
    $events[] = $event;

    // Group events by date for mobile view
    if (!isset($events_by_date[$emi_due_date])) {
        $events_by_date[$emi_due_date] = [];
    }
    $events_by_date[$emi_due_date][] = $event;

    // Update totals
    if ($status == 'paid') {
        $total_principal_paid += $row['principal_amount'];
        $total_interest_paid += $row['interest_amount'];
        $total_overdue_paid += $overdue_charges;
        $total_collected_paid += $row['emi_amount'] + $overdue_charges;
    } else {
        $total_principal_unpaid += $row['principal_amount'];
        $total_interest_unpaid += $row['interest_amount'];
        $total_overdue_unpaid += $overdue_charges;
        $total_expected_unpaid += $row['emi_amount'] + $overdue_charges;
    }
}

$stmt->close();
mysqli_close($conn);
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
          $page_title = "EMI Calendar";
          $breadcrumb_active = "EMI Calendar for " . date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year));
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

          <?php if (!$finance_selected): ?>
            <div class="alert alert-warning" role="alert">
              <i class="fas fa-exclamation-triangle me-2"></i>
              <strong>No Finance Selected:</strong> Please select a finance from the topbar dropdown to view calendar. EMI data is filtered by selected finance.
              <div class="mt-2">
                <form action="set-finance-session.php" method="post" id="financeSelectForm" class="d-inline">
                  <select class="form-select d-inline-block w-auto me-2" name="finance_id" onchange="this.form.submit()">
                    <option value="0">Select Finance</option>
                    <option value="1">A Finance</option>
                    <option value="2">B Finance</option>
                    <option value="3">finance</option>
                  </select>
                </form>
                <a href="#" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('financeSelectForm').submit();">
                  <i class="iconoir-arrow-left me-1"></i> Select Finance
                </a>
              </div>
            </div>
          <?php endif; ?>

          <div class="row align-items-center mb-3">
            <div class="col">
              <h3 class="mb-0">EMI Calendar</h3>
              <small class="text-muted">
                <?php if ($finance_selected): ?>
                  Filtered for selected finance. 
                <?php endif; ?>
                View EMI schedules for <?php echo date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)); ?>
                | Total EMIs: <?php echo count($events); ?> (Paid: <?php echo $total_paid_count; ?>, Pending: <?php echo $total_pending_count; ?>, Overdue: <?php echo $total_overdue_count; ?>)
              </small>
            </div>
            <div class="col-auto">
              <a href="overall-reports.php" class="btn btn-light">
                <i class="iconoir-arrow-left me-1"></i> Back to Reports
              </a>
              <a href="collection.php" class="btn btn-light ms-2">View Collections</a>
            </div>
          </div>

          <!-- Filter Form -->
          <div class="card mb-4">
            <div class="card-header bg-light">
              <h5 class="card-title mb-0">Filter Calendar</h5>
            </div>
            <div class="card-body">
              <form method="GET" action="calendar.php">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label for="month" class="form-label fw-bold">Select Month</label>
                    <select class="form-control" id="month" name="month">
                      <?php for ($m = 1; $m <= 12; $m++) { ?>
                        <option value="<?php echo $m; ?>" <?php echo ($m == $selected_month) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                      <?php } ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="year" class="form-label fw-bold">Select Year</label>
                    <select class="form-control" id="year" name="year">
                      <?php for ($y = 2020; $y <= date('Y') + 1; $y++) { ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $selected_year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                      <?php } ?>
                    </select>
                  </div>
                  <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                  </div>
                </div>
              </form>
            </div>
          </div>

          <!-- Summary Cards -->
          <div class="row mb-4">
            <div class="col-6 col-md-3 mb-3">
              <div class="card bg-primary text-white">
                <div class="card-body p-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <h5 class="mb-0"><?php echo count($events); ?></h5>
                      <small>Total EMIs</small>
                    </div>
                    <i class="fas fa-calendar fa-lg opacity-50"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="card bg-success text-white">
                <div class="card-body p-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <h5 class="mb-0"><?php echo $total_paid_count; ?></h5>
                      <small>Paid EMIs</small>
                    </div>
                    <i class="fas fa-check-circle fa-lg opacity-50"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="card bg-warning text-white">
                <div class="card-body p-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <h5 class="mb-0"><?php echo $total_pending_count; ?></h5>
                      <small>Pending EMIs</small>
                    </div>
                    <i class="fas fa-clock fa-lg opacity-50"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="card bg-danger text-white">
                <div class="card-body p-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <h5 class="mb-0"><?php echo $total_overdue_count; ?></h5>
                      <small>Overdue EMIs</small>
                    </div>
                    <i class="fas fa-exclamation-triangle fa-lg opacity-50"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Totals -->
          <div class="card mb-4">
            <div class="card-header bg-success text-white">
              <h5 class="card-title mb-0">Financial Summary for <?php echo date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)); ?></h5>
            </div>
            <div class="card-body">
              <div class="row mb-4">
                <div class="col-md-6 mb-3">
                  <h6 class="text-success mb-3"><i class="fas fa-check-circle me-2"></i>Paid EMIs</h6>
                  <div class="row">
                    <div class="col-6 mb-2">
                      <small class="text-muted">Principal</small>
                      <div class="fw-bold text-success">₹<?php echo number_format($total_principal_paid, 2); ?></div>
                    </div>
                    <div class="col-6 mb-2">
                      <small class="text-muted">Interest</small>
                      <div class="fw-bold text-warning">₹<?php echo number_format($total_interest_paid, 2); ?></div>
                    </div>
                    <div class="col-6 mb-2">
                      <small class="text-muted">Overdue</small>
                      <div class="fw-bold text-danger">₹<?php echo number_format($total_overdue_paid, 2); ?></div>
                    </div>
                    <div class="col-6 mb-2">
                      <small class="text-muted">Total Collected</small>
                      <div class="fw-bold text-primary">₹<?php echo number_format($total_collected_paid, 2); ?></div>
                    </div>
                  </div>
                </div>
                <div class="col-md-6">
                  <h6 class="text-warning mb-3"><i class="fas fa-clock me-2"></i>Non-Paid EMIs</h6>
                  <div class="row">
                    <div class="col-6 mb-2">
                      <small class="text-muted">Principal</small>
                      <div class="fw-bold text-success">₹<?php echo number_format($total_principal_unpaid, 2); ?></div>
                    </div>
                    <div class="col-6 mb-2">
                      <small class="text-muted">Interest</small>
                      <div class="fw-bold text-warning">₹<?php echo number_format($total_interest_unpaid, 2); ?></div>
                    </div>
                    <div class="col-6 mb-2">
                      <small class="text-muted">Overdue</small>
                      <div class="fw-bold text-danger">₹<?php echo number_format($total_overdue_unpaid, 2); ?></div>
                    </div>
                    <div class="col-6 mb-2">
                      <small class="text-muted">Total Expected</small>
                      <div class="fw-bold text-primary">₹<?php echo number_format($total_expected_unpaid, 2); ?></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="row text-center bg-light rounded p-3">
                <div class="col-md-4 mb-2">
                  <div class="fw-bold text-success fs-5">₹<?php echo number_format($total_collected_paid, 2); ?></div>
                  <small class="text-muted">Total Collected</small>
                </div>
                <div class="col-md-4 mb-2">
                  <div class="fw-bold text-warning fs-5">₹<?php echo number_format($total_expected_unpaid, 2); ?></div>
                  <small class="text-muted">Total Expected</small>
                </div>
                <div class="col-md-4">
                  <div class="fw-bold text-primary fs-5">₹<?php echo number_format($total_collected_paid + $total_expected_unpaid, 2); ?></div>
                  <small class="text-muted">Grand Total</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Mobile EMI List View -->
          <div class="d-block d-lg-none">
            <div class="card">
              <div class="card-header">
                <h5 class="card-title mb-0">EMI List View</h5>
                <small class="text-muted">Tap on any date to view EMI details</small>
              </div>
              <div class="card-body">
                <?php if (!empty($events_by_date)): ?>
                  <?php 
                  ksort($events_by_date);
                  foreach ($events_by_date as $date => $date_events): 
                    $date_obj = new DateTime($date);
                    $is_today = $date == $current_date;
                    $is_past = $date < $current_date;
                    $is_future = $date > $current_date;
                  ?>
                    <div class="card mb-3 border-0 shadow-sm">
                      <div class="card-header bg-light <?php echo $is_today ? 'border-primary' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-center">
                          <h6 class="mb-0 <?php echo $is_today ? 'text-primary fw-bold' : ''; ?>">
                            <?php echo $date_obj->format('d M Y'); ?>
                            <?php if ($is_today): ?>
                              <span class="badge bg-primary ms-2">Today</span>
                            <?php endif; ?>
                          </h6>
                          <span class="badge bg-secondary"><?php echo count($date_events); ?> EMI<?php echo count($date_events) > 1 ? 's' : ''; ?></span>
                        </div>
                      </div>
                      <div class="card-body">
                        <?php foreach ($date_events as $event): ?>
                          <div class="emi-card mb-3 p-3 rounded border-start border-4 
                              <?php echo $event['extendedProps']['status'] == 'Paid' ? 'border-success bg-success bg-opacity-10' : 
                                        ($event['extendedProps']['status'] == 'Overdue' ? 'border-danger bg-danger bg-opacity-10' : 
                                        'border-warning bg-warning bg-opacity-10'); ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                              <div>
                                <span class="badge bg-primary">EMI-<?php echo $event['id']; ?></span>
                                <span class="badge <?php echo $event['extendedProps']['status'] == 'Paid' ? 'bg-success' : 
                                                      ($event['extendedProps']['status'] == 'Overdue' ? 'bg-danger' : 'bg-warning'); ?>">
                                  <?php echo $event['extendedProps']['status']; ?>
                                </span>
                              </div>
                              <div class="fw-bold text-primary">₹<?php echo number_format($event['extendedProps']['emi_amount'], 2); ?></div>
                            </div>
                            
                            <div class="mb-2">
                              <div class="fw-medium"><?php echo $event['extendedProps']['customer_name']; ?></div>
                              <small class="text-muted"><?php echo $event['extendedProps']['customer_number']; ?></small>
                            </div>
                            
                            <div class="row text-sm mb-2">
                              <div class="col-6">
                                <small class="text-muted">Loan</small>
                                <div class="small"><?php echo $event['extendedProps']['loan_name']; ?></div>
                              </div>
                              <div class="col-6">
                                <small class="text-muted">Finance</small>
                                <div class="small"><?php echo $event['extendedProps']['finance_name']; ?></div>
                              </div>
                            </div>
                            
                            <div class="row text-sm mb-3">
                              <div class="col-4">
                                <small class="text-muted">Principal</small>
                                <div class="small fw-bold text-success">₹<?php echo number_format($event['extendedProps']['principal_amount'], 2); ?></div>
                              </div>
                              <div class="col-4">
                                <small class="text-muted">Interest</small>
                                <div class="small fw-bold text-warning">₹<?php echo number_format($event['extendedProps']['interest_amount'], 2); ?></div>
                              </div>
                              <div class="col-4">
                                <small class="text-muted">Overdue</small>
                                <div class="small fw-bold text-danger"><?php echo $event['extendedProps']['overdue_charges'] > 0 ? '₹' . number_format($event['extendedProps']['overdue_charges'], 2) : '-'; ?></div>
                              </div>
                            </div>
                            
                            <?php if ($event['extendedProps']['status'] == 'Paid'): ?>
                              <div class="text-center text-success">
                                <i class="fas fa-check-circle me-1"></i>
                                Paid on <?php echo $event['extendedProps']['paid_date']; ?>
                              </div>
                            <?php else: ?>
                              <div class="d-flex justify-content-between">
                                <a href="customer-wise-reports.php?customer_id=<?php echo $event['extendedProps']['customer_id']; ?>" class="btn btn-sm btn-outline-info">
                                  <i class="fas fa-eye"></i> View
                                </a>
                                <a href="pay-emi.php?emi_id=<?php echo $event['id']; ?>&customer_id=<?php echo $event['extendedProps']['customer_id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Mark this EMI as paid?')">
                                  <i class="fas fa-check"></i> Pay Now
                                </a>
                              </div>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="text-center py-4">
                    <i class="fas fa-calendar-times fa-2x text-muted mb-3"></i>
                    <h5 class="text-muted">No EMI records found</h5>
                    <p class="text-muted">No EMI schedules for <?php echo date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)); ?></p>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Calendar for Desktop -->
          <div class="d-none d-lg-block">
            <div class="card">
              <div class="card-header">
                <h5 class="card-title mb-0">EMI Calendar View</h5>
              </div>
              <div class="card-body">
                <div id="calendar"></div>
              </div>
            </div>
          </div>

          <!-- EMI Details Modal -->
          <div class="modal fade" id="emiDetailsModal" tabindex="-1" aria-labelledby="emiDetailsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="emiDetailsModalLabel">EMI Details</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <div class="table-responsive">
                    <table class="table table-striped table-bordered align-middle w-100">
                      <thead class="table-light">
                        <tr>
                          <th>#EMI ID</th>
                          <th>Customer Name</th>
                          <th>Loan Name</th>
                          <th>Finance</th>
                          <th>Principal (₹)</th>
                          <th>Interest (₹)</th>
                          <th>EMI (₹)</th>
                          <th>Overdue (₹)</th>
                          <th>Status</th>
                          <th>Paid Date</th>
                          <th class="text-end">Actions</th>
                        </tr>
                      </thead>
                      <tbody id="emiDetailsTableBody">
                        <!-- Populated by JavaScript -->
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
            </div>
          </div>

        </div><!-- container -->

        <?php include 'includes/rightbar.php'; ?>
        <?php include 'includes/footer.php'; ?>
      </div>
      <!-- end page content -->
    </div>
    <!-- end page-wrapper -->

    <?php include 'includes/scripts.php'; ?>

    <!-- FullCalendar and Dependencies -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        if (calendarEl) {
          var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            initialDate: '<?php echo $selected_year . '-' . sprintf("%02d", $selected_month) . '-01'; ?>',
            events: <?php echo json_encode($events); ?>,
            headerToolbar: {
              left: 'prev,next today',
              center: 'title',
              right: 'dayGridMonth,dayGridWeek,dayGridDay'
            },
            eventClick: function(info) {
              // Populate modal with EMI details for the clicked date
              var eventsOnDate = calendar.getEvents().filter(event => 
                event.start.toISOString().split('T')[0] === info.event.start.toISOString().split('T')[0]
              );
              
              // Sort events: Non-paid (Pending first, then Overdue) before Paid
              eventsOnDate.sort(function(a, b) {
                if (a.extendedProps.status === 'Paid' && b.extendedProps.status !== 'Paid') return 1;
                if (a.extendedProps.status !== 'Paid' && b.extendedProps.status === 'Paid') return -1;
                if (a.extendedProps.status === 'Pending' && b.extendedProps.status === 'Overdue') return -1;
                if (a.extendedProps.status === 'Overdue' && b.extendedProps.status === 'Pending') return 1;
                return 0;
              });

              var tableBody = $('#emiDetailsTableBody');
              tableBody.empty();
              
              if (eventsOnDate.length === 0) {
                tableBody.append('<tr><td colspan="11" class="text-center text-muted">No EMI records for this date</td></tr>');
              } else {
                eventsOnDate.forEach(function(event) {
                  var actionCell = event.extendedProps.status !== 'Paid' 
                    ? `<td class="text-end">
                         <div class="btn-group">
                           <a href="customer-wise-reports.php?customer_id=${event.extendedProps.customer_id}" class="btn btn-sm btn-outline-info">
                             <i class="fas fa-eye"></i> View
                           </a>
                           <a href="pay-emi.php?emi_id=${event.id}&customer_id=${event.extendedProps.customer_id}" class="btn btn-sm btn-outline-success" onclick="return confirm('Mark this EMI as paid?')">
                             <i class="fas fa-check"></i> Pay
                           </a>
                         </div>
                       </td>` 
                    : '<td class="text-end"><span class="text-muted">-</span></td>';
                  
                  var rowClass = event.extendedProps.status === 'Paid' ? 'table-success' : 
                                (event.extendedProps.status === 'Overdue' ? 'table-danger' : 'table-warning');
                  
                  var row = `
                    <tr class="${rowClass}">
                      <td><span class="badge bg-primary">EMI-${event.id}</span></td>
                      <td>${event.extendedProps.customer_name}<br><small class="text-muted">${event.extendedProps.customer_number}</small></td>
                      <td>${event.extendedProps.loan_name}</td>
                      <td><span class="badge bg-secondary">${event.extendedProps.finance_name}</span></td>
                      <td class="fw-bold text-success">₹${parseFloat(event.extendedProps.principal_amount).toFixed(2)}</td>
                      <td class="fw-bold text-warning">₹${parseFloat(event.extendedProps.interest_amount).toFixed(2)}</td>
                      <td class="fw-bold text-primary">₹${parseFloat(event.extendedProps.emi_amount).toFixed(2)}</td>
                      <td class="fw-bold text-danger">${parseFloat(event.extendedProps.overdue_charges) > 0 ? '₹' + parseFloat(event.extendedProps.overdue_charges).toFixed(2) : '-'}</td>
                      <td><span class="badge bg-${event.extendedProps.status === 'Paid' ? 'success' : (event.extendedProps.status === 'Overdue' ? 'danger' : 'warning')}">${event.extendedProps.status}</span></td>
                      <td>${event.extendedProps.paid_date}</td>
                      ${actionCell}
                    </tr>`;
                  tableBody.append(row);
                });
              }
              
              $('#emiDetailsModalLabel').text('EMI Details for ' + info.event.start.toISOString().split('T')[0] + ' (' + eventsOnDate.length + ' records)');
              $('#emiDetailsModal').modal('show');
            },
            eventDidMount: function(info) {
              // Add tooltip with detailed information
              var tooltipContent = `
                <strong>${info.event.title}</strong><br>
                Loan: ${info.event.extendedProps.loan_name}<br>
                Finance: ${info.event.extendedProps.finance_name}<br>
                EMI: ₹${parseFloat(info.event.extendedProps.emi_amount).toFixed(2)}<br>
                Status: ${info.event.extendedProps.status}<br>
                ${info.event.extendedProps.overdue_charges > 0 ? 'Overdue: ₹' + parseFloat(info.event.extendedProps.overdue_charges).toFixed(2) + '<br>' : ''}
                ${info.event.extendedProps.paid_date !== '-' ? 'Paid: ' + info.event.extendedProps.paid_date : ''}
              `;
              
              $(info.el).tooltip({
                title: tooltipContent,
                placement: 'top',
                trigger: 'hover',
                container: 'body',
                html: true
              });
            }
          });
          calendar.render();
        }
      });
    </script>

    <style>
      /* Calendar event colors */
      .fc-event.bg-success { background-color: #28a745 !important; border-color: #28a745 !important; }
      .fc-event.bg-warning { background-color: #ffc107 !important; border-color: #ffc107 !important; color: #000 !important; }
      .fc-event.bg-danger { background-color: #dc3545 !important; border-color: #dc3545 !important; }

      /* Calendar header styling */
      .fc-toolbar-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: #1f2937;
      }

      /* Today button styling */
      .fc-today-button {
        background-color: #3b82f6 !important;
        border-color: #3b82f6 !important;
      }

      /* Mobile card styling */
      .emi-card {
        transition: transform 0.2s;
      }
      .emi-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      }

      /* Button hover effects */
      .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
      }

      /* Table row hover effects */
      .table tbody tr:hover {
        background-color: #f8fafc;
        transform: scale(1.01);
        transition: all 0.2s ease;
      }

      /* Badge styling */
      .badge {
        font-size: 0.75em;
        font-weight: 500;
      }

      /* Modal styling */
      .modal-header {
        background-color: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
      }

      /* Responsive adjustments */
      @media (max-width: 768px) {
        .fc-toolbar {
          flex-direction: column;
          gap: 10px;
        }
        .fc-toolbar-title {
          font-size: 1.2rem;
        }
        .card-body {
          padding: 1rem;
        }
        .btn {
          padding: 0.375rem 0.75rem;
          font-size: 0.875rem;
        }
      }

      @media (max-width: 576px) {
        .row > [class*="col-"] {
          margin-bottom: 0.5rem;
        }
        .card-body {
          padding: 0.75rem;
        }
        .emi-card {
          padding: 0.75rem !important;
        }
      }
    </style>

</body>
</html>