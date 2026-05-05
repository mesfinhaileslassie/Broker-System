<?php
// user/transaction.php - Complete Transaction Page

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$transaction_id = intval($_GET['id'] ?? 0);
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
    $conn->close();
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

// Get payment code from URL
$generated_code = '';
$show_payment_code = false;
if (isset($_GET['code'])) {
    $generated_code = $_GET['code'];
    $show_payment_code = true;
}

// Handle POST requests
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
        
        $check = $conn->query("SELECT buyer_legal_confirmed, seller_legal_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
        if ($check['buyer_legal_confirmed'] && $check['seller_legal_confirmed']) {
            $payment_success = "Both parties have confirmed legal process! Proceed to delivery confirmation.";
        } else {
            $payment_success = "Legal confirmation recorded. Waiting for buyer confirmation.";
        }
        header("Refresh: 2");
    }
    
    // Handle buyer delivery confirmation
    if (isset($_POST['confirm_buyer_delivery']) && $is_buyer) {
        $conn->query("UPDATE transactions SET buyer_delivery_confirmed = 1 WHERE id = $transaction_id");
        
        $check = $conn->query("SELECT buyer_delivery_confirmed, seller_delivery_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
        if ($check['buyer_delivery_confirmed'] && $check['seller_delivery_confirmed']) {
            $payment_success = "Both parties have confirmed delivery! The buyer can now release payment.";
        } else {
            $payment_success = "Delivery confirmed! Waiting for seller confirmation.";
        }
        header("Refresh: 2");
    }
    
    // Handle seller delivery confirmation
    if (isset($_POST['confirm_seller_delivery']) && $is_seller) {
        $conn->query("UPDATE transactions SET seller_delivery_confirmed = 1 WHERE id = $transaction_id");
        
        $check = $conn->query("SELECT buyer_delivery_confirmed, seller_delivery_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
        if ($check['buyer_delivery_confirmed'] && $check['seller_delivery_confirmed']) {
            $payment_success = "Both parties have confirmed delivery! The buyer can now release payment.";
        } else {
            $payment_success = "Delivery confirmed! Waiting for buyer confirmation.";
        }
        header("Refresh: 2");
    }
    
    // Handle release payment
    if (isset($_POST['release_payment']) && $is_buyer) {
        $check = $conn->query("SELECT buyer_delivery_confirmed, seller_delivery_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
        if ($check['buyer_delivery_confirmed'] && $check['seller_delivery_confirmed']) {
            $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
            $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$transaction['seller_id']}");
            $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW() WHERE id = $transaction_id");
            $payment_success = "Payment released to seller! Transaction completed.";
            header("Refresh: 2");
        } else {
            $payment_error = "Both parties must confirm delivery before payment can be released.";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction #<?php echo $transaction_id; ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .header { background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 16px 24px; }
        .header-content { max-width: 1000px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        
        .container { max-width: 1000px; margin: 40px auto; padding: 0 24px; }
        
        .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card h2 { font-size: 20px; margin-bottom: 20px; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        
        .status-timeline { background: white; border-radius: 24px; padding: 28px; margin-bottom: 24px; }
        .timeline-steps { display: flex; justify-content: space-between; position: relative; margin: 30px 0; flex-wrap: wrap; }
        .timeline-steps::before { content: ''; position: absolute; top: 28px; left: 0; right: 0; height: 2px; background: #e2e8f0; z-index: 0; }
        .step { text-align: center; flex: 1; position: relative; z-index: 1; min-width: 100px; }
        .step-circle { width: 56px; height: 56px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 24px; }
        .step.completed .step-circle { background: #10b981; color: white; }
        .step.active .step-circle { background: #667eea; color: white; transform: scale(1.1); }
        .step-label { font-size: 12px; font-weight: 600; }
        .step-desc { font-size: 11px; color: #64748b; margin-top: 4px; }
        
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 20px; }
        .info-item { padding: 14px; background: #f8fafc; border-radius: 16px; }
        .info-label { font-size: 11px; color: #64748b; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { font-size: 15px; font-weight: 600; color: #0f172a; }
        
        .confirmation-section { background: #f8fafc; border-radius: 20px; padding: 20px; margin-top: 20px; }
        .status-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
        .status-row:last-child { border-bottom: none; }
        
        .btn { padding: 12px 24px; border: none; border-radius: 40px; font-weight: 600; cursor: pointer; display: inline-block; text-decoration: none; transition: all 0.3s; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16,185,129,0.4); }
        
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fed7aa; color: #ea580c; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        
        .error-message { background: #fee2e2; color: #dc2626; padding: 14px; border-radius: 16px; margin-bottom: 20px; }
        .success-message { background: #d1fae5; color: #059669; padding: 14px; border-radius: 16px; margin-bottom: 20px; }
        
        @media (max-width: 768px) {
            .timeline-steps { flex-direction: column; gap: 20px; }
            .timeline-steps::before { display: none; }
            .info-grid { grid-template-columns: 1fr; }
            .status-row { flex-direction: column; align-items: flex-start; gap: 8px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="dashboard.php" style="color: #64748b;"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>
    
    <div class="container">
        <!-- Status Timeline -->
        <div class="status-timeline">
            <h2><i class="fas fa-chart-line"></i> Transaction Progress</h2>
            <div class="timeline-steps">
                <?php
                $currentStep = 0;
                if ($bothDepositsPaid) $currentStep = 1;
                if ($legal_status['both_confirmed']) $currentStep = 2;
                if ($delivery_status['both_confirmed']) $currentStep = 3;
                if ($transaction['status'] == 'completed') $currentStep = 4;
                ?>
                <div class="step <?php echo $currentStep >= 1 ? 'completed' : ($bothDepositsPaid ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-credit-card"></i></div>
                    <div class="step-label">Deposits Paid</div>
                    <div class="step-desc"><?php echo $buyerPaid > 0 ? '✓' : '⏳'; ?> Buyer | <?php echo $sellerPaid > 0 ? '✓' : '⏳'; ?> Seller</div>
                </div>
                <div class="step <?php echo $currentStep >= 2 ? 'completed' : ($currentStep == 1 ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-gavel"></i></div>
                    <div class="step-label">Legal Process</div>
                    <div class="step-desc"><?php echo $legal_status['buyer_confirmed'] ? '✓' : '⏳'; ?> Buyer | <?php echo $legal_status['seller_confirmed'] ? '✓' : '⏳'; ?> Seller</div>
                </div>
                <div class="step <?php echo $currentStep >= 3 ? 'completed' : ($currentStep == 2 ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-truck"></i></div>
                    <div class="step-label">Delivery</div>
                    <div class="step-desc"><?php echo $delivery_status['buyer_confirmed'] ? '✓' : '⏳'; ?> Buyer | <?php echo $delivery_status['seller_confirmed'] ? '✓' : '⏳'; ?> Seller</div>
                </div>
                <div class="step <?php echo $currentStep >= 4 ? 'completed' : ''; ?>">
                    <div class="step-circle"><i class="fas fa-check-circle"></i></div>
                    <div class="step-label">Completed</div>
                    <div class="step-desc">Payment Released</div>
                </div>
            </div>
        </div>
        
        <!-- Transaction Details -->
        <div class="card">
            <h2><i class="fas fa-info-circle"></i> Transaction Details</h2>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Transaction ID</div><div class="info-value">#<?php echo $transaction['id']; ?></div></div>
                <div class="info-item"><div class="info-label">Item</div><div class="info-value"><?php echo htmlspecialchars($transaction['listing_title']); ?></div></div>
                <div class="info-item"><div class="info-label">Total Amount</div><div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div></div>
                <div class="info-item"><div class="info-label">Status</div><div class="info-value"><?php echo getStatusBadge($transaction['status']); ?></div></div>
                <div class="info-item"><div class="info-label">Buyer</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?></div></div>
                <div class="info-item"><div class="info-label">Seller</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_name']); ?></div></div>
            </div>
        </div>
        
        <?php if ($payment_success): ?>
            <div class="success-message"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($payment_success); ?></div>
        <?php endif; ?>
        
        <?php if ($payment_error): ?>
            <div class="error-message"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($payment_error); ?></div>
        <?php endif; ?>
        
        <!-- LEGAL PROCESS CONFIRMATION SECTION -->
        <?php if ($bothDepositsPaid && !$legal_status['both_confirmed']): ?>
            <div class="card">
                <h2><i class="fas fa-gavel"></i> Legal Process Confirmation</h2>
                <p>Confirm that all legal processes, documentation, and requirements are completed.</p>
                
                <div class="confirmation-section">
                    <div class="status-row">
                        <span><i class="fas fa-user"></i> Your Status:</span>
                        <span><?php echo ($is_buyer ? $legal_status['buyer_confirmed'] : $legal_status['seller_confirmed']) ? '<span class="badge badge-success">✓ Confirmed</span>' : '<span class="badge badge-warning">⏳ Pending</span>'; ?></span>
                    </div>
                    <div class="status-row">
                        <span><i class="fas fa-store"></i> Other Party Status:</span>
                        <span><?php echo ($is_buyer ? $legal_status['seller_confirmed'] : $legal_status['buyer_confirmed']) ? '<span class="badge badge-success">✓ Confirmed</span>' : '<span class="badge badge-warning">⏳ Pending</span>'; ?></span>
                    </div>
                </div>
                
                <?php if (($is_buyer && !$legal_status['buyer_confirmed']) || ($is_seller && !$legal_status['seller_confirmed'])): ?>
                    <form method="POST" style="margin-top: 20px;">
                        <button type="submit" name="<?php echo $is_buyer ? 'confirm_buyer_legal' : 'confirm_seller_legal'; ?>" class="btn btn-success" onclick="return confirm('Confirm that all legal processes are completed?')">
                            <i class="fas fa-check-circle"></i> I Confirm Legal Process Completed
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- DELIVERY CONFIRMATION SECTION -->
        <?php if ($legal_status['both_confirmed'] && !$delivery_status['both_confirmed']): ?>
            <div class="card">
                <h2><i class="fas fa-truck"></i> Delivery Confirmation</h2>
                <p>Confirm the delivery status of the item/service.</p>
                
                <div class="confirmation-section">
                    <div class="status-row">
                        <span><i class="fas fa-user"></i> Your Status:</span>
                        <span><?php echo ($is_buyer ? $delivery_status['buyer_confirmed'] : $delivery_status['seller_confirmed']) ? '<span class="badge badge-success">✓ Confirmed</span>' : '<span class="badge badge-warning">⏳ Pending</span>'; ?></span>
                    </div>
                    <div class="status-row">
                        <span><i class="fas fa-store"></i> Other Party Status:</span>
                        <span><?php echo ($is_buyer ? $delivery_status['seller_confirmed'] : $delivery_status['buyer_confirmed']) ? '<span class="badge badge-success">✓ Confirmed</span>' : '<span class="badge badge-warning">⏳ Pending</span>'; ?></span>
                    </div>
                </div>
                
                <?php if (($is_buyer && !$delivery_status['buyer_confirmed']) || ($is_seller && !$delivery_status['seller_confirmed'])): ?>
                    <form method="POST" style="margin-top: 20px;">
                        <button type="submit" name="<?php echo $is_buyer ? 'confirm_buyer_delivery' : 'confirm_seller_delivery'; ?>" class="btn btn-success" onclick="return confirm('Confirm delivery?')">
                            <i class="fas <?php echo $is_buyer ? 'fa-box' : 'fa-truck'; ?>"></i> <?php echo $is_buyer ? 'I Confirm I Have Received the Item' : 'I Confirm I Have Delivered the Item'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- RELEASE PAYMENT SECTION -->
        <?php if ($delivery_status['both_confirmed'] && $transaction['status'] == 'deposits_complete'): ?>
            <div class="card">
                <h2><i class="fas fa-money-bill-wave"></i> Release Payment</h2>
                <p>Both parties have confirmed delivery. You can now release the payment to the seller.</p>
                <div class="confirmation-section">
                    <div class="status-row"><span>Total Amount:</span><span><?php echo formatMoney($transaction['total_amount']); ?></span></div>
                    <div class="status-row"><span>Platform Commission (<?php echo $commissionPercent; ?>%):</span><span><?php echo formatMoney($transaction['commission_amount']); ?></span></div>
                    <div class="status-row" style="font-weight: 700;"><span>Amount to Seller:</span><span><?php echo formatMoney($transaction['total_amount'] - $transaction['commission_amount']); ?></span></div>
                </div>
                <?php if ($is_buyer): ?>
                    <form method="POST" style="margin-top: 20px;">
                        <button type="submit" name="release_payment" class="btn btn-success" onclick="return confirm('Release payment to seller? This action cannot be undone.')">
                            <i class="fas fa-check-circle"></i> Release Payment to Seller
                        </button>
                    </form>
                <?php else: ?>
                    <p class="info-text" style="margin-top: 16px; text-align: center; color: #64748b;">Waiting for buyer to release payment.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($transaction['status'] == 'completed'): ?>
            <div class="card">
                <h2><i class="fas fa-check-circle" style="color: #10b981;"></i> Transaction Completed</h2>
                <p>This transaction has been completed successfully.</p>
                <?php if ($is_seller): ?>
                    <div class="success-message" style="margin-top: 16px;">
                        <i class="fas fa-money-bill-wave"></i> Payment has been released to your wallet.
                    </div>
                <?php endif; ?>
                <div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="product.php?id=<?php echo $transaction['listing_id']; ?>" class="btn btn-primary">View Item</a>
                    <a href="dashboard.php" class="btn" style="background: #64748b; color: white;">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Payment History -->
        <?php if ($payments_history && $payments_history->num_rows > 0): ?>
        <div class="card">
            <h2><i class="fas fa-history"></i> Payment History</h2>
            <div class="table-wrapper">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr><th>Date</th><th>Amount</th><th>Type</th><th>Telebirr Code</th><th>Status</th>
                    </thead>
                    <tbody>
                        <?php while($p = $payments_history->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($p['created_at'])); ?></td>
                            <td><strong><?php echo formatMoney($p['amount']); ?></strong></td>
                            <td><?php echo str_replace('_', ' ', ucfirst($p['type'])); ?></td>
                            <td><code><?php echo $p['telebirr_code_5digit'] ?? '-'; ?></code></td>
                            <td><span class="badge badge-success">Confirmed</span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>