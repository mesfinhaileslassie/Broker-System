<?php
// admin/dashboard.php

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/admin_functions.php';

requireAdminLogin();

$conn = getDbConnection();
$stats = getAdminStats($conn);
$recentTransactions = getRecentTransactions($conn, 8);
$recentUsers = getRecentUsers($conn, 5);
$recentDisputes = getRecentDisputes($conn, 5);

// Chart data for last 7 days revenue
$revenueData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $result = $conn->query("SELECT SUM(commission_amount) as total FROM transactions 
                            WHERE DATE(completed_at) = '$date' AND status = 'completed'");
    $revenueData[] = $result->fetch_assoc()['total'] ?? 0;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f6fa;
        }
        
        .admin-wrapper {
            display: flex;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background: #1a1a2e;
            color: white;
            height: 100vh;
            position: fixed;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 24px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h2 {
            font-size: 20px;
        }
        
        .sidebar-header p {
            font-size: 12px;
            color: #888;
            margin-top: 8px;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
        }
        
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #aaa;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .nav-item:hover, .nav-item.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-item .icon {
            font-size: 20px;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 24px;
        }
        
        /* Header */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 600;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .logout-btn {
            padding: 8px 16px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stat-title {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
        }
        
        .stat-change {
            font-size: 12px;
            color: #27ae60;
            margin-top: 8px;
        }
        
        /* Sections */
        .section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            font-weight: 600;
            color: #666;
            font-size: 13px;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        
        .btn-sm {
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        
        .btn-view {
            background: #667eea;
            color: white;
        }
        
        .two-col-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🏪 Brokerplace</h2>
                <p>Admin Dashboard</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item active">
                    <span class="icon">📊</span>
                    <span>Dashboard</span>
                </li>
                <li class="nav-item" onclick="location.href='users.php'">
                    <span class="icon">👥</span>
                    <span>Users</span>
                </li>
                <li class="nav-item" onclick="location.href='companies.php'">
                    <span class="icon">🏢</span>
                    <span>Companies</span>
                </li>
                <li class="nav-item" onclick="location.href='transactions.php'">
                    <span class="icon">💰</span>
                    <span>Transactions</span>
                </li>
                <li class="nav-item" onclick="location.href='disputes.php'">
                    <span class="icon">⚖️</span>
                    <span>Disputes</span>
                </li>
                <li class="nav-item" onclick="location.href='payments.php'">
                    <span class="icon">💳</span>
                    <span>Payments</span>
                </li>
                <li class="nav-item" onclick="location.href='analytics.php'">
                    <span class="icon">📈</span>
                    <span>Analytics</span>
                </li>
                <li class="nav-item" onclick="location.href='messages.php'">
                    <span class="icon">💬</span>
                    <span>Messages</span>
                </li>
                <li class="nav-item" onclick="location.href='settings.php'">
                    <span class="icon">⚙️</span>
                    <span>Settings</span>
                </li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Dashboard</h1>
                <div class="admin-info">
                    <span>👋 Admin</span>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-title">Total Users</div>
                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-change">+<?php echo $stats['new_users_7d']; ?> new (7d)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Companies</div>
                    <div class="stat-value"><?php echo number_format($stats['total_companies']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Transactions</div>
                    <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                    <div class="stat-change"><?php echo $stats['pending_transactions']; ?> pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Total Revenue</div>
                    <div class="stat-value"><?php echo formatMoney($stats['total_revenue']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Escrow Held</div>
                    <div class="stat-value"><?php echo formatMoney($stats['escrow_held']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Active Disputes</div>
                    <div class="stat-value"><?php echo $stats['active_disputes']; ?></div>
                </div>
            </div>
            
            <!-- Recent Transactions & Disputes -->
            <div class="two-col-grid">
                <!-- Recent Transactions -->
                <div class="section">
                    <div class="section-title">Recent Transactions</div>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($recentTransactions && $recentTransactions->num_rows > 0): ?>
                                <?php while($row = $recentTransactions->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['buyer_name'] ?? 'N/A', 0, 20)); ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['seller_name'] ?? 'N/A', 0, 20)); ?></td>
                                        <td><?php echo formatMoney($row['total_amount']); ?></td>
                                        <td><?php echo getStatusBadge($row['status']); ?></td>
                                        <td><a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-view">View</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center;">No transactions found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Recent Disputes -->
                <div class="section">
                    <div class="section-title">Active Disputes</div>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Transaction</th><th>Raised By</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($recentDisputes && $recentDisputes->num_rows > 0): ?>
                                <?php while($row = $recentDisputes->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td>#<?php echo $row['transaction_id']; ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['raised_by_name'] ?? 'N/A', 0, 15)); ?></td>
                                        <td><span class="badge badge-danger"><?php echo $row['status']; ?></span></td>
                                        <td><a href="disputes.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-view">Review</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center;">No active disputes</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Recent Users -->
            <div class="section">
                <div class="section-title">Recent Registrations</div>
                <table>
                    <thead>
                        <tr><th>ID</th><th>Full Name</th><th>Phone</th><th>Email</th><th>Role</th><th>Verified</th><th>Joined</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($recentUsers && $recentUsers->num_rows > 0): ?>
                            <?php while($row = $recentUsers->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email'] ?? '-'); ?></td>
                                    <td><?php echo getUserRoleBadge($row['role']); ?></td>
                                    <td><?php echo getVerificationBadge($row['is_verified']); ?></td>
                                    <td><?php echo timeAgo($row['created_at']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center;">No users found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>