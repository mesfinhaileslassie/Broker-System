<?php
// user/layout.php - Complete layout with all working sidebar links

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$notifications_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count'];
$pending_legal_count = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id) AND t.status = 'deposits_complete' AND ((t.buyer_legal_confirmed = 0 AND t.buyer_id = $user_id) OR (t.seller_legal_confirmed = 0 AND t.seller_id = $user_id))")->fetch_assoc()['count'];
$unread_chat_count = getUnreadMessageCount($conn, $user_id);
$notifications = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 10");

$conn->close();
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            transition: all 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 10px; }
        .sidebar { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.3) rgba(255,255,255,0.1); }
        
        .sidebar.collapsed { width: 80px; }
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .profile-name,
        .sidebar.collapsed .profile-email { display: none; }
        .sidebar.collapsed .menu-item { justify-content: center; padding: 12px; }
        .sidebar.collapsed .menu-item i { margin-right: 0; }
        .sidebar.collapsed .section-header { display: none; }
        
        .sidebar-header {
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            position: sticky;
            top: 0;
            background: #1e293b;
            z-index: 10;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-icon { font-size: 28px; }
        .logo-text { font-size: 18px; font-weight: 700; }
        
        .collapse-btn {
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        
        .collapse-btn:hover { background: rgba(255,255,255,0.2); }
        
        .nav-menu {
            list-style: none;
            padding: 20px 16px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            margin: 2px 0;
            border-radius: 10px;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            white-space: nowrap;
        }
        
        .menu-item i {
            width: 24px;
            font-size: 16px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .menu-item span { font-size: 13px; font-weight: 500; }
        .menu-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .menu-item.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        
        .badge-count {
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 20px;
            margin-left: auto;
            min-width: 18px;
            text-align: center;
        }
        
        .section-header {
            padding: 8px 16px;
            margin-top: 8px;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            background: #0f172a;
            margin-top: 20px;
        }
        
        .profile-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: white;
        }
        
        .profile-item:hover { background: rgba(255,255,255,0.1); }
        
        .profile-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .profile-info {
            flex: 1;
            min-width: 0;
        }
        
        .profile-name {
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .profile-email {
            font-size: 10px;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        .main-content.expanded { margin-left: 80px; }
        
        .top-bar {
            background: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
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
        .dropdown-menu.show { display: block; }
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
            width: 200px;
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
        
        .container { padding: 24px; }
        
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar .logo-text, .sidebar .menu-label, .sidebar .profile-name, .sidebar .profile-email, .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; }
            .main-content { margin-left: 80px; }
        }
        
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); width: 280px; }
            .sidebar.mobile-open .logo-text, .sidebar.mobile-open .menu-label, .sidebar.mobile-open .profile-name, .sidebar.mobile-open .profile-email, .sidebar.mobile-open .section-header { display: block; }
            .sidebar.mobile-open .menu-item { justify-content: flex-start; }
            .sidebar.mobile-open .menu-item i { margin-right: 12px; }
            .main-content { margin-left: 0; }
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
            
            <!-- Wallet -->
            <a href="/broker_system/user/wallet.php" class="menu-item <?php echo $current_page == 'wallet.php' ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span class="menu-label">Wallet</span>
            </a>
            
            <!-- Notifications -->
            <a href="/broker_system/user/notifications.php" class="menu-item <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span class="menu-label">Notifications</span>
                <?php if ($notifications_count > 0): ?>
                    <span class="badge-count"><?php echo $notifications_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Chat / Messages -->
            <a href="/broker_system/user/chat.php" class="menu-item <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i>
                <span class="menu-label">Messages</span>
                <?php if ($unread_chat_count > 0): ?>
                    <span class="badge-count"><?php echo $unread_chat_count; ?></span>
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
                            <?php if ($notifications && $notifications->num_rows > 0): ?>
                                <?php while($notif = $notifications->fetch_assoc()): ?>
                                    <div class="notification-item" onclick="location.href='/broker_system/user/notifications.php'">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
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
                        <a href="/broker_system/user/settings.php" class="user-menu-item"><i class="fas fa-cog"></i> Settings</a>
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
        // Sidebar collapse
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
    </script>
</body>
</html>