<?php
// user/pay_listing.php - Complete payment page with Telebirr code generation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get payment details from URL
$code = $_GET['code'] ?? '';
$amount = floatval($_GET['amount'] ?? 0);
$listing_id = intval($_GET['listing_id'] ?? 0);

// Verify the payment session
if (empty($code) || $amount <= 0 || $listing_id <= 0) {
    header('Location: listings.php');
    exit;
}

// Get listing details
$listing = $conn->query("
    SELECT l.*, 
           l.admin_deposit_percent as deposit_percent, 
           l.admin_commission_percent as commission_percent
    FROM listings l
    WHERE l.id = $listing_id AND l.seller_id = $user_id AND l.approval_status = 'approved' AND l.status = 'pending'
")->fetch_assoc();

if (!$listing) {
    header('Location: listings.php');
    exit;
}

// Calculate amounts
$deposit_amount = $listing['price'] * ($listing['deposit_percent'] / 100);
$commission_amount = $listing['price'] * ($listing['commission_percent'] / 100);
$total_amount = $deposit_amount + $commission_amount;

// Verify amount matches
if ($amount != $total_amount) {
    header('Location: listings.php');
    exit;
}

// Handle payment confirmation via AJAX or form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $entered_code = $_POST['payment_code'];
    
    if ($entered_code == $code) {
        // Simulate Telebirr payment confirmation
        // In production, you would verify with Telebirr API
        
        // Update listing status to active
        $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
        
        // Record transaction (for seller deposit)
        $user_balance = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
        
        // Success message
        $message = "Payment successful! Your listing is now active.";
        header("Refresh: 3; URL=listings.php");
    } else {
        $error = "Invalid payment code. Please check and try again.";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 600px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 600px; margin: 40px auto; padding: 0 24px; }
        .card { background: white; border-radius: 16px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .payment-code-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; text-align: center; margin: 20px 0; }
        .payment-code { font-size: 48px; font-weight: 700; letter-spacing: 8px; background: white; color: #333; padding: 20px; border-radius: 12px; margin: 16px 0; font-family: monospace; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #28a745; color: white; }
        .countdown { text-align: center; margin-top: 16px; color: #666; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .info-box { background: #e3f2fd; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .amount { font-size: 24px; font-weight: 700; color: #667eea; }
        .telebirr-instruction { background: #fff3cd; padding: 16px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .verify-section { margin-top: 24px; padding-top: 24px; border-top: 1px solid #eee; }
        input { width: 100%; padding: 14px; font-size: 18px; text-align: center; letter-spacing: 4px; border: 2px solid #ddd; border-radius: 8px; margin-bottom: 16px; font-family: monospace; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #667eea; border-radius: 50%; animation: spin 1s linear infinite; margin-right: 8px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Brokerplace</a>
            <a href="listings.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h2 style="margin-bottom: 16px;"><i class="fas fa-credit-card"></i> Complete Payment</h2>
            <p>Pay to activate your listing: <strong><?php echo htmlspecialchars($listing['title']); ?></strong></p>
            
            <div class="info-box">
                <h3>Payment Breakdown</h3>
                <table style="width: 100%; margin-top: 12px;">
                    <tr><td>Item Price:</td><td style="text-align: right;"><?php echo formatMoney($listing['price']); ?></td></tr>
                    <tr><td>Deposit (<?php echo $listing['deposit_percent']; ?>%):</td><td style="text-align: right;"><?php echo formatMoney($deposit_amount); ?></td></tr>
                    <tr><td>Commission (<?php echo $listing['commission_percent']; ?>%):</td><td style="text-align: right;"><?php echo formatMoney($commission_amount); ?></td></tr>
                    <tr style="border-top: 2px solid #ddd; font-weight: 700;"><td>Total to Pay:</td><td style="text-align: right;"><?php echo formatMoney($total_amount); ?></td></tr>
                </table>
            </div>
            
            <?php if ($message): ?>
                <div class="success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
            <?php endif; ?>
            
            <!-- Telebirr Payment Code Section -->
            <div class="telebirr-instruction">
                <i class="fas fa-mobile-alt" style="font-size: 32px;"></i>
                <h3 style="margin: 12px 0;">Pay with Telebirr</h3>
                <p>Open your Telebirr app and enter this 5-digit code:</p>
            </div>
            
            <div class="payment-code-box">
                <div class="payment-code" id="paymentCode"><?php echo $code; ?></div>
                <button class="btn btn-primary" onclick="copyCode()" style="background: rgba(255,255,255,0.2); margin-top: 12px;">
                    <i class="fas fa-copy"></i> Copy Code
                </button>
                <div class="countdown" id="countdownTimer">
                    <i class="fas fa-hourglass-half"></i> Code expires in: <span id="countdown">10:00</span>
                </div>
            </div>
            
            <!-- Verification Section -->
            <div class="verify-section">
                <h4>After paying in Telebirr, confirm here:</h4>
                <form method="POST" id="paymentForm">
                    <input type="text" name="payment_code" id="verificationCode" placeholder="Enter 5-digit code" maxlength="5" pattern="\d{5}" required>
                    <button type="submit" name="confirm_payment" class="btn btn-success" id="confirmBtn">
                        <i class="fas fa-check"></i> Confirm Payment
                    </button>
                </form>
                <p style="font-size: 12px; color: #888; margin-top: 12px;">
                    <i class="fas fa-info-circle"></i> After paying in Telebirr, enter the same 5-digit code above to activate your listing.
                </p>
            </div>
        </div>
    </div>
    
    <script>
        let countdownInterval;
        let remainingSeconds = 600; // 10 minutes
        let currentCode = '<?php echo $code; ?>';
        
        function startCountdown() {
            const countdownEl = document.getElementById('countdown');
            
            countdownInterval = setInterval(() => {
                if (remainingSeconds <= 0) {
                    clearInterval(countdownInterval);
                    countdownEl.innerHTML = 'Expired';
                    document.getElementById('paymentCode').style.opacity = '0.5';
                    document.getElementById('confirmBtn').disabled = true;
                    alert('Payment code expired. Please go back and generate a new code.');
                    return;
                }
                
                const minutes = Math.floor(remainingSeconds / 60);
                const secs = remainingSeconds % 60;
                countdownEl.innerHTML = `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                remainingSeconds--;
            }, 1000);
        }
        
        function copyCode() {
            navigator.clipboard.writeText(currentCode);
            alert('Payment code copied: ' + currentCode);
        }
        
        // Start countdown
        startCountdown();
        
        // Auto-focus on verification code field
        document.getElementById('verificationCode').focus();
    </script>
</body>
</html>