<?php
// user/dashboard.php - Complete Redesigned Dashboard with Working Notifications

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

// Set page title
$page_title = 'Dashboard';

// Start output buffering
ob_start();

// Include database and functions
require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

// Get user balance
$balance_query = $conn->query("SELECT balance FROM users WHERE id = $user_id");
$user_balance = 0;
if ($balance_query && $balance_query->num_rows > 0) {
    $user_data = $balance_query->fetch_assoc();
    $user_balance = $user_data['balance'];
    $_SESSION['user_balance'] = $user_balance;
}

// Get statistics
$stats = [
    'balance' => $user_balance,
    'active_listings' => 0,
    'pending_listings' => 0,
    'total_sales' => 0,
    'total_purchases' => 0,
    'pending_transactions' => 0,
    'total_earned' => 0,
];

// Get active listings count
$result = $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'");
if ($result && $result->num_rows > 0) {
    $stats['active_listings'] = $result->fetch_assoc()['count'];
}

// Get pending listings count
$result = $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'");
if ($result && $result->num_rows > 0) {
    $stats['pending_listings'] = $result->fetch_assoc()['count'];
}

// Get total sales
$result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE seller_id = $user_id AND status = 'completed'");
if ($result && $result->num_rows > 0) {
    $stats['total_sales'] = $result->fetch_assoc()['count'];
}

// Get total purchases
$result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE buyer_id = $user_id AND status = 'completed'");
if ($result && $result->num_rows > 0) {
    $stats['total_purchases'] = $result->fetch_assoc()['count'];
}

// Get pending transactions
$result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE (buyer_id = $user_id OR seller_id = $user_id) AND status NOT IN ('completed', 'cancelled')");
if ($result && $result->num_rows > 0) {
    $stats['pending_transactions'] = $result->fetch_assoc()['count'];
}

// Get total earned
$result = $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE seller_id = $user_id AND status = 'completed'");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $stats['total_earned'] = $row['total'] ?? 0;
}

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

// Get unread notifications count for badge
$unread_notifications = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count'];

// Get recent notifications for dropdown
$recent_notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 5
");

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --primary-dark: #5a67d8;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    /* Dashboard Container */
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
    }
    
    /* Welcome Section */
    .welcome-section {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .welcome-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .welcome-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .welcome-text h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .welcome-text p {
        font-size: 14px;
        opacity: 0.9;
    }
    
    .notification-bell {
        position: relative;
        cursor: pointer;
        background: rgba(255,255,255,0.2);
        padding: 12px;
        border-radius: 50%;
        transition: all 0.3s;
    }
    
    .notification-bell:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .notification-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: var(--danger);
        color: white;
        font-size: 10px;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 20px;
        min-width: 18px;
        text-align: center;
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
        padding: 24px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }

    .stat-icon {
        font-size: 32px;
        margin-bottom: 12px;
    }

    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
    }

    .stat-label {
        font-size: 13px;
        color: var(--gray);
        margin-top: 6px;
    }

    .stat-trend {
        font-size: 11px;
        margin-top: 8px;
        color: var(--warning);
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
        color: var(--dark);
        font-weight: 500;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }

    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        color: var(--primary);
        border-color: var(--primary);
    }

    /* Stepper */
    .stepper {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 28px;
        border: 1px solid var(--border);
    }

    .stepper-title {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 20px;
        color: var(--dark);
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
    }

    .step-circle {
        width: 40px;
        height: 40px;
        background: var(--light);
        border: 2px solid var(--border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 18px;
        transition: all 0.3s;
    }

    .step.completed .step-circle {
        background: var(--success);
        border-color: var(--success);
        color: white;
    }

    .step-label {
        font-size: 11px;
        font-weight: 500;
        color: var(--gray);
    }

    /* Cards */
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
    }

    .card-header h3 {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .card-header a {
        font-size: 12px;
        color: var(--primary);
        text-decoration: none;
        transition: all 0.3s;
    }
    
    .card-header a:hover {
        text-decoration: underline;
    }

    /* Listings Grid */
    .listings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 20px;
    }

    .listing-card {
        background: var(--light);
        border-radius: 16px;
        padding: 16px;
        transition: all 0.3s;
        cursor: pointer;
        border: 1px solid var(--border);
    }

    .listing-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border-color: var(--primary);
    }

    .listing-title {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--dark);
    }

    .listing-price {
        font-size: 16px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 8px;
    }

    .listing-stats {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: var(--gray);
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
        border-bottom: 1px solid var(--border);
        font-size: 13px;
    }

    th {
        font-weight: 600;
        color: var(--gray);
    }

    tr {
        cursor: pointer;
        transition: background 0.3s;
    }

    tr:hover {
        background: var(--light);
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

    .btn-sm {
        padding: 4px 10px;
        font-size: 11px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .btn-primary { background: var(--primary); color: white; }
    
    /* Notification Dropdown */
    .notification-dropdown {
        position: relative;
        display: inline-block;
    }
    
    .dropdown-menu {
        position: absolute;
        top: 100%;
        right: 0;
        width: 380px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        display: none;
        z-index: 1000;
        margin-top: 10px;
        border: 1px solid var(--border);
    }
    
    .dropdown-menu.show {
        display: block;
        animation: dropdownFade 0.3s ease;
    }
    
    @keyframes dropdownFade {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .dropdown-header {
        padding: 16px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .dropdown-header h4 {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .dropdown-header a {
        font-size: 11px;
        color: var(--primary);
        text-decoration: none;
    }
    
    .dropdown-header a:hover {
        text-decoration: underline;
    }
    
    .notification-item-dropdown {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        cursor: pointer;
        transition: background 0.3s;
    }
    
    .notification-item-dropdown:hover {
        background: var(--light);
    }
    
    .notification-item-dropdown.unread {
        background: #eef2ff;
    }
    
    .notification-title-dropdown {
        font-size: 13px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .notification-message-dropdown {
        font-size: 11px;
        color: var(--gray);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .notification-time-dropdown {
        font-size: 10px;
        color: var(--gray);
        margin-top: 4px;
    }
    
    @media (max-width: 768px) {
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
        .welcome-content {
            flex-direction: column;
            text-align: center;
        }
        .dropdown-menu {
            width: 300px;
            right: -50px;
        }
    }
    
    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="dashboard-container">
    <!-- Welcome Section with Notification Bell -->
    <div class="welcome-section">
        <div class="welcome-content">
            <div class="welcome-text">
                <h1>Welcome back, <?php echo htmlspecialchars($user_name); ?>! 👋</h1>
                <p>Here's what's happening with your account today</p>
            </div>
            <div class="notification-dropdown">
                <div class="notification-bell" id="notificationBell">
                    <i class="fas fa-bell fa-lg"></i>
                    <?php if ($unread_notifications > 0): ?>
                        <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </div>
                <div class="dropdown-menu" id="notificationDropdown">
                    <div class="dropdown-header">
                        <h4><i class="fas fa-bell"></i> Notifications</h4>
                        <?php if ($unread_notifications > 0): ?>
                            <a href="notifications.php?mark_all_read=1">Mark all as read</a>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-notifications">
                        <?php if ($recent_notifications && $recent_notifications->num_rows > 0): ?>
                            <?php while($notif = $recent_notifications->fetch_assoc()): ?>
                                <div class="notification-item-dropdown <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="location.href='notifications.php'">
                                    <div class="notification-title-dropdown"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notification-message-dropdown"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notification-time-dropdown"><?php echo timeAgo($notif['created_at']); ?></div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="padding: 40px; text-align: center; color: var(--gray);">
                                <i class="fas fa-bell-slash" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
                                No new notifications
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-header" style="border-top: 1px solid var(--border);">
                        <a href="notifications.php" style="width: 100%; text-align: center;">View all notifications →</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="post_listing.php" class="action-btn"><i class="fas fa-plus-circle"></i> New Listing</a>
        <a href="browse.php" class="action-btn"><i class="fas fa-search"></i> Browse</a>
        <a href="wallet.php" class="action-btn"><i class="fas fa-wallet"></i> Wallet</a>
        <a href="chat.php" class="action-btn"><i class="fas fa-comments"></i> Messages</a>
        <a href="notifications.php" class="action-btn"><i class="fas fa-bell"></i> Notifications</a>
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
                <div class="stat-trend"><?php echo $stats['pending_listings']; ?> pending approval</div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📈</div>
            <div class="stat-value"><?php echo $stats['total_sales']; ?></div>
            <div class="stat-label">Total Sales</div>
            <div class="stat-trend" style="color: var(--success);">Earned: <?php echo formatMoney($stats['total_earned']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🛒</div>
            <div class="stat-value"><?php echo $stats['total_purchases']; ?></div>
            <div class="stat-label">Total Purchases</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?php echo $stats['pending_transactions']; ?></div>
            <div class="stat-label">Pending Transactions</div>
        </div>
    </div>

    <!-- Escrow Process Stepper -->
    <div class="stepper">
        <div class="stepper-title"><i class="fas fa-shield-alt"></i> How Escrow Works</div>
        <div class="steps">
            <div class="step completed">
                <div class="step-circle">💳</div>
                <div class="step-label">Buyer Pays</div>
            </div>
            <div class="step">
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
            <?php if ($recentTransactions && $recentTransactions->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr><th>ID</th><th>Item</th><th>Type</th><th>Amount</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php while($txn = $recentTransactions->fetch_assoc()): ?>
                            <tr onclick="location.href='transaction.php?id=<?php echo $txn['id']; ?>'">
                                <td>#<?php echo $txn['id']; ?></td>
                                <td><?php echo htmlspecialchars(substr($txn['listing_title'], 0, 25)); ?></td>
                                <td><span class="badge <?php echo $txn['action'] == 'bought' ? 'badge-info' : 'badge-success'; ?>"><?php echo ucfirst($txn['action']); ?></span></td>
                                <td><?php echo formatMoney($txn['total_amount']); ?></td>
                                <td><?php echo getStatusBadge($txn['status']); ?></td>
                                <td><a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">View</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--gray);">No transactions yet</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Listings -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-box"></i> Recent Listings</h3>
            <a href="listings.php">View All →</a>
        </div>
        <div class="listings-grid">
            <?php if ($recentListings && $recentListings->num_rows > 0): ?>
                <?php while($listing = $recentListings->fetch_assoc()): ?>
                    <div class="listing-card" onclick="location.href='product.php?id=<?php echo $listing['id']; ?>'">
                        <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                        <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                        <div class="listing-stats">
                            <span class="badge <?php echo $listing['approval_status'] == 'approved' ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo ucfirst($listing['approval_status']); ?>
                            </span>
                            <span><i class="fas fa-eye"></i> <?php echo $listing['views']; ?> views</span>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="listing-card" style="text-align: center; color: var(--gray);">No listings yet</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Notification dropdown toggle
const notificationBell = document.getElementById('notificationBell');
const notificationDropdown = document.getElementById('notificationDropdown');

if (notificationBell) {
    notificationBell.addEventListener('click', function(e) {
        e.stopPropagation();
        notificationDropdown.classList.toggle('show');
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function() {
    if (notificationDropdown) {
        notificationDropdown.classList.remove('show');
    }
});

// Prevent closing when clicking inside dropdown
if (notificationDropdown) {
    notificationDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}
</script>

<?php
// Get the content and include the layout
$content = ob_get_clean();
include '../includes/layout.php';
?>