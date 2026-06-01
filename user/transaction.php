<?php
// user/transaction.php - Complete Transaction Page with Escrow and Seller Notifications

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';
require_once '../includes/transaction_workflow.php';

requireLogin();

$page_title = 'Transaction Details';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_delivered') {
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        $result = markDelivery($conn, $transaction_id, $user_id, $delivery_notes);
        if ($result['success']) {
            $message = "✓ Delivery marked successfully! Waiting for buyer confirmation.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'confirm_receipt') {
        $confirm_notes = $_POST['confirm_notes'] ?? '';
        $result = confirmReceiptAndRelease($conn, $transaction_id, $user_id, $confirm_notes);
        if ($result['success']) {
            $message = "✓ Payment released! Funds have been sent to the seller.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'raise_dispute') {
        $result = openTransactionDispute($conn, $transaction_id, $user_id, $_POST['dispute_reason'] ?? '');
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['error'];
        }
    }
}

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as listing_title, l.type as listing_type, l.cover_image,
           l.admin_deposit_percent, l.admin_commission_percent,
           u1.full_name as buyer_name, u1.email as buyer_email, u1.phone as buyer_phone,
           u2.full_name as seller_name, u2.email as seller_email, u2.phone as seller_phone
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
")->fetch_assoc();

if (!$transaction) {
    $conn->close();
    header('Location: dashboard.php');
    exit;
}

$workflow = getTransactionWorkflowView($conn, $transaction_id);
if ($workflow) {
    $transaction = array_merge($transaction, $workflow);
}

// Get escrow data
$escrow_data = $conn->query("
    SELECT ea.amount as escrow_amount, ea.status as escrow_account_status,
           eq.scheduled_release_date
    FROM escrow_accounts ea
    LEFT JOIN escrow_release_queue eq ON ea.transaction_id = eq.transaction_id AND eq.status = 'pending'
    WHERE ea.transaction_id = $transaction_id AND ea.status = 'held'
    LIMIT 1
")->fetch_assoc();

// Get payment history
$payments = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed' 
    ORDER BY created_at DESC
");

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);

// Calculate amounts
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$buyerRequired = $depositAmount + $commissionAmount;
$sellerRequired = $depositAmount;

// Get payment totals
$buyerPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type IN ('deposit_buyer', 'commission') AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);
$sellerPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type = 'deposit_seller' AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);
$remainingPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type = 'remaining_balance' AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);

$listing_type = $transaction['listing_type'] ?? 'product';
$depositPaidDisplay = (float) ($transaction['amount_paid'] ?? 0);
$remainingBalanceDisplay = (float) ($transaction['remaining_balance'] ?? 0);
$payment_status_label = $transaction['payment_status'] ?? 'pending';
$funds_status_label = $transaction['funds_status'] ?? ($transaction['escrow_status'] ?? 'pending');
$seller_confirmed = (bool) ($transaction['seller_confirmed'] ?? 0) || ($transaction['delivery_status'] ?? '') === 'delivered';
$buyer_confirmed = (bool) ($transaction['buyer_confirmed'] ?? 0);
$is_frozen = ($transaction['admin_frozen'] == 1);
$is_completed = ($transaction['status'] == 'completed');
$is_disputed = ($transaction['status'] == 'disputed' || $funds_status_label === 'disputed');

// Store payments for template (avoid using mysqli after close)
$payments_list = [];
if ($payments && $payments->num_rows > 0) {
    while ($p = $payments->fetch_assoc()) {
        $payments_list[] = $p;
    }
}

// Determine button states
$escrow_active = in_array($funds_status_label, ['held_in_escrow', 'seller_confirmed', 'buyer_confirmed', 'ready_for_release'], true)
    || ($transaction['escrow_status'] ?? '') === 'active';
$payment_received = ($escrow_active && !$is_completed && $depositPaidDisplay > 0);
$can_mark_delivery = ($is_seller && $payment_received && !$seller_confirmed && !$is_disputed && !$is_frozen);
$can_confirm_receipt = ($is_buyer && $seller_confirmed && !$buyer_confirmed && !$is_disputed && !$is_frozen && !$is_completed);
$can_open_dispute = ($is_buyer && $payment_received && !$is_completed && !$is_disputed);
$can_pay_remaining = $is_buyer
    && $payment_status_label !== 'fully_paid'
    && $remainingBalanceDisplay > 0
    && !$is_completed
    && !$is_disputed;
?>

<style>
    .transaction-container { max-width: 1200px; margin: 0 auto; }
    
    .transaction-header {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .transaction-header h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
    .transaction-header p { opacity: 0.9; }
    
    .card {
        background: white;
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .card-header h3 { font-size: 18px; font-weight: 600; color: #0f172a; }
    
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    .status-active { background: #dbeafe; color: #1e40af; }
    .status-delivered { background: #fed7aa; color: #9a3412; }
    .status-completed { background: #d1fae5; color: #059669; }
    .status-disputed { background: #fee2e2; color: #dc2626; }
    .status-frozen { background: #f1f5f9; color: #64748b; }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .info-item { padding: 12px; background: #f8fafc; border-radius: 16px; }
    .info-label { font-size: 11px; color: #64748b; margin-bottom: 4px; }
    .info-value { font-size: 16px; font-weight: 700; color: #0f172a; }
    
    .escrow-box {
        background: linear-gradient(135deg, #667eea10, #764ba210);
        border-radius: 20px;
        padding: 20px;
        margin: 16px 0;
        border: 1px solid #667eea30;
    }
    
    /* Payment Received Card - For Seller */
    .payment-received-card {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        border: 2px solid #10b981;
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .payment-received-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        color: #065f46;
    }
    
    .payment-received-header i {
        font-size: 32px;
    }
    
    .payment-received-header h2 {
        font-size: 20px;
        font-weight: 700;
    }
    
    .buyer-details {
        background: white;
        border-radius: 16px;
        padding: 16px;
        margin: 16px 0;
    }
    
    .buyer-details p {
        margin: 8px 0;
    }
    
    .btn-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
    .btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    
    .delivery-section {
        background: #f0f9ff;
        border: 2px solid #667eea;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .delivery-title {
        font-size: 18px;
        font-weight: 700;
        color: #1e40af;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .alert-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #1e40af; }
    
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
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
    
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    th { font-weight: 600; color: #64748b; }
    
    @media (max-width: 768px) {
        .info-grid { grid-template-columns: 1fr; }
        .btn-group { flex-direction: column; }
        .btn { justify-content: center; }
        .buyer-details { padding: 12px; }
    }
</style>

<div class="transaction-container">
    <!-- Header -->
    <div class="transaction-header">
        <h1><i class="fas fa-receipt"></i> Transaction #<?php echo $transaction['id']; ?></h1>
        <p><?php echo htmlspecialchars($transaction['listing_title']); ?></p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($is_frozen): ?>
        <div class="alert alert-info">
            <i class="fas fa-ice-cream"></i> 
            This transaction has been frozen by admin. Reason: <?php echo htmlspecialchars($transaction['frozen_reason'] ?? 'Not specified'); ?>
        </div>
    <?php endif; ?>
    
    <!-- Status Overview -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line"></i> Status Overview</h3>
            <span class="status-badge <?php 
                if ($is_completed) echo 'status-completed';
                elseif ($is_disputed) echo 'status-disputed';
                elseif ($is_frozen) echo 'status-frozen';
                elseif ($transaction['delivery_status'] == 'delivered') echo 'status-delivered';
                elseif ($escrow_active) echo 'status-active';
                else echo 'status-active';
            ?>">
                <?php 
                if ($is_completed) echo '✓ Completed';
                elseif ($is_disputed) echo '⚠️ Disputed';
                elseif ($is_frozen) echo '❄️ Frozen';
                elseif ($transaction['delivery_status'] == 'delivered') echo '📦 Delivered - Awaiting Confirmation';
                elseif ($escrow_active) echo '💰 Escrow Active';
                else echo '📋 Pending';
                ?>
            </span>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Total Price</div>
                <div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Amount Paid</div>
                <div class="info-value"><?php echo formatMoney($depositPaidDisplay); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Remaining Balance</div>
                <div class="info-value"><?php echo formatMoney($remainingBalanceDisplay); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Payment Status</div>
                <div class="info-value"><?php echo htmlspecialchars(str_replace('_', ' ', $payment_status_label)); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Funds Status</div>
                <div class="info-value"><?php echo htmlspecialchars(str_replace('_', ' ', $funds_status_label)); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Escrow Held</div>
                <div class="info-value"><?php echo formatMoney($escrow_data['escrow_amount'] ?? $transaction['escrow_held'] ?? 0); ?></div>
            </div>
        </div>

        <?php if ($can_pay_remaining): ?>
        <div style="margin-top:16px;padding:16px;background:#ecfdf5;border:1px solid #10b981;border-radius:16px;">
            <p style="margin-bottom:12px;color:#065f46;font-size:14px;">
                <i class="fas fa-wallet"></i>
                You can pay the remaining balance of <?php echo formatMoney($remainingBalanceDisplay); ?> to complete this purchase.
            </p>
            <button type="button" class="btn btn-success pay-remaining-txn-btn" data-transaction-id="<?php echo $transaction_id; ?>">
                <i class="fas fa-credit-card"></i> Pay Remaining Balance
            </button>
            <p id="payRemainingTxnError" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></p>
        </div>
        <?php elseif ($payment_status_label === 'fully_paid'): ?>
        <p style="margin-top:12px;padding:12px;background:#d1fae5;border-radius:12px;color:#065f46;font-weight:600;">
            <i class="fas fa-check-circle"></i> Fully Paid
        </p>
        <?php endif; ?>

        <?php if ($seller_confirmed && !$buyer_confirmed && $is_buyer): ?>
        <p style="margin-top:12px;color:#1e40af;font-size:13px;"><i class="fas fa-hourglass-half"></i> Seller confirmed delivery — please confirm receipt to release funds.</p>
        <?php elseif ($buyer_confirmed && !$seller_confirmed && $is_seller): ?>
        <p style="margin-top:12px;color:#1e40af;font-size:13px;"><i class="fas fa-hourglass-half"></i> Waiting for buyer confirmation.</p>
        <?php endif; ?>
        
        <?php if (!empty($escrow_data['scheduled_release_date']) && !$is_completed): ?>
            <div class="escrow-box">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <i class="fas fa-clock"></i>
                        <strong>Auto-Release Schedule:</strong><br>
                        <small>Funds will automatically release to seller on <?php echo date('F d, Y', strtotime($escrow_data['scheduled_release_date'])); ?></small>
                    </div>
                    <div style="background: #fef3c7; padding: 8px 16px; border-radius: 40px; color: #92400e; font-weight: 600;">
                        <?php
                        $days_left = ceil((strtotime($escrow_data['scheduled_release_date']) - time()) / 86400);
                        echo max(0, (int) $days_left) . ' days remaining';
                        ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php
        $type_labels = [
            'product' => 'Product',
            'rental' => 'Rental',
            'job' => 'Job',
        ];
        ?>
        <p style="font-size: 12px; color: #64748b; margin-top: 12px;">
            <i class="fas fa-tag"></i>
            <?php echo htmlspecialchars($type_labels[$listing_type] ?? ucfirst($listing_type)); ?> transaction
        </p>
    </div>
    
    <!-- PAYMENT RECEIVED CARD - FOR SELLER (Important Notification) -->
    <?php if ($is_seller && $payment_received && !$is_completed): ?>
    <div class="payment-received-card">
        <div class="payment-received-header">
            <i class="fas fa-money-bill-wave"></i>
            <h2>💰 Payment Received - Escrow Active!</h2>
        </div>
        <p style="color: #065f46; margin-bottom: 16px;">The buyer has paid successfully. Funds are now held securely in escrow.</p>
        
        <div class="buyer-details">
            <p><strong><i class="fas fa-user"></i> Buyer Information:</strong></p>
            <p>📛 Name: <?php echo htmlspecialchars($transaction['buyer_name']); ?></p>
            <p>📧 Email: <?php echo htmlspecialchars($transaction['buyer_email']); ?></p>
            <p>📞 Phone: <?php echo htmlspecialchars($transaction['buyer_phone'] ?? 'Not provided'); ?></p>
            <hr style="margin: 12px 0;">
            <p><strong>💰 Amount in Escrow:</strong> <?php echo formatMoney($transaction['escrow_held']); ?></p>
            <p><strong>🔒 Status:</strong> Funds secured - Awaiting delivery</p>
        </div>
        
        <div class="btn-group">
            <a href="chat.php?user=<?php echo $transaction['buyer_id']; ?>" class="btn btn-primary">
                <i class="fas fa-comment"></i> Contact Buyer
            </a>
            <button onclick="openDeliveryModal()" class="btn btn-success">
                <i class="fas fa-truck"></i> Mark as Delivered
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- PAYMENT CONFIRMED CARD - FOR BUYER -->
    <?php if ($is_buyer && $payment_received && !$is_completed): ?>
    <div class="card" style="background: #dbeafe; border: 2px solid #3b82f6;">
        <div class="card-header">
            <h3><i class="fas fa-check-circle" style="color: #2563eb;"></i> Payment Confirmed!</h3>
        </div>
        <div style="text-align: center; padding: 10px;">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #2563eb;"></i>
            <p style="margin-top: 12px;">Your payment has been confirmed and is held securely in escrow.</p>
            <p>The seller has been notified and will prepare your item.</p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ESCROW ACTION BUTTONS -->
    <?php if ($escrow_active && !$is_completed && !$is_disputed && !$is_frozen): ?>
        <?php if ($can_mark_delivery): ?>
            <!-- SELLER: Mark Delivery Button -->
            <div class="delivery-section">
                <div class="delivery-title">
                    <i class="fas fa-truck"></i> Mark as Delivered
                </div>
                <p style="margin-bottom: 16px; color: #1e3a8a;">Confirm that you have delivered the item/service to the buyer.</p>
                <button onclick="openDeliveryModal()" class="btn btn-primary" style="background: #1e40af;">
                    <i class="fas fa-check-circle"></i> I Have Delivered
                </button>
            </div>
        <?php endif; ?>
        
        <?php if ($can_confirm_receipt): ?>
            <!-- BUYER: Confirm Receipt Button -->
            <div class="delivery-section" style="background: #d1fae5; border-color: #10b981;">
                <div class="delivery-title" style="color: #065f46;">
                    <i class="fas fa-check-circle"></i> Confirm Receipt
                </div>
                <p style="margin-bottom: 16px; color: #065f46;">Confirm that you have received the item/service. This will release payment to the seller.</p>
                <button onclick="openConfirmModal()" class="btn btn-success">
                    <i class="fas fa-money-bill-wave"></i> Confirm & Release Payment
                </button>
            </div>
        <?php endif; ?>
        
        <?php if (!$can_mark_delivery && !$can_confirm_receipt && $escrow_active): ?>
            <div class="card" style="text-align: center;">
                <i class="fas fa-hourglass-half" style="font-size: 48px; color: #667eea; margin-bottom: 12px; display: block;"></i>
                <p>Waiting for <?php echo $is_seller ? 'buyer confirmation' : 'seller to mark delivery'; ?>.</p>
                <?php if ($transaction['delivery_status'] == 'delivered'): ?>
                    <p class="info-text">The seller has marked this as delivered. Please confirm receipt to release payment.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Dispute Button -->
    <?php if ($can_open_dispute): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-gavel"></i> Having an Issue?</h3>
            </div>
            <p style="margin-bottom: 16px;">If you're experiencing problems with this transaction, you can raise a dispute. An admin will review your case.</p>
            <button onclick="openDisputeModal()" class="btn btn-danger">
                <i class="fas fa-flag"></i> Raise a Dispute
            </button>
        </div>
    <?php endif; ?>
    
    <!-- Party Information -->
    <div class="info-grid">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user"></i> Buyer Information</h3></div>
            <div class="info-item"><div class="info-label">Name</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_email']); ?></div></div>
            <?php if ($transaction['buyer_phone']): ?>
            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_phone']); ?></div></div>
            <?php endif; ?>
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['buyer_id']; ?>" class="btn btn-outline"><i class="fas fa-comment"></i> Message Buyer</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-store"></i> Seller Information</h3></div>
            <div class="info-item"><div class="info-label">Name</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_email']); ?></div></div>
            <?php if ($transaction['seller_phone']): ?>
            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_phone']); ?></div></div>
            <?php endif; ?>
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['seller_id']; ?>" class="btn btn-outline"><i class="fas fa-comment"></i> Message Seller</a>
            </div>
        </div>
    </div>
    
    <!-- Payment History -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-history"></i> Payment History</h3></div>
        <?php if (!empty($payments_list)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments_list as $p): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($p['created_at'])); ?></td>
                            <td><?php echo formatMoney($p['amount']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $p['type'] ?? 'payment')); ?></td>
                            <td><span class="status-badge status-completed" style="background: #d1fae5;">Confirmed</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; padding: 20px; color: #64748b;">No payments recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Delivery Modal -->
<div id="deliveryModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-truck"></i> Mark as Delivered</h3>
        <form method="POST">
            <input type="hidden" name="action" value="mark_delivered">
            <div class="form-group">
                <label>Delivery Notes (Optional)</label>
                <textarea name="delivery_notes" rows="3" placeholder="Add any delivery details or tracking information..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Confirm Delivery</button>
                <button type="button" onclick="closeDeliveryModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Receipt Modal -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-check-circle"></i> Confirm Receipt & Release Payment</h3>
        <div style="background: #fef3c7; padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <p><strong>⚠️ Important:</strong> Confirming receipt will release the payment to the seller. This action cannot be undone.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="confirm_receipt">
            <div class="form-group">
                <label>Confirmation Notes (Optional)</label>
                <textarea name="confirm_notes" rows="3" placeholder="Add any notes about the delivery..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-success">Confirm & Release Payment</button>
                <button type="button" onclick="closeConfirmModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Dispute Modal -->
<div id="disputeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-flag"></i> Raise a Dispute</h3>
        <form method="POST">
            <input type="hidden" name="action" value="raise_dispute">
            <div class="form-group">
                <label>Reason for Dispute <span style="color: red;">*</span></label>
                <textarea name="dispute_reason" rows="4" placeholder="Please explain in detail why you're raising this dispute..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-danger">Submit Dispute</button>
                <button type="button" onclick="closeDisputeModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeliveryModal() { document.getElementById('deliveryModal').style.display = 'flex'; }
function closeDeliveryModal() { document.getElementById('deliveryModal').style.display = 'none'; }
function openConfirmModal() { document.getElementById('confirmModal').style.display = 'flex'; }
function closeConfirmModal() { document.getElementById('confirmModal').style.display = 'none'; }
function openDisputeModal() { document.getElementById('disputeModal').style.display = 'flex'; }
function closeDisputeModal() { document.getElementById('disputeModal').style.display = 'none'; }

document.querySelectorAll('.pay-remaining-txn-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const tid = this.dataset.transactionId;
        if (!confirm('Pay the remaining balance now?')) return;
        const errEl = document.getElementById('payRemainingTxnError');
        this.disabled = true;
        try {
            const res = await fetch('/broker_system/user/api/transaction_workflow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'pay_remaining', transaction_id: parseInt(tid, 10) })
            });
            const data = await res.json();
            if (data.success && data.pay_url) {
                window.location.href = data.pay_url;
            } else {
                errEl.textContent = data.error || 'Could not start payment';
                errEl.style.display = 'block';
                this.disabled = false;
            }
        } catch (e) {
            errEl.textContent = 'Network error';
            errEl.style.display = 'block';
            this.disabled = false;
        }
    });
});

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$conn->close();
$content = ob_get_clean();
include '../includes/layout.php';
?>