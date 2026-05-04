<?php
// admin/dashboard.php - Modern Admin Dashboard

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Get statistics
$stats = [
    'total_users' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'")->fetch_assoc()['count'],
    'total_companies' => $conn->query("SELECT COUNT(*) as count FROM companies")->fetch_assoc()['count'],
    'total_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'],
    'pending_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_revenue' => $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0,
    'pending_approvals' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'active_disputes' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status IN ('open', 'under_review')")->fetch_assoc()['count'],
    'escrow_held' => $conn->query("SELECT SUM(escrow_held) as total FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['total'] ?? 0,
];

// Get recent transactions
$recentTransactions = $conn->query("
    SELECT t.*, u1.full_name as buyer_name, u2.full_name as seller_name 
    FROM transactions t
    LEFT JOIN users u1 ON t.buyer_id = u1.id
    LEFT JOIN users u2 ON t.seller_id = u2.id
    ORDER BY t.created_at DESC 
    LIMIT 8
");

// Get pending listings
$pendingListings = $conn->query("
    SELECT l.*, u.full_name as seller_name 
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.approval_status = 'pending'
    ORDER BY l.created_at DESC
    LIMIT 5
");

// Get recent users
$recentUsers = $conn->query("
    SELECT * FROM users 
    ORDER BY created_at DESC 
    LIMIT 5
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Ethio Brokerplace</title>
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
            text-decoration: none;
            color: white;
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

        /* Main Content */
        .main-content {
            margin-left: 280px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }

        .main-content.expanded {
            margin-left: 80px;
        }

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

        .admin-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logout-btn {
            padding: 8px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }

        .container {
            padding: 24px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            color: #f59e0b;
        }

        /* Alert Banner */
        .alert-banner {
            background: #fef3c7;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
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

        .alert-btn {
            background: #f59e0b;
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
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

        /* Tables */
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

        tr:hover {
            background: #f8fafc;
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
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-info { background: #dbeafe; color: #2563eb; }

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
        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
            }
            .sidebar .logo-text,
            .sidebar .menu-label,
            .sidebar .profile-name,
            .sidebar .profile-email {
                display: none;
            }
            .sidebar .menu-item {
                justify-content: center;
                padding: 12px;
            }
            .sidebar .menu-item i {
                margin-right: 0;
            }
            .main-content {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
                <i class="fas fa-tachometer-alt"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            <a href="users.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span class="menu-label">Users</span>
            </a>
            <a href="transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>
            <a href="approve_listings.php" class="menu-item">
                <i class="fas fa-check-double"></i>
                <span class="menu-label">Approve Listings</span>
            </a>
            <a href="disputes.php" class="menu-item">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Disputes</span>
            </a>
            <a href="withdrawals.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span class="menu-label">Withdrawals</span>
            </a>
            <a href="settings.php" class="menu-item">
                <i class="fas fa-cog"></i>
                <span class="menu-label">Settings</span>
            </a>
        </ul>

        <div class="sidebar-footer">
            <div class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="profile-email">Administrator</div>
                </div>
            </div>
            <a href="logout.php" class="menu-item" style="margin-top: 8px;">
                <i class="fas fa-sign-out-alt logout-icon"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title">Dashboard</h1>
            <div class="admin-info">
                <span><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($admin_name); ?></span>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <div class="container">
            <!-- Alert Banner for Pending Approvals -->
            <?php if ($stats['pending_approvals'] > 0): ?>
            <div class="alert-banner">
                <div class="alert-content">
                    <i class="fas fa-clock"></i>
                    <span><strong><?php echo $stats['pending_approvals']; ?> listing(s)</strong> pending approval</span>
                </div>
                <a href="approve_listings.php" class="alert-btn">Review Now →</a>
            </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🏢</div>
                    <div class="stat-value"><?php echo number_format($stats['total_companies']); ?></div>
                    <div class="stat-label">Companies</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                    <div class="stat-label">Transactions</div>
                    <div class="stat-trend"><?php echo $stats['pending_transactions']; ?> pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💵</div>
                    <div class="stat-value"><?php echo formatMoney($stats['total_revenue']); ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🔒</div>
                    <div class="stat-value"><?php echo formatMoney($stats['escrow_held']); ?></div>
                    <div class="stat-label">Escrow Held</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-value"><?php echo $stats['pending_approvals']; ?></div>
                    <div class="stat-label">Pending Approvals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚖️</div>
                    <div class="stat-value"><?php echo $stats['active_disputes']; ?></div>
                    <div class="stat-label">Active Disputes</div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Transactions</h3>
                    <a href="transactions.php">View All →</a>
                </div>
                <div class="table-wrapper">
                    <?php if ($recentTransactions->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr><th>ID</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Status</th><th>Date</th><th></th>
                            </thead>
                            <tbody>
                                <?php while($row = $recentTransactions->fetch_assoc()): ?>
                                <tr onclick="location.href='transactions.php?view=<?php echo $row['id']; ?>'" style="cursor:pointer;">
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($row['buyer_name'] ?? 'N/A', 0, 20)); ?></td>
                                    <td><?php echo htmlspecialchars(substr($row['seller_name'] ?? 'N/A', 0, 20)); ?></td>
                                    <td><?php echo formatMoney($row['total_amount']); ?></td>
                                    <td><?php echo getStatusBadge($row['status']); ?></td>
                                    <td><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                                    <td><a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">View</a></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; padding: 40px; color: #64748b;">No transactions found</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Two Column Layout -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <!-- Pending Approvals -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Pending Approvals</h3>
                        <a href="approve_listings.php">View All →</a>
                    </div>
                    <div class="table-wrapper">
                        <?php if ($pendingListings->num_rows > 0): ?>
                            <table>
                                <thead>
                                    <tr><th>Title</th><th>Seller</th><th>Price</th><th></th>
                                </thead>
                                <tbody>
                                    <?php while($row = $pendingListings->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(substr($row['title'], 0, 20)); ?></td>
                                        <td><?php echo htmlspecialchars($row['seller_name']); ?></td>
                                        <td><?php echo formatMoney($row['price']); ?></td>
                                        <td><a href="approve_listings.php" class="btn-sm btn-primary">Review</a></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align: center; padding: 20px; color: #64748b;">No pending approvals</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Users -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-plus"></i> New Users</h3>
                        <a href="users.php">View All →</a>
                    </div>
                    <div class="table-wrapper">
                        <?php if ($recentUsers->num_rows > 0): ?>
                            <table>
                                <thead>
                                    <tr><th>Name</th><th>Email</th><th>Joined</th><th></th>
                                </thead>
                                <tbody>
                                    <?php while($row = $recentUsers->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(substr($row['full_name'], 0, 15)); ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['email'], 0, 20)); ?></td>
                                        <td><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                                        <td><a href="users.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary">View</a></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align: center; padding: 20px; color: #64748b;">No recent users</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Collapse Toggle
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
    </script>
</body>
</html>