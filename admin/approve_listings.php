<?php
// admin/approve_listings.php - Approve Listings with House/Car/Job details

$page_title = 'Approve Listings';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check if logged in and is admin
if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$conn = getDbConnection();
$message = '';
$error = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_listing'])) {
        $listing_id = intval($_POST['listing_id']);
        $deposit_percent = intval($_POST['deposit_percent']);
        $commission_percent = intval($_POST['commission_percent']);
        
        // Only set approval_status to 'approved', status remains 'pending' until seller pays
        $update = $conn->prepare("
            UPDATE listings 
            SET approval_status = 'approved', 
                admin_deposit_percent = ?, 
                admin_commission_percent = ?, 
                approved_at = NOW() 
            WHERE id = ?
        ");
        $update->bind_param("iii", $deposit_percent, $commission_percent, $listing_id);
        
        if ($update->execute()) {
            $message = "Listing approved successfully. Seller must now pay deposit to activate.";
        } else {
            $error = "Failed to approve listing";
        }
    }
    
    if (isset($_POST['reject_listing'])) {
        $listing_id = intval($_POST['listing_id']);
        $reason = $conn->real_escape_string($_POST['rejection_reason']);
        
        $conn->query("UPDATE listings SET approval_status = 'rejected', admin_notes = '$reason' WHERE id = $listing_id");
        $message = "Listing rejected";
    }
}

// Get pending listings
$pendingListings = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.approval_status = 'pending'
    ORDER BY l.created_at DESC
");

// Get statistics
$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'approved' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'approved'")->fetch_assoc()['count'],
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'rejected'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 36px;
        font-weight: 700;
    }
    
    .stat-card.pending .stat-value { color: #f59e0b; }
    .stat-card.approved .stat-value { color: #10b981; }
    .stat-card.rejected .stat-value { color: #ef4444; }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 8px;
    }
    
    .alert {
        padding: 14px 18px;
        border-radius: 16px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    .listing-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .listing-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .listing-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .listing-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .listing-price {
        font-size: 24px;
        font-weight: 700;
        color: #667eea;
    }
    
    .listing-details {
        margin-bottom: 20px;
        padding: 16px;
        background: #f8fafc;
        border-radius: 16px;
    }
    
    .detail-row {
        display: flex;
        margin-bottom: 8px;
        font-size: 13px;
        flex-wrap: wrap;
    }
    
    .detail-label {
        width: 120px;
        font-weight: 600;
        color: #64748b;
    }
    
    .detail-value {
        flex: 1;
        color: #1e293b;
    }
    
    .listing-image {
        margin: 16px 0;
    }
    
    .listing-image img {
        max-width: 100%;
        border-radius: 12px;
        object-fit: cover;
        max-height: 200px;
    }
    
    .listing-description {
        background: #f8fafc;
        padding: 16px;
        border-radius: 16px;
        margin: 16px 0;
        font-size: 14px;
        line-height: 1.5;
        color: #475569;
    }
    
    .additional-details {
        background: #e0e7ff;
        padding: 16px;
        border-radius: 16px;
        margin: 16px 0;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin: 20px 0;
    }
    
    .form-group {
        margin-bottom: 16px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
        font-size: 13px;
    }
    
    .form-group input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .form-group input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-family: inherit;
        resize: vertical;
    }
    
    .info-text {
        font-size: 11px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .btn-group {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .btn {
        padding: 10px 24px;
        border-radius: 40px;
        border: none;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-approve {
        background: #10b981;
        color: white;
    }
    
    .btn-approve:hover {
        background: #059669;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(16,185,129,0.3);
    }
    
    .btn-reject {
        background: #ef4444;
        color: white;
    }
    
    .btn-reject:hover {
        background: #dc2626;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(239,68,68,0.3);
    }
    
    .btn-secondary {
        background: #64748b;
        color: white;
    }
    
    .reject-form {
        display: none;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }
    
    .reject-form.active {
        display: block;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
        display: block;
    }
    
    .type-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .type-rental { background: #dbeafe; color: #1e40af; }
    .type-product { background: #d1fae5; color: #065f46; }
    .type-job { background: #fed7aa; color: #9a3412; }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: 1fr; }
        .listing-header { flex-direction: column; }
        .form-row { grid-template-columns: 1fr; }
        .btn-group { flex-direction: column; }
        .btn { width: 100%; text-align: center; }
        .detail-row { flex-direction: column; }
        .detail-label { width: auto; margin-bottom: 4px; }
    }
</style>

<div class="stats-grid">
    <div class="stat-card pending">
        <div class="stat-value"><?php echo $stats['pending']; ?></div>
        <div class="stat-label">Pending Approval</div>
    </div>
    <div class="stat-card approved">
        <div class="stat-value"><?php echo $stats['approved']; ?></div>
        <div class="stat-label">Approved (Need Payment)</div>
    </div>
    <div class="stat-card rejected">
        <div class="stat-value"><?php echo $stats['rejected']; ?></div>
        <div class="stat-label">Rejected</div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($pendingListings && $pendingListings->num_rows > 0): ?>
    <?php while($listing = $pendingListings->fetch_assoc()): 
        $additional = $listing['additional_details'] ? json_decode($listing['additional_details'], true) : [];
        $cover_image = $listing['cover_image'] ? '/broker_system/uploads/listings/' . $listing['cover_image'] : '';
    ?>
        <div class="listing-card">
            <div class="listing-header">
                <div>
                    <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                    <div style="margin-top: 8px;">
                        <span class="type-badge type-<?php echo $listing['type']; ?>">
                            <?php if ($listing['type'] == 'rental'): ?>
                                🏡 Property
                            <?php elseif ($listing['type'] == 'product'): ?>
                                🚗 Car
                            <?php else: ?>
                                💼 Job
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
            </div>
            
            <?php if ($cover_image && file_exists(str_replace('/broker_system/', '', $cover_image))): ?>
                <div class="listing-image">
                    <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>">
                </div>
            <?php endif; ?>
            
            <div class="listing-details">
                <div class="detail-row">
                    <div class="detail-label">Seller:</div>
                    <div class="detail-value">
                        <strong><?php echo htmlspecialchars($listing['seller_name']); ?></strong><br>
                        <small><?php echo htmlspecialchars($listing['seller_email']); ?></small>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Location:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($listing['location'] ?? 'Not specified'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Submitted:</div>
                    <div class="detail-value"><?php echo date('F d, Y H:i', strtotime($listing['created_at'])); ?></div>
                </div>
            </div>
            
            <?php if (!empty($additional)): ?>
                <div class="additional-details">
                    <strong><i class="fas fa-info-circle"></i> Additional Details:</strong><br>
                    <?php if ($listing['type'] == 'rental'): ?>
                        🛏️ Bedrooms: <?php echo $additional['bedrooms'] ?? 'N/A'; ?> | 
                        🚿 Bathrooms: <?php echo $additional['bathrooms'] ?? 'N/A'; ?> | 
                        📐 Area: <?php echo $additional['area'] ?? 'N/A'; ?> sqm
                    <?php elseif ($listing['type'] == 'product'): ?>
                        📅 Year: <?php echo $additional['year'] ?? 'N/A'; ?> | 
                        📊 Mileage: <?php echo number_format($additional['mileage'] ?? 0); ?> km | 
                        ⛽ Fuel: <?php echo $additional['fuel_type'] ?? 'N/A'; ?> | 
                        ⚙️ Transmission: <?php echo $additional['transmission'] ?? 'N/A'; ?>
                    <?php elseif ($listing['type'] == 'job'): ?>
                        <strong>Employment Type:</strong> <?php echo $additional['employment_type'] ?? 'N/A'; ?><br>
                        <strong>Requirements:</strong><br>
                        <?php echo nl2br(htmlspecialchars($additional['requirements'] ?? '')); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="listing-description">
                <strong>Description:</strong><br>
                <?php echo nl2br(htmlspecialchars(substr($listing['description'], 0, 300))); ?>
                <?php if (strlen($listing['description']) > 300): ?>...<?php endif; ?>
            </div>
            
            <form method="POST" class="approve-form">
                <input type="hidden" name="listing_id" value="<?php echo $listing['id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Deposit Percentage (%)</label>
                        <input type="number" name="deposit_percent" required min="0" max="100" value="30" step="1">
                        <div class="info-text">Seller must pay this percentage to activate</div>
                    </div>
                    <div class="form-group">
                        <label>Commission Percentage (%)</label>
                        <input type="number" name="commission_percent" required min="0" max="100" value="15" step="1">
                        <div class="info-text">Platform commission from transaction</div>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" name="approve_listing" class="btn btn-approve" onclick="return confirm('Approve this listing? The seller must pay deposit to activate.')">
                        <i class="fas fa-check"></i> Approve Listing
                    </button>
                    <button type="button" class="btn btn-reject" onclick="toggleRejectForm(<?php echo $listing['id']; ?>)">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </div>
                
                <div id="rejectForm_<?php echo $listing['id']; ?>" class="reject-form">
                    <div class="form-group">
                        <label>Rejection Reason</label>
                        <textarea name="rejection_reason" rows="3" placeholder="Explain why this listing is being rejected..."></textarea>
                        <div class="info-text">This reason will be shared with the seller</div>
                    </div>
                    <button type="submit" name="reject_listing" class="btn btn-secondary" onclick="return confirm('Reject this listing?')">
                        Confirm Rejection
                    </button>
                </div>
            </form>
            
            <div class="info-text" style="margin-top: 16px; text-align: center; background: #fef3c7; padding: 8px; border-radius: 8px;">
                <i class="fas fa-info-circle"></i> After approval, seller must pay <?php echo $deposit_percent; ?>% deposit + <?php echo $commission_percent; ?>% commission to activate.
            </div>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-check-circle"></i>
        <h3>No Pending Approvals</h3>
        <p>All listings have been reviewed. Check back later for new submissions.</p>
        <a href="dashboard.php" class="btn btn-approve" style="display: inline-block; margin-top: 16px; text-decoration: none;">
            <i class="fas fa-home"></i> Back to Dashboard
        </a>
    </div>
<?php endif; ?>

<script>
    function toggleRejectForm(listingId) {
        const form = document.getElementById('rejectForm_' + listingId);
        form.classList.toggle('active');
    }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>