<?php
// user/transaction.php - Fixed payment page (no PIN confirmation)

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

// Get payment code from URL
$generated_code = '';
$show_payment_code = false;
$payment_status = 'pending';

if (isset($_GET['code'])) {
    $generated_code = $_GET['code'];
    $show_payment_code = true;
    
    // Check if payment is already confirmed via API
    $check_payment = $conn->query("SELECT id FROM payments WHERE telebirr_code_5digit = '$generated_code' AND status = 'confirmed'");
    if ($check_payment->num_rows > 0) {
        $payment_status = 'completed';
    }
}

// Get payment history
$payments_history = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed' 
    ORDER BY created_at DESC
");

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
        
        .payment-code-box { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; border-radius: 20px; text-align: center; margin: 20px 0; }
        .payment-code { font-size: 48px; font-weight: 700; letter-spacing: 10px; background: white; color: #1e293b; padding: 20px; border-radius: 16px; margin: 20px 0; font-family: monospace; }
        .amount-due { font-size: 32px; font-weight: 700; color: #667eea; }
        
        .btn { padding: 12px 24px; border: none; border-radius: 40px; font-weight: 600; cursor: pointer; display: inline-block; text-decoration: none; transition: all 0.3s; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-outline { background: transparent; border: 1px solid white; color: white; }
        
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 20px; }
        .info-item { padding: 14px; background: #f8fafc; border-radius: 16px; }
        .info-label { font-size: 11px; color: #64748b; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { font-size: 15px; font-weight: 600; color: #0f172a; }
        
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fed7aa; color: #ea580c; }
        
        .status-indicator { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: #f1f5f9; border-radius: 40px; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; background: #10b981; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
            .payment-code { font-size: 28px; letter-spacing: 5px; }
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
        <!-- Transaction Details -->
        <div class="card">
            <h2><i class="fas fa-info-circle"></i> Transaction Details</h2>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Transaction ID</div><div class="info-value">#<?php echo $transaction['id']; ?></div></div>
                <div class="info-item"><div class="info-label">Item</div><div class="info-value"><?php echo htmlspecialchars($transaction['listing_title']); ?></div></div>
                <div class="info-item"><div class="info-label">Total Amount</div><div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div></div>
                <div class="info-item"><div class="info-label">Status</div><div class="info-value"><?php echo getStatusBadge($transaction['status']); ?></div></div>
            </div>
        </div>
        
        <!-- Payment Section -->
        <?php if ($transaction['status'] != 'completed' && $transaction['status'] != 'cancelled'): ?>
            
            <?php if ($is_buyer && $buyerRemaining > 0): ?>
                <div class="card">
                    <h2><i class="fas fa-shopping-cart"></i> Complete Your Payment</h2>
                    <p>You need to pay <span class="amount-due"><?php echo formatMoney($buyerRemaining); ?></span></p>
                    
                    <?php if ($show_payment_code && $generated_code): ?>
                        <div class="payment-code-box">
                            <h3>📱 Your Telebirr Payment Code</h3>
                            <div class="payment-code" id="paymentCode"><?php echo $generated_code; ?></div>
                            <p>Use this 5-digit code in the Telebirr app to complete your payment</p>
                            <button class="btn btn-outline" onclick="copyCode()" style="background: rgba(255,255,255,0.2);">
                                <i class="fas fa-copy"></i> Copy Code
                            </button>
                        </div>
                        
                        <div class="status-indicator" style="margin-top: 16px;">
                            <div class="status-dot"></div>
                            <span id="paymentStatus">Waiting for payment confirmation...</span>
                        </div>
                        
                        <div class="info-grid" style="margin-top: 20px;">
                            <div class="info-item"><div class="info-label">Item Price</div><div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div></div>
                            <div class="info-item"><div class="info-label">Deposit (<?php echo $depositPercent; ?>%)</div><div class="info-value"><?php echo formatMoney($depositAmount); ?></div></div>
                            <div class="info-item"><div class="info-label">Commission (<?php echo $commissionPercent; ?>%)</div><div class="info-value"><?php echo formatMoney($commissionAmount); ?></div></div>
                            <div class="info-item"><div class="info-label">You Pay Today</div><div class="info-value"><?php echo formatMoney($buyerRemaining); ?></div></div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px;">
                            <a href="transaction.php?id=<?php echo $transaction_id; ?>&code=generate" class="btn btn-primary">
                                <i class="fas fa-qrcode"></i> Generate Payment Code
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php elseif ($transaction['status'] == 'completed'): ?>
            <div class="card">
                <h2><i class="fas fa-check-circle" style="color: #10b981;"></i> Transaction Completed</h2>
                <p>This transaction has been completed successfully.</p>
                <div style="margin-top: 20px;">
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
                <table>
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
    
    <script>
        let code = '<?php echo $generated_code; ?>';
        let checkInterval;
        
        function copyCode() {
            navigator.clipboard.writeText(code);
            alert('Payment code copied: ' + code);
        }
        
        function checkPaymentStatus() {
            if (!code) return;
            
            fetch('/broker_system/user/api/check_payment_status.php?code=' + code)
                .then(response => response.json())
                .then(data => {
                    if (data.confirmed) {
                        clearInterval(checkInterval);
                        document.getElementById('paymentStatus').innerHTML = '✓ Payment confirmed! Redirecting...';
                        document.getElementById('paymentStatus').style.color = '#10b981';
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    }
                });
        }
        
        // Start checking payment status every 3 seconds
        if (code) {
            checkInterval = setInterval(checkPaymentStatus, 3000);
        }
    </script>
</body>
</html>