<?php
// admin/dashboard.php - Complete Admin Dashboard

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/admin_functions.php';

requireAdminLogin();

$conn = getDbConnection();

// Get all statistics
$stats = getAdminStats($conn);

// Get pending approvals count for alert
$pendingApprovals = $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'");
$pendingCount = $pendingApprovals->fetch_assoc()['count'];

// Get recent transactions
$recentTransactions = getRecentTransactions($conn, 10);

// Get recent users
$recentUsers = getRecentUsers($conn, 8);

// Get recent disputes
$recentDisputes = getRecentDisputes($conn, 5);

// Get monthly revenue data for chart
$monthlyRevenue = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $result = $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month' AND status = 'completed'");
    $monthlyRevenue[] = $result->fetch_assoc()['total'] ?? 0;
}

// Get transaction status breakdown
$statusCounts = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status IN ('pending_deposit', 'awaiting_buyer_deposit', 'awaiting_seller_deposit')")->fetch_assoc()['count'],
    'in_progress' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status IN ('deposits_complete', 'in_progress')")->fetch_assoc()['count'],
    'completed' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'completed'")->fetch_assoc()['count'],
    'disputed' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'disputed'")->fetch_assoc()['count'],
];

// Get today's stats
$today = date('Y-m-d');
$todayRevenue = $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE DATE(created_at) = '$today' AND status = 'completed'")->fetch_assoc()['total'] ?? 0;
$todayUsers = $conn->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = '$today'")->fetch_assoc()['count'];
$todayTransactions = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE DATE(created_at) = '$today'")->fetch_assoc()['count'];

// Get top selling categories
$topCategories = $conn->query("
    SELECT c.name, COUNT(t.id) as total_sales
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE t.status = 'completed'
    GROUP BY c.id
    ORDER BY total_sales DESC
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        
        /* Sidebar */
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 20px; }
        .sidebar-header p { font-size: 12px; color: #888; margin-top: 8px; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        
        /* Main Content */
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        
        /* Alert */
        .alert { background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; border-radius: 8px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .alert-warning { color: #856404; }
        .alert a { background: #ffc107; color: #333; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 500; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-icon { font-size: 32px; margin-bottom: 12px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #333; }
        .stat-label { color: #666; font-size: 14px; margin-top: 8px; }
        .stat-change { font-size: 12px; margin-top: 8px; color: #28a745; }
        
        /* Chart Grid */
        .chart-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px; }
        .chart-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .chart-card h3 { margin-bottom: 16px; font-size: 16px; color: #333; }
        canvas { max-height: 250px; width: 100% !important; }
        
        /* Section */
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .section-title a { font-size: 13px; color: #667eea; text-decoration: none; }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        tr:hover { background: #f8f9fa; }
        
        /* Badges */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        
        /* Buttons */
        .btn-sm { padding: 4px 10px; font-size: 11px; border-radius: 4px; text-decoration: none; background: #667eea; color: white; display: inline-block; }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .chart-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
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
                <li class="nav-item active"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='payments.php'"><i class="fas fa-credit-card"></i> Payments</li>
                <li class="nav-item" onclick="location.href='analytics.php'"><i class="fas fa-chart-line"></i> Analytics</li>
                <li class="nav-item" onclick="location.href='messages.php'"><i class="fas fa-envelope"></i> Messages</li>
                <li class="nav-item" onclick="location.href='tickets.php'"><i class="fas fa-ticket-alt"></i> Support</li>
                <li class="nav-item" onclick="location.href='withdrawals.php'"><i class="fas fa-money-bill-wave"></i> Withdrawals</li>
                <li class="nav-item" onclick="location.href='approve_listings.php'"><i class="fas fa-check-double"></i> Approve Listings</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Dashboard</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <!-- Pending Approvals Alert -->
            <?php if ($pendingCount > 0): ?>
            <div class="alert">
                <div class="alert-warning">
                    <i class="fas fa-clock"></i> <strong><?php echo $pendingCount; ?> listing(s) pending approval</strong>
                    <span style="margin-left: 8px;">Need your review before they can go live.</span>
                </div>
                <a href="approve_listings.php">Review Now <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php endif; ?>
            
            <!-- Today's Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value"><?php echo formatMoney($todayRevenue); ?></div>
                    <div class="stat-label">Today's Revenue</div>
                    <div class="stat-change"><i class="fas fa-calendar-day"></i> <?php echo date('M d, Y'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-value"><?php echo $todayUsers; ?></div>
                    <div class="stat-label">New Users Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-value"><?php echo $todayTransactions; ?></div>
                    <div class="stat-label">Transactions Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-value"><?php echo $stats['active_listings']; ?></div>
                    <div class="stat-label">Active Listings</div>
                </div>
            </div>
            
            <!-- Main Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-change">+<?php echo $stats['new_users_7d']; ?> this week</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🏢</div>
                    <div class="stat-value"><?php echo number_format($stats['total_companies']); ?></div>
                    <div class="stat-label">Companies</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-change"><?php echo $stats['pending_transactions']; ?> pending</div>
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
                    <div class="stat-icon">⚖️</div>
                    <div class="stat-value"><?php echo $stats['active_disputes']; ?></div>
                    <div class="stat-label">Active Disputes</div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="chart-grid">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line"></i> Monthly Revenue (Last 12 Months)</h3>
                    <canvas id="revenueChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Transaction Status</h3>
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            
            <!-- Recent Transactions -->
            <div class="section">
                <div class="section-title">
                    Recent Transactions
                    <a href="transactions.php">View All →</a>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Commission</th><th>Status</th><th>Date</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($recentTransactions && $recentTransactions->num_rows > 0): ?>
                                <?php while($row = $recentTransactions->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['buyer_name'] ?? 'N/A', 0, 20)); ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['seller_name'] ?? 'N/A', 0, 20)); ?></td>
                                        <td><?php echo formatMoney($row['total_amount']); ?></td>
                                        <td><?php echo formatMoney($row['commission_amount']); ?></td>
                                        <td><?php echo getStatusBadge($row['status']); ?></td>
                                        <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                                        <td><a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm">View</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" style="text-align: center;">No transactions found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Recent Users & Top Categories -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="section">
                    <div class="section-title">
                        Recent Users
                        <a href="users.php">View All →</a>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead>
                            <tbody>
                                <?php if ($recentUsers && $recentUsers->num_rows > 0): ?>
                                    <?php while($row = $recentUsers->fetch_assoc()): ?>
                                        <tr onclick="location.href='users.php?view=<?php echo $row['id']; ?>'" style="cursor:pointer;">
                                            <td><?php echo htmlspecialchars(substr($row['full_name'], 0, 20)); ?></td>
                                            <td><?php echo htmlspecialchars(substr($row['email'], 0, 20)); ?></td>
                                            <td><?php echo getUserRoleBadge($row['role']); ?></td>
                                            <td><?php echo timeAgo($row['created_at']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" style="text-align: center;">No users found</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="section">
                    <div class="section-title">
                        Top Categories
                        <a href="analytics.php">View Analytics →</a>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead><tr><th>Category</th><th>Total Sales</th></tr></thead>
                            <tbody>
                                <?php if ($topCategories && $topCategories->num_rows > 0): ?>
                                    <?php while($row = $topCategories->fetch_assoc()): ?>
                                        <tr>
                                            <td><i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['name'] ?? 'Uncategorized'); ?></td>
                                            <td><?php echo $row['total_sales']; ?> transactions</td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" style="text-align: center;">No data available</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue (ETB)',
                    data: <?php echo json_encode($monthlyRevenue); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'top' } }
            }
        });
        
        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'In Progress', 'Completed', 'Disputed'],
                datasets: [{
                    data: [<?php echo $statusCounts['pending']; ?>, <?php echo $statusCounts['in_progress']; ?>, <?php echo $statusCounts['completed']; ?>, <?php echo $statusCounts['disputed']; ?>],
                    backgroundColor: ['#ffc107', '#17a2b8', '#28a745', '#dc3545'],
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    </script>
</body>
</html>