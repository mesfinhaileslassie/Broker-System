<?php
// user/transaction.php - Complete transaction page with auto status checking

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

// ============================================
// AUTO-FIX TRANSACTION STATUS - Ensures consistency
// ============================================
if ($transaction['status'] != 'completed' && $transaction['status'] != 'cancelled' && $transaction['status'] != 'disputed') {
    $required = $transaction['deposit_amount'] * 2 + $transaction['commission_amount'];
    
    // Check if both deposits are complete
    if ($transaction['escrow_held'] >= $required && $transaction['status'] != 'deposits_complete') {
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
        $transaction['status'] = 'deposits_complete';
    }
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

// Check for existing payment code
$existing_code = null;
$code_check = $conn->query("
    SELECT pc.* FROM payment_codes pc
    WHERE pc.transaction_id = $transaction_id AND pc.user_id = $user_id AND pc.status = 'pending'
    ORDER BY pc.created_at DESC LIMIT 1
");
if ($code_check && $code_check->num_rows > 0) {
    $existing_code = $code_check->fetch_assoc();
}

// Generate payment code if needed
$generated_code = '';
$generated_amount = 0;
$show_payment_code = false;

if (isset($_GET['code']) && $_GET['code'] == 'generate') {
    do {
        $new_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_exists = $conn->query("SELECT id FROM payment_codes WHERE code = '$new_code'");
    } while ($code_exists->num_rows > 0);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $amount_to_pay = $is_buyer ? $buyerRemaining : $sellerRemaining;
    $payment_type = $is_buyer ? 'deposit_buyer' : 'deposit_seller';
    
    $stmt = $conn->prepare("INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("siidss", $new_code, $transaction_id, $amount_to_pay, $user_id, $payment_type, $expires_at);
    $stmt->execute();
    
    $generated_code = $new_code;
    $generated_amount = $amount_to_pay;
    $show_payment_code = true;
    
    header("Location: transaction.php?id=$transaction_id&code=$new_code");
    exit;
    
} elseif ($existing_code) {
    $generated_code = $existing_code['code'];
    $generated_amount = $existing_code['amount'];
    $show_payment_code = true;
} elseif (isset($_GET['code']) && strlen($_GET['code']) == 5 && ctype_digit($_GET['code'])) {
    $generated_code = $_GET['code'];
    $generated_amount = $is_buyer ? $buyerRemaining : $sellerRemaining;
    $show_payment_code = true;
}

// Handle POST requests
$payment_error = '';
$payment_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle payment confirmation
    if (isset($_POST['confirm_payment_code'])) {
        $entered_code = $_POST['payment_code_confirm'] ?? '';
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        
        if ($entered_code == $generated_code) {
            $conn->begin_transaction();
            
            try {
                $stmt = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
                $payment_type_record = $is_buyer ? 'deposit_buyer' : 'deposit_seller';
                $stmt->bind_param("iidss", $transaction_id, $user_id, $payment_amount, $payment_type_record, $entered_code);
                $stmt->execute();
                
                $conn->query("UPDATE transactions SET escrow_held = escrow_held + $payment_amount WHERE id = $transaction_id");
                
                // Check if both deposits are complete after this payment
                $check = $conn->query("SELECT escrow_held, deposit_amount, commission_amount FROM transactions WHERE id = $transaction_id")->fetch_assoc();
                $required = $check['deposit_amount'] * 2 + $check['commission_amount'];
                
                if ($check['escrow_held'] >= $required) {
                    $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
                    $payment_success = "Payment confirmed! Both deposits are complete. Please proceed to legal confirmation.";
                } else {
                    if ($is_buyer) {
                        $conn->query("UPDATE transactions SET status = 'awaiting_seller_deposit' WHERE id = $transaction_id");
                        $payment_success = "Payment confirmed! Waiting for seller deposit.";
                    } else {
                        $conn->query("UPDATE transactions SET status = 'awaiting_buyer_deposit' WHERE id = $transaction_id");
                        $payment_success = "Payment confirmed! Waiting for buyer deposit.";
                    }
                }
                
                $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$entered_code'");
                $conn->commit();
                header("Refresh: 2");
            } catch (Exception $e) {
                $conn->rollback();
                $payment_error = "Payment failed: " . $e->getMessage();
            }
        } else {
            $payment_error = "Invalid payment code. Please enter the correct code.";
        }
    }
    
    // Handle buyer legal confirmation
    if (isset($_POST['confirm_buyer_legal']) && $is_buyer) {
        $conn->query("UPDATE transactions SET buyer_legal_confirmed = 1 WHERE id = $transaction_id");
        $payment_success = "Your legal confirmation has been recorded. Waiting for seller confirmation.";
        header("Refresh: 2");
    }
    
    // Handle seller legal confirmation
    if (isset($_POST['confirm_seller_legal']) && $is_seller) {
        $conn->query("UPDATE transactions SET seller_legal_confirmed = 1 WHERE id = $transaction_id");
        $payment_success = "Your legal confirmation has been recorded. Waiting for buyer confirmation.";
        header("Refresh: 2");
    }
    
    // Handle buyer delivery confirmation
    if (isset($_POST['confirm_buyer_delivery']) && $is_buyer) {
        $conn->query("UPDATE transactions SET buyer_delivery_confirmed = 1 WHERE id = $transaction_id");
        
        // Check if both confirmed
        $check = $conn->query("SELECT buyer_delivery_confirmed, seller_delivery_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
        if ($check['buyer_delivery_confirmed'] && $check['seller_delivery_confirmed']) {
            $payment_success = "Both parties have confirmed delivery! The buyer can now release payment.";
        } else {
            $payment_success = "Delivery confirmed! Waiting for the other party to confirm.";
        }
        header("Refresh: 2");
    }
    
    // Handle seller delivery confirmation
    if (isset($_POST['confirm_seller_delivery']) && $is_seller) {
        $conn->query("UPDATE transactions SET seller_delivery_confirmed = 1 WHERE id = $transaction_id");
        
        // Check if both confirmed
        $check = $conn->query("SELECT buyer_delivery_confirmed, seller_delivery_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
        if ($check['buyer_delivery_confirmed'] && $check['seller_delivery_confirmed']) {
            $payment_success = "Both parties have confirmed delivery! The buyer can now release payment.";
        } else {
            $payment_success = "Delivery confirmed! Waiting for the other party to confirm.";
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 1000px; margin: 40px auto; padding: 0 24px; }
        
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h2 { font-size: 18px; margin-bottom: 16px; color: #333; display: flex; align-items: center; gap: 8px; }
        
        .status-timeline { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .timeline-steps { display: flex; justify-content: space-between; position: relative; margin: 30px 0; flex-wrap: wrap; }
        .timeline-steps::before { content: ''; position: absolute; top: 25px; left: 0; right: 0; height: 2px; background: #e0e0e0; z-index: 0; }
        .step { text-align: center; flex: 1; position: relative; z-index: 1; min-width: 80px; }
        .step-circle { width: 50px; height: 50px; background: #e0e0e0; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 20px; }
        .step.completed .step-circle { background: #28a745; color: white; }
        .step.active .step-circle { background: #667eea; color: white; transform: scale(1.1); }
        .step-label { font-size: 11px; font-weight: 500; }
        .step-desc { font-size: 10px; color: #888; margin-top: 4px; }
        
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 20px; }
        .info-item { padding: 12px; background: #f8f9fa; border-radius: 8px; }
        .info-label { font-size: 11px; color: #888; margin-bottom: 4px; }
        .info-value { font-size: 14px; font-weight: 500; }
        
        .payment-code-box { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 25px; border-radius: 12px; text-align: center; margin: 20px 0; }
        .payment-code { font-size: 48px; font-weight: 700; letter-spacing: 10px; background: white; color: #333; padding: 20px; border-radius: 12px; margin: 15px 0; font-family: monospace; }
        .amount-due { font-size: 28px; font-weight: 700; color: #667eea; }
        
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-block; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-outline { background: transparent; border: 1px solid white; color: white; }
        
        .error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .success-message { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        
        .confirmation-section { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-top: 16px; }
        .status-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #e0e0e0; }
        .status-row:last-child { border-bottom: none; }
        
        @media (max-width: 768px) {
            .timeline-steps { flex-direction: column; gap: 20px; }
            .timeline-steps::before { display: none; }
            .info-grid { grid-template-columns: 1fr; }
            .payment-code { font-size: 28px; letter-spacing: 5px; }
            .btn { width: 100%; margin-bottom: 8px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="dashboard.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>
    
    <div class="container">
        <!-- Status Timeline -->
        <div class="status-timeline">
            <h2><i class="fas fa-chart-line"></i> Transaction Progress</h2>
            <div class="timeline-steps">
                <?php
                $currentStep = 0;
                $bothDepositsPaid = ($buyerPaid >= $buyerRequired && $sellerPaid >= $sellerRequired);
                if ($bothDepositsPaid) $currentStep = 1;
                if ($legal_status['both_confirmed']) $currentStep = 2;
                if ($delivery_status['both_confirmed']) $currentStep = 3;
                if ($transaction['status'] == 'completed') $currentStep = 4;
                ?>
                <div class="step <?php echo $currentStep >= 1 ? 'completed' : ($bothDepositsPaid ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-credit-card"></i></div>
                    <div class="step-label">Deposits Paid</div>
                    <div class="step-desc">Both parties</div>
                </div>
                <div class="step <?php echo $currentStep >= 2 ? 'completed' : ($currentStep == 1 ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-gavel"></i></div>
                    <div class="step-label">Legal Process</div>
                    <div class="step-desc">Both Confirm</div>
                </div>
                <div class="step <?php echo $currentStep >= 3 ? 'completed' : ($currentStep == 2 ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-truck"></i></div>
                    <div class="step-label">Delivery</div>
                    <div class="step-desc">Both Confirm</div>
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
        
        <!-- PAYMENT SECTION - Buyer or Seller Deposit -->
        <?php if ($transaction['status'] != 'completed' && $transaction['status'] != 'cancelled'): ?>
            
            <!-- Buyer Payment -->
            <?php if ($is_buyer && $buyerRemaining > 0): ?>
                <div class="card">
                    <h2><i class="fas fa-shopping-cart"></i> Complete Your Payment</h2>
                    <p>You need to pay <span class="amount-due"><?php echo formatMoney($buyerRemaining); ?></span> to proceed.</p>
                    
                    <?php if ($show_payment_code && $generated_code): ?>
                        <div class="payment-code-box">
                            <h3>📱 Your Telebirr Payment Code</h3>
                            <div class="payment-code"><?php echo $generated_code; ?></div>
                            <button class="btn btn-outline" onclick="copyCode('<?php echo $generated_code; ?>')" style="background: rgba(255,255,255,0.2); border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                <i class="fas fa-copy"></i> Copy Code
                            </button>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="payment_amount" value="<?php echo $buyerRemaining; ?>">
                            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                <input type="text" name="payment_code_confirm" placeholder="Enter 5-digit code" maxlength="5" pattern="\d{5}" required style="flex: 1; padding: 12px; font-size: 18px; text-align: center; letter-spacing: 5px; border: 1px solid #ddd; border-radius: 8px;">
                                <button type="submit" name="confirm_payment_code" class="btn btn-success">Confirm Payment</button>
                            </div>
                        </form>
                        
                        <div class="confirmation-section" style="margin-top: 16px;">
                            <h4>Payment Breakdown</h4>
                            <div class="status-row"><span>Item Price:</span><span><?php echo formatMoney($transaction['total_amount']); ?></span></div>
                            <div class="status-row"><span>Deposit (<?php echo $depositPercent; ?>%):</span><span><?php echo formatMoney($depositAmount); ?></span></div>
                            <div class="status-row"><span>Commission (<?php echo $commissionPercent; ?>%):</span><span><?php echo formatMoney($commissionAmount); ?></span></div>
                            <div class="status-row"><span>Already Paid:</span><span><?php echo formatMoney($buyerPaid); ?></span></div>
                        </div>
                    <?php else: ?>
                        <a href="transaction.php?id=<?php echo $transaction_id; ?>&code=generate" class="btn btn-primary">Generate Payment Code</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Seller Deposit -->
            <?php if ($is_seller && $sellerRemaining > 0): ?>
                <div class="card">
                    <h2><i class="fas fa-hand-holding-usd"></i> Seller Deposit Required</h2>
                    <p>You need to deposit <span class="amount-due"><?php echo formatMoney($sellerRemaining); ?></span> to secure this transaction.</p>
                    
                    <?php if ($show_payment_code && $generated_code): ?>
                        <div class="payment-code-box">
                            <h3>📱 Your Telebirr Payment Code</h3>
                            <div class="payment-code"><?php echo $generated_code; ?></div>
                            <button class="btn btn-outline" onclick="copyCode('<?php echo $generated_code; ?>')">Copy Code</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="payment_amount" value="<?php echo $sellerRemaining; ?>">
                            <input type="text" name="payment_code_confirm" placeholder="Enter 5-digit code" maxlength="5" pattern="\d{5}" required style="width: 100%; padding: 12px; margin-bottom: 12px;">
                            <button type="submit" name="confirm_payment_code" class="btn btn-success">Confirm Deposit</button>
                        </form>
                    <?php else: ?>
                        <a href="transaction.php?id=<?php echo $transaction_id; ?>&code=generate" class="btn btn-primary">Generate Payment Code</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- ============================================ -->
            <!-- LEGAL PROCESS CONFIRMATION SECTION            -->
            <!-- ============================================ -->
            <?php if ($buyerPaid >= $buyerRequired && $sellerPaid >= $sellerRequired && !$legal_status['both_confirmed']): ?>
                <div class="card">
                    <h2><i class="fas fa-gavel"></i> Legal Process Confirmation</h2>
                    <p>Confirm that all legal processes, documentation, and requirements are completed.</p>
                    
                    <div class="confirmation-section">
                        <div class="status-row">
                            <span><i class="fas fa-user"></i> Buyer Legal Status:</span>
                            <span><?php echo $legal_status['buyer_confirmed'] ? '<span class="badge badge-success">✓ Confirmed</span>' : '<span class="badge badge-warning">⏳ Pending</span>'; ?></span>
                        </div>
                        <div class="status-row">
                            <span><i class="fas fa-store"></i> Seller Legal Status:</span>
                            <span><?php echo $legal_status['seller_confirmed'] ? '<span class="badge badge-success">✓ Confirmed</span>' : '<span class="badge badge-warning">⏳ Pending</span>'; ?></span>
                        </div>
                    </div>
                    
                    <?php if ($is_buyer && !$legal_status['buyer_confirmed']): ?>
                        <form method="POST" style="margin-top: 16px;">
                            <button type="submit" name="confirm_buyer_legal" class="btn btn-success" onclick="return confirm('Confirm that all legal processes are completed?')">
                                <i class="fas fa-check-circle"></i> I Confirm Legal Process Completed
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($is_seller && !$legal_status['seller_confirmed']): ?>
                        <form method="POST" style="margin-top: 16px;">
                            <button type="submit" name="confirm_seller_legal" class="btn btn-success" onclick="return confirm('Confirm that all legal processes are completed?')">
                                <i class="fas fa-check-circle"></i> I Confirm Legal Process Completed
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- ============================================ -->
            <!-- DELIVERY CONFIRMATION SECTION                 -->
            <!-- ============================================ -->
            <?php if ($legal_status['both_confirmed'] && !$delivery_status['both_confirmed']): ?>
                <div class="card">
                    <h2><i class="fas fa-truck"></i> Delivery Confirmation</h2>
                    <p>Confirm the delivery status of the item/service.</p>
                    
                    <div class="confirmation-section">
                        <div class="status-row">
                            <span><i class="fas fa-user"></i> Buyer Delivery Status:</span>
                            <span><?php echo $delivery_status['buyer_confirmed'] ? '<span class="badge badge-success">✓ Received</span>' : '<span class="badge badge-warning">⏳ Not Confirmed</span>'; ?></span>
                        </div>
                        <div class="status-row">
                            <span><i class="fas fa-store"></i> Seller Delivery Status:</span>
                            <span><?php echo $delivery_status['seller_confirmed'] ? '<span class="badge badge-success">✓ Delivered</span>' : '<span class="badge badge-warning">⏳ Not Confirmed</span>'; ?></span>
                        </div>
                    </div>
                    
                    <?php if ($is_buyer && !$delivery_status['buyer_confirmed']): ?>
                        <form method="POST" style="margin-top: 16px;">
                            <button type="submit" name="confirm_buyer_delivery" class="btn btn-success" onclick="return confirm('Confirm that you have received the item/service?')">
                                <i class="fas fa-box"></i> I Confirm I Have Received the Item
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($is_seller && !$delivery_status['seller_confirmed']): ?>
                        <form method="POST" style="margin-top: 16px;">
                            <button type="submit" name="confirm_seller_delivery" class="btn btn-primary" onclick="return confirm('Confirm that you have delivered the item/service?')">
                                <i class="fas fa-truck"></i> I Confirm I Have Delivered the Item
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- ============================================ -->
            <!-- RELEASE PAYMENT SECTION                       -->
            <!-- ============================================ -->
            <?php if ($delivery_status['both_confirmed'] && $transaction['status'] == 'deposits_complete'): ?>
                <div class="card">
                    <h2><i class="fas fa-money-bill-wave"></i> Release Payment</h2>
                    <p>Both parties have confirmed delivery. You can now release the payment to the seller.</p>
                    <div class="confirmation-section">
                        <div class="status-row"><span>Total Amount:</span><span><?php echo formatMoney($transaction['total_amount']); ?></span></div>
                        <div class="status-row"><span>Platform Commission (<?php echo $commissionPercent; ?>%):</span><span><?php echo formatMoney($transaction['commission_amount']); ?></span></div>
                        <div class="status-row"><span style="font-weight: bold;">Amount to Seller:</span><span style="font-weight: bold;"><?php echo formatMoney($transaction['total_amount'] - $transaction['commission_amount']); ?></span></div>
                    </div>
                    <?php if ($is_buyer): ?>
                        <form method="POST" style="margin-top: 16px;">
                            <button type="submit" name="release_payment" class="btn btn-success" onclick="return confirm('Release payment to seller? This action cannot be undone.')">
                                <i class="fas fa-check-circle"></i> Release Payment to Seller
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="info-text">Waiting for buyer to release payment.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php elseif ($transaction['status'] == 'completed'): ?>
            <div class="card">
                <h2><i class="fas fa-check-circle" style="color: #28a745;"></i> Transaction Completed</h2>
                <p>This transaction has been completed successfully.</p>
                <?php if ($is_seller): ?>
                    <div class="success-message" style="margin-top: 16px;">
                        <i class="fas fa-money-bill-wave"></i> Payment has been released to your wallet.
                    </div>
                <?php endif; ?>
                <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="product.php?id=<?php echo $transaction['listing_id']; ?>" class="btn btn-primary">View Item</a>
                    <a href="dashboard.php" class="btn" style="background: #6c757d; color: white;">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Payment History -->
        <?php if ($payments_history && $payments_history->num_rows > 0): ?>
        <div class="card">
            <h2><i class="fas fa-history"></i> Payment History</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Date</th><th>Amount</th><th>Type</th><th>Telebirr Code</th><th>Status</th></tr>
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
    
    <script>
        function copyCode(code) {
            navigator.clipboard.writeText(code);
            alert('Payment code copied: ' + code);
        }
    </script>
</body>
</html>