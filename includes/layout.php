<?php
// includes/layout.php - Complete Layout with Negotiations Menu

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role = $_SESSION['user_role'];

// Get unread notifications count
$notif_result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$notifications_count = ($notif_result && $notif_result->num_rows > 0) ? $notif_result->fetch_assoc()['count'] : 0;

// Get unread chat messages count
$unread_chat_count = getUnreadMessageCount($conn, $user_id);

// Get pending rental bookings count (for property owners)
$pending_rentals_count = 0;
$rental_check = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_bookings 
    WHERE owner_id = $user_id 
    AND status = 'pending'
");
if ($rental_check && $rental_check->num_rows > 0) {
    $pending_rentals_count = $rental_check->fetch_assoc()['count'];
}

// Get pending legal transactions count
$legal_result = $conn->query("
    SELECT COUNT(*) as count FROM transactions t
    WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id)
    AND t.status = 'deposits_complete'
    AND ((t.buyer_legal_confirmed = 0 AND t.buyer_id = $user_id) OR
         (t.seller_legal_confirmed = 0 AND t.seller_id = $user_id))
");
$pending_legal_count = ($legal_result && $legal_result->num_rows > 0) ? $legal_result->fetch_assoc()['count'] : 0;

// Get pending negotiations count
$pending_negotiations = $conn->query("
    SELECT COUNT(*) as count FROM listing_negotiations 
    WHERE seller_id = $user_id AND status IN ('under_review', 'commission_proposed', 'counter_offer_sent')
");
$pending_negotiations_count = ($pending_negotiations && $pending_negotiations->num_rows > 0) ? $pending_negotiations->fetch_assoc()['count'] : 0;

// Get recent notifications for dropdown
$recent_notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 5
");

$conn->close();

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $page_title ?? 'Dashboard'; ?> - Ethio Brokerplace</title>
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
            background: #f1f5f9;
            overflow-x: hidden;
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
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: 4px 0 20px rgba(0,0,0,0.05);
        }
        
        /* Custom Scrollbar */
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
        .sidebar { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.2) rgba(255,255,255,0.05); }
        
        /* Collapsed Sidebar */
        .sidebar.collapsed { width: 80px; }
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
            margin-left: 80px;
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
        
        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        /* Notification Dropdown */
        .notification-dropdown { position: relative; }
        .notification-icon {
            position: relative;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: background 0.3s;
        }
        .notification-icon:hover { background: #f1f5f9; }
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 10px;
        }
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            display: none;
            z-index: 1000;
            margin-top: 8px;
        }
        .dropdown-menu.show { display: block; animation: dropdownFade 0.3s ease; }
        @keyframes dropdownFade {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dropdown-header h4 { font-size: 14px; font-weight: 600; }
        .dropdown-header a { font-size: 11px; color: #667eea; text-decoration: none; cursor: pointer; }
        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.3s;
        }
        .notification-item:hover { background: #f8fafc; }
        .notification-item.unread { background: #eef2ff; }
        .notification-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .notification-message { font-size: 11px; color: #64748b; }
        .notification-time { font-size: 10px; color: #94a3b8; margin-top: 4px; }
        
        /* User Dropdown */
        .user-dropdown { position: relative; cursor: pointer; }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        .user-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 220px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            display: none;
            margin-top: 8px;
            z-index: 1000;
        }
        .user-menu.show { display: block; }
        .user-menu-item {
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #334155;
            text-decoration: none;
            transition: background 0.3s;
        }
        .user-menu-item:hover { background: #f1f5f9; }
        
        .container {
            padding: 28px;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar .logo-text,
            .sidebar .menu-label,
            .sidebar .profile-info,
            .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; font-size: 20px; }
            .main-content { margin-left: 80px; }
        }
        
        @media (max-width: 768px) {
            .sidebar { 
                transform: translateX(-100%);
                width: 280px;
            }
            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar.mobile-open .logo-text,
            .sidebar.mobile-open .menu-label,
            .sidebar.mobile-open .profile-info,
            .sidebar.mobile-open .section-header { display: block; }
            .sidebar.mobile-open .menu-item { justify-content: flex-start; padding: 10px 14px; }
            .sidebar.mobile-open .menu-item i { margin-right: 12px; font-size: 18px; }
            .main-content { margin-left: 0; }
            .top-bar { padding: 12px 20px; }
            .page-title { font-size: 20px; }
            .container { padding: 20px; }
            .dropdown-menu { width: 300px; right: -50px; }
        }
    </style>
</head>
<body>
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
            <!-- Dashboard -->
            <a href="/broker_system/user/dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            
            <!-- Browse -->
            <a href="/broker_system/user/browse.php" class="menu-item <?php echo $current_page == 'browse.php' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i>
                <span class="menu-label">Browse</span>
            </a>
            
            <!-- My Listings -->
            <a href="/broker_system/user/listings.php" class="menu-item <?php echo $current_page == 'listings.php' ? 'active' : ''; ?>">
                <i class="fas fa-box"></i>
                <span class="menu-label">My Listings</span>
            </a>
            
            <!-- NEGOTIATIONS - NEW MENU ITEM -->
            <a href="/broker_system/user/negotiations.php" class="menu-item <?php echo $current_page == 'negotiations.php' ? 'active' : ''; ?>">
                <i class="fas fa-handshake"></i>
                <span class="menu-label">Negotiations</span>
                <?php if ($pending_negotiations_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_negotiations_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- MY RENTERS -->
            <a href="/broker_system/user/owner_bookings.php" class="menu-item <?php echo $current_page == 'owner_bookings.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span class="menu-label">My Renters</span>
                <?php if ($pending_rentals_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_rentals_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Wallet -->
            <a href="/broker_system/user/wallet.php" class="menu-item <?php echo $current_page == 'wallet.php' ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span class="menu-label">Wallet</span>
            </a>
            
            <!-- Messages / Chat -->
            <a href="/broker_system/user/chat.php" class="menu-item <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i>
                <span class="menu-label">Messages</span>
                <?php if ($unread_chat_count > 0): ?>
                    <span class="badge-count"><?php echo $unread_chat_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Notifications -->
            <a href="/broker_system/user/notifications.php" class="menu-item <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span class="menu-label">Notifications</span>
                <?php if ($notifications_count > 0): ?>
                    <span class="badge-count"><?php echo $notifications_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Activity Section -->
            <div class="section-header">Activity</div>
            
            <!-- Transactions -->
            <a href="/broker_system/user/transactions.php" class="menu-item <?php echo $current_page == 'transactions.php' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>
            
            <!-- Legal Process -->
            <a href="/broker_system/user/legal_process.php" class="menu-item <?php echo $current_page == 'legal_process.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Legal Process</span>
                <?php if ($pending_legal_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_legal_count; ?></span>
                <?php endif; ?>
            </a>
        </ul>
        
        <div class="sidebar-footer">
            <!-- Profile -->
            <a href="/broker_system/user/profile.php" class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="profile-email"><?php echo htmlspecialchars($user_email); ?></div>
                </div>
            </a>
            
            <!-- Settings -->
            <a href="/broker_system/user/settings.php" class="menu-item" style="margin-top: 4px;">
                <i class="fas fa-cog"></i>
                <span class="menu-label">Settings</span>
            </a>
            
            <!-- Logout -->
            <a href="/broker_system/auth/logout.php" class="menu-item" style="margin-top: 4px;">
                <i class="fas fa-sign-out-alt logout-icon"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>
    
    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><?php echo $page_title ?? 'Dashboard'; ?></h1>
            <div class="top-bar-actions">
                <!-- Notifications Dropdown -->
                <div class="notification-dropdown">
                    <div class="notification-icon" id="notificationIcon">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifications_count > 0): ?>
                            <span class="notification-badge"><?php echo $notifications_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-menu" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h4>Notifications</h4>
                            <a href="/broker_system/user/notifications.php">View all</a>
                        </div>
                        <div id="notificationList">
                            <?php if ($recent_notifications && $recent_notifications->num_rows > 0): ?>
                                <?php while($notif = $recent_notifications->fetch_assoc()): ?>
                                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="location.href='<?php echo $notif['link'] ?? 'notifications.php'; ?>'">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notification-message"><?php echo htmlspecialchars(substr($notif['message'], 0, 80)); ?></div>
                                        <div class="notification-time"><?php echo timeAgo($notif['created_at']); ?></div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="notification-item">No new notifications</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- User Dropdown -->
                <div class="user-dropdown">
                    <div class="user-avatar" id="userAvatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                    <div class="user-menu" id="userMenu">
                        <a href="/broker_system/user/profile.php" class="user-menu-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="/broker_system/user/wallet.php" class="user-menu-item"><i class="fas fa-wallet"></i> Wallet</a>
                        <a href="/broker_system/user/notifications.php" class="user-menu-item"><i class="fas fa-bell"></i> Notifications</a>
                        <a href="/broker_system/user/negotiations.php" class="user-menu-item"><i class="fas fa-handshake"></i> Negotiations</a>
                        <a href="/broker_system/user/settings.php" class="user-menu-item"><i class="fas fa-cog"></i> Settings</a>
                        <hr style="margin: 8px 0; border-color: #f1f5f9;">
                        <a href="/broker_system/user/owner_bookings.php" class="user-menu-item"><i class="fas fa-users"></i> My Renters</a>
                        <hr style="margin: 8px 0; border-color: #f1f5f9;">
                        <a href="/broker_system/auth/logout.php" class="user-menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="container">
            <?php echo $content ?? ''; ?>
        </div>
    </div>
    
    <script>
        // Sidebar collapse toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseBtn');
        
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
        
        // Load saved sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            if (collapseBtn) {
                const icon = collapseBtn.querySelector('i');
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            }
        }
        
        // Notification dropdown
        const notificationIcon = document.getElementById('notificationIcon');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
        if (notificationIcon) {
            notificationIcon.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
                if (userMenu) userMenu.classList.remove('show');
            });
        }
        
        // User dropdown
        const userAvatar = document.getElementById('userAvatar');
        const userMenu = document.getElementById('userMenu');
        
        if (userAvatar) {
            userAvatar.addEventListener('click', function(e) {
                e.stopPropagation();
                userMenu.classList.toggle('show');
                if (notificationDropdown) notificationDropdown.classList.remove('show');
            });
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            if (notificationDropdown) notificationDropdown.classList.remove('show');
            if (userMenu) userMenu.classList.remove('show');
        });
        
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            sidebar.classList.toggle('mobile-open');
        }
        
        // Mark notification as read when clicked
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function() {
                this.classList.remove('unread');
            });
        });
    </script>
</body>
</html>