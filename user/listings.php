<?php
// user/listings.php - My Listings Page with Images

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
    
    .page-header p {
        color: #64748b;
        font-size: 14px;
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
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 24px;
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
        height: 200px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
        overflow: hidden;
        position: relative;
    }
    
    .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .card-content {
        padding: 20px;
    }
    
    .listing-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #0f172a;
    }
    
    .listing-price {
        font-size: 20px;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 12px;
    }
    
    .listing-stats {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        font-size: 13px;
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
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-info { background: #dbeafe; color: #2563eb; }
    
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
        flex: 1;
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
        background: white;
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
    
    .empty-state h3 {
        font-size: 20px;
        color: #334155;
        margin-bottom: 8px;
    }
    
    .empty-state p {
        color: #64748b;
    }
    
    @media (max-width: 768px) {
        .listings-grid {
            grid-template-columns: 1fr;
        }
        .tabs {
            overflow-x: auto;
            flex-wrap: nowrap;
        }
        .action-buttons {
            flex-direction: column;
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
        <?php while($listing = $listings->fetch_assoc()): 
            $cover_image = $listing['cover_image'] ? '/broker_system/uploads/listings/' . $listing['cover_image'] : '';
            $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
        ?>
            <div class="listing-card">
                <div class="card-image">
                    <?php if ($cover_image && file_exists(str_replace('/broker_system/', '../', $cover_image))): ?>
                        <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>">
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">
                            <?php echo $icons[$listing['type']]; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-content">
                    <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                    <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                    <div class="listing-stats">
                        <?php
                        $status_badge = '';
                        if ($listing['approval_status'] == 'approved' && $listing['status'] == 'active') {
                            $status_badge = '<span class="badge badge-success">Active</span>';
                        } elseif ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending') {
                            $status_badge = '<span class="badge badge-warning">Awaiting Payment</span>';
                        } elseif ($listing['approval_status'] == 'pending') {
                            $status_badge = '<span class="badge badge-warning">Pending Approval</span>';
                        } elseif ($listing['approval_status'] == 'rejected') {
                            $status_badge = '<span class="badge badge-danger">Rejected</span>';
                        } else {
                            $status_badge = '<span class="badge badge-info">' . ucfirst($listing['approval_status']) . '</span>';
                        }
                        echo $status_badge;
                        ?>
                        <span><i class="fas fa-eye"></i> <?php echo $listing['views']; ?> views</span>
                    </div>
                    
                    <?php if ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending'): ?>
                        <?php
                        $deposit_percent = $listing['admin_deposit_percent'] ?? 30;
                        $commission_percent = $listing['admin_commission_percent'] ?? 15;
                        $deposit_amount = $listing['price'] * ($deposit_percent / 100);
                        $commission_amount = $listing['price'] * ($commission_percent / 100);
                        $total_payment = $deposit_amount + $commission_amount;
                        ?>
                        <div class="payment-info">
                            <strong>Payment Required to Activate:</strong><br>
                            Deposit (<?php echo $deposit_percent; ?>%): <?php echo formatMoney($deposit_amount); ?> + 
                            Commission (<?php echo $commission_percent; ?>%): <?php echo formatMoney($commission_amount); ?>
                            = <strong><?php echo formatMoney($total_payment); ?></strong>
                        </div>
                        <div class="action-buttons">
                            <a href="pay_listing.php?listing_id=<?php echo $listing['id']; ?>" class="btn btn-success">
                                <i class="fas fa-credit-card"></i> Pay Now
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="action-buttons">
                            <a href="product.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="edit_listing.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline">
                                <i class="fas fa-edit"></i> Edit
                            </a>
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
        <a href="post_listing.php" class="btn btn-primary" style="display: inline-block; margin-top: 16px; padding: 10px 24px;">
            <i class="fas fa-plus-circle"></i> Create Your First Listing
        </a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>