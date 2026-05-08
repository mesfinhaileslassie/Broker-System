<?php
// user/transaction.php - Complete Transaction Page with Prominent Delivery Buttons

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Transaction Details';
ob_start();

$conn = getDbConnection();
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = $_SESSION['user_id'];

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
    header('Location: dashboard.php');
    exit;
}

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);

// Calculate amounts
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$buyerRequired = $depositAmount + $commissionAmount;
$sellerRequired = $depositAmount;

// Get payments made
$payments_result = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed'
");
$buyerPaid = 0;
$sellerPaid = 0;
while($p = $payments_result->fetch_assoc()) {
    if ($p['type'] == 'deposit_buyer' || $p['type'] == 'commission') {
        $buyerPaid += $p['amount'];
    } elseif ($p['type'] == 'deposit_seller') {
        $sellerPaid += $p['amount'];
    }
}

$buyerRemaining = $buyerRequired - $buyerPaid;
$sellerRemaining = $sellerRequired - $sellerPaid;
$bothDepositsPaid = ($buyerPaid >= $buyerRequired && $sellerPaid >= $sellerRequired);

// Get confirmation statuses
$legal_status = [
    'buyer_confirmed' => $transaction['buyer_legal_confirmed'] ?? false,
    'seller_confirmed' => $transaction['seller_legal_confirmed'] ?? false,
    'both_confirmed' => ($transaction['buyer_legal_confirmed'] ?? false) && ($transaction['seller_legal_confirmed'] ?? false)
];

$delivery_status = [
    'buyer_confirmed' => $transaction['buyer_delivery_confirmed'] ?? false,
    'seller_confirmed' => $transaction['seller_delivery_confirmed'] ?? false,
    'both_confirmed' => ($transaction['buyer_delivery_confirmed'] ?? false) && ($transaction['seller_delivery_confirmed'] ?? false)
];

// Get payment history
$payments_history = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed' 
    ORDER BY created_at DESC
");

// Get dispute info
$dispute = $conn->query("
    SELECT * FROM disputes 
    WHERE transaction_id = $transaction_id 
    ORDER BY created_at DESC LIMIT 1
")->fetch_assoc();

// Handle POST actions
$payment_error = '';
$payment_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle buyer legal confirmation
    if (isset($_POST['confirm_buyer_legal']) && $is_buyer) {
        $conn->query("UPDATE transactions SET buyer_legal_confirmed = 1 WHERE id = $transaction_id");
        $payment_success = "Legal confirmation recorded. Waiting for seller confirmation.";
        header("Refresh: 2");
    }
    
    // Handle seller legal confirmation
    if (isset($_POST['confirm_seller_legal']) && $is_seller) {
        $conn->query("UPDATE transactions SET seller_legal_confirmed = 1 WHERE id = $transaction_id");
        $payment_success = "Legal confirmation recorded. Waiting for buyer confirmation.";
        header("Refresh: 2");
    }
    
    // Handle dispute
    if (isset($_POST['raise_dispute'])) {
        $reason = sanitizeString($_POST['dispute_reason']);
        if (!empty($reason)) {
            $stmt = $conn->prepare("INSERT INTO disputes (transaction_id, raised_by, reason, status, created_at) VALUES (?, ?, ?, 'open', NOW())");
            $stmt->bind_param("iis", $transaction_id, $user_id, $reason);
            $stmt->execute();
            $conn->query("UPDATE transactions SET status = 'disputed' WHERE id = $transaction_id");
            $payment_success = "Dispute raised successfully. Admin will review your case.";
            header("Refresh: 2");
        }
    }
}

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --primary-dark: #5a67d8;
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
    
    .transaction-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    /* Header Section */
    .transaction-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 30px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .transaction-header::before {
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
    
    .transaction-header-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .transaction-id {
        font-size: 14px;
        opacity: 0.8;
        margin-bottom: 8px;
    }
    
    .transaction-title {
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .transaction-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255,255,255,0.2);
        padding: 6px 14px;
        border-radius: 40px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .dashboard-link {
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 10px 20px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .dashboard-link:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
    }
    
    /* Timeline */
    .timeline-modern {
        background: white;
        border-radius: 24px;
        padding: 32px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .timeline-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .timeline-steps {
        display: flex;
        justify-content: space-between;
        position: relative;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .timeline-steps::before {
        content: '';
        position: absolute;
        top: 28px;
        left: 0;
        right: 0;
        height: 2px;
        background: var(--border);
        z-index: 0;
    }
    
    .step-modern {
        flex: 1;
        text-align: center;
        position: relative;
        z-index: 1;
        min-width: 100px;
    }
    
    .step-circle {
        width: 56px;
        height: 56px;
        background: white;
        border: 2px solid var(--border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 24px;
        transition: all 0.3s;
    }
    
    .step-modern.completed .step-circle {
        background: var(--success);
        border-color: var(--success);
        color: white;
    }
    
    .step-modern.active .step-circle {
        border-color: var(--primary);
        background: var(--primary);
        color: white;
        transform: scale(1.1);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .step-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .step-desc {
        font-size: 10px;
        color: var(--gray);
    }
    
    /* Delivery Confirmation Section - PROMINENT */
    .delivery-section {
        background: linear-gradient(135deg, #fff9e6, #fff3cd);
        border: 2px solid var(--warning);
        border-radius: 24px;
        padding: 32px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }
    
    .delivery-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(245,158,11,0.05) 1px, transparent 1px);
        background-size: 30px 30px;
    }
    
    .delivery-title {
        font-size: 22px;
        font-weight: 700;
        color: var(--warning);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .delivery-subtitle {
        font-size: 14px;
        color: var(--gray);
        margin-bottom: 24px;
    }
    
    .delivery-buttons {
        display: flex;
        gap: 20px;
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .btn-delivery {
        padding: 18px 36px;
        border-radius: 60px;
        font-size: 18px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        border: none;
        min-width: 280px;
        justify-content: center;
    }
    
    .btn-delivery-buyer {
        background: linear-gradient(135deg, var(--success), #059669);
        color: white;
        box-shadow: 0 4px 15px rgba(16,185,129,0.3);
    }
    
    .btn-delivery-seller {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        box-shadow: 0 4px 15px rgba(102,126,234,0.3);
    }
    
    .btn-delivery:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    }
    
    .btn-delivery:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    
    .confirmation-status {
        text-align: center;
        margin-top: 24px;
        padding: 16px;
        background: white;
        border-radius: 16px;
    }
    
    .confirmation-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 40px;
        font-size: 13px;
        font-weight: 500;
    }
    
    /* Info Cards */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
        margin-bottom: 30px;
    }
    
    .info-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .info-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .info-card-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .info-row:last-child {
        border-bottom: none;
    }
    
    .info-label {
        color: var(--gray);
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-value {
        font-weight: 500;
        color: var(--dark);
        font-size: 13px;
    }
    
    /* Payment Breakdown */
    .payment-breakdown {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border-radius: 20px;
        padding: 20px;
        margin-top: 16px;
    }
    
    .breakdown-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .breakdown-item:last-child {
        border-bottom: none;
    }
    
    .breakdown-item.total {
        font-weight: 700;
        font-size: 16px;
        margin-top: 8px;
        padding-top: 12px;
        border-top: 2px solid var(--border);
        color: var(--primary);
    }
    
    /* Progress Section */
    .progress-section {
        margin-top: 20px;
        padding: 0;
    }
    
    .progress-bar-container {
        background: var(--border);
        border-radius: 10px;
        height: 8px;
        overflow: hidden;
        margin: 16px 0;
    }
    
    .progress-bar-fill {
        background: linear-gradient(90deg, var(--primary), var(--secondary));
        height: 100%;
        border-radius: 10px;
        transition: width 0.5s ease;
    }
    
    .badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-info { background: #dbeafe; color: #1e40af; }
    
    /* Alert */
    .alert-modern {
        padding: 16px 20px;
        border-radius: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .alert-success-modern { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error-modern { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    
    /* Modal */
    .modal-modern {
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
    
    .modal-content-modern {
        background: white;
        border-radius: 28px;
        padding: 32px;
        width: 500px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header-modern {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header-modern h3 {
        font-size: 20px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .close-modal-modern {
        cursor: pointer;
        font-size: 28px;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .close-modal-modern:hover {
        color: var(--danger);
    }
    
    textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid var(--border);
        border-radius: 16px;
        font-family: inherit;
        resize: vertical;
        margin-bottom: 20px;
    }
    
    textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .info-grid {
            grid-template-columns: 1fr;
        }
        .timeline-steps {
            flex-direction: column;
            gap: 16px;
        }
        .timeline-steps::before {
            display: none;
        }
        .step-modern {
            display: flex;
            align-items: center;
            gap: 16px;
            text-align: left;
        }
        .step-circle {
            margin: 0;
        }
        .transaction-header-content {
            flex-direction: column;
            text-align: center;
        }
        .delivery-buttons {
            flex-direction: column;
            align-items: center;
        }
        .btn-delivery {
            width: 100%;
            max-width: 100%;
            padding: 14px 24px;
            font-size: 16px;
        }
    }
</style>

<div class="transaction-container">
    <!-- Header -->
    <div class="transaction-header">
        <div class="transaction-header-content">
            <div>
                <div class="transaction-id">
                    <i class="fas fa-receipt"></i> Transaction #<?php echo $transaction['id']; ?>
                </div>
                <div class="transaction-title">
                    <?php echo htmlspecialchars($transaction['listing_title']); ?>
                </div>
                <div class="transaction-status">
                    <i class="fas <?php echo $transaction['status'] == 'completed' ? 'fa-check-circle' : ($transaction['status'] == 'disputed' ? 'fa-gavel' : 'fa-hourglass-half'); ?>"></i>
                    <?php echo ucfirst(str_replace('_', ' ', $transaction['status'])); ?>
                </div>
            </div>
            <a href="dashboard.php" class="dashboard-link">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
        </div>
    </div>
    
    <!-- Alert Messages -->
    <?php if ($payment_success): ?>
        <div class="alert-modern alert-success-modern">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($payment_success); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($payment_error): ?>
        <div class="alert-modern alert-error-modern">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($payment_error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Timeline -->
    <div class="timeline-modern">
        <div class="timeline-title">
            <i class="fas fa-chart-line"></i> Transaction Progress
        </div>
        <div class="timeline-steps">
            <?php
            $currentStep = 0;
            if ($bothDepositsPaid) $currentStep = 1;
            if ($legal_status['both_confirmed']) $currentStep = 2;
            if ($delivery_status['both_confirmed']) $currentStep = 3;
            if ($transaction['status'] == 'completed') $currentStep = 4;
            ?>
            <div class="step-modern <?php echo $currentStep >= 1 ? 'completed' : ($bothDepositsPaid ? 'active' : ''); ?>">
                <div class="step-circle"><i class="fas fa-credit-card"></i></div>
                <div class="step-label">Deposits Paid</div>
                <div class="step-desc"><?php echo $buyerPaid > 0 ? '✓' : '⏳'; ?> Buyer | <?php echo $sellerPaid > 0 ? '✓' : '⏳'; ?> Seller</div>
            </div>
            <div class="step-modern <?php echo $currentStep >= 2 ? 'completed' : ($currentStep == 1 ? 'active' : ''); ?>">
                <div class="step-circle"><i class="fas fa-gavel"></i></div>
                <div class="step-label">Legal Process</div>
                <div class="step-desc"><?php echo $legal_status['buyer_confirmed'] ? '✓' : '⏳'; ?> Buyer | <?php echo $legal_status['seller_confirmed'] ? '✓' : '⏳'; ?> Seller</div>
            </div>
            <div class="step-modern <?php echo $currentStep >= 3 ? 'completed' : ($currentStep == 2 ? 'active' : ''); ?>">
                <div class="step-circle"><i class="fas fa-truck"></i></div>
                <div class="step-label">Delivery</div>
                <div class="step-desc"><?php echo $delivery_status['buyer_confirmed'] ? '✓' : '⏳'; ?> Buyer | <?php echo $delivery_status['seller_confirmed'] ? '✓' : '⏳'; ?> Seller</div>
            </div>
            <div class="step-modern <?php echo $currentStep >= 4 ? 'completed' : ''; ?>">
                <div class="step-circle"><i class="fas fa-check-circle"></i></div>
                <div class="step-label">Completed</div>
                <div class="step-desc">Payment Released</div>
            </div>
        </div>
    </div>
    
    <!-- DELIVERY CONFIRMATION SECTION - PROMINENT (Shows when legal is complete) -->
    <?php if ($legal_status['both_confirmed'] && $transaction['status'] != 'completed' && $transaction['status'] != 'disputed'): ?>
    <div class="delivery-section">
        <div class="delivery-title">
            <i class="fas fa-truck"></i> Delivery Confirmation
        </div>
        <div class="delivery-subtitle">
            <?php if ($delivery_status['both_confirmed']): ?>
                Both parties have confirmed delivery! Payment will be released automatically.
            <?php elseif ($delivery_status['buyer_confirmed'] || $delivery_status['seller_confirmed']): ?>
                One party has confirmed. Waiting for the other party to confirm delivery.
            <?php else: ?>
                Please confirm delivery to release payment to the seller.
            <?php endif; ?>
        </div>
        
        <div class="delivery-buttons">
            <?php if ($is_buyer && !$delivery_status['buyer_confirmed']): ?>
                <button onclick="confirmDelivery(<?php echo $transaction_id; ?>, 'buyer')" class="btn-delivery btn-delivery-buyer">
                    <i class="fas fa-check-circle fa-2x"></i>
                    <div>
                        <div style="font-size: 14px; font-weight: normal;">I Confirm</div>
                        <div style="font-size: 18px;">DELIVERY RECEIVED</div>
                    </div>
                </button>
            <?php endif; ?>
            
            <?php if ($is_seller && !$delivery_status['seller_confirmed']): ?>
                <button onclick="confirmDelivery(<?php echo $transaction_id; ?>, 'seller')" class="btn-delivery btn-delivery-seller">
                    <i class="fas fa-truck fa-2x"></i>
                    <div>
                        <div style="font-size: 14px; font-weight: normal;">I Confirm</div>
                        <div style="font-size: 18px;">ITEM DELIVERED</div>
                    </div>
                </button>
            <?php endif; ?>
        </div>
        
        <div class="confirmation-status">
            <div style="display: flex; justify-content: center; gap: 30px; flex-wrap: wrap;">
                <div class="confirmation-badge" style="background: <?php echo $delivery_status['buyer_confirmed'] ? '#d1fae5' : '#fef3c7'; ?>">
                    <i class="fas fa-user"></i>
                    Buyer: <?php echo $delivery_status['buyer_confirmed'] ? '✓ Confirmed' : '⏳ Pending'; ?>
                </div>
                <div class="confirmation-badge" style="background: <?php echo $delivery_status['seller_confirmed'] ? '#d1fae5' : '#fef3c7'; ?>">
                    <i class="fas fa-store"></i>
                    Seller: <?php echo $delivery_status['seller_confirmed'] ? '✓ Confirmed' : '⏳ Pending'; ?>
                </div>
            </div>
            <?php if ($delivery_status['both_confirmed']): ?>
                <div style="margin-top: 16px; padding: 12px; background: #d1fae5; border-radius: 12px; color: #059669;">
                    <i class="fas fa-check-circle"></i> Both parties confirmed! Payment is being released...
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Legal Process Section (if not completed) -->
    <?php if ($bothDepositsPaid && !$legal_status['both_confirmed'] && $transaction['status'] != 'completed' && $transaction['status'] != 'disputed'): ?>
    <div class="info-card" style="border-left: 4px solid var(--warning);">
        <div class="info-card-title">
            <i class="fas fa-gavel"></i> Legal Process Required
        </div>
        <p style="font-size: 13px; color: var(--gray); margin-bottom: 16px;">
            Before delivery, both parties must confirm that all legal documentation is complete.
        </p>
        <div style="background: var(--light); border-radius: 16px; padding: 16px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                <span><i class="fas fa-user"></i> Your Status:</span>
                <span><?php echo ($is_buyer ? $legal_status['buyer_confirmed'] : $legal_status['seller_confirmed']) ? '<span class="badge badge-success"><i class="fas fa-check"></i> Confirmed</span>' : '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>'; ?></span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span><i class="fas fa-store"></i> Other Party Status:</span>
                <span><?php echo ($is_buyer ? $legal_status['seller_confirmed'] : $legal_status['buyer_confirmed']) ? '<span class="badge badge-success"><i class="fas fa-check"></i> Confirmed</span>' : '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>'; ?></span>
            </div>
        </div>
        
        <?php if (($is_buyer && !$legal_status['buyer_confirmed']) || ($is_seller && !$legal_status['seller_confirmed'])): ?>
            <form method="POST">
                <button type="submit" name="<?php echo $is_buyer ? 'confirm_buyer_legal' : 'confirm_seller_legal'; ?>" class="btn-delivery btn-delivery-seller" style="width: 100%; padding: 14px;">
                    <i class="fas fa-file-signature"></i> I Confirm Legal Process Completed
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Info Grid -->
    <div class="info-grid">
        <!-- Buyer Info -->
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-user"></i> Buyer Information
            </div>
            <div class="info-row">
                <span class="info-label"><i class="fas fa-user"></i> Name</span>
                <span class="info-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                <span class="info-value"><?php echo htmlspecialchars($transaction['buyer_email']); ?></span>
            </div>
            <?php if ($transaction['buyer_phone']): ?>
            <div class="info-row">
                <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                <span class="info-value"><?php echo htmlspecialchars($transaction['buyer_phone']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Seller Info -->
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-store"></i> Seller Information
            </div>
            <div class="info-row">
                <span class="info-label"><i class="fas fa-user"></i> Name</span>
                <span class="info-value"><?php echo htmlspecialchars($transaction['seller_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                <span class="info-value"><?php echo htmlspecialchars($transaction['seller_email']); ?></span>
            </div>
            <?php if ($transaction['seller_phone']): ?>
            <div class="info-row">
                <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                <span class="info-value"><?php echo htmlspecialchars($transaction['seller_phone']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Payment Breakdown -->
    <div class="info-card">
        <div class="info-card-title">
            <i class="fas fa-money-bill-wave"></i> Payment Summary
        </div>
        <div class="payment-breakdown">
            <div class="breakdown-item">
                <span>Item Price</span>
                <span><?php echo formatMoney($transaction['total_amount']); ?></span>
            </div>
            <div class="breakdown-item">
                <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                <span><?php echo formatMoney($depositAmount); ?></span>
            </div>
            <div class="breakdown-item">
                <span>Commission (<?php echo $commissionPercent; ?>%)</span>
                <span><?php echo formatMoney($commissionAmount); ?></span>
            </div>
            <div class="breakdown-item total">
                <span>Total You Pay Today</span>
                <span><?php echo formatMoney($buyerRequired); ?></span>
            </div>
            <div class="breakdown-item">
                <span>Seller Receives (after completion)</span>
                <span><?php echo formatMoney($transaction['total_amount'] - $commissionAmount); ?></span>
            </div>
        </div>
        
        <!-- Payment Progress -->
        <div class="progress-section">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 12px; color: var(--gray);">Buyer Payment</span>
                <span style="font-size: 12px; font-weight: 600;"><?php echo formatMoney($buyerPaid); ?> / <?php echo formatMoney($buyerRequired); ?></span>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?php echo min(100, ($buyerPaid / $buyerRequired) * 100); ?>%"></div>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin: 16px 0 8px;">
                <span style="font-size: 12px; color: var(--gray);">Seller Payment</span>
                <span style="font-size: 12px; font-weight: 600;"><?php echo formatMoney($sellerPaid); ?> / <?php echo formatMoney($sellerRequired); ?></span>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?php echo min(100, ($sellerPaid / $sellerRequired) * 100); ?>%"></div>
            </div>
        </div>
    </div>
    
    <!-- Completed Transaction Message -->
    <?php if ($transaction['status'] == 'completed'): ?>
    <div class="info-card">
        <div class="info-card-title">
            <i class="fas fa-check-circle" style="color: var(--success);"></i> Transaction Completed
        </div>
        <div style="text-align: center; padding: 20px;">
            <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 16px;"></i>
            <h3 style="margin-bottom: 8px;">Transaction Completed Successfully!</h3>
            <p style="color: var(--gray);">Payment has been released to the seller.</p>
            <?php if ($is_seller): ?>
                <div style="margin-top: 16px; background: var(--light); border-radius: 16px; padding: 16px;">
                    <span style="color: var(--gray);">Amount received:</span>
                    <strong style="color: var(--primary); font-size: 20px;"><?php echo formatMoney($transaction['total_amount'] - $transaction['commission_amount']); ?></strong>
                </div>
            <?php endif; ?>
            <div style="margin-top: 20px;">
                <a href="product.php?id=<?php echo $transaction['listing_id']; ?>" class="btn-delivery btn-delivery-seller" style="text-decoration: none; padding: 12px 28px;">
                    <i class="fas fa-eye"></i> View Item
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Payment History -->
    <?php if ($payments_history && $payments_history->num_rows > 0): ?>
    <div class="info-card">
        <div class="info-card-title">
            <i class="fas fa-history"></i> Payment History
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr><th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border);">Date</th><th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border);">Amount</th><th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border);">Type</th><th style="padding: 12px; text-align: left; border-bottom: 1px solid var(--border);">Status</th></tr>
            </thead>
            <tbody>
                <?php while($p = $payments_history->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('M d, H:i', strtotime($p['created_at'])); ?></td>
                    <td><strong><?php echo formatMoney($p['amount']); ?></strong></td>
                    <td><?php echo str_replace('_', ' ', ucfirst($p['type'])); ?></td>
                    <td><span class="badge badge-success">Confirmed</span></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Dispute Section -->
    <?php if ($transaction['status'] != 'completed' && $transaction['status'] != 'disputed'): ?>
    <div class="info-card">
        <div class="info-card-title">
            <i class="fas fa-gavel"></i> Having an Issue?
        </div>
        <p style="font-size: 13px; color: var(--gray); margin-bottom: 16px;">
            If you're experiencing problems with this transaction, you can raise a dispute. An admin will review your case.
        </p>
        <button onclick="openDisputeModal()" style="background: transparent; border: 1px solid var(--border); padding: 12px 24px; border-radius: 40px; color: var(--gray); cursor: pointer;">
            <i class="fas fa-flag"></i> Raise a Dispute
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Dispute Modal -->
<div id="disputeModal" class="modal-modern">
    <div class="modal-content-modern">
        <div class="modal-header-modern">
            <h3><i class="fas fa-flag"></i> Raise a Dispute</h3>
            <span class="close-modal-modern" onclick="closeDisputeModal()">&times;</span>
        </div>
        <form method="POST">
            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Reason for dispute <span style="color: var(--danger);">*</span></label>
            <textarea name="dispute_reason" rows="5" required placeholder="Please explain in detail why you're raising this dispute..."></textarea>
            <button type="submit" name="raise_dispute" style="width: 100%; padding: 14px; background: var(--danger); color: white; border: none; border-radius: 40px; font-weight: 600; cursor: pointer;">
                <i class="fas fa-gavel"></i> Submit Dispute
            </button>
        </form>
    </div>
</div>

<script>
function confirmDelivery(transactionId, role) {
    const confirmText = role === 'buyer' 
        ? 'CONFIRM DELIVERY RECEIVED\n\nPlease confirm that you have received the item/service in good condition.\n\nOnce confirmed, this action cannot be undone and payment will be released to the seller.\n\nDo you want to proceed?'
        : 'CONFIRM ITEM DELIVERED\n\nPlease confirm that you have delivered the item/service to the buyer.\n\nOnce confirmed, this action cannot be undone.\n\nDo you want to proceed?';
    
    if (!confirm(confirmText)) return;
    
    const url = role === 'buyer' 
        ? 'api/confirm_delivery.php' 
        : 'api/confirm_seller_delivery.php';
    
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ transaction_id: transactionId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.status === 'completed') {
                alert('✓ ' + data.message + '\n\nPayment has been released to the seller!');
                location.reload();
            } else {
                alert('✓ ' + data.message);
                location.reload();
            }
        } else {
            alert('Error: ' + data.error);
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('An error occurred: ' + error);
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

function openDisputeModal() {
    document.getElementById('disputeModal').style.display = 'flex';
}

function closeDisputeModal() {
    document.getElementById('disputeModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('disputeModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>