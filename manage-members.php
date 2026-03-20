<?php
// manage-members.php - UPDATED WITH SITE NUMBER, PHOTOS & NOMINEE PHONE
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'includes/db.php';

// Delete logic (same as before)
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM emi_schedule WHERE member_id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM members WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $_SESSION['success'] = "Member deleted successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error deleting member.";
    }
    header("Location: manage-members.php");
    exit;
}

// Search & Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$plan_filter = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;

$sql = "SELECT m.id, m.agreement_number, m.customer_name, m.customer_number, 
               m.customer_number2, m.nominee_name, m.nominee_number, 
               m.emi_date, m.customer_photo, m.bid_winner_site_number,
               p.title AS plan_title
        FROM members m
        JOIN plans p ON m.plan_id = p.id
        WHERE 1=1";

$params = [];
$types = "";

if ($search !== '') {
    $sql .= " AND (m.agreement_number LIKE ? OR m.customer_name LIKE ? 
                 OR m.customer_number LIKE ? OR m.nominee_name LIKE ?
                 OR m.bid_winner_site_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like;
    $types .= "sssss";
}

if ($plan_filter > 0) {
    $sql .= " AND m.plan_id = ?";
    $params[] = $plan_filter;
    $types .= "i";
}

$sql .= " ORDER BY m.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch plans
$plans = [];
$plan_result = $conn->query("SELECT id, title FROM plans ORDER BY title ASC");
while ($row = $plan_result->fetch_assoc()) {
    $plans[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr" data-startbar="dark" data-bs-theme="light">
<?php include 'includes/head.php'; ?>
<style>
    .photo-thumbnail {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        cursor: pointer;
        border: 2px solid #dee2e6;
        transition: all 0.3s ease;
    }
    .photo-thumbnail:hover {
        border-color: #0d6efd;
        transform: scale(1.1);
    }
    .site-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 500;
        line-height: 1;
        color: #fff;
        background-color: #20c997;
        border-radius: 0.25rem;
    }
    .modal-photo {
        max-width: 100%;
        max-height: 70vh;
        object-fit: contain;
    }
    .tooltip-inner {
        max-width: 200px;
        padding: 0.5rem;
    }
    .compact-table td {
        vertical-align: middle;
    }
</style>
<body>
    <!-- Top Bar -->
    <?php include 'includes/topbar.php'; ?>

    <!-- Left Sidebar -->
    <div class="startbar d-print-none">
        <?php include 'includes/leftbar-tab-menu.php'; ?>
        <?php include 'includes/leftbar.php'; ?>
        <div class="startbar-overlay d-print-none"></div>
    </div>

    <!-- Main Page Wrapper -->
    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Breadcrumb -->
                <?php
                $page_title = "Manage Members";
                $breadcrumb_active = "Members List";
                include 'includes/breadcrumb.php';
                ?>

                <!-- Messages -->
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

                <div class="row align-items-center mb-3">
                    <div class="col">
                        <h3 class="mb-0">Manage Members</h3>
                        <small class="text-muted">View, edit, delete or check EMI schedule</small>
                    </div>
                    <div class="col-auto">
                        <a href="add-member.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i> Add New Member
                        </a>
                    </div>
                </div>

                <!-- Search & Filter -->
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Agreement No / Name / Phone / Site No" 
                                       value="<?= htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Filter by Plan</label>
                                <select class="form-control" name="plan_id">
                                    <option value="">All Plans</option>
                                    <?php foreach ($plans as $plan): ?>
                                        <option value="<?= $plan['id']; ?>" <?= $plan_filter == $plan['id'] ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($plan['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> Search
                                </button>
                            </div>
                            <div class="col-md-2">
                                <a href="manage-members.php" class="btn btn-light w-100">
                                    <i class="fas fa-redo me-1"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Members Table -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Members List (<?= $result->num_rows; ?> found)</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0 compact-table">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40">#</th>
                                        <th width="50">Photo</th>
                                        <th>Application No</th>
                                        <th>Customer Name</th>
                                        <th>Customer Phone</th>
                                        <th>Nominee</th>
                                        <th>Nominee Phone</th>
                                        <th>Plan</th>
                                        <th>Seat No</th>
                                        <th>Agreement Date</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result->num_rows > 0): $sr = 1; ?>
                                        <?php while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= $sr++; ?></td>
                                                <td>
                                                    <?php if (!empty($row['customer_photo']) && file_exists($row['customer_photo'])): ?>
                                                        <img src="<?= htmlspecialchars($row['customer_photo']); ?>" 
                                                             alt="<?= htmlspecialchars($row['customer_name']); ?>" 
                                                             class="photo-thumbnail"
                                                             data-bs-toggle="modal" 
                                                             data-bs-target="#photoModal"
                                                             data-photo="<?= htmlspecialchars($row['customer_photo']); ?>"
                                                             data-name="<?= htmlspecialchars($row['customer_name']); ?>"
                                                             title="Click to view">
                                                    <?php else: ?>
                                                        <div class="text-center">
                                                            <i class="fas fa-user-circle text-muted" style="font-size: 1.5rem;"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($row['agreement_number']); ?></strong>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($row['customer_name']); ?>
                                                    <?php if (!empty($row['customer_number2'])): ?>
                                                        <br><small class="text-muted">Alt: <?= htmlspecialchars($row['customer_number2']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="tel:<?= htmlspecialchars($row['customer_number']); ?>" 
                                                       class="text-decoration-none" 
                                                       title="Click to call">
                                                        <?= htmlspecialchars($row['customer_number']); ?>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($row['nominee_name']); ?></td>
                                                <td>
                                                    <a href="tel:<?= htmlspecialchars($row['nominee_number']); ?>" 
                                                       class="text-decoration-none" 
                                                       title="Click to call">
                                                        <?= htmlspecialchars($row['nominee_number']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info bg-opacity-10 text-info">
                                                        <?= htmlspecialchars($row['plan_title']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($row['bid_winner_site_number'])): ?>
                                                        <span class="site-badge">
                                                            <?= htmlspecialchars($row['bid_winner_site_number']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= date('d-m-Y', strtotime($row['emi_date'])); ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <a href="emi-schedule-member.php?id=<?= $row['id']; ?>" 
                                                           class="btn btn-sm btn-success" 
                                                           title="EMI Schedule"
                                                           data-bs-toggle="tooltip">
                                                            <i class="fas fa-calendar-alt"></i>
                                                        </a>
                                                        <a href="add-member.php?edit=<?= $row['id']; ?>" 
                                                           class="btn btn-sm btn-warning" 
                                                           title="Edit"
                                                           data-bs-toggle="tooltip">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="?delete=<?= $row['id']; ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           title="Delete"
                                                           onclick="return confirm('Delete this member and all EMI records?');"
                                                           data-bs-toggle="tooltip">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center py-5 text-muted">
                                                <i class="fas fa-users fa-2x mb-3"></i><br>
                                                No members found. <a href="add-member.php">Add your first member</a>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div><!-- container-fluid -->
            <?php include 'includes/rightbar.php'; ?>
            <?php include 'includes/footer.php'; ?>
        </div><!-- page-content -->
    </div><!-- page-wrapper -->

    <!-- Photo Modal -->
    <div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="photoModalTitle">Customer Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalPhoto" src="" alt="Customer Photo" class="modal-photo img-fluid rounded">
                    <p class="mt-3" id="photoCustomerName"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="downloadPhoto" class="btn btn-primary" download>
                        <i class="fas fa-download me-1"></i> Download
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })

        // Photo modal functionality
        var photoModal = document.getElementById('photoModal')
        if (photoModal) {
            photoModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var photoSrc = button.getAttribute('data-photo')
                var customerName = button.getAttribute('data-name')
                
                var modalTitle = photoModal.querySelector('.modal-title')
                var modalPhoto = photoModal.querySelector('#modalPhoto')
                var photoName = photoModal.querySelector('#photoCustomerName')
                var downloadLink = photoModal.querySelector('#downloadPhoto')
                
                modalTitle.textContent = customerName + ' - Photo'
                modalPhoto.src = photoSrc
                photoName.textContent = customerName
                downloadLink.href = photoSrc
            })
        }

        // Make table rows clickable for better UX
        document.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('click', function(e) {
                // Don't trigger if clicking on actions or photo
                if (!e.target.closest('.btn-group') && !e.target.closest('.photo-thumbnail')) {
                    const editBtn = this.querySelector('a[href*="edit"]');
                    if (editBtn) {
                        window.location.href = editBtn.href;
                    }
                }
            });
        });
    </script>
</body>
</html>     