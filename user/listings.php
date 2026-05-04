<?php
// user/listings.php - Manage listings with payment for approved listings

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle payment for approved listing
if (isset($_GET['pay']) && isset($_GET['listing_id'])) {
    $listing_id = intval($_GET['listing_id']);
    
    // Get listing details
    $listing = $conn->query("
        SELECT l.*, 
               l.admin_deposit_percent as deposit_percent, 
               l.admin_commission_percent as commission_percent
        FROM listings l
        WHERE l.id = $listing_id AND l.seller_id = $user_id AND l.approval_status = 'approved' AND l.status = 'pending'
    ")->fetch_assoc();
    
    if ($listing) {
        $deposit_amount = $listing['price'] * ($listing['deposit_percent'] / 100);
        $commission_amount = $listing['price'] * ($listing['commission_percent'] / 100);
        $total_amount = $deposit_amount + $commission_amount;
        
        // Generate payment code
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // Store payment code in session for verification
        $_SESSION['pending_payment'] = [
            'listing_id' => $listing_id,
            'amount' => $total_amount,
            'code' => $payment_code,
            'expires_at' => $expires_at,
            'deposit_percent' => $listing['deposit_percent'],
            'commission_percent' => $listing['commission_percent'],
            'price' => $listing['price']
        ];
        
        // Redirect to payment page
        header("Location: pay_listing.php?code=$payment_code&amount=$total_amount&listing_id=$listing_id");
        exit;
    } else {
        $error = "Listing not found or already paid.";
    }
}

// Get user's listings with different statuses
$status = $_GET['status'] ?? 'all';

$where = "l.seller_id = $user_id";
if ($status == 'pending') {
    $where .= " AND l.approval_status = 'approved' AND l.status = 'pending'";
} elseif ($status == 'approved') {
    $where .= " AND l.approval_status = 'approved' AND l.status = 'active'";
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
    'pending_payment' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'approved' AND status = 'pending'")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'approved' AND status = 'active'")->fetch_assoc()['count'],
    'waiting' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'")->fetch_assoc()['count'],
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'rejected'")->fetch_assoc()['count'],
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Listings - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 1200px; margin: 40px auto; padding: 0 24px; }
        
        /* Tabs */
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
        .tab { padding: 10px 20px; background: white; border-radius: 8px; text-decoration: none; color: #333; transition: all 0.3s; }
        .tab:hover { background: #667eea; color: white; }
        .tab.active { background: #667eea; color: white; }
        .tab .count { background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 20px; margin-left: 8px; font-size: 12px; }
        
        /* Cards */
        .listing-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; gap: 20px; flex-wrap: wrap; }
        .listing-image { width: 120px; height: 120px; background: #f0f0f0; border-radius: 8px; overflow: hidden; }
        .listing-image img { width: 100%; height: 100%; object-fit: cover; }
        .listing-info { flex: 1; }
        .listing-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
        .listing-price { font-size: 20px; font-weight: 700; color: #667eea; margin-bottom: 8px; }
        .listing-details { color: #666; font-size: 13px; margin-bottom: 12px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; margin-right: 8px; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; text-decoration: none; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 12px; }
        .empty-state i { font-size: 48px; color: #ccc; margin-bottom: 16px; }
        
        @media (max-width: 768px) {
            .listing-card { flex-direction: column; }
            .listing-image { width: 100%; height: 180px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="dashboard.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>
    
    <div class="container">
        <h1 style="margin-bottom: 24px;"><i class="fas fa-list"></i> My Listings</h1>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="?status=all" class="tab <?php echo $status == 'all' ? 'active' : ''; ?>">
                All <span class="count"><?php echo $counts['all']; ?></span>
            </a>
            <a href="?status=pending" class="tab <?php echo $status == 'pending' ? 'active' : ''; ?>">
                Need Payment <span class="count"><?php echo $counts['pending_payment']; ?></span>
            </a>
            <a href="?status=approved" class="tab <?php echo $status == 'approved' ? 'active' : ''; ?>">
                Active <span class="count"><?php echo $counts['active']; ?></span>
            </a>
            <a href="?status=waiting" class="tab <?php echo $status == 'waiting' ? 'active' : ''; ?>">
                Pending Approval <span class="count"><?php echo $counts['waiting']; ?></span>
            </a>
            <a href="?status=rejected" class="tab <?php echo $status == 'rejected' ? 'active' : ''; ?>">
                Rejected <span class="count"><?php echo $counts['rejected']; ?></span>
            </a>
        </div>
        
        <?php if ($error): ?>
            <div class="message message-error" style="background:#f8d7da; color:#721c24; padding:12px; border-radius:8px; margin-bottom:20px;"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($listings->num_rows > 0): ?>
            <?php while($listing = $listings->fetch_assoc()): ?>
                <div class="listing-card">
                    <div class="listing-image">
                        <?php if ($listing['cover_image']): ?>
                            <img src="/broker_system/uploads/listings/<?php echo $listing['cover_image']; ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>">
                        <?php else: ?>
                            <div style="display: flex; align-items: center; justify-content: center; height: 100%; background: #e0e0e0;">
                                <i class="fas fa-image" style="font-size: 32px; color: #999;"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="listing-info">
                        <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                        <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                        <div class="listing-details">
                            <span class="badge badge-info"><?php echo ucfirst($listing['type']); ?></span>
                            <?php
                            if ($listing['approval_status'] == 'pending') {
                                echo '<span class="badge badge-warning">⏳ Pending Approval</span>';
                            } elseif ($listing['approval_status'] == 'approved') {
                                if ($listing['status'] == 'pending') {
                                    echo '<span class="badge badge-warning">💰 Payment Required</span>';
                                } else {
                                    echo '<span class="badge badge-success">✓ Active</span>';
                                }
                            } elseif ($listing['approval_status'] == 'rejected') {
                                echo '<span class="badge badge-danger">✗ Rejected</span>';
                            }
                            ?>
                        </div>
                        
                        <?php if ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending'): ?>
                            <?php
                            $deposit_amount = $listing['price'] * (($listing['admin_deposit_percent'] ?? 30) / 100);
                            $commission_amount = $listing['price'] * (($listing['admin_commission_percent'] ?? 15) / 100);
                            $total_payment = $deposit_amount + $commission_amount;
                            ?>
                            <div style="background: #e3f2fd; padding: 12px; border-radius: 8px; margin: 12px 0;">
                                <strong>Payment Required to Activate:</strong><br>
                                Deposit (<?php echo $listing['admin_deposit_percent'] ?? 30; ?>%): <?php echo formatMoney($deposit_amount); ?><br>
                                Commission (<?php echo $listing['admin_commission_percent'] ?? 15; ?>%): <?php echo formatMoney($commission_amount); ?><br>
                                <strong>Total: <?php echo formatMoney($total_payment); ?></strong>
                            </div>
                            <a href="?pay=1&listing_id=<?php echo $listing['id']; ?>" class="btn btn-success">
                                <i class="fas fa-credit-card"></i> Pay Now to Activate
                            </a>
                        <?php elseif ($listing['approval_status'] == 'approved' && $listing['status'] == 'active'): ?>
                            <a href="product.php?id=<?php echo $listing['id']; ?>" class="btn btn-primary btn-sm">View Listing</a>
                            <a href="edit_listing.php?id=<?php echo $listing['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                        <?php elseif ($listing['approval_status'] == 'pending'): ?>
                            <div class="listing-details" style="color: #856404;">
                                <i class="fas fa-clock"></i> This listing is waiting for admin approval. You'll be notified once reviewed.
                            </div>
                        <?php elseif ($listing['approval_status'] == 'rejected' && $listing['admin_notes']): ?>
                            <div class="listing-details" style="color: #721c24;">
                                <i class="fas fa-comment"></i> Rejection reason: <?php echo htmlspecialchars($listing['admin_notes']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No listings found</h3>
                <p>You haven't posted any listings yet.</p>
                <a href="post_listing.php" class="btn btn-primary" style="margin-top: 16px;">Create Your First Listing</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>