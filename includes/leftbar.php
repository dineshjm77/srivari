
<!-- Updated Sidebar with Logo Visible in Both Full & Collapsed Views -->
<!--start startbar-menu-->
<div class="startbar-menu">
    <div class="startbar-collapse" id="startbarCollapse" data-simplebar>
        <div class="d-flex align-items-start flex-column w-100">
            <!-- Logo - Always visible, scales down in collapsed mode -->
            <div class="text-center my-4 logo-container">
                <img src="assets/images/srivari.jpeg" alt="SRI VARI CHITS Logo" class="img-fluid logo-img">
            </div>

            <!-- Navigation -->
            <ul class="navbar-nav mb-auto w-100">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-tachometer-alt menu-icon"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <!-- Customers -->
                <li class="nav-item">
                    <a class="nav-link" href="#sidebarCustomers" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarCustomers">
                        <i class="fas fa-users menu-icon"></i>
                        <span>Customers</span>
                    </a>
                    <div class="collapse" id="sidebarCustomers">
                        <ul class="nav flex-column">
                            <li class="nav-item"><a class="nav-link" href="add-member.php"><i class="fas fa-user-plus me-2"></i>Add Customer</a></li>
                            <li class="nav-item"><a class="nav-link" href="manage-members.php"><i class="fas fa-user-cog me-2"></i>Manage Customers</a></li>
                            <li class="nav-item"><a class="nav-link" href="overdue-customers.php"><i class="fas fa-exclamation-triangle me-2"></i>Overdue Customers</a></li>
                        </ul>
                    </div>
                </li>

                <!-- Chit -->
                <li class="nav-item">
                    <a class="nav-link" href="#sidebarChit" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarChit">
                        <i class="fas fa-hand-holding-usd menu-icon"></i>
                        <span>Chit</span>
                    </a>
                    <div class="collapse" id="sidebarChit">
                        <ul class="nav flex-column">
                             <li class="nav-item"><a class="nav-link" href="add-plan.php"><i class="fas fa-list-alt me-2"></i>Add Plans</a></li>
                            <li class="nav-item"><a class="nav-link" href="manage-plans.php"><i class="fas fa-list-alt me-2"></i>Chit Plans</a></li>
                        </ul>
                    </div>
                </li>
                
                <!-- Users -->
                <li class="nav-item">
                    <a class="nav-link" href="#sidebarUsers" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarUsers">
                        <i class="fas fa-user-friends menu-icon"></i>
                        <span>Users</span>
                    </a>
                    <div class="collapse" id="sidebarUsers">
                        <ul class="nav flex-column">
                            <li class="nav-item"><a class="nav-link" href="add-user.php"><i class="fas fa-user-plus me-2"></i>Add Users</a></li>
                            <li class="nav-item"><a class="nav-link" href="users.php"><i class="fas fa-user-cog me-2"></i>List Users</a></li>
                        </ul>
                    </div>
                </li>
                
                <!-- Expenses -->
                <li class="nav-item">
                    <a class="nav-link" href="#sidebarExpenses" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarExpenses">
                        <i class="fas fa-file-invoice-dollar menu-icon"></i>
                        <span>Expenses</span>
                    </a>
                    <div class="collapse" id="sidebarExpenses">
                        <ul class="nav flex-column">
                            <li class="nav-item"><a class="nav-link" href="add-expense.php"><i class="fas fa-plus-circle me-2"></i>Add Expense</a></li>
                            <li class="nav-item"><a class="nav-link" href="manage-expenses.php"><i class="fas fa-cog me-2"></i>Manage Expenses</a></li>
                        </ul>
                    </div>
                </li>

                <!-- Reports -->
                <li class="nav-item">
                    <a class="nav-link" href="#sidebarReports" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarReports">
                        <i class="fas fa-chart-bar menu-icon"></i>
                        <span>Reports</span>
                    </a>
                    <div class="collapse" id="sidebarReports">
                        <ul class="nav flex-column">
                            <li class="nav-item"><a class="nav-link" href="plan-reports.php"><i class="fas fa-chart-pie me-2"></i>Overall Reports</a></li>
                            <li class="nav-item"><a class="nav-link" href="customer-wise-reports.php"><i class="fas fa-user-chart me-2"></i>Customer Wise</a></li>
                            <li class="nav-item"><a class="nav-link" href="collection-list.php"><i class="fas fa-cash-register me-2"></i>Collection List</a></li>
                        </ul>
                    </div>
                </li>

               
                
                <!-- Big Winners -->
                <li class="nav-item">
                    <a class="nav-link" href="big-winner-reports.php">
                        <i class="fas fa-trophy menu-icon"></i>
                        <span>Bid Winners</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
<!--end startbar-menu-->

<style>
    /* Logo behavior - visible in both full and collapsed sidebar */
    .logo-container {
        width: 100%;
        padding: 0 20px;
        transition: all 0.3s ease;
    }

    .logo-img {
        max-width: 180px;
        height: auto;
        transition: max-width 0.3s ease;
    }

    /* When sidebar is collapsed (small view) - shrink logo */
    body:not(.startbar-show) .logo-container {
        padding: 0 10px;
    }

    body:not(.startbar-show) .logo-img {
        max-width: 60px; /* Small logo in collapsed mode */
    }

    /* Center logo in collapsed mode */
    body:not(.startbar-show) .logo-container {
        display: flex;
        justify-content: center;
    }

    /* Smooth hover effect */
    .logo-img:hover {
        opacity: 0.9;
    }

    /* Menu icon styling */
    .menu-icon {
        width: 20px;
        text-align: center;
        margin-right: 10px;
        font-size: 16px;
    }

    /* Nested menu item icons */
    .nav.flex-column .nav-link i {
        font-size: 14px;
        width: 20px;
    }

    /* Active menu item styling */
    .nav-item .nav-link.active {
        background-color: rgba(74, 100, 145, 0.1);
        color: #4a6491;
        border-left: 3px solid #4a6491;
    }

    /* Hover effects */
    .nav-link:hover {
        background-color: rgba(74, 100, 145, 0.05);
    }

    /* Collapse animation */
    .collapse {
        transition: all 0.3s ease;
    }

    /* Nested menu padding */
    .nav.flex-column {
        padding-left: 30px;
    }

    .nav.flex-column .nav-link {
        padding: 8px 15px;
        font-size: 14px;
    }
</style>    