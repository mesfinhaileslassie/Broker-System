<?php
// admin/analytics.php - Analytics Dashboard

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();

// Get data for charts
$dailyRevenue = [];
$dailyUsers = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $rev = $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE DATE(completed_at) = '$date' AND status = 'completed'")->fetch_assoc()['total'] ?? 0;
    $users = $conn->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = '$date'")->fetch_assoc()['count'];
    $dailyRevenue[] = $rev;
    $dailyUsers[] = $users;
}

// Get top users by spending
$topBuyers = $conn->query("
    SELECT u.full_name, u.email, SUM(t.total_amount) as total_spent
    FROM transactions t
    JOIN users u ON t.buyer_id = u.id
    WHERE t.status = 'completed'
    GROUP BY t.buyer_id
    ORDER BY total_spent DESC
    LIMIT 5
");

// Get top sellers by earnings
$topSellers = $conn->query("
    SELECT u.full_name, u.email, SUM(t.total_amount) as total_earned
    FROM transactions t
    JOIN users u ON t.seller_id = u.id
    WHERE t.status = 'completed'
    GROUP BY t.seller_id
    ORDER BY total_earned DESC
    LIMIT 5
");

// Get transaction by type
$transactionsByType = [
    'products' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'product' AND t.status = 'completed'")->fetch_assoc()['count'],
    'jobs' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'job' AND t.status = 'completed'")->fetch_assoc()['count'],
    'rentals' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'rental' AND t.status = 'completed'")->fetch_assoc()['count'],
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        .chart-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px; }
        .chart-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .chart-card h3 { margin-bottom: 16px; color: #333; }
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        canvas { max-height: 300px; }
        .stats-number { font-size: 24px; font-weight: 700; color: #667eea; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar">
            <div class="sidebar-header"><h2>🏪 Brokerplace</h2><p>Admin Dashboard</p></div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='payments.php'"><i class="fas fa-credit-card"></i> Payments</li>
                <li class="nav-item active"><i class="fas fa-chart-line"></i> Analytics</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Analytics Dashboard</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Daily Revenue (Last 7 Days)</h3>
                    <canvas id="revenueChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3>New Users (Last 7 Days)</h3>
                    <canvas id="usersChart"></canvas>
                </div>
            </div>
            
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Transactions by Type</h3>
                    <canvas id="typeChart"></canvas>
                    <div style="margin-top: 16px;">
                        <p>📦 Products: <strong><?php echo $transactionsByType['products']; ?></strong></p>
                        <p>💼 Jobs: <strong><?php echo $transactionsByType['jobs']; ?></strong></p>
                        <p>🏠 Rentals: <strong><?php echo $transactionsByType['rentals']; ?></strong></p>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>Top Buyers</h3>
                    <table>
                        <thead><tr><th>User</th><th>Total Spent</th></tr></thead>
                        <tbody>
                            <?php while($row = $topBuyers->fetch_assoc()): ?>
                            <tr><td><?php echo htmlspecialchars($row['full_name']); ?></td><td><?php echo formatMoney($row['total_spent']); ?></td></tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">Top Sellers</div>
                <table>
                    <thead><tr><th>Seller</th><th>Total Earned</th></tr></thead>
                    <tbody>
                        <?php while($row = $topSellers->fetch_assoc()): ?>
                        <tr><td><?php echo htmlspecialchars($row['full_name']); ?></td><td><?php echo formatMoney($row['total_earned']); ?></td></tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: ['Day 6', 'Day 5', 'Day 4', 'Day 3', 'Day 2', 'Yesterday', 'Today'],
                datasets: [{
                    label: 'Revenue (ETB)',
                    data: <?php echo json_encode($dailyRevenue); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' }
                }
            }
        });

        // Users Chart
        const usersCtx = document.getElementById('usersChart').getContext('2d');
        new Chart(usersCtx, {
            type: 'bar',
            data: {
                labels: ['Day 6', 'Day 5', 'Day 4', 'Day 3', 'Day 2', 'Yesterday', 'Today'],
                datasets: [{
                    label: 'New Users',
                    data: <?php echo json_encode($dailyUsers); ?>,
                    backgroundColor: '#28a745',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' }
                }
            }
        });

        // Transactions by Type Chart
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: ['Products', 'Jobs', 'Rentals'],
                datasets: [{
                    data: [<?php echo $transactionsByType['products']; ?>, <?php echo $transactionsByType['jobs']; ?>, <?php echo $transactionsByType['rentals']; ?>],
                    backgroundColor: ['#667eea', '#28a745', '#fd7e14'],
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true
            }
        });
    </script>
</body>
</html>