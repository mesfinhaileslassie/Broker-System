<?php
// user/dashboard.php - User dashboard

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get user stats
$stats = [
    'balance' => $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc()['balance'],
    'active_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active'")->fetch_assoc()['count'],
    'total_sales' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE seller_id = $user_id AND status = 'completed'")->fetch_assoc()['count'],
    'total_purchases' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE buyer_id = $user_id AND status = 'completed'")->fetch_assoc()['count'],
    'pending_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE (buyer_id = $user_id OR seller_id = $user_id) AND status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
];

// Get recent transactions
$recentTransactions = $conn->query("
    SELECT t.*, 
           CASE WHEN t.buyer_id = $user_id THEN 'bought' ELSE 'sold' END as action,
           CASE WHEN t.buyer_id = $user_id THEN u2.full_name ELSE u1.full_name END as other_party
    FROM transactions t
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.buyer_id = $user_id OR t.seller_id = $user_id
    ORDER BY t.created_at DESC
    LIMIT 5
");

// Get recent listings
$recentListings = $conn->query("
    SELECT * FROM listings 
    WHERE seller_id = $user_id 
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
    <title>Dashboard - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        
        /* Header */
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 1000; }
        .header-content { max-width: 1200px; margin: 0 auto; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .nav { display: flex; gap: 24px; align-items: center; }
        .nav a { text-decoration: none; color: #333; }
        .nav a:hover { color: #667eea; }
        .logout-btn { color: #dc3545 !important; }
        
        /* Container */
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        
        /* Welcome Banner */
        .welcome { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 24px; border-radius: 12px; margin-bottom: 24px; }
        .welcome h1 { font-size: 28px; margin-bottom: 8px; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); text-align: center; }
        .stat-value { font-size: 32px; font-weight: 700; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 8px; }
        
        /* Sections */
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .section-title a { font-size: 14px; color: #667eea; text-decoration: none; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        
        /* Quick Actions */
        .quick-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .action-btn { padding: 12px 24px; background: white; border: 1px solid #ddd; border-radius: 8px; text-decoration: none; color: #333; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .action-btn:hover { border-color: #667eea; color: #667eea; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <div class="nav">
                <a href="browse.php">Browse</a>
                <a href="listings.php">My Listings</a>
                <a href="wallet.php">Wallet</a>
                <a href="../auth/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="welcome">
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>! 👋</h1>
            <p>Your trusted marketplace for buying, selling, and renting</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo formatMoney($stats['balance']); ?></div>
                <div class="stat-label">Wallet Balance</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['active_listings']; ?></div>
                <div class="stat-label">Active Listings</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_sales']; ?></div>
                <div class="stat-label">Total Sales</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_purchases']; ?></div>
                <div class="stat-label">Total Purchases</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pending_transactions']; ?></div>
                <div class="stat-label">Pending Transactions</div>
            </div>
        </div>
        
        <div class="quick-actions">
            <a href="post_listing.php" class="action-btn"><i class="fas fa-plus-circle"></i> Post New Listing</a>
            <a href="browse.php" class="action-btn"><i class="fas fa-search"></i> Browse Items</a>
            <a href="wallet.php" class="action-btn"><i class="fas fa-wallet"></i> Manage Wallet</a>
            <a href="withdraw.php" class="action-btn"><i class="fas fa-money-bill-wave"></i> Withdraw Funds</a>
        </div>
        
        <div class="section">
            <div class="section-title">
                Recent Transactions
                <a href="transactions.php">View All →</a>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Type</th><th>Other Party</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php while($txn = $recentTransactions->fetch_assoc()): ?>
                            <tr>
                                <td>#<?php echo $txn['id']; ?></td>
                                <td><?php echo ucfirst($txn['action']); ?></td>
                                <td><?php echo htmlspecialchars($txn['other_party']); ?></td>
                                <td><?php echo formatMoney($txn['total_amount']); ?></td>
                                <td><?php echo getStatusBadge($txn['status']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($txn['created_at'])); ?></td>
                                <td><a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm btn-primary">View</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">
                My Listings
                <a href="listings.php">Manage →</a>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Title</th><th>Type</th><th>Price</th><th>Status</th><th>Views</th><th>Posted</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php while($listing = $recentListings->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($listing['title']); ?></td>
                                <td><?php echo ucfirst($listing['type']); ?></td>
                                <td><?php echo formatMoney($listing['price']); ?></td>
                                <td><?php echo $listing['status'] == 'active' ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-warning">' . ucfirst($listing['status']) . '</span>'; ?></td>
                                <td><?php echo $listing['views']; ?></td>
                                <td><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></td>
                                <td><a href="edit_listing.php?id=<?php echo $listing['id']; ?>" class="btn-sm btn-primary">Edit</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>