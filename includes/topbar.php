<?php
// includes/topbar.php - FULL WORKING TOPBAR FOR SRI VARI CHITS

include_once 'db.php';

// Today's date for due EMI count
$today = date('Y-m-d');

// Count today's unpaid EMIs
$today_due_count = 0;
$sql_count = "SELECT COUNT(*) as count
              FROM emi_schedule es
              JOIN members m ON es.member_id = m.id
              WHERE es.status = 'unpaid' AND es.emi_due_date = ?";
$stmt_count = $conn->prepare($sql_count);
$stmt_count->bind_param("s", $today);
$stmt_count->execute();
$res_count = $stmt_count->get_result();
if ($row = $res_count->fetch_assoc()) {
    $today_due_count = $row['count'];
}
$stmt_count->close();
?>
<style>
    .chit-brand {
        font-weight: 600;
        font-size: 18px;
        color: #2c3e50;
    }
    .user-initial {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 16px;
    }
    .alert-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        min-width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #dc3545;
        color: white;
        font-size: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    @media (max-width: 576px) {
        .chit-brand {
            font-size: 16px;
        }
        .user-initial {
            width: 32px;
            height: 32px;
            font-size: 14px;
        }
    }
</style>

<div class="topbar d-print-none">
    <div class="container-fluid">
        <nav class="topbar-custom d-flex justify-content-between" id="topbar-custom">
            <!-- Left Side: Brand and Menu Button -->
            <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">
                <li>
                    <button class="nav-link mobile-menu-btn nav-icon" id="togglemenu">
                        <i class="iconoir-menu"></i>
                    </button>
                </li>
                <li>
                    <div class="d-inline-flex align-items-center ms-2">
                        <span class="chit-brand">
                            <i class="iconoir-hand-cash me-1"></i>SRI VARI CHITS PVT LTD
                        </span>
                    </div>
                </li>
            </ul>

            <!-- Right Side: Controls -->
            <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">
                <!-- Light/Dark Mode Toggle -->
                <li class="topbar-item">
                    <a class="nav-link nav-icon" href="javascript:void(0);" id="light-dark-mode">
                        <i class="iconoir-half-moon dark-mode"></i>
                        <i class="iconoir-sun-light light-mode"></i>
                    </a>
                </li>

                <!-- Notifications Dropdown -->
                <li class="dropdown topbar-item position-relative">
                    <a class="nav-link dropdown-toggle arrow-none nav-icon" data-bs-toggle="dropdown" href="#" role="button"
                       aria-haspopup="false" aria-expanded="false">
                        <i class="iconoir-bell"></i>
                        <?php if ($today_due_count > 0): ?>
                            <span class="alert-badge"><?= $today_due_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end dropdown-lg py-0">
                        <h5 class="dropdown-item-text m-0 py-3 d-flex justify-content-between align-items-center">
                            Today's Due (<?= $today_due_count; ?>)
                        </h5>
                        <div class="dropdown-divider"></div>
                        <?php
                        $sql_due = "SELECT m.customer_name, es.emi_amount, es.id AS emi_id
                                    FROM emi_schedule es
                                    JOIN members m ON es.member_id = m.id
                                    WHERE es.status = 'unpaid' AND es.emi_due_date = ?
                                    ORDER BY m.customer_name
                                    LIMIT 5";
                        $stmt_due = $conn->prepare($sql_due);
                        $stmt_due->bind_param("s", $today);
                        $stmt_due->execute();
                        $res_due = $stmt_due->get_result();
                        ?>
                        <?php if ($res_due->num_rows > 0): ?>
                            <?php while ($due = $res_due->fetch_assoc()): ?>
                                <a class="dropdown-item py-2" href="collect-emi.php?emi_id=<?= $due['emi_id']; ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <i class="iconoir-money-circle fs-4 text-success me-2"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="mb-0 fs-13"><?= htmlspecialchars($due['customer_name']); ?></p>
                                            <small class="text-muted">₹<?= number_format($due['emi_amount'], 2); ?></small>
                                        </div>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="dropdown-item py-3 text-center text-muted">
                                <i class="iconoir-check fs-4 mb-2"></i>
                                <p class="mb-0">No due payments today</p>
                            </div>
                        <?php endif; ?>
                        <?php $stmt_due->close(); ?>
                        <a href="collection-reports.php" class="dropdown-item text-center text-dark fs-13 py-2">
                            View All <i class="iconoir-arrow-right fs-4 ms-1"></i>
                        </a>
                    </div>
                </li>

                <!-- User Profile Dropdown -->
                <li class="dropdown topbar-item">
                    <a class="nav-link dropdown-toggle arrow-none nav-icon d-inline-flex align-items-center"
                       data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                        <span class="user-initial rounded-circle bg-primary-subtle text-primary">
                            <?= isset($_SESSION['username']) ? strtoupper(substr($_SESSION['username'], 0, 1)) :
                               (isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'U'); ?>
                        </span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end py-0">
                        <div class="d-flex align-items-center dropdown-item py-2 bg-secondary-subtle">
                            <div class="flex-shrink-0">
                                <span class="user-initial rounded-circle bg-primary-subtle text-primary" style="width:48px;height:48px;font-size:20px;">
                                    <?= isset($_SESSION['username']) ? strtoupper(substr($_SESSION['username'], 0, 1)) :
                                       (isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'U'); ?>
                                </span>
                            </div>
                            <div class="flex-grow-1 ms-2 text-truncate align-self-center">
                                <h6 class="my-0 fw-medium text-dark fs-13">
                                    <?= $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'; ?>
                                </h6>
                                <small class="text-muted mb-0">
                                    <?= ucfirst($_SESSION['role'] ?? 'Member'); ?>
                                </small>
                            </div>
                        </div>
                        <div class="dropdown-divider mt-0"></div>
                        <a class="dropdown-item" href="profile.php"><i class="iconoir-user fs-5 me-2"></i> Profile</a>
                        <a class="dropdown-item" href="settings.php"><i class="iconoir-settings fs-5 me-2"></i> Settings</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-danger" href="logout.php"><i class="iconoir-log-out fs-5 me-2"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
</div>

<script>
// Mobile menu toggle - Fixed & smooth
document.getElementById('togglemenu')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.body.classList.toggle('startbar-show');
});

// Light/Dark mode toggle
const lightDarkBtn = document.getElementById('light-dark-mode');
if (lightDarkBtn) {
    lightDarkBtn.addEventListener('click', function() {
        const html = document.querySelector('html');
        const currentTheme = html.getAttribute('data-bs-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-bs-theme', newTheme);
        localStorage.setItem('theme', newTheme);
    });

    // Load saved theme
    const savedTheme = localStorage.getItem('theme') || 'light' || ;
    document.querySelector('html').setAttribute('data-bs-theme', savedTheme);
}
</script>