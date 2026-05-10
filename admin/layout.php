<?php
// admin/layout.php - Modern Professional Admin Layout with Hamburger Menu

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication with unified session
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

// Check if user has admin or broker role
if ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'broker') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

$admin_name = $_SESSION['user_name'];
$admin_email = $_SESSION['user_email'];
$current_page = basename($_SERVER['PHP_SELF']);

// Get unread chat count
require_once '../config/database.php';
require_once '../includes/chat_functions.php';
$conn = getDbConnection();
$unread_chat_count = getUnreadMessageCount($conn, $_SESSION['user_id']);

// Get pending approval count
$pending_approvals = $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'];
$pending_disputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status IN ('open', 'under_review')")->fetch_assoc()['count'];
$pending_withdrawals = $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo $page_title ?? 'Admin Panel'; ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            overflow-x: hidden;
        }
        
        /* ============================================
           MOBILE MENU TOGGLE BUTTON (HAMBURGER)
        ============================================ */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1060;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(102,126,234,0.3);
            transition: all 0.3s ease;
        }
        
        .mobile-menu-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(102,126,234,0.4);
        }
        
        /* Sidebar Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(2px);
            z-index: 1040;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* ============================================
           SIDEBAR STYLES
        ============================================ */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #0f172a 100%);
            color: #e2e8f0;
            transition: all 0.3s ease;
            z-index: 1050;
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: 4px 0 20px rgba(0,0,0,0.08);
        }
        
        /* Custom Scrollbar */
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
        .sidebar { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.2) rgba(255,255,255,0.05); }
        
        /* Collapsed Sidebar (Desktop) */
        .sidebar.collapsed { width: 88px; }
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .profile-info,
        .sidebar.collapsed .section-header { display: none; }
        .sidebar.collapsed .menu-item { justify-content: center; padding: 12px; }
        .sidebar.collapsed .menu-item i { margin-right: 0; font-size: 20px; }
        .sidebar.collapsed .logo { justify-content: center; }
        .sidebar.collapsed .badge-count { position: absolute; top: 5px; right: 5px; }
        
        /* Sidebar Header */
        .sidebar-header {
            padding: 24px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            position: sticky;
            top: 0;
            background: #0f172a;
            z-index: 10;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-icon {
            font-size: 28px;
        }
        
        .logo-text {
            font-size: 18px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .collapse-btn {
            background: rgba(255,255,255,0.08);
            border: none;
            color: #94a3b8;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .collapse-btn:hover {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        
        /* Navigation Menu */
        .nav-menu {
            list-style: none;
            padding: 20px 16px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            margin: 4px 0;
            border-radius: 12px;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            position: relative;
        }
        
        .menu-item i {
            width: 24px;
            font-size: 18px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .menu-item span {
            font-size: 14px;
            font-weight: 500;
        }
        
        .menu-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }
        
        .menu-item.active {
            background: linear-gradient(135deg, #667eea20, #764ba220);
            color: white;
            border-left: 3px solid #667eea;
        }
        
        .badge-count {
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            margin-left: auto;
            min-width: 18px;
            text-align: center;
        }
        
        .section-header {
            padding: 12px 16px 6px;
            margin-top: 12px;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        /* Sidebar Footer */
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
            background: #0f172a;
            margin-top: 20px;
        }
        
        .profile-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #e2e8f0;
        }
        
        .profile-item:hover {
            background: rgba(255,255,255,0.08);
        }
        
        .profile-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .profile-info {
            flex: 1;
            min-width: 0;
        }
        
        .profile-name {
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .profile-email {
            font-size: 11px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* ============================================
           MAIN CONTENT STYLES
        ============================================ */
        .main-content {
            margin-left: 280px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        .main-content.expanded {
            margin-left: 88px;
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            padding: 16px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 99;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-bottom: 1px solid #e2e8f0;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .admin-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: #f1f5f9;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            color: #475569;
        }
        
        .admin-badge i {
            color: #667eea;
            font-size: 14px;
        }
        
        .logout-btn {
            padding: 8px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-radius: 30px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }
        
        /* Container */
        .container {
            padding: 28px;
        }
        
        /* ============================================
           RESPONSIVE BREAKPOINTS
        ============================================ */
        
        /* Desktop and Tablet (above 768px) - No hamburger needed */
        @media (min-width: 769px) {
            .mobile-menu-toggle {
                display: none !important;
            }
        }
        
        /* Tablet (768px - 1024px) - Collapsed sidebar */
        @media (max-width: 1024px) and (min-width: 769px) {
            .sidebar { 
                width: 88px; 
            }
            .sidebar .logo-text,
            .sidebar .menu-label,
            .sidebar .profile-info,
            .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; font-size: 20px; }
            .main-content { margin-left: 88px; }
        }
        
        /* Mobile (below 768px) - Hidden sidebar with hamburger */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .sidebar.mobile-open .logo-text,
            .sidebar.mobile-open .menu-label,
            .sidebar.mobile-open .profile-info,
            .sidebar.mobile-open .section-header {
                display: block;
            }
            
            .sidebar.mobile-open .menu-item {
                justify-content: flex-start;
                padding: 10px 14px;
            }
            
            .sidebar.mobile-open .menu-item i {
                margin-right: 12px;
                font-size: 18px;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .top-bar {
                padding: 12px 20px;
                padding-left: 80px;
            }
            
            .page-title {
                font-size: 18px;
            }
            
            .container {
                padding: 16px;
            }
            
            .admin-badge span {
                display: none;
            }
            
            .admin-badge {
                padding: 8px 12px;
            }
            
            .logout-btn span {
                display: none;
            }
            
            .logout-btn {
                padding: 8px 12px;
            }
        }
        
        /* Small Mobile (below 480px) */
        @media (max-width: 480px) {
            .top-bar {
                padding: 10px 16px;
                padding-left: 70px;
            }
            
            .page-title {
                font-size: 16px;
            }
            
            .container {
                padding: 12px;
            }
        }
    </style>
</head>
<body>

    <!-- Mobile Menu Toggle (Hamburger Button) -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <span class="logo-icon">🏪</span>
                <span class="logo-text">Brokerplace</span>
            </div>
            <button class="collapse-btn" id="collapseBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>
        
        <ul class="nav-menu">
            <!-- Main Navigation -->
            <a href="dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            <a href="users.php" class="menu-item <?php echo $current_page == 'users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="menu-label">Users</span>
            </a>
            <a href="transactions.php" class="menu-item <?php echo $current_page == 'transactions.php' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>
            <a href="chat.php" class="menu-item <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i>
                <span class="menu-label">Messages</span>
                <?php if ($unread_chat_count > 0): ?>
                    <span class="badge-count"><?php echo $unread_chat_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Management Section -->
            <div class="section-header">Management</div>
            <a href="approve_listings.php" class="menu-item <?php echo $current_page == 'approve_listings.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-double"></i>
                <span class="menu-label">Approve Listings</span>
                <?php if ($pending_approvals > 0): ?>
                    <span class="badge-count"><?php echo $pending_approvals; ?></span>
                <?php endif; ?>
            </a>
            <a href="disputes.php" class="menu-item <?php echo $current_page == 'disputes.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Disputes</span>
                <?php if ($pending_disputes > 0): ?>
                    <span class="badge-count"><?php echo $pending_disputes; ?></span>
                <?php endif; ?>
            </a>
            <a href="withdrawals.php" class="menu-item <?php echo $current_page == 'withdrawals.php' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span class="menu-label">Withdrawals</span>
                <?php if ($pending_withdrawals > 0): ?>
                    <span class="badge-count"><?php echo $pending_withdrawals; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Settings -->
            <div class="section-header">Settings</div>
            <a href="settings.php" class="menu-item <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i>
                <span class="menu-label">Settings</span>
            </a>
        </ul>
        
        <div class="sidebar-footer">
            <div class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="profile-email"><?php echo htmlspecialchars($admin_email); ?></div>
                </div>
            </div>
            <a href="../auth/logout.php" class="menu-item" style="margin-top: 8px;">
                <i class="fas fa-sign-out-alt logout-icon"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>
    
    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><?php echo $page_title ?? 'Dashboard'; ?></h1>
            <div class="admin-info">
                <div class="admin-badge">
                    <i class="fas fa-user-shield"></i>
                    <span>Admin</span>
                </div>
                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </div>
        </div>
        <div class="container">
            <?php echo $content ?? ''; ?>
        </div>
    </div>
    
    <script>
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseBtn');
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        // Sidebar collapse functionality (desktop)
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                const icon = collapseBtn.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                } else {
                    icon.classList.remove('fa-chevron-right');
                    icon.classList.add('fa-chevron-left');
                }
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }
        
        // Load saved sidebar state (desktop only)
        if (window.innerWidth > 768) {
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                if (collapseBtn) {
                    const icon = collapseBtn.querySelector('i');
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                }
            }
        }
        
        // Mobile sidebar toggle
        function openMobileSidebar() {
            sidebar.classList.add('mobile-open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeMobileSidebar() {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', openMobileSidebar);
        }
        
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeMobileSidebar);
        }
        
        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                closeMobileSidebar();
            }
        });
        
        // Close sidebar when window is resized above mobile breakpoint
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                closeMobileSidebar();
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>