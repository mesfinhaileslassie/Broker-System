<?php
// user/pay_listing.php - Simple payment page with auto-activation check

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = intval($_GET['listing_id'] ?? 0);
$error = '';
$success = '';
$code = '';
$amount = 0;
$listing_info = null;
$is_paid = false;

if ($listing_id > 0) {
    // Get listing details
    $listing_result = $conn->query("
        SELECT l.*, 
               l.admin_deposit_percent as deposit_percent, 
               l.admin_commission_percent as commission_percent
        FROM listings l
        WHERE l.id = $listing_id 
        AND l.seller_id = $user_id 
        AND l.approval_status = 'approved'
    ");
    
    $listing_info = $listing_result->fetch_assoc();
    
    if ($listing_info) {
        // Check if already paid (status active)
        if ($listing_info['status'] == 'active') {
            $is_paid = true;
            $success = "This listing is already active!";
        } else {
            // Calculate payment amount
            $deposit_amount = $listing_info['price'] * ($listing_info['deposit_percent'] / 100);
            $commission_amount = $listing_info['price'] * ($listing_info['commission_percent'] / 100);
            $amount = $deposit_amount + $commission_amount;
            
            // Check if payment already exists
            $payment_check = $conn->query("
                SELECT p.* FROM payments p
                JOIN transactions t ON p.transaction_id = t.id
                WHERE t.listing_id = $listing_id AND p.user_id = $user_id AND p.status = 'confirmed'
            ");
            
            if ($payment_check->num_rows > 0) {
                // Payment already made, activate listing
                $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
                $is_paid = true;
                $success = "Payment confirmed! Your listing is now active.";
            } else {
                // Check if a payment code already exists
                $code_check = $conn->query("
                    SELECT pc.code, pc.expires_at, pc.id
                    FROM payment_codes pc
                    JOIN transactions t ON pc.transaction_id = t.id
                    WHERE t.listing_id = $listing_id AND pc.status = 'pending'
                    ORDER BY pc.created_at DESC LIMIT 1
                ");
                
                if ($code_check->num_rows > 0) {
                    $existing = $code_check->fetch_assoc();
                    $code = $existing['code'];
                } else {
                    // Generate a new 5-digit code
                    do {
                        $new_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                        $code_exists = $conn->query("SELECT id FROM payment_codes WHERE code = '$new_code'");
                    } while ($code_exists->num_rows > 0);
                    
                    // Create a transaction if not exists
                    $transaction_check = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id");
                    if ($transaction_check->num_rows == 0) {
                        $stmt = $conn->prepare("
                            INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_seller_deposit')
                        ");
                        $remaining = $listing_info['price'] - $deposit_amount;
                        $stmt->bind_param("iiiiddd", $listing_id, $user_id, $user_id, $listing_info['price'], $deposit_amount, $commission_amount, $remaining);
                        $stmt->execute();
                        $transaction_id = $conn->insert_id;
                    } else {
                        $transaction_id = $transaction_check->fetch_assoc()['id'];
                    }
                    
                    // Save payment code
                    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $stmt = $conn->prepare("
                        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
                        VALUES (?, ?, ?, ?, 'deposit_seller', ?, 'pending')
                    ");
                    $stmt->bind_param("siids", $new_code, $transaction_id, $amount, $user_id, $expires_at);
                    $stmt->execute();
                    $code = $new_code;
                }
            }
        }
    } else {
        $error = "Listing not found.";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay for Listing - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .header { background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 16px 24px; }
        .header-content { max-width: 600px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        
        .container { max-width: 600px; margin: 40px auto; padding: 0 24px; }
        
        .payment-card {
            background: white;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
        }
        
        .payment-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            padding: 32px;
            text-align: center;
            color: white;
        }
        
        .payment-header h2 { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
        .payment-header p { font-size: 14px; opacity: 0.9; }
        
        .payment-body { padding: 32px; }
        
        .item-details {
            background: #f8fafc;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .item-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 14px;
        }
        
        .detail-label { color: #64748b; }
        .detail-value { font-weight: 600; color: #1e293b; }
        
        .detail-total {
            border-top: 2px solid #e2e8f0;
            margin-top: 8px;
            padding-top: 12px;
            font-size: 18px;
            font-weight: 700;
        }
        
        .detail-total .detail-value { color: #667eea; font-size: 20px; }
        
        .code-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 20px;
            padding: 28px;
            text-align: center;
            margin-bottom: 24px;
        }
        
        .code-label { font-size: 13px; color: rgba(255,255,255,0.8); margin-bottom: 12px; }
        
        .payment-code {
            font-size: 52px;
            font-weight: 800;
            letter-spacing: 12px;
            background: white;
            color: #1e293b;
            padding: 20px;
            border-radius: 16px;
            font-family: monospace;
            margin: 16px 0;
        }
        
        .copy-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 8px 20px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .copy-btn:hover { background: rgba(255,255,255,0.3); transform: scale(1.02); }
        
        .instructions {
            background: #f1f5f9;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .instructions h4 { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #1e293b; }
        
        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            font-size: 13px;
            color: #475569;
        }
        
        .step-number {
            width: 28px;
            height: 28px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }
        
        .status-section {
            background: #f8fafc;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: white;
            border-radius: 40px;
            margin-bottom: 16px;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #f59e0b;
            animation: pulse 1.5s infinite;
        }
        
        .status-dot.success { background: #10b981; animation: none; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
        
        .btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
            width: 100%;
            text-align: center;
        }
        
        .btn-primary:hover { background: #5a67d8; transform: translateY(-2px); }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; transform: translateY(-2px); }
        
        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 14px;
            border-radius: 16px;
            margin-bottom: 20px;
            border-left: 4px solid #dc2626;
        }
        
        .success-message {
            background: #d1fae5;
            color: #059669;
            padding: 14px;
            border-radius: 16px;
            margin-bottom: 20px;
            border-left: 4px solid #059669;
            text-align: center;
        }
        
        .timer { font-size: 12px; color: rgba(255,255,255,0.7); margin-top: 12px; }
        
        @media (max-width: 480px) {
            .payment-code { font-size: 32px; letter-spacing: 6px; }
            .payment-body { padding: 24px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="listings.php?status=pending" style="color: #64748b; text-decoration: none;"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </header>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <a href="listings.php?status=pending" class="btn btn-primary" style="display: block; text-align: center;">Go Back to Listings</a>
        <?php elseif ($is_paid): ?>
            <div class="payment-card">
                <div class="payment-header" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <h2><i class="fas fa-check-circle"></i> Payment Successful!</h2>
                    <p>Your listing is now active</p>
                </div>
                <div class="payment-body">
                    <div class="success-message" style="margin-bottom: 0;">
                        <i class="fas fa-check-circle" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                        <p><?php echo htmlspecialchars($success); ?></p>
                    </div>
                    <a href="listings.php?status=active" class="btn btn-success" style="display: block; text-align: center; margin-top: 20px;">
                        <i class="fas fa-eye"></i> View My Active Listings
                    </a>
                </div>
            </div>
        <?php elseif ($code && $listing_info): ?>
            <div class="payment-card">
                <div class="payment-header">
                    <h2><i class="fas fa-credit-card"></i> Complete Payment</h2>
                    <p>Activate your listing by paying deposit + commission</p>
                </div>
                
                <div class="payment-body">
                    <!-- Item Details -->
                    <div class="item-details">
                        <div class="item-title"><?php echo htmlspecialchars($listing_info['title']); ?></div>
                        <div class="detail-row">
                            <span class="detail-label">Item Price</span>
                            <span class="detail-value"><?php echo formatMoney($listing_info['price']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Deposit (<?php echo $listing_info['deposit_percent']; ?>%)</span>
                            <span class="detail-value"><?php echo formatMoney($listing_info['price'] * $listing_info['deposit_percent'] / 100); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Commission (<?php echo $listing_info['commission_percent']; ?>%)</span>
                            <span class="detail-value"><?php echo formatMoney($listing_info['price'] * $listing_info['commission_percent'] / 100); ?></span>
                        </div>
                        <div class="detail-row detail-total">
                            <span class="detail-label">Total to Pay</span>
                            <span class="detail-value"><?php echo formatMoney($amount); ?></span>
                        </div>
                    </div>
                    
                    <!-- Payment Code -->
                    <div class="code-box">
                        <div class="code-label">Your Telebirr Payment Code</div>
                        <div class="payment-code" id="paymentCode"><?php echo $code; ?></div>
                        <button class="copy-btn" onclick="copyCode()">
                            <i class="fas fa-copy"></i> Copy Code
                        </button>
                        <div class="timer" id="timer">Code expires in 10:00</div>
                    </div>
                    
                    <!-- Instructions -->
                    <div class="instructions">
                        <h4><i class="fas fa-mobile-alt"></i> How to Pay with Telebirr</h4>
                        <div class="step"><div class="step-number">1</div><span>Open Telebirr app on your phone</span></div>
                        <div class="step"><div class="step-number">2</div><span>Go to Marketplace / Payment section</span></div>
                        <div class="step"><div class="step-number">3</div><span>Enter this code: <strong><?php echo $code; ?></strong></span></div>
                        <div class="step"><div class="step-number">4</div><span>Confirm payment with your Telebirr PIN</span></div>
                    </div>
                    
                    <!-- Payment Status -->
                    <div class="status-section" id="statusSection">
                        <div class="status-indicator">
                            <div class="status-dot" id="statusDot"></div>
                            <span class="status-text" id="statusText">Waiting for payment confirmation...</span>
                        </div>
                        <a href="listings.php?status=pending" class="btn btn-outline" style="display: inline-block; margin-top: 12px; background: #e2e8f0; color: #64748b; padding: 10px 20px; border-radius: 40px; text-decoration: none;">
                            <i class="fas fa-arrow-left"></i> Return to My Listings
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="error-message">
                No payment required. <a href="listings.php">Go to My Listings</a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        let code = '<?php echo $code; ?>';
        let checkInterval;
        let timeLeft = 600;
        let timerInterval;
        
        function copyCode() {
            navigator.clipboard.writeText(code);
            alert('Payment code copied: ' + code);
        }
        
        function updateTimer() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            const timerElement = document.getElementById('timer');
            if (timerElement) {
                timerElement.textContent = `Code expires in ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }
            
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                clearInterval(checkInterval);
                const statusText = document.getElementById('statusText');
                if (statusText) {
                    statusText.innerHTML = '⚠️ Code expired. Please generate a new code.';
                    statusText.style.color = '#dc2626';
                }
                const statusDot = document.getElementById('statusDot');
                if (statusDot) statusDot.style.background = '#dc2626';
            }
            timeLeft--;
        }
        
        function checkPaymentStatus() {
            if (!code) return;
            
            fetch('/broker_system/user/api/check_payment_status.php?code=' + code)
                .then(response => response.json())
                .then(data => {
                    if (data.confirmed) {
                        clearInterval(checkInterval);
                        clearInterval(timerInterval);
                        const statusText = document.getElementById('statusText');
                        const statusDot = document.getElementById('statusDot');
                        if (statusText) {
                            statusText.innerHTML = '✓ Payment confirmed! Your listing is now active.';
                            statusText.style.color = '#10b981';
                        }
                        if (statusDot) {
                            statusDot.style.background = '#10b981';
                            statusDot.style.animation = 'none';
                        }
                        // Show success message and redirect after 3 seconds
                        setTimeout(() => {
                            window.location.href = 'listings.php?status=active';
                        }, 3000);
                    }
                });
        }
        
        // Start checking payment status every 3 seconds
        if (code) {
            checkInterval = setInterval(checkPaymentStatus, 3000);
            timerInterval = setInterval(updateTimer, 1000);
        }
    </script>
</body>
</html>