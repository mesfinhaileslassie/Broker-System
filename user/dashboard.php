<?php
// user/dashboard.php - Modern User Dashboard with Sidebar Layout

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$_SESSION['user_balance'] = $user['balance'];

// Get statistics
$stats = [
    'balance' => $user['balance'],
    'active_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'],
    'pending_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'")->fetch_assoc()['count'],
    'total_sales' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE seller_id = $user_id AND status = 'completed'")->fetch_assoc()['count'],
    'total_purchases' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE buyer_id = $user_id AND status = 'completed'")->fetch_assoc()['count'],
    'pending_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE (buyer_id = $user_id OR seller_id = $user_id) AND status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_earned' => $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE seller_id = $user_id AND status = 'completed'")->fetch_assoc()['total'] ?? 0,
];

// Get unread notifications count
$notifications_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count'];

// Get recent notifications
$notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 5
");

// Get recent transactions
$recentTransactions = $conn->query("
    SELECT t.*, l.title as listing_title,
           CASE WHEN t.buyer_id = $user_id THEN 'bought' ELSE 'sold' END as action
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.buyer_id = $user_id OR t.seller_id = $user_id
    ORDER BY t.created_at DESC 
    LIMIT 5
");

// Get recent listings
$recentListings = $conn->query("
    SELECT * FROM listings 
    WHERE seller_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 4
");

// Get pending legal transactions count
$pending_legal_count = $conn->query("
    SELECT COUNT(*) as count FROM transactions t
    WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id)
    AND t.status = 'deposits_complete'
    AND ((t.buyer_legal_confirmed = 0 AND t.buyer_id = $user_id) OR
         (t.seller_legal_confirmed = 0 AND t.seller_id = $user_id))
")->fetch_assoc()['count'];

// Mark notifications as read
if (isset($_GET['mark_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    header('Location: dashboard.php');
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Ethio Brokerplace</title>
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
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            transition: all 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .profile-name,
        .sidebar.collapsed .profile-email {
            display: none;
        }

        .sidebar.collapsed .menu-item {
            justify-content: center;
            padding: 12px;
        }

        .sidebar.collapsed .menu-item i {
            margin-right: 0;
        }

        /* Sidebar Header */
        .sidebar-header {
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            font-size: 28px;
        }

        .logo-text {
            font-size: 18px;
            font-weight: 700;
        }

        .collapse-btn {
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .collapse-btn:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Sidebar Navigation */
        .nav-menu {
            list-style: none;
            padding: 20px 16px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            margin: 4px 0;
            border-radius: 12px;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .menu-item i {
            width: 24px;
            font-size: 18px;
            margin-right: 12px;
        }

        .menu-item span {
            font-size: 14px;
            font-weight: 500;
        }

        .menu-item:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .menu-item.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .badge-count {
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 20px;
            margin-left: auto;
        }

        /* Sidebar Footer */
        .sidebar-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .profile-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .profile-item:hover {
            background: rgba(255,255,255,0.1);
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 14px;
            font-weight: 600;
        }

        .profile-email {
            font-size: 11px;
            color: #94a3b8;
        }

        .logout-icon {
            color: #ef4444;
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
        .notification-dropdown {
            position: relative;
        }

        .notification-icon {
            position: relative;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: background 0.3s;
        }

        .notification-icon:hover {
            background: #f1f5f9;
        }

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
            width: 320px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            display: none;
            z-index: 1000;
            margin-top: 8px;
        }

        .notification-dropdown:hover .dropdown-menu {
            display: block;
        }

        .dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dropdown-header h4 {
            font-size: 14px;
            font-weight: 600;
        }

        .dropdown-header a {
            font-size: 11px;
            color: #667eea;
            text-decoration: none;
        }

        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.3s;
        }

        .notification-item:hover {
            background: #f8fafc;
        }

        .notification-title {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .notification-message {
            font-size: 11px;
            color: #64748b;
        }

        .notification-time {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* User Avatar Dropdown */
        .user-dropdown {
            position: relative;
            cursor: pointer;
        }

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
        }

        .user-dropdown:hover .user-menu {
            display: block;
        }

        .user-menu-item {
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #334155;
            text-decoration: none;
            transition: background 0.3s;
        }

        .user-menu-item:hover {
            background: #f1f5f9;
        }

        /* Container */
        .container {
            padding: 24px;
        }

        /* Welcome Section */
        .welcome-section {
            margin-bottom: 28px;
        }

        .welcome-section h1 {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .welcome-section p {
            color: #64748b;
            font-size: 14px;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 12px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .action-btn {
            background: white;
            padding: 12px 24px;
            border-radius: 40px;
            text-decoration: none;
            color: #334155;
            font-weight: 500;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            color: #667eea;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
        }

        .stat-icon {
            font-size: 28px;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-label {
            font-size: 13px;
            color: #64748b;
            margin-top: 6px;
        }

        .stat-trend {
            font-size: 11px;
            margin-top: 8px;
            color: #10b981;
        }

        /* Alert Card */
        .alert-card {
            background: #fef3c7;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
        }

        .alert-card .btn {
            background: #f59e0b;
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
        }

        /* Escrow Stepper */
        .stepper {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 28px;
        }

        .stepper-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #0f172a;
        }

        .steps {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }

        .step-circle {
            width: 40px;
            height: 40px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 18px;
        }

        .step.active .step-circle {
            background: #667eea;
            color: white;
        }

        .step.completed .step-circle {
            background: #10b981;
            color: white;
        }

        .step-label {
            font-size: 11px;
            font-weight: 500;
            color: #64748b;
        }

        /* Card */
        .card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }

        .card-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
        }

        .card-header a {
            font-size: 12px;
            color: #667eea;
            text-decoration: none;
        }

        /* Listing Cards Grid */
        .listings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }

        .listing-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            transition: all 0.3s;
        }

        .listing-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .listing-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .listing-price {
            font-size: 16px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 8px;
        }

        .listing-stats {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #64748b;
        }

        /* Table */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        th {
            font-weight: 600;
            color: #64748b;
        }

        /* Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }

        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fed7aa; color: #ea580c; }
        .badge-info { background: #dbeafe; color: #2563eb; }
        .badge-danger { background: #fee2e2; color: #dc2626; }

        /* Buttons */
        .btn-sm {
            padding: 4px 10px;
            font-size: 11px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary { background: #667eea; color: white; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .steps {
                flex-direction: column;
                gap: 12px;
            }
            .step {
                display: flex;
                align-items: center;
                gap: 12px;
                text-align: left;
            }
            .step-circle {
                margin: 0;
            }
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
            <a href="dashboard.php" class="menu-item active">
                <i class="fas fa-home"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            <a href="browse.php" class="menu-item">
                <i class="fas fa-search"></i>
                <span class="menu-label">Browse</span>
            </a>
            <a href="listings.php" class="menu-item">
                <i class="fas fa-box"></i>
                <span class="menu-label">My Listings</span>
            </a>
            <a href="wallet.php" class="menu-item">
                <i class="fas fa-wallet"></i>
                <span class="menu-label">Wallet</span>
            </a>
            <a href="#" class="menu-item" id="notificationsMenu">
                <i class="fas fa-bell"></i>
                <span class="menu-label">Notifications</span>
                <?php if ($notifications_count > 0): ?>
                    <span class="badge-count"><?php echo $notifications_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="legal_process.php" class="menu-item">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Legal Process</span>
                <?php if ($pending_legal_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_legal_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>
        </ul>

        <div class="sidebar-footer">
            <div class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="profile-email"><?php echo htmlspecialchars($_SESSION['user_email']); ?></div>
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
        <!-- Top Bar -->
        <div class="top-bar">
            <h1 class="page-title">Dashboard</h1>
            <div class="top-bar-actions">
                <!-- Notifications Dropdown -->
                <div class="notification-dropdown">
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifications_count > 0): ?>
                            <span class="notification-badge"><?php echo $notifications_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-menu">
                        <div class="dropdown-header">
                            <h4>Notifications</h4>
                            <a href="?mark_read=1">Mark all read</a>
                        </div>
                        <?php if ($notifications->num_rows > 0): ?>
                            <?php while($notif = $notifications->fetch_assoc()): ?>
                                <div class="notification-item">
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

                <!-- User Dropdown -->
                <div class="user-dropdown">
                    <div class="user-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                    <div class="user-menu">
                        <a href="profile.php" class="user-menu-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="wallet.php" class="user-menu-item"><i class="fas fa-wallet"></i> Wallet</a>
                        <a href="settings.php" class="user-menu-item"><i class="fas fa-cog"></i> Settings</a>
                        <hr style="margin: 8px 0; border-color: #f1f5f9;">
                        <a href="../auth/logout.php" class="user-menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h1>Welcome back, <?php echo htmlspecialchars($user_name); ?> 👋</h1>
                <p>Here's your activity overview</p>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="post_listing.php" class="action-btn"><i class="fas fa-plus-circle"></i> New Listing</a>
                <a href="browse.php" class="action-btn"><i class="fas fa-search"></i> Browse</a>
                <a href="wallet.php" class="action-btn"><i class="fas fa-wallet"></i> Wallet</a>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value"><?php echo formatMoney($stats['balance']); ?></div>
                    <div class="stat-label">Wallet Balance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📦</div>
                    <div class="stat-value"><?php echo $stats['active_listings']; ?></div>
                    <div class="stat-label">Active Listings</div>
                    <?php if ($stats['pending_listings'] > 0): ?>
                        <div class="stat-trend"><?php echo $stats['pending_listings']; ?> pending</div>
                    <?php endif; ?>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📈</div>
                    <div class="stat-value"><?php echo $stats['total_sales']; ?></div>
                    <div class="stat-label">Total Sales</div>
                    <div class="stat-trend">Earned: <?php echo formatMoney($stats['total_earned']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🛒</div>
                    <div class="stat-value"><?php echo $stats['total_purchases']; ?></div>
                    <div class="stat-label">Total Purchases</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-value"><?php echo $stats['pending_transactions']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>

            <!-- Legal Process Alert -->
            <?php if ($pending_legal_count > 0): ?>
            <div class="alert-card">
                <div class="alert-content">
                    <i class="fas fa-gavel"></i>
                    <span><strong><?php echo $pending_legal_count; ?> transaction(s)</strong> require legal confirmation</span>
                </div>
                <a href="legal_process.php" class="btn">Complete Now →</a>
            </div>
            <?php endif; ?>

            <!-- Escrow Process Stepper -->
            <div class="stepper">
                <div class="stepper-title">How Escrow Works</div>
                <div class="steps">
                    <div class="step completed">
                        <div class="step-circle">💳</div>
                        <div class="step-label">Buyer Pays</div>
                    </div>
                    <div class="step active">
                        <div class="step-circle">📥</div>
                        <div class="step-label">Seller Deposit</div>
                    </div>
                    <div class="step">
                        <div class="step-circle">📄</div>
                        <div class="step-label">Legal Process</div>
                    </div>
                    <div class="step">
                        <div class="step-circle">✅</div>
                        <div class="step-label">Delivery</div>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Transactions</h3>
                    <a href="transactions.php">View All →</a>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Item</th><th>Type</th><th>Amount</th><th>Status</th><th></th>
                        </thead>
                        <tbody>
                            <?php if ($recentTransactions->num_rows > 0): ?>
                                <?php while($txn = $recentTransactions->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $txn['id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($txn['listing_title'], 0, 25)); ?></td>
                                    <td><span class="badge <?php echo $txn['action'] == 'bought' ? 'badge-info' : 'badge-success'; ?>"><?php echo ucfirst($txn['action']); ?></span></td>
                                    <td><?php echo formatMoney($txn['total_amount']); ?></td>
                                    <td><?php echo getStatusBadge($txn['status']); ?></td>
                                    <td><a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm btn-primary">👁</a></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center;">No transactions yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Listings (Card Style) -->
            <div class="card-header" style="margin-bottom: 20px;">
                <h3><i class="fas fa-box"></i> Recent Listings</h3>
                <a href="listings.php">View All →</a>
            </div>
            <div class="listings-grid">
                <?php if ($recentListings->num_rows > 0): ?>
                    <?php while($listing = $recentListings->fetch_assoc()): ?>
                    <div class="listing-card">
                        <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                        <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                        <div class="listing-stats">
                            <span class="badge <?php echo $listing['approval_status'] == 'approved' ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo ucfirst($listing['approval_status']); ?>
                            </span>
                            <span><i class="fas fa-eye"></i> <?php echo $listing['views']; ?></span>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="listing-card" style="text-align: center; color: #64748b;">No listings yet</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Collapse Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseBtn');

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
        });

        // Mobile sidebar toggle (for small screens)
        function toggleMobileSidebar() {
            sidebar.classList.toggle('mobile-open');
        }
    </script>
</body>
</html>