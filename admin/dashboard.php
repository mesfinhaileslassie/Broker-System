<?php
// ============================================
// FILE: broker_system/admin/negotiations.php
// ============================================
// Admin Negotiation Management - Integrated with Admin Layout

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check if logged in and is admin
if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Commission Negotiations';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $negotiation_id = intval($_POST['negotiation_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'propose') {
        $commission = floatval($_POST['commission_percent'] ?? 0);
        $deposit = floatval($_POST['deposit_amount'] ?? 0);
        $featured_fee = floatval($_POST['featured_fee'] ?? 0);
        $notes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
        
        if ($commission > 0 && $deposit > 0) {
            $stmt = $conn->prepare("
                UPDATE listing_negotiations 
                SET proposed_commission = ?, 
                    proposed_deposit = ?, 
                    featured_listing_fee = ?,
                    admin_notes = ?,
                    status = 'commission_proposed',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("dddsi", $commission, $deposit, $featured_fee, $notes, $negotiation_id);
            
            if ($stmt->execute()) {
                $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
                if ($neg) {
                    $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
                    
                    $notif_stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, title, message, created_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $title = "Commission Proposal for {$listing['title']}";
                    $msg = "Admin has proposed {$commission}% commission and " . formatMoney($deposit) . " deposit for your listing.";
                    $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                    $notif_stmt->execute();
                    
                    $conn->query("
                        INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                        VALUES ($negotiation_id, 0, 'system', 'Admin has proposed {$commission}% commission and " . formatMoney($deposit) . " deposit. Please review.', NOW())
                    ");
                }
                $message = "Proposal sent to seller!";
            } else {
                $error = "Failed to propose terms";
            }
        } else {
            $error = "Please enter valid commission and deposit amounts.";
        }
    } 
    elseif ($action === 'accept_counter') {
        $neg = $conn->query("SELECT counter_commission, counter_deposit, seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        
        if ($neg && $neg['counter_commission']) {
            $stmt = $conn->prepare("
                UPDATE listing_negotiations 
                SET proposed_commission = counter_commission,
                    proposed_deposit = counter_deposit,
                    counter_commission = NULL,
                    counter_deposit = NULL,
                    status = 'agreement_accepted',
                    accepted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("i", $negotiation_id);
            
            if ($stmt->execute()) {
                $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
                
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $title = "Counter Offer Accepted!";
                $msg = "Great news! Your counter offer has been accepted. Please proceed with deposit payment.";
                $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                $notif_stmt->execute();
                
                $conn->query("
                    INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                    VALUES ($negotiation_id, 0, 'system', 'Admin accepted your counter offer! Please proceed with deposit payment.', NOW())
                ");
                
                $message = "Counter offer accepted!";
            }
        }
    } 
    elseif ($action === 'reject_counter') {
        $stmt = $conn->prepare("
            UPDATE listing_negotiations 
            SET counter_commission = NULL,
                counter_deposit = NULL,
                status = 'commission_proposed',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("i", $negotiation_id);
        
        if ($stmt->execute()) {
            $neg = $conn->query("SELECT seller_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
            if ($neg) {
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $title = "Counter Offer Update";
                $msg = "Your counter offer has been reviewed. The original proposal is still available.";
                $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                $notif_stmt->execute();
            }
            $message = "Counter offer rejected.";
        }
    }
    elseif ($action === 'approve_payment') {
        $neg = $conn->query("
            SELECT ln.*, l.id as listing_id, l.title, l.price, l.seller_id as listing_seller_id,
                   u.id as seller_id, u.email as seller_email
            FROM listing_negotiations ln
            JOIN listings l ON ln.listing_id = l.id
            JOIN users u ON ln.seller_id = u.id
            WHERE ln.id = $negotiation_id
        ")->fetch_assoc();
        
        if ($neg) {
            $conn->begin_transaction();
            
            try {
                $conn->query("
                    UPDATE listing_negotiations 
                    SET status = 'published', 
                        published_at = NOW()
                    WHERE id = $negotiation_id
                ");
                
                $final_commission = $neg['counter_commission'] ?: $neg['proposed_commission'];
                $conn->query("
                    UPDATE listings 
                    SET status = 'active', 
                        approval_status = 'approved',
                        admin_commission_percent = $final_commission
                    WHERE id = {$neg['listing_id']}
                ");
                
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $title = "🎉 Your Listing is Live!";
                $msg = "Congratulations! Your listing '{$neg['title']}' has been published and is now visible to buyers.";
                $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                $notif_stmt->execute();
                
                $conn->query("
                    INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                    VALUES ($negotiation_id, 0, 'system', '🎉 Congratulations! Your listing has been published and is now live!', NOW())
                ");
                
                $conn->commit();
                $message = "Listing published successfully!";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to publish: " . $e->getMessage();
            }
        }
    }
    elseif ($action === 'send_message') {
        $message_text = $conn->real_escape_string($_POST['message'] ?? '');
        $negotiation_id = intval($_POST['negotiation_id'] ?? 0);
        
        if (!empty($message_text) && $negotiation_id > 0) {
            $neg = $conn->query("SELECT seller_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
            if ($neg) {
                $conn->query("
                    INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                    VALUES ($negotiation_id, {$_SESSION['user_id']}, 'admin', '$message_text', NOW())
                ");
                
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, created_at) 
                    VALUES (?, 'New Message', ?, NOW())
                ");
                $notif_stmt->bind_param("is", $neg['seller_id'], $message_text);
                $notif_stmt->execute();
                
                $message = "Message sent!";
            }
        }
    }
}

// Get stats
$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'under_review'")->fetch_assoc()['count'],
    'proposed' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'commission_proposed'")->fetch_assoc()['count'],
    'counter' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'counter_offer_sent'")->fetch_assoc()['count'],
    'payment' => $conn->query("SELECT COUNT(*) as count FROM listing_negotiations WHERE status = 'agreement_accepted'")->fetch_assoc()['count']
];

// Get negotiations
$pending_review = $conn->query("
    SELECT ln.*, l.title, l.type, l.price, l.created_at as listing_created,
           u.full_name as seller_name, u.email as seller_email
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'under_review'
    ORDER BY ln.created_at ASC
");

$proposed = $conn->query("
    SELECT ln.*, l.title, l.type, l.price,
           u.full_name as seller_name, u.email as seller_email
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'commission_proposed'
    ORDER BY ln.updated_at ASC
");

$counter_offers = $conn->query("
    SELECT ln.*, l.title, l.type, l.price,
           u.full_name as seller_name, u.email as seller_email
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'counter_offer_sent'
    ORDER BY ln.updated_at ASC
");

$awaiting_payment = $conn->query("
    SELECT ln.*, l.title, l.type, l.price,
           u.full_name as seller_name, u.email as seller_email
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'agreement_accepted'
    ORDER BY ln.accepted_at ASC
");

$conn->close();
?>

<style>
    .admin-negotiations {
        max-width: 100%;
    }
    
    /* Stats Grid */
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
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    /* Section Cards */
    .section-card {
        background: white;
        border-radius: 24px;
        margin-bottom: 32px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .section-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        font-size: 18px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .badge-count {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 13px;
    }
    
    /* Negotiation Card */
    .negotiation-card {
        padding: 20px 24px;
        border-bottom: 1px solid #e2e8f0;
        transition: all 0.3s;
    }
    
    .negotiation-card:hover {
        background: #f8fafc;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 12px;
    }
    
    .listing-title {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .seller-info {
        font-size: 12px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .seller-info i {
        width: 14px;
        color: #667eea;
    }
    
    .price {
        font-size: 18px;
        font-weight: 700;
        color: #667eea;
    }
    
    .offer-details {
        display: flex;
        gap: 24px;
        flex-wrap: wrap;
        margin: 12px 0;
        padding: 12px;
        background: #f8fafc;
        border-radius: 12px;
    }
    
    .offer-item {
        display: flex;
        align-items: baseline;
        gap: 8px;
    }
    
    .offer-label {
        font-size: 11px;
        color: #64748b;
    }
    
    .offer-value {
        font-size: 16px;
        font-weight: 700;
    }
    
    .offer-value.proposed {
        color: #667eea;
    }
    
    .offer-value.counter {
        color: #f59e0b;
    }
    
    .counter-box {
        background: #fef3c7;
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
        border-left: 3px solid #f59e0b;
    }
    
    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 16px;
    }
    
    .btn-sm {
        padding: 8px 16px;
        font-size: 12px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
    }
    
    .btn-primary { background: #667eea; color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
    
    .btn-sm:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
        width: 550px;
        max-width: 90%;
        max-height: 85vh;
        overflow-y: auto;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
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
        margin-bottom: 16px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        font-size: 13px;
        color: #334155;
    }
    
    .form-group input, .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 14px;
    }
    
    .form-group input:focus, .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    
    .info-text {
        font-size: 11px;
        color: #64748b;
        margin-top: 4px;
        display: block;
    }
    
    .chat-messages {
        max-height: 400px;
        overflow-y: auto;
        margin-bottom: 16px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 12px;
    }
    
    .message {
        margin-bottom: 16px;
        padding: 12px;
        border-radius: 12px;
    }
    
    .message-admin {
        background: #e0e7ff;
    }
    
    .message-seller {
        background: #d1fae5;
    }
    
    .message-system {
        background: #fef3c7;
        text-align: center;
    }
    
    .empty-state {
        padding: 40px;
        text-align: center;
        color: #64748b;
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
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .offer-details {
            flex-direction: column;
            gap: 8px;
        }
        .action-buttons {
            flex-direction: column;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
        .section-header {
            flex-direction: column;
            text-align: center;
        }
    }
</style>

<div class="admin-negotiations">
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card" onclick="document.getElementById('pendingSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">📋 Pending Review</div>
        </div>
        <div class="stat-card" onclick="document.getElementById('proposedSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['proposed']; ?></div>
            <div class="stat-label">⏳ Awaiting Response</div>
        </div>
        <div class="stat-card" onclick="document.getElementById('counterSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['counter']; ?></div>
            <div class="stat-label">🔄 Counter Offers</div>
        </div>
        <div class="stat-card" onclick="document.getElementById('paymentSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['payment']; ?></div>
            <div class="stat-label">💰 Payment Due</div>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Pending Review -->
    <div id="pendingSection" class="section-card">
        <div class="section-header">
            <span><i class="fas fa-clock"></i> Pending Review</span>
            <span class="badge-count"><?php echo $pending_review->num_rows; ?> listings</span>
        </div>
        <?php if ($pending_review->num_rows > 0): ?>
            <?php while($neg = $pending_review->fetch_assoc()): ?>
                <div class="negotiation-card">
                    <div class="card-header">
                        <div>
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?> • 
                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($neg['seller_email']); ?>
                            </div>
                        </div>
                        <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                    </div>
                    <div class="action-buttons">
                        <button onclick="openProposeModal(<?php echo $neg['id']; ?>, <?php echo $neg['price']; ?>, '<?php echo $neg['type']; ?>')" class="btn-sm btn-primary">
                            <i class="fas fa-percent"></i> Propose Terms
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">✨ No pending reviews. All caught up!</div>
        <?php endif; ?>
    </div>
    
    <!-- Awaiting Seller Response -->
    <div id="proposedSection" class="section-card">
        <div class="section-header">
            <span><i class="fas fa-hourglass-half"></i> Awaiting Seller Response</span>
            <span class="badge-count"><?php echo $proposed->num_rows; ?> listings</span>
        </div>
        <?php if ($proposed->num_rows > 0): ?>
            <?php while($neg = $proposed->fetch_assoc()): ?>
                <div class="negotiation-card">
                    <div class="card-header">
                        <div>
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?>
                            </div>
                        </div>
                        <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                    </div>
                    <div class="offer-details">
                        <div class="offer-item">
                            <span class="offer-label">Commission:</span>
                            <span class="offer-value proposed"><?php echo $neg['proposed_commission']; ?>%</span>
                        </div>
                        <div class="offer-item">
                            <span class="offer-label">Deposit:</span>
                            <span class="offer-value proposed"><?php echo formatMoney($neg['proposed_deposit']); ?></span>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button onclick="openChatModal(<?php echo $neg['id']; ?>)" class="btn-sm btn-primary">
                            <i class="fas fa-comments"></i> Send Message
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">No pending responses</div>
        <?php endif; ?>
    </div>
    
    <!-- Counter Offers -->
    <div id="counterSection" class="section-card">
        <div class="section-header">
            <span><i class="fas fa-exchange-alt"></i> Counter Offers Received</span>
            <span class="badge-count"><?php echo $counter_offers->num_rows; ?> listings</span>
        </div>
        <?php if ($counter_offers->num_rows > 0): ?>
            <?php while($neg = $counter_offers->fetch_assoc()): ?>
                <div class="negotiation-card">
                    <div class="card-header">
                        <div>
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?>
                            </div>
                        </div>
                        <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                    </div>
                    <div class="offer-details">
                        <div class="offer-item">
                            <span class="offer-label">Our Offer:</span>
                            <span class="offer-value proposed"><?php echo $neg['proposed_commission']; ?>% / <?php echo formatMoney($neg['proposed_deposit']); ?></span>
                        </div>
                        <div class="offer-item">
                            <span class="offer-label">Seller Counter:</span>
                            <span class="offer-value counter"><?php echo $neg['counter_commission']; ?>% / <?php echo formatMoney($neg['counter_deposit']); ?></span>
                        </div>
                    </div>
                    <?php if ($neg['counter_message']): ?>
                        <div class="counter-box">
                            <i class="fas fa-quote-left"></i> <?php echo htmlspecialchars($neg['counter_message']); ?>
                        </div>
                    <?php endif; ?>
                    <div class="action-buttons">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $neg['id']; ?>">
                            <input type="hidden" name="action" value="accept_counter">
                            <button type="submit" class="btn-sm btn-success" onclick="return confirm('Accept this counter offer?')">
                                <i class="fas fa-check"></i> Accept
                            </button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $neg['id']; ?>">
                            <input type="hidden" name="action" value="reject_counter">
                            <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject this counter offer?')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </form>
                        <button onclick="openChatModal(<?php echo $neg['id']; ?>)" class="btn-sm btn-primary">
                            <i class="fas fa-comments"></i> Discuss
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">No counter offers</div>
        <?php endif; ?>
    </div>
    
    <!-- Awaiting Payment -->
    <div id="paymentSection" class="section-card">
        <div class="section-header">
            <span><i class="fas fa-credit-card"></i> Awaiting Payment Verification</span>
            <span class="badge-count"><?php echo $awaiting_payment->num_rows; ?> listings</span>
        </div>
        <?php if ($awaiting_payment->num_rows > 0): ?>
            <?php while($neg = $awaiting_payment->fetch_assoc()): ?>
                <div class="negotiation-card">
                    <div class="card-header">
                        <div>
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?>
                            </div>
                        </div>
                        <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                    </div>
                    <div class="offer-details">
                        <div class="offer-item">
                            <span class="offer-label">Agreed Commission:</span>
                            <span class="offer-value proposed"><?php echo $neg['counter_commission'] ?: $neg['proposed_commission']; ?>%</span>
                        </div>
                        <div class="offer-item">
                            <span class="offer-label">Deposit Amount:</span>
                            <span class="offer-value proposed"><?php echo formatMoney($neg['counter_deposit'] ?: $neg['proposed_deposit']); ?></span>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <form method="POST">
                            <input type="hidden" name="negotiation_id" value="<?php echo $neg['id']; ?>">
                            <input type="hidden" name="action" value="approve_payment">
                            <button type="submit" class="btn-sm btn-success" onclick="return confirm('Verify payment and publish this listing?')">
                                <i class="fas fa-check-circle"></i> Verify & Publish
                            </button>
                        </form>
                        <button onclick="openChatModal(<?php echo $neg['id']; ?>)" class="btn-sm btn-primary">
                            <i class="fas fa-comments"></i> Contact Seller
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">No pending payments</div>
        <?php endif; ?>
    </div>
</div>

<!-- Propose Modal -->
<div id="proposeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-percent"></i> Propose Commission & Deposit</h3>
            <span class="close-modal" onclick="closeModal('proposeModal')">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="negotiation_id" id="proposeNegotiationId">
            <input type="hidden" name="action" value="propose">
            
            <div class="form-group">
                <label>Listing Price</label>
                <input type="text" id="listingPriceDisplay" disabled style="background:#f8fafc;">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Commission (%)</label>
                    <input type="number" name="commission_percent" id="commissionPercent" step="0.5" min="1" max="20" required>
                    <small id="commissionRecommendation" class="info-text"></small>
                </div>
                <div class="form-group">
                    <label>Deposit Amount (ETB)</label>
                    <input type="number" name="deposit_amount" id="depositAmount" step="100" min="0" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Admin Notes (Optional)</label>
                <textarea name="admin_notes" rows="3" placeholder="Add any notes for the seller..."></textarea>
            </div>
            
            <button type="submit" class="btn-sm btn-primary" style="width:100%; padding:12px;">
                <i class="fas fa-paper-plane"></i> Send Proposal
            </button>
        </form>
    </div>
</div>

<!-- Chat Modal -->
<div id="chatModal" class="modal">
    <div class="modal-content" style="width:600px;">
        <div class="modal-header">
            <h3><i class="fas fa-comments"></i> Negotiation Chat</h3>
            <span class="close-modal" onclick="closeModal('chatModal')">&times;</span>
        </div>
        <div id="chatMessages" class="chat-messages">
            <div style="text-align:center; padding:20px;">Loading messages...</div>
        </div>
        <form method="POST" id="chatForm">
            <input type="hidden" name="negotiation_id" id="chatNegotiationId">
            <input type="hidden" name="action" value="send_message">
            <textarea name="message" rows="3" placeholder="Type your message..." required style="width:100%; margin-bottom:12px; padding:10px; border-radius:8px; border:1px solid #e2e8f0;"></textarea>
            <button type="submit" class="btn-sm btn-primary" style="width:100%; padding:12px;">
                <i class="fas fa-paper-plane"></i> Send Message
            </button>
        </form>
    </div>
</div>

<script>
    let currentNegotiationId = null;
    let chatRefreshInterval = null;
    
    function openProposeModal(negotiationId, price, type) {
        document.getElementById('proposeNegotiationId').value = negotiationId;
        document.getElementById('listingPriceDisplay').value = formatMoney(price);
        
        let recommendedCommission = 5;
        if (type === 'rental') recommendedCommission = 7;
        else if (type === 'product') recommendedCommission = 5;
        else if (type === 'job') recommendedCommission = 8;
        
        if (price > 2000000) recommendedCommission = 3;
        else if (price >= 500000) recommendedCommission = 5;
        
        document.getElementById('commissionPercent').value = recommendedCommission;
        document.getElementById('commissionRecommendation').innerHTML = `🤖 AI Recommendation: ${recommendedCommission}%`;
        
        let recommendedDeposit = price * 0.25;
        if (recommendedDeposit > 50000) recommendedDeposit = 50000;
        document.getElementById('depositAmount').value = Math.round(recommendedDeposit);
        
        document.getElementById('proposeModal').style.display = 'flex';
    }
    
    async function openChatModal(negotiationId) {
        currentNegotiationId = negotiationId;
        document.getElementById('chatNegotiationId').value = negotiationId;
        document.getElementById('chatModal').style.display = 'flex';
        await loadChatMessages(negotiationId);
        startChatRefresh();
    }
    
    async function loadChatMessages(negotiationId) {
        try {
            const response = await fetch(`ajax/get_negotiation_messages.php?id=${negotiationId}`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                let html = '';
                data.messages.forEach(msg => {
                    const isAdmin = msg.sender_type === 'admin';
                    const isSystem = msg.sender_type === 'system';
                    const bgClass = isSystem ? 'message-system' : (isAdmin ? 'message-admin' : 'message-seller');
                    
                    html += `
                        <div class="message ${bgClass}">
                            <strong>${isSystem ? '📢 System' : (isAdmin ? '👨‍💼 You' : '👤 Seller')}</strong>
                            <div style="margin-top:6px;">${escapeHtml(msg.message)}</div>
                            <div style="font-size:10px; color:#64748b; margin-top:8px;">${msg.time}</div>
                        </div>
                    `;
                });
                document.getElementById('chatMessages').innerHTML = html || '<div style="text-align:center; padding:20px;">No messages yet</div>';
                document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
            }
        } catch (error) {
            document.getElementById('chatMessages').innerHTML = '<div style="text-align:center; padding:20px; color:red;">Failed to load messages</div>';
        }
    }
    
    function startChatRefresh() {
        if (chatRefreshInterval) clearInterval(chatRefreshInterval);
        chatRefreshInterval = setInterval(() => {
            if (currentNegotiationId && document.getElementById('chatModal').style.display === 'flex') {
                loadChatMessages(currentNegotiationId);
            }
        }, 5000);
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        if (modalId === 'chatModal' && chatRefreshInterval) {
            clearInterval(chatRefreshInterval);
            currentNegotiationId = null;
        }
    }
    
    function formatMoney(amount) {
        return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(amount) + ' ETB';
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
            if (chatRefreshInterval) clearInterval(chatRefreshInterval);
        }
    }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>