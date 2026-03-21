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
                        <i class="iconoir-report-columns menu-icon"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <li class="nav-item"><a class="nav-link" href="add-member.php"><i class="fas fa-user-plus me-2"></i>Add Customer</a></li>
                            <li class="nav-item"><a class="nav-link" href="manage-members.php"><i class="fas fa-user-cog me-2"></i>Manage Customers</a></li>
                            <li class="nav-item"><a class="nav-link" href="overdue-customers.php"><i class="fas fa-exclamation-triangle me-2"></i>Overdue Customers</a></li>

                <!-- Customers -->
                
               

                

               

                
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
</style>