<?php
// ============================================
// FILE: broker_system/admin/negotiations.php
// ============================================
// Admin Negotiation Management - Complete

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
            // Update negotiation with proposed terms
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
                // Get seller info for notification
                $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
                if ($neg) {
                    // Get listing title
                    $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
                    
                    // Send notification to seller
                    $notif_stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, title, message, link, created_at) 
                        VALUES (?, ?, ?, '/broker_system/user/negotiations.php', NOW())
                    ");
                    $title = "Commission Proposal for {$listing['title']}";
                    $msg = "Admin has proposed {$commission}% commission and " . formatMoney($deposit) . " deposit for your listing. Please review and respond.";
                    $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                    $notif_stmt->execute();
                    
                    // Add system message to negotiation chat
                    $chat_table = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
                    if ($chat_table->num_rows > 0) {
                        $system_msg = "Admin has proposed {$commission}% commission and " . formatMoney($deposit) . " deposit. Please review and respond.";
                        $msg_stmt = $conn->prepare("
                            INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                            VALUES (?, 0, 'system', ?, NOW())
                        ");
                        $msg_stmt->bind_param("is", $negotiation_id, $system_msg);
                        $msg_stmt->execute();
                    }
                }
                $message = "Commission and deposit proposed successfully! Seller has been notified.";
            } else {
                $error = "Failed to propose terms: " . $conn->error;
            }
        } else {
            $error = "Please enter valid commission and deposit amounts.";
        }
    } 
    elseif ($action === 'accept_counter') {
        // Get counter offer details
        $neg = $conn->query("SELECT counter_commission, counter_deposit, seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        
        if ($neg && $neg['counter_commission']) {
            $stmt = $conn->prepare("
                UPDATE listing_negotiations 
                SET proposed_commission = counter_commission,
                    proposed_deposit = counter_deposit,
                    counter_commission = NULL,
                    counter_deposit = NULL,
                    counter_message = NULL,
                    status = 'agreement_accepted',
                    accepted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("i", $negotiation_id);
            
            if ($stmt->execute()) {
                // Get listing title
                $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
                
                // Send notification to seller
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, link, created_at) 
                    VALUES (?, ?, ?, '/broker_system/user/negotiations.php', NOW())
                ");
                $title = "Counter Offer Accepted - {$listing['title']}";
                $msg = "Great news! Your counter offer has been accepted. Please proceed with deposit payment to publish your listing.";
                $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                $notif_stmt->execute();
                
                // Add system message
                $chat_table = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
                if ($chat_table->num_rows > 0) {
                    $system_msg = "Admin has accepted your counter offer! Please proceed with deposit payment.";
                    $msg_stmt = $conn->prepare("
                        INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                        VALUES (?, 0, 'system', ?, NOW())
                    ");
                    $msg_stmt->bind_param("is", $negotiation_id, $system_msg);
                    $msg_stmt->execute();
                }
                
                $message = "Counter offer accepted! Waiting for seller to pay deposit.";
            } else {
                $error = "Failed to accept counter offer";
            }
        }
    } 
    elseif ($action === 'reject_counter') {
        $stmt = $conn->prepare("
            UPDATE listing_negotiations 
            SET counter_commission = NULL,
                counter_deposit = NULL,
                counter_message = NULL,
                status = 'commission_proposed',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("i", $negotiation_id);
        
        if ($stmt->execute()) {
            // Get seller info
            $neg = $conn->query("SELECT seller_id, listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
            if ($neg) {
                $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
                
                // Send notification
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, link, created_at) 
                    VALUES (?, ?, ?, '/broker_system/user/negotiations.php', NOW())
                ");
                $title = "Counter Offer Rejected - {$listing['title']}";
                $msg = "Your counter offer has been rejected. The original proposal is still available for acceptance.";
                $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                $notif_stmt->execute();
                
                // Add system message
                $chat_table = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
                if ($chat_table->num_rows > 0) {
                    $system_msg = "Admin has rejected your counter offer. The original proposal is still available.";
                    $msg_stmt = $conn->prepare("
                        INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                        VALUES (?, 0, 'system', ?, NOW())
                    ");
                    $msg_stmt->bind_param("is", $negotiation_id, $system_msg);
                    $msg_stmt->execute();
                }
            }
            $message = "Counter offer rejected. Original proposal remains active.";
        } else {
            $error = "Failed to reject counter offer";
        }
    }
    elseif ($action === 'approve_payment') {
        // Get negotiation details
        $neg = $conn->query("
            SELECT ln.*, l.id as listing_id, l.title, u.email as seller_email 
            FROM listing_negotiations ln
            JOIN listings l ON ln.listing_id = l.id
            JOIN users u ON ln.seller_id = u.id
            WHERE ln.id = $negotiation_id
        ")->fetch_assoc();
        
        if ($neg) {
            $conn->begin_transaction();
            
            try {
                // Update negotiation status
                $conn->query("
                    UPDATE listing_negotiations 
                    SET status = 'published', 
                        published_at = NOW(),
                        updated_at = NOW()
                    WHERE id = $negotiation_id
                ");
                
                // Update listing status to active
                $final_commission = $neg['counter_commission'] ?: $neg['proposed_commission'];
                $final_deposit = $neg['counter_deposit'] ?: $neg['proposed_deposit'];
                
                $conn->query("
                    UPDATE listings 
                    SET status = 'active', 
                        approval_status = 'approved',
                        admin_commission_percent = $final_commission,
                        admin_deposit_percent = ($final_deposit / price) * 100,
                        updated_at = NOW()
                    WHERE id = {$neg['listing_id']}
                ");
                
                // Create payment record if not exists
                $check_payment = $conn->query("
                    SELECT id FROM payments 
                    WHERE transaction_id IN (SELECT id FROM transactions WHERE listing_id = {$neg['listing_id']})
                    AND type = 'deposit_seller'
                ");
                
                if ($check_payment->num_rows == 0) {
                    $conn->query("
                        INSERT INTO payments (user_id, amount, type, status, created_at) 
                        VALUES ({$neg['seller_id']}, $final_deposit, 'deposit_seller', 'confirmed', NOW())
                    ");
                }
                
                // Send notification to seller
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, link, created_at) 
                    VALUES (?, ?, ?, '/broker_system/user/product.php?id={$neg['listing_id']}', NOW())
                ");
                $title = "🎉 Your Listing is Live! - {$neg['title']}";
                $msg = "Congratulations! Your listing has been published and is now visible to buyers. Start receiving inquiries!";
                $notif_stmt->bind_param("iss", $neg['seller_id'], $title, $msg);
                $notif_stmt->execute();
                
                // Add system message
                $chat_table = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
                if ($chat_table->num_rows > 0) {
                    $system_msg = "🎉 Congratulations! Your listing has been published and is now live on the marketplace.";
                    $msg_stmt = $conn->prepare("
                        INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                        VALUES (?, 0, 'system', ?, NOW())
                    ");
                    $msg_stmt->bind_param("is", $negotiation_id, $system_msg);
                    $msg_stmt->execute();
                }
                
                $conn->commit();
                $message = "Payment verified! Listing has been published successfully.";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to publish listing: " . $e->getMessage();
            }
        }
    }
    elseif ($action === 'send_message') {
        $message_text = $_POST['message'] ?? '';
        $negotiation_id = intval($_POST['negotiation_id'] ?? 0);
        
        if (!empty($message_text) && $negotiation_id > 0) {
            $chat_table = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
            if ($chat_table->num_rows > 0) {
                $msg_stmt = $conn->prepare("
                    INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                    VALUES (?, ?, 'admin', ?, NOW())
                ");
                $admin_id = $_SESSION['user_id'];
                $msg_stmt->bind_param("iis", $negotiation_id, $admin_id, $message_text);
                $msg_stmt->execute();
                
                // Get seller ID for notification
                $neg = $conn->query("SELECT seller_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
                if ($neg) {
                    $notif_stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, title, message, link, created_at) 
                        VALUES (?, 'New Message', ?, '/broker_system/user/negotiate.php?id=$negotiation_id', NOW())
                    ");
                    $notif_stmt->bind_param("is", $neg['seller_id'], $message_text);
                    $notif_stmt->execute();
                }
                
                $message = "Message sent to seller!";
            } else {
                $error = "Chat system not available";
            }
        }
    }
}

// Get all negotiations with different statuses
$pending_review = $conn->query("
    SELECT ln.*, l.title, l.type, l.price, l.created_at as listing_created,
           u.full_name as seller_name, u.email as seller_email,
           (SELECT COUNT(*) FROM negotiation_messages WHERE negotiation_id = ln.id AND is_read = 0 AND sender_type = 'seller') as unread_count
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'under_review'
    ORDER BY ln.created_at ASC
");

$proposed = $conn->query("
    SELECT ln.*, l.title, l.type, l.price,
           u.full_name as seller_name, u.email as seller_email,
           (SELECT COUNT(*) FROM negotiation_messages WHERE negotiation_id = ln.id AND is_read = 0 AND sender_type = 'seller') as unread_count
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'commission_proposed'
    ORDER BY ln.updated_at ASC
");

$counter_offers = $conn->query("
    SELECT ln.*, l.title, l.type, l.price,
           u.full_name as seller_name, u.email as seller_email,
           (SELECT COUNT(*) FROM negotiation_messages WHERE negotiation_id = ln.id AND is_read = 0 AND sender_type = 'seller') as unread_count
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'counter_offer_sent'
    ORDER BY ln.updated_at ASC
");

$awaiting_payment = $conn->query("
    SELECT ln.*, l.title, l.type, l.price,
           u.full_name as seller_name, u.email as seller_email,
           (SELECT COUNT(*) FROM negotiation_messages WHERE negotiation_id = ln.id AND is_read = 0 AND sender_type = 'seller') as unread_count
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    JOIN users u ON ln.seller_id = u.id
    WHERE ln.status = 'agreement_accepted'
    ORDER BY ln.accepted_at ASC
");

// Count statistics
$stats = [
    'pending' => $pending_review ? $pending_review->num_rows : 0,
    'proposed' => $proposed ? $proposed->num_rows : 0,
    'counter' => $counter_offers ? $counter_offers->num_rows : 0,
    'payment' => $awaiting_payment ? $awaiting_payment->num_rows : 0
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
        --info: #3b82f6;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .admin-negotiations {
        max-width: 1400px;
        margin: 0 auto;
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
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 13px;
        color: var(--gray);
        margin-top: 6px;
    }
    
    /* Section Cards */
    .section-card {
        background: white;
        border-radius: 24px;
        margin-bottom: 32px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .section-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        font-size: 18px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .section-header i {
        margin-right: 8px;
    }
    
    .badge-count {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 13px;
    }
    
    /* Negotiation Items */
    .negotiation-item {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border);
        transition: all 0.3s;
    }
    
    .negotiation-item:hover {
        background: var(--light);
    }
    
    .negotiation-item:last-child {
        border-bottom: none;
    }
    
    .item-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 12px;
    }
    
    .listing-info {
        flex: 1;
    }
    
    .listing-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .seller-info {
        font-size: 12px;
        color: var(--gray);
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        margin-top: 4px;
    }
    
    .seller-info i {
        width: 14px;
        color: var(--primary);
    }
    
    .price-info {
        text-align: right;
    }
    
    .price {
        font-size: 18px;
        font-weight: 700;
        color: var(--primary);
    }
    
    .type-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        margin-top: 4px;
    }
    
    .type-rental { background: #dbeafe; color: #1e40af; }
    .type-product { background: #d1fae5; color: #065f46; }
    .type-job { background: #fed7aa; color: #9a3412; }
    
    /* Offer Details */
    .offer-details {
        display: flex;
        gap: 24px;
        flex-wrap: wrap;
        margin: 12px 0;
        padding: 12px;
        background: var(--light);
        border-radius: 12px;
    }
    
    .offer-detail {
        display: flex;
        align-items: baseline;
        gap: 8px;
    }
    
    .offer-label {
        font-size: 11px;
        color: var(--gray);
    }
    
    .offer-value {
        font-size: 16px;
        font-weight: 700;
    }
    
    .offer-value.proposed { color: var(--primary); }
    .offer-value.counter { color: var(--warning); }
    
    /* Counter Offer Box */
    .counter-box {
        background: #fef3c7;
        border-radius: 12px;
        padding: 12px;
        margin: 12px 0;
        border-left: 3px solid var(--warning);
    }
    
    .counter-message {
        font-size: 12px;
        color: #92400e;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #fde68a;
    }
    
    /* Action Buttons */
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
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .btn-primary { background: var(--primary); color: white; }
    .btn-success { background: var(--success); color: white; }
    .btn-warning { background: var(--warning); color: white; }
    .btn-danger { background: var(--danger); color: white; }
    .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--gray); }
    
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
        border-bottom: 1px solid var(--border);
    }
    
    .modal-header h3 {
        font-size: 20px;
        font-weight: 600;
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
        margin-bottom: 16px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        font-size: 13px;
        color: var(--dark);
    }
    
    .form-group input, .form-group textarea, .form-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 14px;
    }
    
    .form-group input:focus, .form-group textarea:focus {
        outline: none;
        border-color: var(--primary);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    
    .info-text {
        font-size: 11px;
        color: var(--gray);
        margin-top: 4px;
    }
    
    .empty-state {
        padding: 40px;
        text-align: center;
        color: var(--gray);
    }
    
    .unread-indicator {
        background: var(--danger);
        color: white;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 8px;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .item-header {
            flex-direction: column;
        }
        .price-info {
            text-align: left;
        }
        .offer-details {
            flex-direction: column;
            gap: 8px;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn-sm {
            justify-content: center;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="admin-negotiations">
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card" onclick="document.getElementById('pendingSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Review</div>
        </div>
        <div class="stat-card" onclick="document.getElementById('proposedSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['proposed']; ?></div>
            <div class="stat-label">Awaiting Seller Response</div>
        </div>
        <div class="stat-card" onclick="document.getElementById('counterSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['counter']; ?></div>
            <div class="stat-label">Counter Offers Received</div>
        </div>
        <div class="stat-card" onclick="document.getElementById('paymentSection').scrollIntoView({behavior: 'smooth'})">
            <div class="stat-value"><?php echo $stats['payment']; ?></div>
            <div class="stat-label">Awaiting Payment Verification</div>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success" style="background: #d1fae5; color: #059669; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="background: #fee2e2; color: #dc2626; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Pending Review Section -->
    <div id="pendingSection" class="section-card">
        <div class="section-header">
            <div>
                <i class="fas fa-clock"></i> Pending Review
            </div>
            <div class="badge-count"><?php echo $stats['pending']; ?> listings</div>
        </div>
        <?php if ($pending_review && $pending_review->num_rows > 0): ?>
            <?php while($neg = $pending_review->fetch_assoc()): ?>
                <div class="negotiation-item">
                    <div class="item-header">
                        <div class="listing-info">
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?></span>
                                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($neg['seller_email']); ?></span>
                                <span class="type-badge type-<?php echo $neg['type']; ?>">
                                    <?php 
                                    if ($neg['type'] == 'rental') echo '🏠 Rental';
                                    elseif ($neg['type'] == 'product') echo '🚗 Product';
                                    else echo '💼 Job';
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="price-info">
                            <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                            <div class="listing-date" style="font-size: 11px; color: var(--gray);">
                                <i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($neg['listing_created'])); ?>
                            </div>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button onclick="openProposeModal(<?php echo $neg['id']; ?>, <?php echo $neg['price']; ?>, '<?php echo $neg['type']; ?>')" class="btn-sm btn-primary">
                            <i class="fas fa-percent"></i> Review & Propose Terms
                        </button>
                        <button onclick="viewListingDetails(<?php echo $neg['listing_id']; ?>)" class="btn-sm btn-outline">
                            <i class="fas fa-eye"></i> View Listing
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 12px; display: block;"></i>
                No pending reviews. All listings have been processed.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Proposed - Awaiting Seller Response -->
    <div id="proposedSection" class="section-card">
        <div class="section-header">
            <div>
                <i class="fas fa-hourglass-half"></i> Awaiting Seller Response
            </div>
            <div class="badge-count"><?php echo $stats['proposed']; ?> listings</div>
        </div>
        <?php if ($proposed && $proposed->num_rows > 0): ?>
            <?php while($neg = $proposed->fetch_assoc()): ?>
                <div class="negotiation-item">
                    <div class="item-header">
                        <div class="listing-info">
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?></span>
                                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($neg['seller_email']); ?></span>
                            </div>
                        </div>
                        <div class="price-info">
                            <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                        </div>
                    </div>
                    <div class="offer-details">
                        <div class="offer-detail">
                            <span class="offer-label">Proposed Commission:</span>
                            <span class="offer-value proposed"><?php echo $neg['proposed_commission']; ?>%</span>
                        </div>
                        <div class="offer-detail">
                            <span class="offer-label">Proposed Deposit:</span>
                            <span class="offer-value proposed"><?php echo formatMoney($neg['proposed_deposit']); ?></span>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button onclick="openChatModal(<?php echo $neg['id']; ?>)" class="btn-sm btn-primary">
                            <i class="fas fa-comments"></i> Chat with Seller
                            <?php if ($neg['unread_count'] > 0): ?>
                                <span class="unread-indicator"><?php echo $neg['unread_count']; ?></span>
                            <?php endif; ?>
                        </button>
                        <button onclick="openProposeModal(<?php echo $neg['id']; ?>, <?php echo $neg['price']; ?>, '<?php echo $neg['type']; ?>')" class="btn-sm btn-outline">
                            <i class="fas fa-edit"></i> Modify Offer
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">No pending seller responses</div>
        <?php endif; ?>
    </div>
    
    <!-- Counter Offers Received -->
    <div id="counterSection" class="section-card">
        <div class="section-header">
            <div>
                <i class="fas fa-exchange-alt"></i> Counter Offers Received
            </div>
            <div class="badge-count"><?php echo $stats['counter']; ?> listings</div>
        </div>
        <?php if ($counter_offers && $counter_offers->num_rows > 0): ?>
            <?php while($neg = $counter_offers->fetch_assoc()): ?>
                <div class="negotiation-item">
                    <div class="item-header">
                        <div class="listing-info">
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?></span>
                                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($neg['seller_email']); ?></span>
                            </div>
                        </div>
                        <div class="price-info">
                            <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                        </div>
                    </div>
                    <div class="offer-details">
                        <div class="offer-detail">
                            <span class="offer-label">Original Offer:</span>
                            <span class="offer-value proposed"><?php echo $neg['proposed_commission']; ?>% / <?php echo formatMoney($neg['proposed_deposit']); ?></span>
                        </div>
                        <div class="offer-detail">
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
                            <button type="submit" class="btn-sm btn-success" onclick="return confirm('Accept this counter offer? The seller will then need to pay the deposit.')">
                                <i class="fas fa-check"></i> Accept Counter Offer
                            </button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $neg['id']; ?>">
                            <input type="hidden" name="action" value="reject_counter">
                            <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject this counter offer? The original offer will remain active.')">
                                <i class="fas fa-times"></i> Reject Counter Offer
                            </button>
                        </form>
                        <button onclick="openChatModal(<?php echo $neg['id']; ?>)" class="btn-sm btn-primary">
                            <i class="fas fa-comments"></i> Chat
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">No counter offers received</div>
        <?php endif; ?>
    </div>
    
    <!-- Awaiting Payment Verification -->
    <div id="paymentSection" class="section-card">
        <div class="section-header">
            <div>
                <i class="fas fa-credit-card"></i> Awaiting Payment Verification
            </div>
            <div class="badge-count"><?php echo $stats['payment']; ?> listings</div>
        </div>
        <?php if ($awaiting_payment && $awaiting_payment->num_rows > 0): ?>
            <?php while($neg = $awaiting_payment->fetch_assoc()): ?>
                <div class="negotiation-item">
                    <div class="item-header">
                        <div class="listing-info">
                            <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                            <div class="seller-info">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($neg['seller_name']); ?></span>
                                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($neg['seller_email']); ?></span>
                            </div>
                        </div>
                        <div class="price-info">
                            <div class="price"><?php echo formatMoney($neg['price']); ?></div>
                        </div>
                    </div>
                    <div class="offer-details">
                        <div class="offer-detail">
                            <span class="offer-label">Agreed Commission:</span>
                            <span class="offer-value proposed"><?php echo $neg['counter_commission'] ?: $neg['proposed_commission']; ?>%</span>
                        </div>
                        <div class="offer-detail">
                            <span class="offer-label">Deposit to Collect:</span>
                            <span class="offer-value proposed"><?php echo formatMoney($neg['counter_deposit'] ?: $neg['proposed_deposit']); ?></span>
                        </div>
                        <div class="offer-detail">
                            <span class="offer-label">Agreed On:</span>
                            <span class="offer-value"><?php echo date('M d, Y', strtotime($neg['accepted_at'])); ?></span>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="negotiation_id" value="<?php echo $neg['id']; ?>">
                            <input type="hidden" name="action" value="approve_payment">
                            <button type="submit" class="btn-sm btn-success" onclick="return confirm('Verify payment and publish this listing? This action cannot be undone.')">
                                <i class="fas fa-check-circle"></i> Verify Payment & Publish
                            </button>
                        </form>
                        <button onclick="openChatModal(<?php echo $neg['id']; ?>)" class="btn-sm btn-primary">
                            <i class="fas fa-comments"></i> Contact Seller
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">No listings awaiting payment verification</div>
        <?php endif; ?>
    </div>
</div>

<!-- Propose Modal -->
<div id="proposeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-percent"></i> Propose Commission & Deposit</h3>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <form method="POST" id="proposeForm">
            <input type="hidden" name="negotiation_id" id="proposeNegotiationId">
            <input type="hidden" name="action" value="propose">
            
            <div class="form-group">
                <label>Listing Price</label>
                <input type="text" id="listingPriceDisplay" disabled style="background: var(--light);">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Commission (%)</label>
                    <input type="number" name="commission_percent" id="commissionPercent" step="0.5" min="1" max="20" required>
                    <div id="commissionRecommendation" class="info-text"></div>
                </div>
                <div class="form-group">
                    <label>Deposit Amount (ETB)</label>
                    <input type="number" name="deposit_amount" id="depositAmount" step="100" min="0" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Featured Listing Fee (Optional)</label>
                <input type="number" name="featured_fee" id="featuredFee" step="100" min="0" value="0">
                <div class="info-text">Featured listings appear at the top of search results</div>
            </div>
            
            <div class="form-group">
                <label>Admin Notes (Optional)</label>
                <textarea name="admin_notes" rows="3" placeholder="Add any notes for the seller..."></textarea>
            </div>
            
            <button type="submit" class="btn-sm btn-primary" style="width: 100%; padding: 12px;">
                <i class="fas fa-paper-plane"></i> Send Proposal to Seller
            </button>
        </form>
    </div>
</div>

<!-- Chat Modal -->
<div id="chatModal" class="modal">
    <div class="modal-content" style="width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-comments"></i> Negotiation Chat</h3>
            <span class="close-modal" onclick="closeChatModal()">&times;</span>
        </div>
        <div id="chatContent" style="max-height: 400px; overflow-y: auto; margin-bottom: 16px; padding: 12px; background: var(--light); border-radius: 12px;">
            <div class="loading" style="text-align: center; padding: 20px;">Loading messages...</div>
        </div>
        <form method="POST" id="chatForm">
            <input type="hidden" name="negotiation_id" id="chatNegotiationId">
            <input type="hidden" name="action" value="send_message">
            <textarea name="message" class="form-group" rows="3" placeholder="Type your message to the seller..." required style="width: 100%; margin-bottom: 12px;"></textarea>
            <button type="submit" class="btn-sm btn-primary" style="width: 100%; padding: 12px;">
                <i class="fas fa-paper-plane"></i> Send Message
            </button>
        </form>
    </div>
</div>

<script>
// Scroll to section when clicking stat card
let currentNegotiationId = null;

function openProposeModal(negotiationId, price, type) {
    document.getElementById('proposeNegotiationId').value = negotiationId;
    document.getElementById('listingPriceDisplay').value = formatMoney(price);
    
    // Smart commission recommendation
    let recommendedCommission = 5;
    if (type === 'rental') recommendedCommission = 7;
    else if (type === 'product') recommendedCommission = 5;
    else if (type === 'job') recommendedCommission = 8;
    
    if (price > 2000000) recommendedCommission = 3;
    else if (price >= 500000) recommendedCommission = 5;
    
    document.getElementById('commissionPercent').value = recommendedCommission;
    document.getElementById('commissionRecommendation').innerHTML = 
        `<i class="fas fa-robot"></i> AI Recommendation: ${recommendedCommission}% based on listing value`;
    
    // Recommended deposit (25% of price, capped at 50,000)
    let recommendedDeposit = price * 0.25;
    if (recommendedDeposit > 50000) recommendedDeposit = 50000;
    document.getElementById('depositAmount').value = Math.round(recommendedDeposit);
    
    document.getElementById('proposeModal').style.display = 'flex';
}

function openChatModal(negotiationId) {
    currentNegotiationId = negotiationId;
    document.getElementById('chatNegotiationId').value = negotiationId;
    document.getElementById('chatModal').style.display = 'flex';
    
    // Load chat messages via AJAX
    fetch(`ajax/get_negotiation_messages.php?id=${negotiationId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                data.messages.forEach(msg => {
                    const isAdmin = msg.sender_type === 'admin';
                    const isSystem = msg.sender_type === 'system';
                    const bgColor = isSystem ? '#fef3c7' : (isAdmin ? '#e0e7ff' : '#f8fafc');
                    const textColor = isSystem ? '#92400e' : (isAdmin ? '#1e40af' : '#1e293b');
                    const align = isSystem ? 'center' : (isAdmin ? 'left' : 'right');
                    
                    html += `
                        <div style="margin-bottom: 16px; padding: 12px; background: ${bgColor}; border-radius: 12px; text-align: ${align};">
                            <strong style="color: ${textColor};">
                                ${isSystem ? '📢 System' : (isAdmin ? '👨‍💼 Admin' : '👤 Seller')}
                            </strong>
                            <div style="font-size: 13px; margin-top: 6px; color: ${textColor};">${escapeHtml(msg.message)}</div>
                            <div style="font-size: 10px; color: #64748b; margin-top: 8px;">${msg.time}</div>
                        </div>
                    `;
                });
                document.getElementById('chatContent').innerHTML = html || '<div style="text-align: center; padding: 20px;">No messages yet. Start the conversation!</div>';
            }
        })
        .catch(error => {
            document.getElementById('chatContent').innerHTML = '<div style="text-align: center; padding: 20px; color: red;">Failed to load messages</div>';
        });
}

function viewListingDetails(listingId) {
    window.open(`/broker_system/user/product.php?id=${listingId}`, '_blank');
}

function closeModal() {
    document.getElementById('proposeModal').style.display = 'none';
}

function closeChatModal() {
    document.getElementById('chatModal').style.display = 'none';
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount) + ' ETB';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-refresh chat modal every 10 seconds if open
let chatRefreshInterval = null;

function startChatRefresh() {
    if (chatRefreshInterval) clearInterval(chatRefreshInterval);
    chatRefreshInterval = setInterval(() => {
        if (currentNegotiationId && document.getElementById('chatModal').style.display === 'flex') {
            fetch(`ajax/get_negotiation_messages.php?id=${currentNegotiationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.messages) {
                        let html = '';
                        data.messages.forEach(msg => {
                            const isAdmin = msg.sender_type === 'admin';
                            const isSystem = msg.sender_type === 'system';
                            const bgColor = isSystem ? '#fef3c7' : (isAdmin ? '#e0e7ff' : '#f8fafc');
                            const textColor = isSystem ? '#92400e' : (isAdmin ? '#1e40af' : '#1e293b');
                            const align = isSystem ? 'center' : (isAdmin ? 'left' : 'right');
                            
                            html += `
                                <div style="margin-bottom: 16px; padding: 12px; background: ${bgColor}; border-radius: 12px; text-align: ${align};">
                                    <strong style="color: ${textColor};">${isSystem ? '📢 System' : (isAdmin ? '👨‍💼 Admin' : '👤 Seller')}</strong>
                                    <div style="font-size: 13px; margin-top: 6px;">${escapeHtml(msg.message)}</div>
                                    <div style="font-size: 10px; color: #64748b; margin-top: 8px;">${msg.time}</div>
                                </div>
                            `;
                        });
                        document.getElementById('chatContent').innerHTML = html;
                        document.getElementById('chatContent').scrollTop = document.getElementById('chatContent').scrollHeight;
                    }
                });
        }
    }, 10000);
}

startChatRefresh();

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Scroll chat to bottom when opened
document.getElementById('chatModal').addEventListener('click', function() {
    setTimeout(() => {
        const chatContent = document.getElementById('chatContent');
        if (chatContent) chatContent.scrollTop = chatContent.scrollHeight;
    }, 100);
});
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>