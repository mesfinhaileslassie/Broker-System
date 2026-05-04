<?php
// user/dashboard.php - Complete User Dashboard

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Mark notifications as read if requested
if (isset($_GET['mark_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    header('Location: dashboard.php');
    exit;
}

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$_SESSION['user_balance'] = $user['balance'];

// Get user stats
$stats = [
    'balance' => $user['balance'],
    'active_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'],
    'pending_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'")->fetch_assoc()['count'],
    'approved_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'approved' AND status = 'pending'")->fetch_assoc()['count'],
    'total_sales' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE seller_id = $user_id AND status = 'completed'")->fetch_assoc()['count'],
    'total_purchases' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE buyer_id = $user_id AND status = 'completed'")->fetch_assoc()['count'],
    'pending_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE (buyer_id = $user_id OR seller_id = $user_id) AND status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_earned' => $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE seller_id = $user_id AND status = 'completed'")->fetch_assoc()['total'] ?? 0,
    'total_spent' => $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE buyer_id = $user_id AND status = 'completed'")->fetch_assoc()['total'] ?? 0,
];

// Get unread notifications
$notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE user_id = $user_id AND is_read = 0 
    ORDER BY created_at DESC 
    LIMIT 10
");

// Get recent transactions
$recentTransactions = $conn->query("
    SELECT t.*, 
           l.title as listing_title,
           CASE WHEN t.buyer_id = $user_id THEN 'bought' ELSE 'sold' END as action,
           CASE WHEN t.buyer_id = $user_id THEN u2.full_name ELSE u1.full_name END as other_party
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.buyer_id = $user_id OR t.seller_id = $user_id
    ORDER BY t.created_at DESC
    LIMIT 8
");

// Get recent listings
$recentListings = $conn->query("
    SELECT * FROM listings 
    WHERE seller_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 6
");

// Get pending payment listings (approved but not paid)
$pendingPaymentListings = $conn->query("
    SELECT * FROM listings 
    WHERE seller_id = $user_id AND approval_status = 'approved' AND status = 'pending'
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
        .header-content { max-width: 1400px; margin: 0 auto; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .nav { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        .nav a { text-decoration: none; color: #333; transition: color 0.3s; }
        .nav a:hover { color: #667eea; }
        .logout-btn { color: #dc3545 !important; }
        .notification-badge { position: relative; }
        .badge-count { position: absolute; top: -8px; right: -12px; background: #dc3545; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; }
        
        /* Container */
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        
        /* Welcome Banner */
        .welcome { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 32px; border-radius: 16px; margin-bottom: 24px; }
        .welcome h1 { font-size: 28px; margin-bottom: 8px; }
        .welcome p { opacity: 0.9; }
        
        /* Notification Section */
        .notifications-section { background: #e3f2fd; border-radius: 12px; padding: 16px; margin-bottom: 24px; }
        .notifications-section h4 { margin-bottom: 12px; color: #004085; }
        .notification-item { padding: 10px; border-bottom: 1px solid #cce5ff; }
        .notification-item:last-child { border-bottom: none; }
        .notification-title { font-weight: 600; color: #004085; }
        .notification-message { font-size: 13px; color: #555; margin-top: 4px; }
        .notification-time { font-size: 11px; color: #888; margin-top: 4px; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s; text-align: center; }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-icon { font-size: 32px; margin-bottom: 12px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #333; }
        .stat-label { color: #666; font-size: 13px; margin-top: 8px; }
        .stat-trend { font-size: 11px; margin-top: 6px; color: #28a745; }
        
        /* Quick Actions */
        .quick-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 32px; }
        .action-btn { padding: 14px 24px; background: white; border: 1px solid #ddd; border-radius: 10px; text-decoration: none; color: #333; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s; }
        .action-btn:hover { border-color: #667eea; color: #667eea; transform: translateY(-2px); }
        
        /* Section */
        .section { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .section-title a { font-size: 13px; color: #667eea; text-decoration: none; }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        tr:hover { background: #f8f9fa; cursor: pointer; }
        
        /* Badges */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-primary { background: #cce5ff; color: #004085; }
        
        /* Buttons */
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        
        /* Pending Payment Alert */
        .alert { background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; border-radius: 8px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .alert-warning { color: #856404; }
        .alert a { background: #ffc107; color: #333; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 500; }
        
        /* How It Works */
        .how-it-works { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center; }
        .step { padding: 16px; }
        .step-icon { font-size: 32px; margin-bottom: 8px; }
        .step-title { font-weight: 600; margin-bottom: 4px; }
        .step-desc { font-size: 12px; color: #666; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .quick-actions { flex-direction: column; }
            .action-btn { justify-content: center; }
            table { font-size: 12px; }
            th, td { padding: 8px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <div class="nav">
                <a href="browse.php"><i class="fas fa-search"></i> Browse</a>
                <a href="listings.php"><i class="fas fa-list"></i> My Listings</a>
                <a href="wallet.php"><i class="fas fa-wallet"></i> <?php echo formatMoney($_SESSION['user_balance']); ?></a>
                <div class="notification-badge">
                    <a href="#">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifications->num_rows > 0): ?>
                            <span class="badge-count"><?php echo $notifications->num_rows; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <span style="color: #666;">👋 <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <a href="../auth/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <!-- Welcome Banner -->
        <div class="welcome">
            <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>! 👋</h1>
            <p>Your trusted marketplace for buying, selling, and renting with secure escrow payments</p>
        </div>
        
        <!-- User Notifications -->
        <?php if ($notifications->num_rows > 0): ?>
        <div class="notifications-section">
            <h4><i class="fas fa-bell"></i> Notifications (<?php echo $notifications->num_rows; ?> unread)</h4>
            <?php while($notif = $notifications->fetch_assoc()): ?>
                <div class="notification-item">
                    <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                    <div class="notification-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                    <div class="notification-time"><?php echo timeAgo($notif['created_at']); ?></div>
                </div>
            <?php endwhile; ?>
            <a href="?mark_read=1" style="font-size: 12px; margin-top: 8px; display: inline-block;">Mark all as read</a>
        </div>
        <?php endif; ?>
        
        <!-- Pending Payment Alert -->
        <?php if ($pendingPaymentListings && $pendingPaymentListings->num_rows > 0): ?>
        <div class="alert">
            <div class="alert-warning">
                <i class="fas fa-clock"></i> <strong><?php echo $pendingPaymentListings->num_rows; ?> listing(s) approved!</strong>
                <span>Pay the required deposit and commission to activate your listings.</span>
            </div>
            <a href="listings.php?status=pending">Pay Now <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php endif; ?>
        
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
                    <div class="stat-trend"><i class="fas fa-clock"></i> <?php echo $stats['pending_listings']; ?> pending approval</div>
                <?php endif; ?>
                <?php if ($stats['approved_listings'] > 0): ?>
                    <div class="stat-trend"><i class="fas fa-credit-card"></i> <?php echo $stats['approved_listings']; ?> awaiting payment</div>
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
                <div class="stat-trend">Spent: <?php echo formatMoney($stats['total_spent']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-value"><?php echo $stats['pending_transactions']; ?></div>
                <div class="stat-label">Pending Transactions</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="post_listing.php" class="action-btn"><i class="fas fa-plus-circle"></i> Post New Listing</a>
            <a href="browse.php" class="action-btn"><i class="fas fa-search"></i> Browse Items</a>
            <a href="wallet.php" class="action-btn"><i class="fas fa-wallet"></i> Manage Wallet</a>
            <a href="withdraw.php" class="action-btn"><i class="fas fa-money-bill-wave"></i> Withdraw Funds</a>
            <a href="listings.php?status=pending" class="action-btn"><i class="fas fa-clock"></i> Pending Approvals</a>
        </div>
        
        <!-- Recent Transactions -->
        <div class="section">
            <div class="section-title">
                Recent Transactions
                <a href="transactions.php">View All →</a>
            </div>
            <?php if ($recentTransactions->num_rows > 0): ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Item</th><th>Type</th><th>Other Party</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php while($txn = $recentTransactions->fetch_assoc()): ?>
                                <tr onclick="location.href='transaction.php?id=<?php echo $txn['id']; ?>'">
                                    <td>#<?php echo $txn['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars(substr($txn['listing_title'], 0, 30)); ?></strong></td>
                                    <td>
                                        <span class="badge <?php echo $txn['action'] == 'bought' ? 'badge-info' : 'badge-success'; ?>">
                                            <?php echo ucfirst($txn['action']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($txn['other_party']); ?></td>
                                    <td><strong><?php echo formatMoney($txn['total_amount']); ?></strong></td>
                                    <td><?php echo getStatusBadge($txn['status']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($txn['created_at'])); ?></td>
                                    <td>
                                        <a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">View</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #888; padding: 40px;">No transactions yet. <a href="browse.php">Start shopping!</a></p>
            <?php endif; ?>
        </div>
        
        <!-- My Recent Listings -->
        <div class="section">
            <div class="section-title">
                My Recent Listings
                <a href="listings.php">Manage All →</a>
            </div>
            <?php if ($recentListings->num_rows > 0): ?>
                <div style="overflow-x: auto;">
                    </table>
                        <thead>
                            <tr><th>Title</th><th>Type</th><th>Price</th><th>Approval Status</th><th>Listing Status</th><th>Views</th><th>Posted</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php while($listing = $recentListings->fetch_assoc()): ?>
                                <tr onclick="location.href='product.php?id=<?php echo $listing['id']; ?>'">
                                    <td><strong><?php echo htmlspecialchars($listing['title']); ?></strong></td>
                                    <td><?php echo ucfirst($listing['type']); ?></td>
                                    <td><?php echo formatMoney($listing['price']); ?></td>
                                    <td>
                                        <?php
                                        if ($listing['approval_status'] == 'pending') {
                                            echo '<span class="badge badge-warning">⏳ Pending</span>';
                                        } elseif ($listing['approval_status'] == 'approved') {
                                            echo '<span class="badge badge-success">✓ Approved</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">✗ Rejected</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($listing['status'] == 'active') {
                                            echo '<span class="badge badge-success">Active</span>';
                                        } elseif ($listing['status'] == 'pending') {
                                            echo '<span class="badge badge-warning">Awaiting Payment</span>';
                                        } else {
                                            echo '<span class="badge badge-secondary">' . ucfirst($listing['status']) . '</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $listing['views']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></td>
                                    <td>
                                        <a href="edit_listing.php?id=<?php echo $listing['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">Edit</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #888; padding: 40px;">No listings yet. <a href="post_listing.php">Create your first listing!</a></p>
            <?php endif; ?>
        </div>
        
        <!-- How Escrow Works -->
        <div class="section">
            <div class="section-title">How Escrow Payment Works</div>
            <div class="how-it-works">
                <div class="step">
                    <div class="step-icon">1️⃣</div>
                    <div class="step-title">Buyer pays deposit + commission</div>
                    <div class="step-desc">Funds held securely in escrow</div>
                </div>
                <div class="step">
                    <div class="step-icon">2️⃣</div>
                    <div class="step-title">Seller pays deposit</div>
                    <div class="step-desc">Shows commitment to the deal</div>
                </div>
                <div class="step">
                    <div class="step-icon">3️⃣</div>
                    <div class="step-title">Item delivered/service completed</div>
                    <div class="step-desc">Seller fulfills the order</div>
                </div>
                <div class="step">
                    <div class="step-icon">4️⃣</div>
                    <div class="step-title">Buyer confirms</div>
                    <div class="step-desc">Payment released to seller</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>