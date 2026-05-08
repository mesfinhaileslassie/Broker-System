<?php
// admin/dashboard.php - Modern Admin Dashboard (Unified Session)

require_once '../includes/auth.php';

// Check if logged in and is admin using unified session
if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$admin_name = $_SESSION['user_name'];

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Admin Dashboard - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f6;
            overflow-x: hidden;
        }

        /* ===== SIDEBAR v2 ===== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 270px;
            height: 100%;
            background: #0f172a;
            backdrop-filter: blur(0px);
            color: #e2e8f0;
            transition: all 0.25s ease-in-out;
            z-index: 1050;
            overflow-y: auto;
            scrollbar-width: thin;
            box-shadow: 4px 0 20px rgba(0,0,0,0.08);
        }

        .sidebar.collapsed {
            width: 88px;
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
            gap: 0;
        }

        .sidebar.collapsed .menu-item i {
            margin-right: 0 !important;
            font-size: 1.4rem;
        }

        .sidebar-header {
            padding: 22px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            font-size: 28px;
            background: linear-gradient(135deg, #a57cff, #4f46e5);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .logo-text {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            background: linear-gradient(135deg, #fff, #cbd5e1);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .collapse-btn {
            background: rgba(255,255,255,0.08);
            border: none;
            color: #cbd5e1;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .collapse-btn:hover {
            background: rgba(255,255,255,0.18);
            color: white;
        }

        .nav-menu {
            list-style: none;
            padding: 20px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            border-radius: 14px;
            color: #cbd5e6;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            font-weight: 500;
        }

        .menu-item i {
            width: 24px;
            font-size: 1.25rem;
            text-align: center;
        }

        .menu-item span {
            font-size: 0.9rem;
        }

        .menu-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }

        .menu-item.active {
            background: linear-gradient(115deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 4px 10px rgba(79,70,229,0.25);
        }

        .sidebar-footer {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            padding: 16px 14px;
            border-top: 1px solid rgba(255,255,255,0.07);
        }

        .profile-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 14px;
            text-decoration: none;
            color: #e2e8f0;
            margin-bottom: 8px;
        }

        .profile-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(145deg, #4f46e5, #6b21a5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
            box-shadow: 0 3px 7px rgba(0,0,0,0.2);
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 0.85rem;
            font-weight: 600;
        }

        .profile-email {
            font-size: 0.7rem;
            color: #94a3b8;
        }

        /* ===== MAIN PANEL ===== */
        .main-content {
            margin-left: 270px;
            transition: margin 0.25s ease-in-out;
            min-height: 100vh;
            background: #f8fafc;
        }

        .main-content.expanded {
            margin-left: 88px;
        }

        .top-bar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1020;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 2px 6px rgba(0,0,0,0.02);
            backdrop-filter: blur(2px);
        }

        .page-title {
            font-size: 1.65rem;
            font-weight: 700;
            background: linear-gradient(135deg, #0f172a, #2d3a5e);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.4px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .logout-btn {
            padding: 0.45rem 1.2rem;
            background: #ef4444;
            color: white;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.8rem;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        .container {
            padding: 28px 28px;
            max-width: 1600px;
        }

        /* Stats Grid (Glassmorphism subtle) */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.3rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 28px;
            padding: 1.2rem 1.2rem;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02), 0 1px 2px rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(0,0,0,0.03);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.08);
            border-color: #eef2ff;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.8rem;
            display: inline-block;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #0a0c10;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #5b6e8c;
            margin-top: 6px;
        }

        .stat-trend {
            font-size: 0.7rem;
            margin-top: 8px;
            color: #f97316;
            font-weight: 500;
        }

        /* Alert Banner */
        .alert-banner {
            background: #fffbeb;
            border-radius: 20px;
            padding: 0.8rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            border-left: 5px solid #f59e0b;
        }

        .alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #b45309;
            font-weight: 500;
        }

        .alert-btn {
            background: #f97316;
            color: white;
            padding: 0.4rem 1.2rem;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.8rem;
        }

        /* Cards */
        .card {
            background: #ffffff;
            border-radius: 28px;
            padding: 1.2rem 1.2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
            border: 1px solid #eef2f6;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.6rem;
            border-bottom: 2px solid #f0f2f8;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header a {
            font-size: 0.75rem;
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
        }

        /* Table */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 0.9rem 0.6rem;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.8rem;
        }

        th {
            font-weight: 600;
            color: #475569;
        }

        tr:hover td {
            background-color: #fafcff;
        }

        /* Badges */
        .badge {
            padding: 0.2rem 0.7rem;
            border-radius: 100px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-warning { background: #ffedd5; color: #b45309; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }
        .badge-info { background: #e0f2fe; color: #0369a1; }

        .btn-sm {
            padding: 0.2rem 0.9rem;
            font-size: 0.7rem;
            border-radius: 30px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
        }

        .btn-primary {
            background: #4f46e5;
            color: white;
            transition: 0.15s;
        }
        .btn-primary:hover { background: #4338ca; }

        /* Two column layout */
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.8rem;
        }

        @media (max-width: 1000px) {
            .sidebar { width: 88px; }
            .sidebar .logo-text, .sidebar .menu-label, .sidebar .profile-name, .sidebar .profile-email { display: none; }
            .sidebar .menu-item { justify-content: center; gap: 0; }
            .sidebar .menu-item i { margin-right: 0; }
            .main-content { margin-left: 88px; }
            .two-columns { grid-template-columns: 1fr; gap: 1rem; }
        }

        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 0.8rem; }
            .container { padding: 16px; }
            .top-bar { padding: 0.8rem 1rem; flex-wrap: wrap; gap: 10px; }
        }

        /* utility */
        .cursor-pointer { cursor: pointer; }
        .text-muted { color: #6c757d; }
    </style>
</head>
<body>

    <!-- SIDEBAR (REDESIGN, BUTTONS/FUNC SAME) -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <span class="logo-icon">🛡️</span>
                <span class="logo-text">Brokerplace</span>
            </div>
            <button class="collapse-btn" id="collapseBtn" aria-label="Toggle Sidebar">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <ul class="nav-menu">
            <a href="dashboard.php" class="menu-item active">
                <i class="fas fa-chart-line"></i>
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
                <i class="fas fa-sliders-h"></i>
                <span class="menu-label">Settings</span>
            </a>
        </ul>

        <div class="sidebar-footer">
            <div class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="profile-email">Admin · Superuser</div>
                </div>
            </div>
            <a href="../auth/logout.php" class="menu-item" style="margin-top: 6px;">
                <i class="fas fa-sign-out-alt"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><i class="fas fa-tachometer-alt" style="font-size: 1.4rem; margin-right: 8px;"></i> Admin Dashboard</h1>
            <div class="admin-info">
                <span style="font-weight: 500;"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($admin_name); ?></span>
                <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Exit</a>
            </div>
        </div>

        <div class="container">
            <!-- Alert Banner: pending approvals (unchanged functionality) -->
            <?php if ($stats['pending_approvals'] > 0): ?>
            <div class="alert-banner">
                <div class="alert-content">
                    <i class="fas fa-clock fa-fw"></i>
                    <span><strong><?php echo $stats['pending_approvals']; ?> listing(s)</strong> awaiting approval</span>
                </div>
                <a href="approve_listings.php" class="alert-btn">Review →</a>
            </div>
            <?php endif; ?>

            <!-- Stats Grid (preserved values) -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?php echo number_format($stats['total_users']); ?></div><div class="stat-label">Total Users</div></div>
                <div class="stat-card"><div class="stat-icon">🏢</div><div class="stat-value"><?php echo number_format($stats['total_companies']); ?></div><div class="stat-label">Companies</div></div>
                <div class="stat-card"><div class="stat-icon">🔄</div><div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div><div class="stat-label">Transactions</div><div class="stat-trend"><?php echo $stats['pending_transactions']; ?> pending</div></div>
                <div class="stat-card"><div class="stat-icon">💰</div><div class="stat-value"><?php echo formatMoney($stats['total_revenue']); ?></div><div class="stat-label">Total Revenue</div></div>
                <div class="stat-card"><div class="stat-icon">🔒</div><div class="stat-value"><?php echo formatMoney($stats['escrow_held']); ?></div><div class="stat-label">Escrow Held</div></div>
                <div class="stat-card"><div class="stat-icon">⏳</div><div class="stat-value"><?php echo $stats['pending_approvals']; ?></div><div class="stat-label">Pending Approvals</div></div>
                <div class="stat-card"><div class="stat-icon">⚖️</div><div class="stat-value"><?php echo $stats['active_disputes']; ?></div><div class="stat-label">Active Disputes</div></div>
            </div>

            <!-- Recent Transactions - full functionality -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Recent Activity · Transactions</h3>
                    <a href="transactions.php">Browse All →</a>
                </div>
                <div class="table-wrapper">
                    <?php if ($recentTransactions->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Status</th><th>Date</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php while($row = $recentTransactions->fetch_assoc()): ?>
                            <tr onclick="location.href='transactions.php?view=<?php echo $row['id']; ?>'" style="cursor:pointer;">
                                <td>#<?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars(substr($row['buyer_name'] ?? 'N/A', 0, 20)); ?></td>
                                <td><?php echo htmlspecialchars(substr($row['seller_name'] ?? 'N/A', 0, 20)); ?></td>
                                <td><?php echo formatMoney($row['total_amount']); ?></td>
                                <td><?php echo getStatusBadge($row['status']); ?></td>
                                <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                                <td><a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">Details</a></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="text-muted" style="text-align:center; padding:2rem;">⚠️ No recent transactions</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Two column layout: Pending Listings & New Users -->
            <div class="two-columns">
                <!-- Pending Approvals Card -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-hourglass-half"></i> Pending Listings</h3>
                        <a href="approve_listings.php">Manage →</a>
                    </div>
                    <div class="table-wrapper">
                        <?php if ($pendingListings->num_rows > 0): ?>
                        <table>
                            <thead><tr><th>Title</th><th>Seller</th><th>Price</th><th></th></tr></thead>
                            <tbody>
                                <?php while($row = $pendingListings->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(substr($row['title'], 0, 28)); ?></td>
                                    <td><?php echo htmlspecialchars($row['seller_name']); ?></td>
                                    <td><?php echo formatMoney($row['price']); ?></td>
                                    <td><a href="approve_listings.php" class="btn-sm btn-primary">Review</a></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div style="padding: 1.5rem; text-align:center; color:#5b6e8c;">🎉 All listings approved!</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Users Card -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-plus"></i> Newest Members</h3>
                        <a href="users.php">All users →</a>
                    </div>
                    <div class="table-wrapper">
                        <?php if ($recentUsers->num_rows > 0): ?>
                        <table>
                            <thead><tr><th>Name</th><th>Email</th><th>Joined</th><th></th></tr></thead>
                            <tbody>
                                <?php while($row = $recentUsers->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(substr($row['full_name'], 0, 16)); ?></td>
                                    <td><?php echo htmlspecialchars(substr($row['email'], 0, 18)); ?></td>
                                    <td><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                                    <td><a href="users.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary">Profile</a></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div style="padding: 1.5rem; text-align:center; color:#5b6e8c;">No recent signups</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const collapseBtn = document.getElementById('collapseBtn');

            function toggleSidebar() {
                if (!sidebar || !mainContent) return;
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                const icon = collapseBtn?.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    if (icon) {
                        icon.classList.remove('fa-chevron-left');
                        icon.classList.add('fa-chevron-right');
                    }
                } else {
                    if (icon) {
                        icon.classList.remove('fa-chevron-right');
                        icon.classList.add('fa-chevron-left');
                    }
                }
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            }

            if (collapseBtn) {
                collapseBtn.addEventListener('click', toggleSidebar);
            }

            // load saved state
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                const icon = collapseBtn?.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                }
            }
        })();
    </script>
</body>
</html>