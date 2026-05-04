<?php
// admin/approve_listings.php - Approve Listings

$page_title = 'Approve Listings';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_listing'])) {
        $listing_id = intval($_POST['listing_id']);
        $deposit_percent = intval($_POST['deposit_percent']);
        $commission_percent = intval($_POST['commission_percent']);
        $conn->query("UPDATE listings SET approval_status = 'approved', admin_deposit_percent = $deposit_percent, admin_commission_percent = $commission_percent, approved_at = NOW() WHERE id = $listing_id");
        $message = "Listing approved successfully";
    }
    
    if (isset($_POST['reject_listing'])) {
        $listing_id = intval($_POST['listing_id']);
        $reason = $conn->real_escape_string($_POST['rejection_reason']);
        $conn->query("UPDATE listings SET approval_status = 'rejected', admin_notes = '$reason' WHERE id = $listing_id");
        $message = "Listing rejected";
    }
}

$pendingListings = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.email as seller_email
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.approval_status = 'pending'
    ORDER BY l.created_at DESC
");

$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'approved' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'approved'")->fetch_assoc()['count'],
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'rejected'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; }
    .listing-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 20px; }
    .listing-header { display: flex; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; }
    .listing-title { font-size: 18px; font-weight: 600; }
    .listing-price { font-size: 20px; font-weight: 700; color: #667eea; }
    .listing-details { color: #64748b; margin-bottom: 16px; font-size: 13px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['pending']; ?></div><div class="stat-label">Pending</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['approved']; ?></div><div class="stat-label">Approved</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['rejected']; ?></div><div class="stat-label">Rejected</div></div>
</div>

<?php if ($message): ?>
<div class="alert alert-success" style="background:#d1fae5; color:#059669; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($pendingListings->num_rows > 0): ?>
    <?php while($listing = $pendingListings->fetch_assoc()): ?>
    <div class="listing-card">
        <div class="listing-header">
            <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
            <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
        </div>
        <div class="listing-details">
            <p><strong>Seller:</strong> <?php echo htmlspecialchars($listing['seller_name']); ?> (<?php echo htmlspecialchars($listing['seller_email']); ?>)</p>
            <p><strong>Type:</strong> <?php echo ucfirst($listing['type']); ?> | <strong>Location:</strong> <?php echo htmlspecialchars($listing['location'] ?? 'N/A'); ?></p>
            <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars(substr($listing['description'], 0, 200))); ?>...</p>
        </div>
        <form method="POST">
            <input type="hidden" name="listing_id" value="<?php echo $listing['id']; ?>">
            <div class="form-row">
                <div class="form-group"><label>Deposit Percentage (%)</label><input type="number" name="deposit_percent" required min="0" max="100" value="30"></div>
                <div class="form-group"><label>Commission Percentage (%)</label><input type="number" name="commission_percent" required min="0" max="100" value="15"></div>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="submit" name="approve_listing" class="btn-sm btn-success">Approve</button>
                <button type="button" class="btn-sm btn-danger" onclick="showRejectForm(<?php echo $listing['id']; ?>)">Reject</button>
            </div>
            <div id="rejectForm_<?php echo $listing['id']; ?>" style="display: none; margin-top: 16px;">
                <textarea name="rejection_reason" rows="2" placeholder="Reason for rejection" style="width:100%; margin-bottom:8px;"></textarea>
                <button type="submit" name="reject_listing" class="btn-sm btn-danger">Confirm Rejection</button>
            </div>
        </form>
    </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="card"><p style="text-align:center; padding:40px;">No pending approvals</p></div>
<?php endif; ?>

<script>
function showRejectForm(id) {
    const form = document.getElementById('rejectForm_' + id);
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>