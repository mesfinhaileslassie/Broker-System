<?php
// user/dashboard.php - Complete Dashboard Page

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

// Get statistics with error handling
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

$conn->close();
?>

<style>
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
        color: #f59e0b;
    }

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

    .step.completed .step-circle {
        background: #10b981;
        color: white;
    }

    .step-label {
        font-size: 11px;
        font-weight: 500;
        color: #64748b;
    }

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

    .btn-primary { background: #667eea; color: white; }

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
    }
</style>

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
            <div class="stat-trend"><?php echo $stats['pending_listings']; ?> pending approval</div>
        <?php endif; ?>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📈</div>
        <div class="stat-value"><?php echo $stats['total_sales']; ?></div>
        <div class="stat-label">Total Sales</div>
        <div class="stat-trend" style="color: #10b981;">Earned: <?php echo formatMoney($stats['total_earned']); ?></div>
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
    <div class="stepper-title">How Escrow Works</div>
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
                    <tr><th>ID</th><th>Item</th><th>Type</th><th>Amount</th><th>Status</th><th></th>
                </thead>
                <tbody>
                    <?php while($txn = $recentTransactions->fetch_assoc()): ?>
                        <tr onclick="location.href='transaction.php?id=<?php echo $txn['id']; ?>'" style="cursor:pointer;">
                            <td>#<?php echo $txn['id']; ?></td>
                            <td><?php echo htmlspecialchars(substr($txn['listing_title'], 0, 25)); ?></td>
                            <td><span class="badge <?php echo $txn['action'] == 'bought' ? 'badge-info' : 'badge-success'; ?>"><?php echo ucfirst($txn['action']); ?></span></td>
                            <td><?php echo formatMoney($txn['total_amount']); ?></td>
                            <td><?php echo getStatusBadge($txn['status']); ?></td>
                            <td><a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">👁 View</a></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; padding: 40px; color: #64748b;">No transactions yet</p>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Listings -->
<div class="card" style="margin-bottom: 0;">
    <div class="card-header">
        <h3><i class="fas fa-box"></i> Recent Listings</h3>
        <a href="listings.php">View All →</a>
    </div>
    <div class="listings-grid">
        <?php if ($recentListings && $recentListings->num_rows > 0): ?>
            <?php while($listing = $recentListings->fetch_assoc()): ?>
                <div class="listing-card" onclick="location.href='product.php?id=<?php echo $listing['id']; ?>'" style="cursor:pointer;">
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

<?php
// Get the content and include the layout
$content = ob_get_clean();
include '../includes/layout.php';
?>