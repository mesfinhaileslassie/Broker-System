<?php
// user/listings.php - My Listings Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'My Listings';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get filter status
$status = $_GET['status'] ?? 'all';

// Build query
$where = "l.seller_id = $user_id";
if ($status == 'active') {
    $where .= " AND l.status = 'active' AND l.approval_status = 'approved'";
} elseif ($status == 'pending') {
    $where .= " AND l.approval_status = 'approved' AND l.status = 'pending'";
} elseif ($status == 'waiting') {
    $where .= " AND l.approval_status = 'pending'";
} elseif ($status == 'rejected') {
    $where .= " AND l.approval_status = 'rejected'";
}

$listings = $conn->query("
    SELECT l.*, c.name as category_name
    FROM listings l
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE $where
    ORDER BY l.created_at DESC
");

// Get counts for tabs
$counts = [
    'all' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'],
    'pending_payment' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'approved' AND status = 'pending'")->fetch_assoc()['count'],
    'waiting' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'")->fetch_assoc()['count'],
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'rejected'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 12px;
    }
    
    .tab {
        padding: 8px 20px;
        background: transparent;
        border-radius: 30px;
        text-decoration: none;
        color: #64748b;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .tab:hover {
        background: #f1f5f9;
        color: #334155;
    }
    
    .tab.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .tab .count {
        background: rgba(0,0,0,0.1);
        padding: 2px 6px;
        border-radius: 20px;
        margin-left: 6px;
        font-size: 11px;
    }
    
    .listings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
    }
    
    .listing-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .listing-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .card-image {
        height: 160px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
        position: relative;
    }
    
    .card-content {
        padding: 16px;
    }
    
    .listing-title {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #0f172a;
    }
    
    .listing-price {
        font-size: 18px;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 12px;
    }
    
    .listing-stats {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        font-size: 12px;
        color: #64748b;
    }
    
    .payment-info {
        background: #fef3c7;
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
        font-size: 12px;
    }
    
    .payment-info strong {
        color: #92400e;
    }
    
    .action-buttons {
        display: flex;
        gap: 10px;
        margin-top: 12px;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 10px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        text-align: center;
        transition: all 0.3s;
    }
    
    .btn-primary {
        background: #667eea;
        color: white;
    }
    
    .btn-primary:hover {
        background: #5a67d8;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
    }
    
    .btn-outline {
        border: 1px solid #e2e8f0;
        color: #64748b;
    }
    
    .btn-outline:hover {
        border-color: #667eea;
        color: #667eea;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
    
    @media (max-width: 768px) {
        .listings-grid {
            grid-template-columns: 1fr;
        }
        .tabs {
            overflow-x: auto;
            flex-wrap: nowrap;
        }
    }
</style>

<div class="page-header">
    <h1>My Listings</h1>
    <p>Manage your products, jobs, and rental listings</p>
</div>

<!-- Tabs -->
<div class="tabs">
    <a href="?status=all" class="tab <?php echo $status == 'all' ? 'active' : ''; ?>">
        All <span class="count"><?php echo $counts['all']; ?></span>
    </a>
    <a href="?status=active" class="tab <?php echo $status == 'active' ? 'active' : ''; ?>">
        Active <span class="count"><?php echo $counts['active']; ?></span>
    </a>
    <a href="?status=pending" class="tab <?php echo $status == 'pending' ? 'active' : ''; ?>">
        Need Payment <span class="count"><?php echo $counts['pending_payment']; ?></span>
    </a>
    <a href="?status=waiting" class="tab <?php echo $status == 'waiting' ? 'active' : ''; ?>">
        Pending Approval <span class="count"><?php echo $counts['waiting']; ?></span>
    </a>
    <a href="?status=rejected" class="tab <?php echo $status == 'rejected' ? 'active' : ''; ?>">
        Rejected <span class="count"><?php echo $counts['rejected']; ?></span>
    </a>
</div>

<?php if ($listings->num_rows > 0): ?>
    <div class="listings-grid">
        <?php while($listing = $listings->fetch_assoc()): ?>
            <div class="listing-card">
                <div class="card-image">
                    <?php
                    $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
                    echo $icons[$listing['type']];
                    ?>
                </div>
                <div class="card-content">
                    <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                    <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                    <div class="listing-stats">
                        <span class="badge <?php echo $listing['approval_status'] == 'approved' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo ucfirst($listing['approval_status']); ?>
                        </span>
                        <span><i class="fas fa-eye"></i> <?php echo $listing['views']; ?> views</span>
                    </div>
                    
                    <?php if ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending'): ?>
                        <?php
                        $deposit_amount = $listing['price'] * (($listing['admin_deposit_percent'] ?? 30) / 100);
                        $commission_amount = $listing['price'] * (($listing['admin_commission_percent'] ?? 15) / 100);
                        $total_payment = $deposit_amount + $commission_amount;
                        ?>
                        <div class="payment-info">
                            <strong>Payment Required to Activate:</strong><br>
                            Deposit: <?php echo formatMoney($deposit_amount); ?> + Commission: <?php echo formatMoney($commission_amount); ?>
                            = <?php echo formatMoney($total_payment); ?>
                        </div>
                        <div class="action-buttons">
                            <a href="pay_listing.php?listing_id=<?php echo $listing['id']; ?>" class="btn btn-success" style="flex: 1;">Pay Now to Activate</a>
                        </div>
                    <?php else: ?>
                        <div class="action-buttons">
                            <a href="product.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline" style="flex: 1;">View</a>
                            <a href="edit_listing.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline" style="flex: 1;">Edit</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-box-open"></i>
        <h3>No listings found</h3>
        <p>You haven't posted any listings yet.</p>
        <a href="post_listing.php" class="btn btn-primary" style="display: inline-block; margin-top: 16px;">Create Your First Listing</a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>