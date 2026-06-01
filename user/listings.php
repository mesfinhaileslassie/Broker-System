<?php
// user/listings.php - My Listings Page with Full Negotiation Buttons

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
require_once '../includes/auth.php';
require_once '../includes/seller_listing_payment.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle negotiation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accept_terms'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'agreement_accepted', accepted_at = NOW() 
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        
        // Get listing info for notification
        $neg = $conn->query("SELECT listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
        
        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Terms Accepted', 'Seller has accepted the terms for listing \"{$listing['title']}\". Awaiting deposit payment.', NOW())
        ");
        $notif_stmt->bind_param("i", $admin['id']);
        $notif_stmt->execute();
        
        $message = "Terms accepted! Please pay the deposit to publish your listing.";
    }
    
    if (isset($_POST['reject_terms'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'rejected', rejection_reason = 'Seller rejected the proposal'
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        $message = "Listing rejected. You can submit a new listing if you change your mind.";
    }
    
    if (isset($_POST['send_counter'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $counter_commission = floatval($_POST['counter_commission']);
        $counter_deposit = floatval($_POST['counter_deposit']);
        $counter_message = $conn->real_escape_string($_POST['counter_message'] ?? '');
        
        $conn->query("
            UPDATE listing_negotiations 
            SET counter_commission = $counter_commission, 
                counter_deposit = $counter_deposit, 
                counter_message = '$counter_message', 
                status = 'counter_offer_sent' 
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        
        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Counter Offer Received', 'A seller has sent a counter offer. Please review.', NOW())
        ");
        $notif_stmt->bind_param("i", $admin['id']);
        $notif_stmt->execute();
        
        $message = "Counter offer sent! Admin will review your proposal.";
    }
}

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
} elseif ($status == 'negotiating') {
    $where .= " AND l.id IN (SELECT listing_id FROM listing_negotiations WHERE seller_id = $user_id AND status IN ('commission_proposed', 'counter_offer_sent'))";
} elseif ($status == 'rejected') {
    $where .= " AND l.approval_status = 'rejected'";
}

$listings = $conn->query("
    SELECT l.*, c.name as category_name,
           ln.id as negotiation_id, ln.status as negotiation_status,
           ln.proposed_commission, ln.proposed_deposit,
           ln.counter_commission, ln.counter_deposit, ln.counter_message,
           ln.accepted_at
    FROM listings l
    LEFT JOIN categories c ON l.category_id = c.id
    LEFT JOIN listing_negotiations ln ON l.id = ln.listing_id AND ln.seller_id = $user_id
    WHERE $where
    ORDER BY l.created_at DESC
");

// Get counts for tabs
$counts = [
    'all' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'],
    'pending_payment' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'approved' AND status = 'pending'")->fetch_assoc()['count'],
    'waiting' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'")->fetch_assoc()['count'],
    'negotiating' => $conn->query("
        SELECT COUNT(*) as count FROM listing_negotiations ln 
        JOIN listings l ON ln.listing_id = l.id 
        WHERE ln.seller_id = $user_id AND ln.status IN ('commission_proposed', 'counter_offer_sent')
    ")->fetch_assoc()['count'],
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'rejected'")->fetch_assoc()['count'],
];

$listings_rows = [];
if ($listings && $listings->num_rows > 0) {
    while ($row = $listings->fetch_assoc()) {
        $row['seller_payment'] = null;
        if ($row['status'] === 'active' && $row['approval_status'] === 'approved') {
            $row['seller_payment'] = getSellerListingPaymentInfo($conn, $row['id'], $user_id);
        }
        $listings_rows[] = $row;
    }
}

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
    
    .stats-banner {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 20px;
        padding: 20px 28px;
        margin-bottom: 28px;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .stats-banner h3 {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .stats-banner p {
        opacity: 0.9;
        font-size: 13px;
    }
    
    .stats-banner .badge {
        background: rgba(255,255,255,0.2);
        padding: 8px 20px;
        border-radius: 40px;
        font-weight: 600;
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
        grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
        gap: 24px;
    }
    
    .listing-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .listing-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .card-image {
        height: 180px;
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
    .badge-negotiating { background: #ede9fe; color: #6b21a5; }
    
    /* Negotiation Box */
    .negotiation-box {
        background: #f8fafc;
        border-radius: 16px;
        padding: 16px;
        margin: 12px 0;
        border: 1px solid #e2e8f0;
    }
    
    .offer-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 12px;
    }
    
    .offer-item {
        flex: 1;
        text-align: center;
    }
    
    .offer-label {
        font-size: 11px;
        color: #64748b;
        margin-bottom: 4px;
    }
    
    .offer-value {
        font-size: 18px;
        font-weight: 700;
    }
    
    .offer-value.proposed {
        color: #667eea;
    }
    
    .offer-value.counter {
        color: #f59e0b;
    }
    
    .counter-message {
        background: #fef3c7;
        padding: 10px;
        border-radius: 10px;
        font-size: 12px;
        margin-top: 12px;
        color: #92400e;
    }
    
    .btn-group {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 12px;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 40px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        text-align: center;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .btn-warning {
        background: #f59e0b;
        color: white;
    }
    
    .btn-warning:hover {
        background: #d97706;
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-2px);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid #e2e8f0;
        color: #64748b;
    }
    
    .btn-outline:hover {
        border-color: #667eea;
        color: #667eea;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .payment-info {
        background: #fef3c7;
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
        font-size: 12px;
    }

    .seller-payment-summary {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
        font-size: 12px;
    }

    .seller-payment-summary .pay-row {
        display: flex;
        justify-content: space-between;
        margin: 4px 0;
    }

    .seller-payment-summary .pay-row.remaining {
        font-weight: 700;
        color: #059669;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #bbf7d0;
    }

    .badge-fully-paid {
        background: #d1fae5;
        color: #059669;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        margin-top: 8px;
    }

    .pay-remaining-btn.loading {
        opacity: 0.7;
        pointer-events: none;
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
        width: 500px;
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
        font-weight: 600;
        color: #0f172a;
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 28px;
        color: #94a3b8;
        transition: color 0.3s;
    }
    
    .close-modal:hover {
        color: #ef4444;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #334155;
        font-size: 13px;
    }
    
    .form-group input, .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
    }
    
    .form-group input:focus, .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
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
    
    @media (max-width: 768px) {
        .listings-grid {
            grid-template-columns: 1fr;
        }
        .tabs {
            overflow-x: auto;
            flex-wrap: nowrap;
        }
        .offer-row {
            flex-direction: column;
            text-align: center;
        }
        .btn-group {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-header">
    <h1>My Listings</h1>
    <p>Manage your products, jobs, and rental listings</p>
</div>

<!-- Stats Banner for Negotiations -->
<?php if ($counts['negotiating'] > 0): ?>
<div class="stats-banner">
    <div>
        <h3><i class="fas fa-handshake"></i> Active Negotiations</h3>
        <p>You have <?php echo $counts['negotiating']; ?> listing(s) waiting for your response</p>
    </div>
    <div class="badge">Action Required!</div>
</div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

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
    <a href="?status=negotiating" class="tab <?php echo $status == 'negotiating' ? 'active' : ''; ?>">
        🤝 Negotiating <span class="count"><?php echo $counts['negotiating']; ?></span>
    </a>
    <a href="?status=waiting" class="tab <?php echo $status == 'waiting' ? 'active' : ''; ?>">
        Pending Approval <span class="count"><?php echo $counts['waiting']; ?></span>
    </a>
    <a href="?status=rejected" class="tab <?php echo $status == 'rejected' ? 'active' : ''; ?>">
        Rejected <span class="count"><?php echo $counts['rejected']; ?></span>
    </a>
</div>

<?php if (!empty($listings_rows)): ?>
    <div class="listings-grid">
        <?php foreach ($listings_rows as $listing): 
            $cover_image = $listing['cover_image'] ? '/broker_system/uploads/listings/' . $listing['cover_image'] : '';
            $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
            $has_negotiation = $listing['negotiation_id'];
            $neg_status = $listing['negotiation_status'];
            $is_waiting_for_admin = ($neg_status == 'counter_offer_sent');
            $is_awaiting_payment = ($neg_status == 'agreement_accepted');
            $can_accept = ($neg_status == 'commission_proposed');
            $can_counter = ($neg_status == 'commission_proposed');
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
                            $status_badge = '<span class="badge badge-success">✓ Active</span>';
                        } elseif ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending') {
                            $status_badge = '<span class="badge badge-warning">⏳ Awaiting Payment</span>';
                        } elseif ($listing['approval_status'] == 'pending') {
                            $status_badge = '<span class="badge badge-warning">⏳ Pending Approval</span>';
                        } elseif ($listing['approval_status'] == 'rejected') {
                            $status_badge = '<span class="badge badge-danger">✗ Rejected</span>';
                        } elseif ($neg_status == 'commission_proposed') {
                            $status_badge = '<span class="badge badge-negotiating">🤝 Offer Received - Action Required!</span>';
                        } elseif ($neg_status == 'counter_offer_sent') {
                            $status_badge = '<span class="badge badge-negotiating">⏳ Waiting for Admin Response</span>';
                        } elseif ($neg_status == 'agreement_accepted') {
                            $status_badge = '<span class="badge badge-success">✓ Agreement Signed - Pay to Publish</span>';
                        } else {
                            $status_badge = '<span class="badge badge-info">' . ucfirst($listing['approval_status']) . '</span>';
                        }
                        echo $status_badge;
                        ?>
                        <span><i class="fas fa-eye"></i> <?php echo $listing['views']; ?> views</span>
                    </div>
                    
                    <!-- NEGOTIATION SECTION -->
                    <?php if ($has_negotiation && $listing['proposed_commission']): ?>
                        <div class="negotiation-box">
                            <div class="offer-row">
                                <div class="offer-item">
                                    <div class="offer-label">Proposed Commission</div>
                                    <div class="offer-value proposed"><?php echo $listing['proposed_commission']; ?>%</div>
                                </div>
                                <div class="offer-item">
                                    <div class="offer-label">Proposed Deposit</div>
                                    <div class="offer-value proposed"><?php echo formatMoney($listing['proposed_deposit']); ?></div>
                                </div>
                            </div>
                            
                            <?php if ($listing['counter_commission']): ?>
                                <div class="offer-row" style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
                                    <div class="offer-item">
                                        <div class="offer-label">Your Counter - Commission</div>
                                        <div class="offer-value counter"><?php echo $listing['counter_commission']; ?>%</div>
                                    </div>
                                    <div class="offer-item">
                                        <div class="offer-label">Your Counter - Deposit</div>
                                        <div class="offer-value counter"><?php echo formatMoney($listing['counter_deposit']); ?></div>
                                    </div>
                                </div>
                                <?php if ($listing['counter_message']): ?>
                                    <div class="counter-message">
                                        <i class="fas fa-quote-left"></i> <?php echo htmlspecialchars($listing['counter_message']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <!-- NEGOTIATION BUTTONS -->
                            <div class="btn-group">
                                <?php if ($can_accept && !$is_waiting_for_admin && !$is_awaiting_payment): ?>
                                    <form method="POST" style="display: inline; flex: 1;">
                                        <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                                        <button type="submit" name="accept_terms" class="btn btn-success" style="width: 100%;" onclick="return confirm('Accept these terms? You will need to pay the deposit to publish your listing.')">
                                            <i class="fas fa-check-circle"></i> ✅ Accept Terms
                                        </button>
                                    </form>
                                    <button onclick="openCounterModal(<?php echo $listing['negotiation_id']; ?>, <?php echo $listing['proposed_commission']; ?>, <?php echo $listing['proposed_deposit']; ?>)" class="btn btn-warning" style="flex: 1;">
                                        <i class="fas fa-exchange-alt"></i> 🔄 Counter Offer
                                    </button>
                                    <form method="POST" style="display: inline; flex: 1;">
                                        <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                                        <button type="submit" name="reject_terms" class="btn btn-danger" style="width: 100%;" onclick="return confirm('Reject this listing? This will cancel the negotiation.')">
                                            <i class="fas fa-times-circle"></i> ❌ Reject
                                        </button>
                                    </form>
                                    
                                <?php elseif ($is_waiting_for_admin): ?>
                                    <button class="btn btn-outline" disabled style="width: 100%;">
                                        <i class="fas fa-hourglass-half"></i> ⏳ Waiting for Admin Response
                                    </button>
                                    
                                <?php elseif ($is_awaiting_payment): ?>
                                    <a href="pay_deposit.php?negotiation_id=<?php echo $listing['negotiation_id']; ?>" class="btn btn-success" style="flex: 1;">
                                        <i class="fas fa-credit-card"></i> 💰 Pay Deposit to Publish
                                    </a>
                                    <button class="btn btn-outline" disabled style="flex: 1;">
                                        <i class="fas fa-check-circle"></i> ✓ Terms Accepted
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Payment Required Box (for approved listings waiting for payment) -->
                    <?php if ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending' && !$has_negotiation): ?>
                        <?php
                        $deposit_percent = $listing['admin_deposit_percent'] ?? 30;
                        $commission_percent = $listing['admin_commission_percent'] ?? 15;
                        $deposit_amount = $listing['price'] * ($deposit_percent / 100);
                        $commission_amount = $listing['price'] * ($commission_percent / 100);
                        $total_payment = $deposit_amount + $commission_amount;
                        ?>
                        <div class="payment-info">
                            <strong>💰 Payment Required to Activate:</strong><br>
                            Deposit (<?php echo $deposit_percent; ?>%): <?php echo formatMoney($deposit_amount); ?> + 
                            Commission (<?php echo $commission_percent; ?>%): <?php echo formatMoney($commission_amount); ?>
                            = <strong><?php echo formatMoney($total_payment); ?></strong>
                        </div>
                        <div class="btn-group">
                            <a href="pay_listing.php?listing_id=<?php echo $listing['id']; ?>" class="btn btn-success" style="flex: 1;">
                                <i class="fas fa-credit-card"></i> 💰 Pay Now
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php
                    $sp = $listing['seller_payment'] ?? null;
                    if ($sp && $sp['has_deposit_payment']): ?>
                        <div class="seller-payment-summary" id="payment-summary-<?php echo $listing['id']; ?>">
                            <strong><i class="fas fa-chart-pie"></i> Your Payment Status</strong>
                            <div class="pay-row">
                                <span>Total Price</span>
                                <span><?php echo formatMoney($sp['total_price']); ?></span>
                            </div>
                            <div class="pay-row">
                                <span>Deposit Paid</span>
                                <span><?php echo formatMoney($sp['deposit_paid']); ?></span>
                            </div>
                            <div class="pay-row remaining">
                                <span>Remaining Balance</span>
                                <span class="remaining-amount"><?php echo formatMoney($sp['remaining_balance']); ?></span>
                            </div>
                            <?php if ($sp['payment_status'] === 'fully_paid'): ?>
                                <span class="badge-fully-paid"><i class="fas fa-check-circle"></i> Fully Paid</span>
                            <?php elseif ($sp['can_pay_remaining']): ?>
                                <button type="button"
                                    class="btn btn-success pay-remaining-btn"
                                    style="width: 100%; margin-top: 12px;"
                                    data-listing-id="<?php echo $listing['id']; ?>">
                                    <i class="fas fa-wallet"></i> Pay Remaining Balance
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Regular Action Buttons (for non-negotiation listings) -->
                    <?php if (!$has_negotiation && !($listing['approval_status'] == 'approved' && $listing['status'] == 'pending')): ?>
                        <div class="btn-group">
                            <a href="product.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline" style="flex: 1;">
                                <i class="fas fa-eye"></i> 👁️ View
                            </a>
                            <a href="edit_listing.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline" style="flex: 1;">
                                <i class="fas fa-edit"></i> ✏️ Edit
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
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

<!-- Counter Offer Modal -->
<div id="counterModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-exchange-alt"></i> Send Counter Offer</h3>
            <span class="close-modal" onclick="closeCounterModal()">&times;</span>
        </div>
        <form method="POST" id="counterForm">
            <input type="hidden" name="negotiation_id" id="counterNegotiationId">
            <input type="hidden" name="send_counter" value="1">
            
            <div class="form-row">
                <div class="form-group">
                    <label>Your Proposed Commission (%)</label>
                    <input type="number" name="counter_commission" id="counterCommission" step="0.5" min="1" max="20" required>
                </div>
                <div class="form-group">
                    <label>Your Proposed Deposit (ETB)</label>
                    <input type="number" name="counter_deposit" id="counterDeposit" step="100" min="0" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Message (Optional)</label>
                <textarea name="counter_message" rows="4" placeholder="Explain why you're suggesting these terms..."></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-paper-plane"></i> Send Counter Offer
            </button>
        </form>
    </div>
</div>

<script>
function openCounterModal(negotiationId, currentCommission, currentDeposit) {
    document.getElementById('counterNegotiationId').value = negotiationId;
    document.getElementById('counterCommission').value = currentCommission;
    document.getElementById('counterDeposit').value = currentDeposit;
    document.getElementById('counterModal').style.display = 'flex';
}

function closeCounterModal() {
    document.getElementById('counterModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('counterModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

document.querySelectorAll('.pay-remaining-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const listingId = this.dataset.listingId;
        if (!confirm('Are you sure you want to pay the remaining balance?')) {
            return;
        }

        const originalHtml = this.innerHTML;
        this.classList.add('loading');
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const res = await fetch('/broker_system/user/api/pay_remaining.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: parseInt(listingId, 10), action: 'initiate' })
            });
            const data = await res.json();

            if (data.success && data.pay_url) {
                window.location.href = data.pay_url;
                return;
            }

            alert(data.error || 'Could not start remaining payment');
            this.classList.remove('loading');
            this.innerHTML = originalHtml;
        } catch (err) {
            alert('Network error. Please try again.');
            this.classList.remove('loading');
            this.innerHTML = originalHtml;
        }
    });
});

<?php if (isset($_GET['fully_paid'])): ?>
(function() {
    const n = document.createElement('div');
    n.style.cssText = 'position:fixed;top:20px;right:20px;background:#10b981;color:#fff;padding:14px 20px;border-radius:12px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
    n.innerHTML = '<i class="fas fa-check-circle"></i> Remaining balance paid successfully!';
    document.body.appendChild(n);
    setTimeout(() => n.remove(), 5000);
})();
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>