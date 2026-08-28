<?php
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Get unread alerts count
$unread_alerts = getUnreadAlertsCount($conn);

// Get user role for sidebar
$user_role = getUserRole();
$user_branch = getUserBranch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Chicken Farm Inventory'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --sidebar-width: 260px;
            --primary-color: #4e73df;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            /* LG Chicken Orange Colors */
            --sidebar-orange: #E67E22;
            --sidebar-orange-dark: #D35400;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fc;
        }
        
        /* Sidebar Styles - ORANGE THEME */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--sidebar-orange);
            color: white;
            z-index: 1000;
            transition: all 0.3s;
            overflow-y: auto;
        }
        
        .sidebar-brand {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }

        .sidebar-brand small {
            color: rgba(255,255,255,0.8);
            font-size: 12px;
        }
        
        .sidebar-brand img.sidebar-logo {
            max-width: 55px;
            height: auto;
            font-size: 40px;
            margin-bottom: 10px;
        }
        
        .sidebar-brand h4 {
            font-weight: 600;
            margin: 5px 0 2px 0;
            font-size: 18px;
            color: #FFFFFF;
        }
        
        .sidebar-menu {
            padding: 15px 0;
        }
        
        .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            color: white;
            background: var(--sidebar-orange-dark);
            border-left-color: white;
        }
        
        .sidebar-menu i {
            width: 25px;
            text-align: center;
            margin-right: 10px;
            color: #FFFFFF;
        }
        
        .sidebar-divider {
            border-top: 1px solid rgba(255,255,255,0.2);
            margin: 15px 20px;
        }
        
        .sidebar-role {
            padding: 10px 20px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.7);
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
        }
        
        /* Top Navbar */
        .top-navbar {
            background: white;
            border-radius: 10px;
            padding: 15px 25px;
            margin-bottom: 25px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-navbar .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .top-navbar .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .notification-icon {
            position: relative;
            cursor: pointer;
            font-size: 20px;
            color: var(--secondary-color);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Dashboard Cards */
        .dashboard-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            border-left: 4px solid;
            transition: transform 0.3s;
            position: relative;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
        }
        
        .dashboard-card.primary { border-left-color: var(--primary-color); }
        .dashboard-card.success { border-left-color: var(--success-color); }
        .dashboard-card.info { border-left-color: var(--info-color); }
        .dashboard-card.warning { border-left-color: var(--warning-color); }
        .dashboard-card.danger { border-left-color: var(--danger-color); }
        
        .dashboard-card .card-icon {
            font-size: 30px;
            opacity: 0.3;
            position: absolute;
            right: 20px;
            top: 20px;
        }
        
        .dashboard-card .card-title {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--secondary-color);
            margin-bottom: 5px;
        }
        
        .dashboard-card .card-value {
            font-size: 28px;
            font-weight: 700;
            color: #5a5c69;
        }
        
        /* Tables */
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            overflow: hidden;
        }
        
        .table-container .table-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e3e6f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-container .table-header h5 {
            margin: 0;
            font-weight: 600;
            color: #4e73df;
        }
        
        .table-container table {
            margin-bottom: 0;
        }
        
        .table-container th {
            background: #f8f9fc;
            color: #4e73df;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e3e6f0;
        }
        
        .table-container td {
            vertical-align: middle;
            color: #858796;
        }
        
        /* Buttons */
        .btn-action {
            padding: 5px 12px;
            font-size: 13px;
            border-radius: 5px;
        }
        
        .btn-add {
            background: var(--success-color);
            border: none;
            color: white;
        }
        
        .btn-add:hover {
            background: #17a673;
            color: white;
        }
        
        .btn-edit {
            background: var(--primary-color);
            border: none;
            color: white;
        }
        
        .btn-edit:hover {
            background: #2e59d9;
            color: white;
        }
        
        .btn-delete {
            background: var(--danger-color);
            border: none;
            color: white;
        }
        
        .btn-delete:hover {
            background: #be2617;
            color: white;
        }
        
        .btn-view {
            background: var(--info-color);
            border: none;
            color: white;
        }
        
        .btn-approve {
            background: var(--success-color);
            border: none;
            color: white;
        }
        
        .btn-reject {
            background: var(--danger-color);
            border: none;
            color: white;
        }
        
        /* Modal */
        .modal-content {
            border-radius: 10px;
            border: none;
        }
        
        .modal-header {
            background: var(--primary-color);
            color: white;
            border-radius: 10px 10px 0 0;
        }
        
        .modal-header .btn-close {
            filter: invert(1);
        }
        
        /* Form Styles */
        .form-label {
            font-weight: 600;
            color: #4e73df;
            font-size: 14px;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e3e6f0;
            padding: 10px 15px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        /* Status Badges */
        .badge-pending { background: var(--warning-color); color: #000; }
        .badge-approved { background: var(--success-color); color: white; }
        .badge-rejected { background: var(--danger-color); color: white; }
        
        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 25px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="download.png" alt="Chicken Farm Logo" class="sidebar-logo">
        <h4>Chicken Farm</h4>
        <small>Inventory System</small>
        </div>
        
        <div class="sidebar-menu">
            <div class="sidebar-role"><?php echo ucfirst($user_role); ?> Panel</div>
            
            <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            
            <?php if ($user_role == 'admin'): ?>
            <a href="branches.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'branches.php' ? 'active' : ''; ?>">
                <i class="fas fa-building"></i> Branches
            </a>
            <?php endif; ?>
            
            <?php if ($user_role != 'cashier'): ?>
            <a href="products.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i> Products
            </a>
            <?php endif; ?>
            
            <!-- Stock එක Admin + Cashier දෙකටම පෙන්නනවා -->
            <?php if ($user_role == 'admin' || $user_role == 'cashier'): ?>
            <a href="stock.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'stock.php' ? 'active' : ''; ?>">
                <i class="fas fa-cubes"></i> Stock
            </a>
            <?php endif; ?>
            
            <a href="sales.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> Sales (POS)
            </a>
            
            <?php if ($user_role != 'cashier'): ?>
            <a href="transfer.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'transfer.php' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i> Transfers
            </a>
            
            <a href="reorder_requests.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reorder_requests.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i> Reorder Requests
            </a>
            <?php endif; ?>
            
            <?php if ($user_role == 'admin'): ?>
            <div class="sidebar-divider"></div>
            <div class="sidebar-role">Administration</div>
            
            <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Users
            </a>
            
            <a href="reports.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <?php endif; ?>
            
            <div class="sidebar-divider"></div>
            
            <a href="logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div>
                <h5 class="mb-0"><?php echo isset($page_title) ? $page_title : 'Dashboard'; ?></h5>
                <small class="text-muted">
                    <i class="fas fa-store me-1"></i>
                    <?php echo $_SESSION['branch_name'] ? $_SESSION['branch_name'] : 'All Branches'; ?>
                </small>
            </div>
            
            <div class="user-info">
                <a href="alerts.php" class="notification-icon text-decoration-none">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_alerts > 0): ?>
                    <span class="notification-badge"><?php echo $unread_alerts; ?></span>
                    <?php endif; ?>
                </a>
                
                <div class="dropdown">
                    <div class="d-flex align-items-center gap-2" data-bs-toggle="dropdown" style="cursor: pointer;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="fw-bold" style="font-size: 14px;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                            <small class="text-muted" style="font-size: 12px;"><?php echo ucfirst($user_role); ?></small>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php showAlert(); ?>