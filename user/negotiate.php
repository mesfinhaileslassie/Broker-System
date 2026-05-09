<?php
// ============================================
// FILE: broker_system/user/negotiate.php
// ============================================
// Detailed Negotiation Page - FIXED

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check login FIRST
requireLogin();

$page_title = 'Negotiate Listing';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$negotiation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Get negotiation details
$stmt = $conn->prepare("
    SELECT ln.*, l.title, l.type, l.price, l.description, l.cover_image,
           l.location, l.category_id
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    WHERE ln.id = ? AND ln.seller_id = ?
");
$stmt->bind_param("ii", $negotiation_id, $user_id);
$stmt->execute();
$negotiation = $stmt->get_result()->fetch_assoc();

if (!$negotiation) {
    header('Location: negotiations.php');
    exit;
}

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    if ($post_action === 'accept_terms') {
        $update = $conn->prepare("
            UPDATE listing_negotiations 
            SET status = 'agreement_accepted', accepted_at = NOW() 
            WHERE id = ? AND seller_id = ?
        ");
        $update->bind_param("ii", $negotiation_id, $user_id);
        if ($update->execute()) {
            $message = "Terms accepted! Please pay the deposit to publish your listing.";
            // Refresh data
            $stmt2 = $conn->prepare("
                SELECT ln.*, l.title, l.type, l.price 
                FROM listing_negotiations ln
                JOIN listings l ON ln.listing_id = l.id
                WHERE ln.id = ?
            ");
            $stmt2->bind_param("i", $negotiation_id);
            $stmt2->execute();
            $negotiation = $stmt2->get_result()->fetch_assoc();
        }
    } 
    elseif ($post_action === 'send_counter') {
        $counter_commission = floatval($_POST['counter_commission'] ?? 0);
        $counter_deposit = floatval($_POST['counter_deposit'] ?? 0);
        $counter_message = $_POST['counter_message'] ?? '';
        
        if ($counter_commission > 0 && $counter_deposit > 0) {
            $update = $conn->prepare("
                UPDATE listing_negotiations 
                SET counter_commission = ?, counter_deposit = ?, 
                    counter_message = ?, status = 'counter_offer_sent' 
                WHERE id = ? AND seller_id = ?
            ");
            $update->bind_param("ddssi", $counter_commission, $counter_deposit, $counter_message, $negotiation_id, $user_id);
            if ($update->execute()) {
                $message = "Counter offer sent successfully! Waiting for admin response.";
                // Refresh data
                $stmt2 = $conn->prepare("
                    SELECT ln.*, l.title, l.type, l.price 
                    FROM listing_negotiations ln
                    JOIN listings l ON ln.listing_id = l.id
                    WHERE ln.id = ?
                ");
                $stmt2->bind_param("i", $negotiation_id);
                $stmt2->execute();
                $negotiation = $stmt2->get_result()->fetch_assoc();
            }
        } else {
            $error = "Please enter valid commission and deposit amounts.";
        }
    }
    elseif ($post_action === 'send_message') {
        $msg_text = $_POST['message'] ?? '';
        if (!empty($msg_text)) {
            $table_check = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
            if ($table_check->num_rows > 0) {
                $msg_stmt = $conn->prepare("
                    INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                    VALUES (?, ?, 'seller', ?, NOW())
                ");
                $msg_stmt->bind_param("iis", $negotiation_id, $user_id, $msg_text);
                $msg_stmt->execute();
                $message = "Message sent!";
            } else {
                $message = "Message sent! Admin will review your message.";
            }
        }
    }
}

// Get messages
$messages = array();
$table_check = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
if ($table_check->num_rows > 0) {
    $msg_result = $conn->query("
        SELECT nm.*, 
               CASE WHEN nm.sender_type = 'admin' THEN 'Admin' ELSE 'You' END as sender_name
        FROM negotiation_messages nm
        WHERE nm.negotiation_id = $negotiation_id
        ORDER BY nm.created_at ASC
    ");
    while($row = $msg_result->fetch_assoc()) {
        $messages[] = $row;
    }
}

$conn->close();

// Determine which display values to use
$display_commission = $negotiation['counter_commission'] ?: $negotiation['proposed_commission'];
$display_deposit = $negotiation['counter_deposit'] ?: $negotiation['proposed_deposit'];
$is_accepted = ($negotiation['status'] == 'agreement_accepted');
$is_published = ($negotiation['status'] == 'published');
$can_accept = ($negotiation['status'] == 'commission_proposed' || $negotiation['status'] == 'counter_offer_sent');
$can_counter = ($negotiation['status'] == 'commission_proposed');
?>

<style>
    .negotiate-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .page-header {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        color: white;
    }
    
    .page-header h1 {
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .listing-info {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .listing-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .listing-price {
        font-size: 24px;
        font-weight: 700;
        color: #667eea;
    }
    
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-top: 12px;
    }
    
    .offer-section {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .section-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .offer-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .offer-card {
        background: #f8fafc;
        border-radius: 16px;
        padding: 20px;
        text-align: center;
        border-left: 4px solid #667eea;
    }
    
    .offer-card.counter {
        border-left-color: #f59e0b;
    }
    
    .offer-label {
        font-size: 12px;
        color: #64748b;
        margin-bottom: 8px;
    }
    
    .offer-value {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .offer-value.proposed {
        color: #667eea;
    }
    
    .offer-value.counter {
        color: #f59e0b;
    }
    
    .counter-form {
        background: #fef3c7;
        border-radius: 16px;
        padding: 20px;
        margin-top: 20px;
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
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    
    .chat-section {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .chat-messages {
        height: 300px;
        overflow-y: auto;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
        background: #f8fafc;
    }
    
    .message {
        margin-bottom: 16px;
        padding: 10px 14px;
        border-radius: 12px;
        max-width: 80%;
    }
    
    .message.admin {
        background: #e0e7ff;
        margin-right: auto;
    }
    
    .message.seller {
        background: #d1fae5;
        margin-left: auto;
    }
    
    .system-message {
        background: #fef3c7;
        text-align: center;
        margin: 8px auto;
        max-width: 90%;
        font-size: 12px;
        color: #92400e;
    }
    
    .message-sender {
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .message-text {
        font-size: 13px;
    }
    
    .message-time {
        font-size: 9px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .chat-input {
        display: flex;
        gap: 12px;
    }
    
    .chat-input textarea {
        flex: 1;
        padding: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        resize: none;
        font-family: inherit;
    }
    
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
    }
    
    .btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-warning {
        background: #f59e0b;
        color: white;
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid #e2e8f0;
        color: #64748b;
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
        .offer-grid {
            grid-template-columns: 1fr;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
    }
</style>

<div class="negotiate-container">
    <div class="page-header">
        <h1><i class="fas fa-handshake"></i> Negotiate Listing</h1>
        <p>Review terms, send counter offers, or accept the agreement</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Listing Information -->
    <div class="listing-info">
        <div class="listing-title"><?php echo htmlspecialchars($negotiation['title']); ?></div>
        <div class="listing-price"><?php echo formatMoney($negotiation['price']); ?></div>
        <?php if ($negotiation['location']): ?>
            <div style="color: #64748b; margin-top: 8px;">
                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($negotiation['location']); ?>
            </div>
        <?php endif; ?>
        <div class="status-badge" style="background: #e2e8f0; color: #475569;">
            Status: <?php echo ucfirst(str_replace('_', ' ', $negotiation['status'])); ?>
        </div>
    </div>
    
    <!-- Offer Details -->
    <div class="offer-section">
        <div class="section-title"><i class="fas fa-percent"></i> Current Offer</div>
        
        <div class="offer-grid">
            <div class="offer-card">
                <div class="offer-label">Commission Rate</div>
                <div class="offer-value proposed">
                    <?php echo $negotiation['proposed_commission'] ? $negotiation['proposed_commission'] . '%' : '—'; ?>
                </div>
                <div style="font-size: 12px; color: #64748b; margin-top: 8px;">Proposed by Admin</div>
            </div>
            <div class="offer-card">
                <div class="offer-label">Deposit Amount</div>
                <div class="offer-value proposed">
                    <?php echo $negotiation['proposed_deposit'] ? formatMoney($negotiation['proposed_deposit']) : '—'; ?>
                </div>
                <div style="font-size: 12px; color: #64748b; margin-top: 8px;">Proposed by Admin</div>
            </div>
        </div>
        
        <?php if ($negotiation['counter_commission']): ?>
        <div class="offer-grid" style="margin-top: 16px;">
            <div class="offer-card counter">
                <div class="offer-label">Your Counter Offer - Commission</div>
                <div class="offer-value counter"><?php echo $negotiation['counter_commission']; ?>%</div>
            </div>
            <div class="offer-card counter">
                <div class="offer-label">Your Counter Offer - Deposit</div>
                <div class="offer-value counter"><?php echo formatMoney($negotiation['counter_deposit']); ?></div>
            </div>
        </div>
        <?php if ($negotiation['counter_message']): ?>
            <div style="background: #fef3c7; padding: 12px; border-radius: 12px; margin-top: 12px;">
                <strong>Your Note:</strong> <?php echo htmlspecialchars($negotiation['counter_message']); ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Counter Offer Form -->
        <?php if ($can_counter && !$is_accepted && !$is_published): ?>
        <div class="counter-form">
            <h4 style="margin-bottom: 16px; font-weight: 600;">Send Counter Offer</h4>
            <form method="POST">
                <input type="hidden" name="action" value="send_counter">
                <div class="form-row">
                    <div class="form-group">
                        <label>Your Proposed Commission (%)</label>
                        <input type="number" name="counter_commission" step="0.5" min="1" max="20" required 
                               value="<?php echo $negotiation['proposed_commission']; ?>">
                    </div>
                    <div class="form-group">
                        <label>Your Proposed Deposit (ETB)</label>
                        <input type="number" name="counter_deposit" step="100" min="0" required
                               value="<?php echo $negotiation['proposed_deposit']; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Message (Optional)</label>
                    <textarea name="counter_message" rows="3" placeholder="Explain your counter offer..."></textarea>
                </div>
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-paper-plane"></i> Send Counter Offer
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <?php if ($can_accept && !$is_accepted && !$is_published): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="accept_terms">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Accept these terms? You will need to pay the deposit to publish your listing.')">
                        <i class="fas fa-check-circle"></i> Accept Terms & Proceed to Payment
                    </button>
                </form>
            <?php endif; ?>
            
            <?php if ($is_accepted && !$is_published): ?>
                <a href="pay_deposit.php?negotiation_id=<?php echo $negotiation_id; ?>" class="btn btn-success">
                    <i class="fas fa-credit-card"></i> Pay Deposit to Publish
                </a>
            <?php endif; ?>
            
            <?php if ($is_published): ?>
                <a href="product.php?id=<?php echo $negotiation['listing_id']; ?>" class="btn btn-primary">
                    <i class="fas fa-eye"></i> View Published Listing
                </a>
            <?php endif; ?>
            
            <a href="negotiations.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Negotiations
            </a>
        </div>
    </div>
    
    <!-- Chat Section -->
    <div class="chat-section">
        <div class="section-title"><i class="fas fa-comments"></i> Negotiation Chat</div>
        
        <div class="chat-messages" id="chatMessages">
            <?php if (!empty($messages)): ?>
                <?php foreach($messages as $msg): ?>
                    <?php
                    $msg_class = ($msg['sender_type'] == 'admin') ? 'admin' : 'seller';
                    ?>
                    <div class="message <?php echo $msg_class; ?>">
                        <div class="message-sender"><?php echo htmlspecialchars($msg['sender_name']); ?></div>
                        <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                        <div class="message-time"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="system-message" style="padding: 10px; text-align: center;">
                    No messages yet. Start the conversation!
                </div>
            <?php endif; ?>
        </div>
        
        <form method="POST" class="chat-input">
            <input type="hidden" name="action" value="send_message">
            <textarea name="message" rows="2" placeholder="Type your message here..." required></textarea>
            <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">
                <i class="fas fa-paper-plane"></i> Send
            </button>
        </form>
    </div>
</div>

<script>
    // Scroll chat to bottom
    var chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>