<?php
// admin/analytics.php - Analytics Dashboard

$page_title = 'Analytics Dashboard';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

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

// Monthly revenue for last 6 months
$monthlyRevenue = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $rev = $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month' AND status = 'completed'")->fetch_assoc()['total'] ?? 0;
    $monthlyRevenue[] = $rev;
}
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$currentMonth = date('n') - 1;
$last6Months = [];
for ($i = 5; $i >= 0; $i--) {
    $index = ($currentMonth - $i + 12) % 12;
    $last6Months[] = $months[$index];
}

// Top users by spending
$topBuyers = $conn->query("
    SELECT u.full_name, u.email, SUM(t.total_amount) as total_spent
    FROM transactions t
    JOIN users u ON t.buyer_id = u.id
    WHERE t.status = 'completed'
    GROUP BY t.buyer_id
    ORDER BY total_spent DESC
    LIMIT 5
");

// Top sellers by earnings
$topSellers = $conn->query("
    SELECT u.full_name, u.email, SUM(t.total_amount) as total_earned
    FROM transactions t
    JOIN users u ON t.seller_id = u.id
    WHERE t.status = 'completed'
    GROUP BY t.seller_id
    ORDER BY total_earned DESC
    LIMIT 5
");

// Transactions by type
$transactionsByType = [
    'products' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'product' AND t.status = 'completed'")->fetch_assoc()['count'],
    'jobs' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'job' AND t.status = 'completed'")->fetch_assoc()['count'],
    'rentals' => $conn->query("SELECT COUNT(*) as count FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE l.type = 'rental' AND t.status = 'completed'")->fetch_assoc()['count'],
];

$conn->close();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    .chart-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-bottom: 24px; }
    .chart-card { background: white; border-radius: 20px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .chart-card h3 { margin-bottom: 20px; font-size: 16px; font-weight: 600; }
    canvas { max-height: 250px; width: 100% !important; }
    .stats-number { font-size: 24px; font-weight: 700; color: #667eea; }
    @media (max-width: 768px) { .chart-grid { grid-template-columns: 1fr; } }
</style>

<div class="chart-grid">
    <div class="chart-card">
        <h3><i class="fas fa-chart-line"></i> Daily Revenue (Last 7 Days)</h3>
        <canvas id="revenueChart"></canvas>
    </div>
    <div class="chart-card">
        <h3><i class="fas fa-users"></i> New Users (Last 7 Days)</h3>
        <canvas id="usersChart"></canvas>
    </div>
</div>

<div class="chart-grid">
    <div class="chart-card">
        <h3><i class="fas fa-chart-bar"></i> Monthly Revenue</h3>
        <canvas id="monthlyChart"></canvas>
    </div>
    <div class="chart-card">
        <h3><i class="fas fa-chart-pie"></i> Transactions by Type</h3>
        <canvas id="typeChart"></canvas>
        <div style="margin-top: 16px;">
            <p>📦 Products: <strong><?php echo $transactionsByType['products']; ?></strong></p>
            <p>💼 Jobs: <strong><?php echo $transactionsByType['jobs']; ?></strong></p>
            <p>🏠 Rentals: <strong><?php echo $transactionsByType['rentals']; ?></strong></p>
        </div>
    </div>
</div>

<div class="chart-grid">
    <div class="chart-card">
        <h3><i class="fas fa-trophy"></i> Top Buyers</h3>
        <div class="table-wrapper">
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
    <div class="chart-card">
        <h3><i class="fas fa-award"></i> Top Sellers</h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>User</th><th>Total Earned</th></tr></thead>
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
// Daily Revenue Chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: { labels: ['Day 6', 'Day 5', 'Day 4', 'Day 3', 'Day 2', 'Yesterday', 'Today'], datasets: [{ label: 'Revenue (ETB)', data: <?php echo json_encode($dailyRevenue); ?>, borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.1)', tension: 0.4, fill: true }] },
    options: { responsive: true, maintainAspectRatio: true }
});

// New Users Chart
new Chart(document.getElementById('usersChart'), {
    type: 'bar',
    data: { labels: ['Day 6', 'Day 5', 'Day 4', 'Day 3', 'Day 2', 'Yesterday', 'Today'], datasets: [{ label: 'New Users', data: <?php echo json_encode($dailyUsers); ?>, backgroundColor: '#10b981', borderRadius: 8 }] },
    options: { responsive: true, maintainAspectRatio: true }
});

// Monthly Revenue Chart
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode($last6Months); ?>, datasets: [{ label: 'Revenue (ETB)', data: <?php echo json_encode($monthlyRevenue); ?>, backgroundColor: '#667eea', borderRadius: 8 }] },
    options: { responsive: true, maintainAspectRatio: true }
});

// Transactions by Type Chart
new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: { labels: ['Products', 'Jobs', 'Rentals'], datasets: [{ data: [<?php echo $transactionsByType['products']; ?>, <?php echo $transactionsByType['jobs']; ?>, <?php echo $transactionsByType['rentals']; ?>], backgroundColor: ['#667eea', '#10b981', '#f59e0b'] }] },
    options: { responsive: true, maintainAspectRatio: true }
});
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>