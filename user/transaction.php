<?php
// user/transaction.php - Complete transaction page with Telebirr payment code

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$transaction_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, 
           l.title as listing_title, 
           l.type as listing_type,
           l.cover_image,
           l.admin_deposit_percent,
           l.admin_commission_percent,
           u1.full_name as buyer_name, 
           u1.email as buyer_email,
           u1.phone as buyer_phone,
           u2.full_name as seller_name, 
           u2.email as seller_email,
           u2.phone as seller_phone
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
$payments = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed'
");
$buyerPaid = 0;
$sellerPaid = 0;
while($p = $payments->fetch_assoc()) {
    if ($p['type'] == 'deposit_buyer' || $p['type'] == 'commission') {
        $buyerPaid += $p['amount'];
    } elseif ($p['type'] == 'deposit_seller') {
        $sellerPaid += $p['amount'];
    }
}

$buyerRemaining = $buyerRequired - $buyerPaid;
$sellerRemaining = $sellerRequired - $sellerPaid;

// Check if payment code was just generated
$showPaymentCode = false;
$generatedCode = '';
$generatedAmount = 0;
$generatedType = '';

if (isset($_GET['code']) && isset($_GET['amount']) && isset($_GET['type'])) {
    $showPaymentCode = true;
    $generatedCode = $_GET['code'];
    $generatedAmount = floatval($_GET['amount']);
    $generatedType = $_GET['type'];
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
        .container { max-width: 1200px; margin: 40px auto; padding: 0 24px; }
        
        /* Status Timeline */
        .status-timeline { background: white; border-radius: 12px; padding: 32px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .timeline-steps { display: flex; justify-content: space-between; position: relative; margin: 40px 0; }
        .timeline-steps::before { content: ''; position: absolute; top: 30px; left: 0; right: 0; height: 2px; background: #e0e0e0; z-index: 0; }
        .step { text-align: center; flex: 1; position: relative; z-index: 1; }
        .step-circle { width: 60px; height: 60px; background: #e0e0e0; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 24px; transition: all 0.3s; }
        .step.completed .step-circle { background: #28a745; color: white; }
        .step.active .step-circle { background: #667eea; color: white; transform: scale(1.1); }
        .step-label { font-size: 14px; font-weight: 500; margin-top: 8px; }
        .step-desc { font-size: 12px; color: #888; margin-top: 4px; }
        
        /* Cards */
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h2 { font-size: 18px; margin-bottom: 16px; color: #333; display: flex; align-items: center; gap: 8px; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 20px; }
        .info-item { padding: 12px; background: #f8f9fa; border-radius: 8px; }
        .info-label { font-size: 12px; color: #888; margin-bottom: 4px; }
        .info-value { font-size: 16px; font-weight: 500; }
        
        /* Payment Section */
        .payment-section { background: #f8f9fa; border-radius: 12px; padding: 24px; margin-top: 16px; }
        .amount-due { font-size: 32px; font-weight: 700; color: #667eea; }
        .payment-code-box { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; border-radius: 12px; text-align: center; margin: 20px 0; }
        .payment-code { font-size: 48px; font-weight: 700; letter-spacing: 8px; background: white; color: #333; padding: 20px; border-radius: 8px; margin: 16px 0; font-family: monospace; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; transform: translateY(-2px); }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: #ffc107; color: #333; }
        .countdown { font-size: 14px; color: #666; margin-top: 12px; }
        .payment-status { text-align: center; padding: 20px; }
        .spinner { display: inline-block; width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        /* Messages */
        .error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .success-message { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .timeline-steps { flex-direction: column; gap: 20px; }
            .timeline-steps::before { display: none; }
            .info-grid { grid-template-columns: 1fr; }
            .payment-code { font-size: 28px; letter-spacing: 4px; }
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
                $stepStatus = [
                    'awaiting_buyer_deposit' => 1,
                    'awaiting_seller_deposit' => 2,
                    'deposits_complete' => 3,
                    'in_progress' => 3,
                    'completed' => 4,
                    'disputed' => 3
                ];
                $currentStep = $stepStatus[$transaction['status']] ?? 0;
                ?>
                <div class="step <?php echo $currentStep >= 1 ? 'completed' : ($currentStep == 0 ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-credit-card"></i></div>
                    <div class="step-label">Buyer Deposit + Commission</div>
                    <div class="step-desc"><?php echo formatMoney($buyerRequired); ?> paid: <?php echo formatMoney($buyerPaid); ?></div>
                </div>
                <div class="step <?php echo $currentStep >= 2 ? 'completed' : ($currentStep == 1 ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-handshake"></i></div>
                    <div class="step-label">Seller Deposit</div>
                    <div class="step-desc"><?php echo formatMoney($sellerRequired); ?> paid: <?php echo formatMoney($sellerPaid); ?></div>
                </div>
                <div class="step <?php echo $currentStep >= 3 ? 'completed' : ($currentStep == 2 ? 'active' : ''); ?>">
                    <div class="step-circle"><i class="fas fa-truck"></i></div>
                    <div class="step-label">Delivery & Confirmation</div>
                    <div class="step-desc">Buyer confirms receipt</div>
                </div>
                <div class="step <?php echo $currentStep >= 4 ? 'completed' : ''; ?>">
                    <div class="step-circle"><i class="fas fa-check-circle"></i></div>
                    <div class="step-label">Completed</div>
                    <div class="step-desc">Payment released to seller</div>
                </div>
            </div>
        </div>
        
        <!-- Transaction Details -->
        <div class="card">
            <h2><i class="fas fa-info-circle"></i> Transaction Details</h2>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Transaction ID</div><div class="info-value">#<?php echo $transaction['id']; ?></div></div>
                <div class="info-item"><div class="info-label">Item</div><div class="info-value"><a href="product.php?id=<?php echo $transaction['listing_id']; ?>"><?php echo htmlspecialchars($transaction['listing_title']); ?></a></div></div>
                <div class="info-item"><div class="info-label">Total Amount</div><div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div></div>
                <div class="info-item"><div class="info-label">Status</div><div class="info-value"><?php echo getStatusBadge($transaction['status']); ?></div></div>
                <div class="info-item"><div class="info-label">Buyer</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?><br><small><?php echo htmlspecialchars($transaction['buyer_email']); ?></small></div></div>
                <div class="info-item"><div class="info-label">Seller</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_name']); ?><br><small><?php echo htmlspecialchars($transaction['seller_email']); ?></small></div></div>
                <div class="info-item"><div class="info-label">Created</div><div class="info-value"><?php echo date('F d, Y H:i', strtotime($transaction['created_at'])); ?></div></div>
                <?php if ($transaction['completed_at']): ?>
                <div class="info-item"><div class="info-label">Completed</div><div class="info-value"><?php echo date('F d, Y H:i', strtotime($transaction['completed_at'])); ?></div></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Payment Section -->
        <?php if ($transaction['status'] != 'completed' && $transaction['status'] != 'cancelled'): ?>
            
            <!-- Buyer Payment -->
            <?php if ($is_buyer && $buyerRemaining > 0): ?>
                <div class="card">
                    <h2><i class="fas fa-shopping-cart"></i> Complete Your Payment</h2>
                    <p>You need to pay <span class="amount-due"><?php echo formatMoney($buyerRemaining); ?></span> to proceed with this purchase.</p>
                    
                    <div class="payment-section">
                        <h3>Payment Breakdown</h3>
                        <div class="info-grid" style="margin-top: 12px;">
                            <div class="info-item"><div class="info-label">Item Price</div><div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div></div>
                            <div class="info-item"><div class="info-label">Deposit (<?php echo $depositPercent; ?>%)</div><div class="info-value"><?php echo formatMoney($depositAmount); ?></div></div>
                            <div class="info-item"><div class="info-label">Commission (<?php echo $commissionPercent; ?>%)</div><div class="info-value"><?php echo formatMoney($commissionAmount); ?></div></div>
                            <div class="info-item"><div class="info-label">Already Paid</div><div class="info-value"><?php echo formatMoney($buyerPaid); ?></div></div>
                        </div>
                        
                        <?php if ($showPaymentCode && $generatedType == 'buyer'): ?>
                            <div class="payment-code-box">
                                <i class="fas fa-qrcode" style="font-size: 32px;"></i>
                                <h3>Your Telebirr Payment Code</h3>
                                <div class="payment-code"><?php echo $generatedCode; ?></div>
                                <p>Open your Telebirr app and enter this 5-digit code</p>
                                <div class="countdown" id="countdownTimer">Code expires in: <span id="countdown">10:00</span></div>
                                <button class="btn btn-primary" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
                            </div>
                            <div class="payment-status" id="paymentStatus">
                                <div class="spinner"></div>
                                <p>Waiting for payment confirmation...</p>
                            </div>
                        <?php else: ?>
                            <button class="btn btn-primary" onclick="generatePaymentCode()" id="generateBtn" style="width: 100%;">
                                <i class="fas fa-qrcode"></i> Generate Telebirr Payment Code
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Seller Deposit -->
            <?php if ($is_seller && $sellerRemaining > 0): ?>
                <div class="card">
                    <h2><i class="fas fa-hand-holding-usd"></i> Seller Deposit Required</h2>
                    <p>As a seller, you need to deposit <span class="amount-due"><?php echo formatMoney($sellerRemaining); ?></span> to secure this transaction.</p>
                    
                    <div class="payment-section">
                        <p>This deposit shows your commitment and will be held in escrow until the buyer confirms delivery.</p>
                        
                        <?php if ($showPaymentCode && $generatedType == 'seller'): ?>
                            <div class="payment-code-box">
                                <i class="fas fa-qrcode" style="font-size: 32px;"></i>
                                <h3>Your Telebirr Payment Code</h3>
                                <div class="payment-code"><?php echo $generatedCode; ?></div>
                                <p>Open your Telebirr app and enter this 5-digit code</p>
                                <div class="countdown" id="countdownTimer">Code expires in: <span id="countdown">10:00</span></div>
                                <button class="btn btn-primary" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
                            </div>
                            <div class="payment-status" id="paymentStatus">
                                <div class="spinner"></div>
                                <p>Waiting for payment confirmation...</p>
                            </div>
                        <?php else: ?>
                            <button class="btn btn-primary" onclick="generatePaymentCode()" id="generateBtn" style="width: 100%;">
                                <i class="fas fa-qrcode"></i> Generate Telebirr Payment Code
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Confirm Delivery (Buyer only) -->
            <?php if ($is_buyer && $transaction['status'] == 'deposits_complete'): ?>
                <div class="card">
                    <h2><i class="fas fa-check-circle"></i> Confirm Delivery</h2>
                    <p>Have you received the item or service?</p>
                    <div class="payment-section">
                        <p><strong>⚠️ Important:</strong> Once you confirm delivery, the payment will be released to the seller.</p>
                        <button class="btn btn-success" onclick="confirmDelivery()" style="width: 100%; margin-top: 16px;">
                            <i class="fas fa-check"></i> Yes, Confirm Delivery & Release Payment
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Dispute Button -->
            <?php if (in_array($transaction['status'], ['awaiting_seller_deposit', 'deposits_complete', 'in_progress']) && !$is_seller): ?>
                <div class="card">
                    <h2><i class="fas fa-gavel"></i> Having Issues?</h2>
                    <button class="btn btn-warning" onclick="openDispute()" style="width: 100%;">
                        <i class="fas fa-flag"></i> Raise a Dispute
                    </button>
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
                <div class="payment-section" style="margin-top: 16px;">
                    <a href="product.php?id=<?php echo $transaction['listing_id']; ?>" class="btn btn-primary">View Item</a>
                    <a href="dashboard.php" class="btn" style="background: #6c757d; color: white;">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Payment History -->
        <?php 
        $payments_history = $conn->query("
            SELECT * FROM payments 
            WHERE transaction_id = $transaction_id AND status = 'confirmed' 
            ORDER BY created_at DESC
        ");
        if ($payments_history && $payments_history->num_rows > 0): 
        ?>
        <div class="card">
            <h2><i class="fas fa-history"></i> Payment History</h2>
            <div style="overflow-x: auto;">
                <table style="width: 100%;">
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
        let countdownInterval;
        let checkInterval;
        let currentCode = '';
        
        function generatePaymentCode() {
            const btn = document.getElementById('generateBtn');
            const amount = <?php echo $is_buyer ? $buyerRemaining : $sellerRemaining; ?>;
            const paymentType = '<?php echo $is_buyer ? "buyer" : "seller"; ?>';
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating payment code...';
            
            fetch('/broker_system/api/generate_code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_id: <?php echo $transaction_id; ?>,
                    amount: amount,
                    payment_type: 'deposit_<?php echo $is_buyer ? "buyer" : "seller"; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentCode = data.payment_code;
                    window.location.href = `?id=<?php echo $transaction_id; ?>&code=${data.payment_code}&amount=${data.amount}&type=<?php echo $is_buyer ? 'buyer' : 'seller'; ?>`;
                } else {
                    alert('Error: ' + data.error);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-qrcode"></i> Generate Telebirr Payment Code';
                }
            })
            .catch(error => {
                alert('Error generating payment code: ' + error);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-qrcode"></i> Generate Telebirr Payment Code';
            });
        }
        
        function startCountdown(seconds) {
            const countdownEl = document.getElementById('countdown');
            let remaining = seconds;
            
            countdownInterval = setInterval(() => {
                const minutes = Math.floor(remaining / 60);
                const secs = remaining % 60;
                countdownEl.innerHTML = `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                
                if (remaining <= 0) {
                    clearInterval(countdownInterval);
                    clearInterval(checkInterval);
                    countdownEl.innerHTML = 'Expired';
                    document.getElementById('paymentStatus').innerHTML = '<div class="error-message">Code expired. Please generate a new code.</div>';
                }
                remaining--;
            }, 1000);
        }
        
        function checkPaymentConfirmation() {
            const code = <?php echo json_encode($generatedCode); ?>;
            
            checkInterval = setInterval(() => {
                fetch('/broker_system/api/check_payment.php?code=' + code)
                    .then(response => response.json())
                    .then(data => {
                        if (data.confirmed) {
                            clearInterval(checkInterval);
                            clearInterval(countdownInterval);
                            document.getElementById('paymentStatus').innerHTML = `
                                <div class="success-message">
                                    <i class="fas fa-check-circle"></i> Payment confirmed! Redirecting...
                                </div>
                            `;
                            setTimeout(() => {
                                window.location.href = 'transaction.php?id=<?php echo $transaction_id; ?>';
                            }, 2000);
                        }
                    });
            }, 3000);
        }
        
        function copyCode() {
            const code = <?php echo json_encode($generatedCode); ?>;
            navigator.clipboard.writeText(code);
            alert('Payment code copied: ' + code);
        }
        
        function confirmDelivery() {
            if (confirm('Have you received the item/service? This will release the payment to the seller.')) {
                fetch('/broker_system/api/confirm_delivery.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ transaction_id: <?php echo $transaction_id; ?> })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                });
            }
        }
        
        function openDispute() {
            const reason = prompt('Please describe the issue:');
            if (reason) {
                fetch('/broker_system/api/create_dispute.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        transaction_id: <?php echo $transaction_id; ?>,
                        reason: reason
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Dispute raised successfully. Admin will review your case.');
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                });
            }
        }
        
        <?php if ($showPaymentCode): ?>
            startCountdown(600);
            checkPaymentConfirmation();
        <?php endif; ?>
    </script>
</body>
</html>