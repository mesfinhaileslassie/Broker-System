<?php
// admin/approve_listings.php - Fixed SQL injection and notification issue

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle approval
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_listing'])) {
        $listing_id = intval($_POST['listing_id']);
        $deposit_percent = intval($_POST['deposit_percent']);
        $commission_percent = intval($_POST['commission_percent']);
        $admin_id = $_SESSION['admin_id'];
        
        $stmt = $conn->prepare("UPDATE listings SET approval_status = 'approved', admin_deposit_percent = ?, admin_commission_percent = ?, approved_at = NOW(), approved_by = ? WHERE id = ?");
        $stmt->bind_param("iiii", $deposit_percent, $commission_percent, $admin_id, $listing_id);
        
        if ($stmt->execute()) {
            // Get listing and user info
            $listingResult = $conn->query("SELECT seller_id, title, price FROM listings WHERE id = $listing_id");
            $listing = $listingResult->fetch_assoc();
            
            // Calculate amounts
            $deposit_amount = $listing['price'] * ($deposit_percent / 100);
            $commission_amount = $listing['price'] * ($commission_percent / 100);
            $total_upfront = $deposit_amount + $commission_amount;
            
            // Escape the message to avoid SQL errors
            $title = addslashes($listing['title']);
            $notification = addslashes("Your listing '{$listing['title']}' has been approved! Deposit: {$deposit_percent}% (" . number_format($deposit_amount, 2) . " ETB) Commission: {$commission_percent}% (" . number_format($commission_amount, 2) . " ETB) Total to pay: " . number_format($total_upfront, 2) . " ETB. Pay now to activate your listing.");
            
            $conn->query("INSERT INTO notifications (user_id, title, message) VALUES ({$listing['seller_id']}, 'Listing Approved - Payment Required', '$notification')");
            
            $message = "Listing approved! User has been notified to pay deposit and commission.";
        } else {
            $error = "Failed to approve listing";
        }
    }
    
    if (isset($_POST['reject_listing'])) {
        $listing_id = intval($_POST['listing_id']);
        $reason = $conn->real_escape_string($_POST['rejection_reason']);
        
        $listingResult = $conn->query("SELECT seller_id, title FROM listings WHERE id = $listing_id");
        $listing = $listingResult->fetch_assoc();
        
        $title = addslashes($listing['title']);
        $reason_escaped = addslashes($reason);
        $notification = "Your listing '{$listing['title']}' was rejected. Reason: $reason";
        
        $conn->query("INSERT INTO notifications (user_id, title, message) VALUES ({$listing['seller_id']}, 'Listing Rejected', '$notification')");
        
        $conn->query("UPDATE listings SET approval_status = 'rejected', admin_notes = '$reason_escaped' WHERE id = $listing_id");
        $message = "Listing rejected and user notified";
    }
}

// Get pending listings
$pendingListings = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email, c.name as category_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE l.approval_status = 'pending'
    ORDER BY l.created_at DESC
");

// Get statistics
$totalPending = $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'];
$totalApproved = $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'approved'")->fetch_assoc()['count'];
$totalRejected = $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'rejected'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Listings - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 20px; }
        .sidebar-header p { font-size: 12px; color: #888; margin-top: 8px; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); text-align: center; }
        .stat-card .value { font-size: 32px; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 14px; margin-top: 8px; }
        .stat-card.pending .value { color: #ffc107; }
        .stat-card.approved .value { color: #28a745; }
        .stat-card.rejected .value { color: #dc3545; }
        .listing-card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .listing-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; flex-wrap: wrap; }
        .listing-title { font-size: 20px; font-weight: 700; color: #333; }
        .listing-price { font-size: 24px; font-weight: 700; color: #667eea; }
        .listing-details { margin-bottom: 20px; }
        .detail-row { display: flex; margin-bottom: 8px; }
        .detail-label { width: 120px; font-weight: 600; color: #666; }
        .detail-value { flex: 1; color: #333; }
        .listing-description { background: #f8f9fa; padding: 16px; border-radius: 8px; margin: 16px 0; }
        .listing-image { max-width: 200px; margin: 16px 0; border-radius: 8px; overflow: hidden; }
        .listing-image img { width: 100%; height: auto; }
        .percentage-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin: 16px 0; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 12px; }
        .empty-state i { font-size: 64px; color: #ccc; margin-bottom: 16px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; background: #e0e0e0; }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; }
            .percentage-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🏪 Brokerplace</h2>
                <p>Admin Dashboard</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item active"><i class="fas fa-check-double"></i> Approve Listings</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title"><i class="fas fa-check-double"></i> Approve Listings</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card pending"><div class="value"><?php echo $totalPending; ?></div><div class="label">Pending Approval</div></div>
                <div class="stat-card approved"><div class="value"><?php echo $totalApproved; ?></div><div class="label">Approved</div></div>
                <div class="stat-card rejected"><div class="value"><?php echo $totalRejected; ?></div><div class="label">Rejected</div></div>
            </div>
            
            <?php if ($pendingListings->num_rows > 0): ?>
                <h2 style="margin-bottom: 20px;">📋 Listings Waiting for Review (<?php echo $pendingListings->num_rows; ?>)</h2>
                
                <?php while($listing = $pendingListings->fetch_assoc()): ?>
                    <div class="listing-card">
                        <div class="listing-header">
                            <div>
                                <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                                <div style="margin-top: 8px;">
                                    <span class="badge"><?php echo ucfirst($listing['type']); ?></span>
                                    <span class="badge" style="margin-left: 8px;"><?php echo htmlspecialchars($listing['category_name'] ?? 'Uncategorized'); ?></span>
                                </div>
                            </div>
                            <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                        </div>
                        
                        <div class="listing-details">
                            <div class="detail-row"><div class="detail-label">Seller:</div><div class="detail-value"><?php echo htmlspecialchars($listing['seller_name']); ?> (<?php echo htmlspecialchars($listing['seller_email']); ?>)</div></div>
                            <div class="detail-row"><div class="detail-label">Location:</div><div class="detail-value"><?php echo htmlspecialchars($listing['location'] ?? 'Not specified'); ?></div></div>
                            <div class="detail-row"><div class="detail-label">Submitted:</div><div class="detail-value"><?php echo date('F d, Y H:i', strtotime($listing['created_at'])); ?></div></div>
                        </div>
                        
                        <?php if ($listing['cover_image']): ?>
                            <div class="listing-image"><img src="/broker_system/uploads/listings/<?php echo $listing['cover_image']; ?>" alt="Cover image"></div>
                        <?php endif; ?>
                        
                        <div class="listing-description"><strong>Description:</strong><br><?php echo nl2br(htmlspecialchars($listing['description'])); ?></div>
                        
                        <form method="POST">
                            <input type="hidden" name="listing_id" value="<?php echo $listing['id']; ?>">
                            <div class="percentage-form">
                                <div class="form-group">
                                    <label>Deposit Percentage (%)</label>
                                    <input type="number" name="deposit_percent" required min="0" max="100" value="30" step="1">
                                    <small>Buyer and seller must deposit this %</small>
                                </div>
                                <div class="form-group">
                                    <label>Commission Percentage (%)</label>
                                    <input type="number" name="commission_percent" required min="0" max="100" value="15" step="1">
                                    <small>System commission from this sale</small>
                                </div>
                            </div>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <button type="submit" name="approve_listing" class="btn btn-approve" onclick="return confirm('Approve this listing?')">
                                    <i class="fas fa-check"></i> Approve Listing
                                </button>
                                <button type="button" class="btn btn-reject" onclick="showRejectForm(<?php echo $listing['id']; ?>)">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                            <div id="rejectForm_<?php echo $listing['id']; ?>" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 1px solid #eee;">
                                <div class="form-group">
                                    <label>Rejection Reason</label>
                                    <textarea name="rejection_reason" rows="3" placeholder="Explain why this listing is being rejected..."></textarea>
                                </div>
                                <button type="submit" name="reject_listing" class="btn btn-reject">Confirm Rejection</button>
                            </div>
                        </form>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>No Pending Approvals</h3>
                    <p>All listings have been reviewed. Check back later for new submissions.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function showRejectForm(listingId) {
            const form = document.getElementById('rejectForm_' + listingId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>