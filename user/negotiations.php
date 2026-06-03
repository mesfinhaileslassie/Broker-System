<?php
// user/negotiations.php - User/Seller Negotiations Page
// Sellers can view and respond to admin commission proposals

session_start();

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

$page_title = 'My Negotiations';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle user response to proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $negotiation_id = intval($_POST['negotiation_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'accept_proposal') {
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'accepted',
                accepted_at = NOW(),
                updated_at = NOW()
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        
        // Get listing details
        $neg = $conn->query("SELECT listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
        
        // Update listing approval status
        $conn->query("UPDATE listings SET approval_status = 'approved' WHERE id = {$neg['listing_id']}");
        
        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $conn->query("
            INSERT INTO notifications (user_id, title, message, link, created_at) 
            VALUES ({$admin['id']}, '✓ Proposal Accepted', 
            'Seller has accepted the commission proposal for listing \"{$listing['title']}\". Ready for payment.', 
            '/broker_system/admin/negotiations.php', NOW())
        ");
        
        $message = "You have accepted the proposal! Please pay the deposit to publish your listing.";
        
    } elseif ($action === 'reject_proposal') {
        $reason = $conn->real_escape_string($_POST['rejection_reason'] ?? '');
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'rejected',
                rejection_reason = '$reason',
                rejected_at = NOW(),
                updated_at = NOW()
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        
        $neg = $conn->query("SELECT listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
        
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $conn->query("
            INSERT INTO notifications (user_id, title, message, link, created_at) 
            VALUES ({$admin['id']}, '✗ Proposal Rejected', 
            'Seller has rejected the proposal for listing \"{$listing['title']}\". Reason: $reason', 
            '/broker_system/admin/negotiations.php', NOW())
        ");
        
        $message = "You have rejected the proposal. The admin may send a revised proposal.";
    }
}

// Get negotiations for this user
$negotiations = $conn->query("
    SELECT ln.*, l.title, l.type, l.price, l.id as listing_id,
           l.approval_status, l.status as listing_status,
           ln.proposed_commission, ln.proposed_deposit,
           ln.counter_commission, ln.counter_deposit,
           ln.status as negotiation_status,
           ln.admin_notes,
           ln.sent_at, ln.accepted_at, ln.rejected_at
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    WHERE ln.seller_id = $user_id
    ORDER BY ln.created_at DESC
");

// Get statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE seller_id = $user_id")->fetch_assoc()['count'] ?? 0,
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE seller_id = $user_id AND status = 'proposal_sent'")->fetch_assoc()['count'] ?? 0,
    'accepted' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE seller_id = $user_id AND status = 'accepted'")->fetch_assoc()['count'] ?? 0,
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE seller_id = $user_id AND status = 'rejected'")->fetch_assoc()['count'] ?? 0,
];

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .negotiations-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    /* Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .page-header::before {
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
    
    .page-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .page-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 12px;
        color: var(--gray);
        margin-top: 4px;
    }
    
    /* Negotiation Cards */
    .negotiation-card {
        background: white;
        border-radius: 24px;
        margin-bottom: 24px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .card-header {
        padding: 20px;
        background: var(--light);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .listing-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .listing-price {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary);
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .status-proposal_sent { background: #dbeafe; color: #1e40af; }
    .status-accepted { background: #d1fae5; color: #059669; }
    .status-rejected { background: #fee2e2; color: #dc2626; }
    .status-under_review { background: #fef3c7; color: #92400e; }
    
    /* Proposal Box */
    .proposal-box {
        padding: 24px;
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--border);
    }
    
    .proposal-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .proposal-details {
        display: flex;
        gap: 32px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    
    .proposal-item {
        text-align: center;
        flex: 1;
        min-width: 120px;
    }
    
    .proposal-label {
        font-size: 11px;
        color: var(--gray);
        margin-bottom: 4px;
        text-transform: uppercase;
    }
    
    .proposal-value {
        font-size: 24px;
        font-weight: 800;
        color: var(--primary);
    }
    
    .admin-note {
        background: #fef3c7;
        padding: 12px;
        border-radius: 12px;
        font-size: 13px;
        color: #92400e;
        margin-top: 12px;
    }
    
    /* Action Buttons */
    .action-buttons {
        padding: 20px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        border-top: 1px solid var(--border);
    }
    
    .btn {
        padding: 10px 24px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-success {
        background: var(--success);
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: var(--danger);
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-2px);
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--gray);
    }
    
    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    
    /* Alert */
    .alert {
        padding: 14px 18px;
        border-radius: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
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
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-content {
        background: white;
        border-radius: 24px;
        padding: 28px;
        width: 450px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header h3 {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 28px;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .close-modal:hover {
        color: var(--danger);
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--border);
        border-radius: 12px;
        resize: vertical;
        font-family: inherit;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 24px;
        border: 1px solid var(--border);
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
        display: block;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .proposal-details {
            flex-direction: column;
            gap: 16px;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
        .card-header {
            flex-direction: column;
            text-align: center;
        }
    }
</style>

<div class="negotiations-container">
    <!-- Header -->
    <div class="page-header">
        <h1><i class="fas fa-handshake"></i> My Negotiations</h1>
        <p>Review and respond to commission proposals from admin</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Negotiations</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Response</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['accepted']; ?></div>
            <div class="stat-label">Accepted</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['rejected']; ?></div>
            <div class="stat-label">Rejected</div>
        </div>
    </div>
    
    <!-- Negotiations List -->
    <?php if ($negotiations && $negotiations->num_rows > 0): ?>
        <?php while($neg = $negotiations->fetch_assoc()): 
            $status_class = '';
            $status_text = '';
            switch($neg['negotiation_status']) {
                case 'proposal_sent':
                    $status_class = 'status-proposal_sent';
                    $status_text = 'Proposal Received - Action Required';
                    break;
                case 'accepted':
                    $status_class = 'status-accepted';
                    $status_text = 'Accepted - Pay Deposit to Publish';
                    break;
                case 'rejected':
                    $status_class = 'status-rejected';
                    $status_text = 'Rejected';
                    break;
                default:
                    $status_class = 'status-under_review';
                    $status_text = 'Under Review';
            }
            
            $type_icon = '';
            if ($neg['type'] == 'rental') $type_icon = '🏠';
            elseif ($neg['type'] == 'product') $type_icon = '🚗';
            else $type_icon = '💼';
        ?>
            <div class="negotiation-card">
                <div class="card-header">
                    <div>
                        <div class="listing-title"><?php echo $type_icon; ?> <?php echo htmlspecialchars($neg['title']); ?></div>
                        <div style="font-size: 12px; color: var(--gray); margin-top: 4px;">
                            <?php echo ucfirst($neg['type']); ?> Listing
                        </div>
                    </div>
                    <div>
                        <div class="listing-price"><?php echo formatMoney($neg['price']); ?></div>
                        <div class="status-badge <?php echo $status_class; ?>" style="margin-top: 8px;">
                            <?php echo $status_text; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($neg['proposed_commission']): ?>
                <div class="proposal-box">
                    <div class="proposal-title">
                        <i class="fas fa-file-signature"></i> Commission Proposal
                    </div>
                    <div class="proposal-details">
                        <div class="proposal-item">
                            <div class="proposal-label">Commission Rate</div>
                            <div class="proposal-value"><?php echo $neg['proposed_commission']; ?>%</div>
                        </div>
                        <div class="proposal-item">
                            <div class="proposal-label">Deposit Amount</div>
                            <div class="proposal-value"><?php echo formatMoney($neg['proposed_deposit']); ?></div>
                        </div>
                    </div>
                    
                    <?php if ($neg['admin_notes']): ?>
                    <div class="admin-note">
                        <i class="fas fa-comment-dots"></i> <strong>Admin Note:</strong> <?php echo htmlspecialchars($neg['admin_notes']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="action-buttons">
                    <?php if ($neg['negotiation_status'] == 'proposal_sent'): ?>
                        <button onclick="openAcceptModal(<?php echo $neg['id']; ?>)" class="btn btn-success">
                            <i class="fas fa-check-circle"></i> Accept Proposal
                        </button>
                        <button onclick="openRejectModal(<?php echo $neg['id']; ?>)" class="btn btn-danger">
                            <i class="fas fa-times-circle"></i> Reject Proposal
                        </button>
                        
                    <?php elseif ($neg['negotiation_status'] == 'accepted'): ?>
                        <a href="/broker_system/user/pay_listing.php?listing_id=<?php echo $neg['listing_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-credit-card"></i> Pay Deposit to Publish
                        </a>
                        
                    <?php elseif ($neg['negotiation_status'] == 'rejected'): ?>
                        <span class="btn btn-outline" style="cursor: default;">
                            <i class="fas fa-clock"></i> Waiting for Revised Proposal
                        </span>
                    <?php endif; ?>
                    
                    <a href="/broker_system/user/product.php?id=<?php echo $neg['listing_id']; ?>" target="_blank" class="btn btn-outline">
                        <i class="fas fa-eye"></i> View Listing
                    </a>
                    
                    <a href="/broker_system/user/chat.php?user=1" class="btn btn-outline">
                        <i class="fas fa-comment"></i> Contact Admin
                    </a>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-handshake"></i>
            <h3>No Negotiations</h3>
            <p>You don't have any active negotiations at this time.</p>
            <a href="post_listing.php" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                <i class="fas fa-plus-circle"></i> Post a Listing
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Accept Modal -->
<div id="acceptModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle" style="color: #10b981;"></i> Accept Proposal</h3>
            <span class="close-modal" onclick="closeAcceptModal()">&times;</span>
        </div>
        <div style="background: #fef3c7; padding: 16px; border-radius: 12px; margin-bottom: 20px;">
            <p><strong>⚠️ Important:</strong> By accepting this proposal, you agree to pay the deposit amount to publish your listing.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="negotiation_id" id="accept_negotiation_id">
            <input type="hidden" name="action" value="accept_proposal">
            <div class="modal-buttons" style="display: flex; gap: 12px;">
                <button type="submit" class="btn btn-success" style="flex: 1;">Yes, Accept Proposal</button>
                <button type="button" onclick="closeAcceptModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle" style="color: #ef4444;"></i> Reject Proposal</h3>
            <span class="close-modal" onclick="closeRejectModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="negotiation_id" id="reject_negotiation_id">
            <input type="hidden" name="action" value="reject_proposal">
            <div class="form-group">
                <label>Reason for Rejection (Optional)</label>
                <textarea name="rejection_reason" rows="3" placeholder="Let us know why you're rejecting this proposal..."></textarea>
            </div>
            <div class="modal-buttons" style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="submit" class="btn btn-danger" style="flex: 1;">Yes, Reject Proposal</button>
                <button type="button" onclick="closeRejectModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAcceptModal(id) {
        document.getElementById('accept_negotiation_id').value = id;
        document.getElementById('acceptModal').style.display = 'flex';
    }
    
    function closeAcceptModal() {
        document.getElementById('acceptModal').style.display = 'none';
    }
    
    function openRejectModal(id) {
        document.getElementById('reject_negotiation_id').value = id;
        document.getElementById('rejectModal').style.display = 'flex';
    }
    
    function closeRejectModal() {
        document.getElementById('rejectModal').style.display = 'none';
    }
    
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>