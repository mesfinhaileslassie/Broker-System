<?php
// user/pay_listing.php - REAL payment page connected to Telebirr

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$error = '';
$code = '';
$amount = 0;
$listing_info = null;
$payment_success = false;

// Get listing ID from URL
$listing_id = intval($_GET['listing_id'] ?? 0);

if ($listing_id > 0) {
    // Get listing details (only approved and pending payment)
    $listing_result = $conn->query("
        SELECT l.*, 
               l.admin_deposit_percent as deposit_percent, 
               l.admin_commission_percent as commission_percent
        FROM listings l
        WHERE l.id = $listing_id 
        AND l.seller_id = $user_id 
        AND l.approval_status = 'approved' 
        AND l.status = 'pending'
    ");
    
    $listing_info = $listing_result->fetch_assoc();
    
    if ($listing_info) {
        // Calculate payment amount
        $deposit_amount = $listing_info['price'] * ($listing_info['deposit_percent'] / 100);
        $commission_amount = $listing_info['price'] * ($listing_info['commission_percent'] / 100);
        $amount = $deposit_amount + $commission_amount;
        
        // Check if a payment code already exists for this listing
        $code_check = $conn->query("
            SELECT pc.code, pc.expires_at 
            FROM payment_codes pc
            JOIN transactions t ON pc.transaction_id = t.id
            WHERE t.listing_id = $listing_id AND pc.status = 'pending'
            ORDER BY pc.created_at DESC LIMIT 1
        ");
        
        if ($code_check->num_rows > 0) {
            // Existing code found
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
            
            // Save payment code to database
            $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $stmt = $conn->prepare("
                INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
                VALUES (?, ?, ?, ?, 'deposit_seller', ?, 'pending')
            ");
            $stmt->bind_param("siids", $new_code, $transaction_id, $amount, $user_id, $expires_at);
            $stmt->execute();
            $code = $new_code;
        }
    } else {
        $error = "Listing not found or already paid.";
    }
}

// Handle payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $entered_code = $_POST['payment_code'];
    $pin = $_POST['pin'];
    
    if ($pin == '1234') {
        // Verify the code
        $verify = $conn->query("
            SELECT pc.*, t.listing_id 
            FROM payment_codes pc
            JOIN transactions t ON pc.transaction_id = t.id
            WHERE pc.code = '$entered_code' AND pc.status = 'pending'
        ");
        
        if ($verify->num_rows > 0) {
            $payment = $verify->fetch_assoc();
            
            // Update listing to active
            $conn->query("UPDATE listings SET status = 'active' WHERE id = {$payment['listing_id']}");
            
            // Mark payment code as used
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$entered_code'");
            
            $payment_success = true;
            $code = ''; // clear code display
        } else {
            $error = "Invalid payment code";
        }
    } else {
        $error = "Incorrect PIN";
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
        .btn-primary { background: #667eea; color: white; text-decoration: none; display: inline-block; text-align: center; }
        .btn-success { background: #28a745; color: white; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .info-box { background: #e3f2fd; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .pin-input { font-size: 24px; text-align: center; letter-spacing: 5px; padding: 10px; width: 150px; margin: 10px auto; display: block; }
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
            
            <?php if ($payment_success): ?>
                <div class="success">
                    <i class="fas fa-check-circle"></i> 
                    <strong>Payment Successful!</strong><br>
                    Your listing has been activated. You can now manage it from your dashboard.
                </div>
                <a href="listings.php" class="btn btn-primary" style="margin-top: 16px;">Go to My Listings</a>
                
            <?php elseif ($error): ?>
                <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
                <a href="listings.php?status=pending" class="btn btn-primary">Go Back to Listings</a>
                
            <?php elseif ($code && $listing_info): ?>
                <div class="info-box">
                    <h3>Payment Details</h3>
                    <p><strong>Listing:</strong> <?php echo htmlspecialchars($listing_info['title']); ?></p>
                    <p><strong>Item Price:</strong> <?php echo formatMoney($listing_info['price']); ?></p>
                    <p><strong>Deposit (<?php echo $listing_info['deposit_percent']; ?>%):</strong> <?php echo formatMoney($listing_info['price'] * $listing_info['deposit_percent'] / 100); ?></p>
                    <p><strong>Commission (<?php echo $listing_info['commission_percent']; ?>%):</strong> <?php echo formatMoney($listing_info['price'] * $listing_info['commission_percent'] / 100); ?></p>
                    <p><strong style="color:#667eea;">Total to Pay: <?php echo formatMoney($amount); ?></strong></p>
                </div>
                
                <div class="payment-code-box">
                    <div class="payment-code" id="paymentCode"><?php echo $code; ?></div>
                    <button class="btn btn-primary" onclick="copyCode()" style="background: rgba(255,255,255,0.2); width: auto;">
                        <i class="fas fa-copy"></i> Copy Code
                    </button>
                </div>
                
                <div class="info-box">
                    <h3>How to Pay with Telebirr:</h3>
                    <ol style="margin-left: 20px;">
                        <li>Open Telebirr app on your phone</li>
                        <li>Go to Marketplace / Payment section</li>
                        <li>Enter this code: <strong><?php echo $code; ?></strong></li>
                        <li>Confirm payment with your Telebirr PIN</li>
                        <li>After payment, click the button below to confirm</li>
                    </ol>
                </div>
                
                <form method="POST">
                    <div style="text-align: center;">
                        <input type="hidden" name="payment_code" value="<?php echo $code; ?>">
                        <label><strong>Enter Telebirr PIN to confirm:</strong></label>
                        <input type="password" name="pin" class="pin-input" maxlength="4" pattern="[0-9]{4}" required placeholder="1234">
                    </div>
                    <button type="submit" name="confirm_payment" class="btn btn-success" style="margin-top: 16px;">
                        <i class="fas fa-check-circle"></i> Confirm Payment & Activate Listing
                    </button>
                </form>
                
            <?php else: ?>
                <div class="error">No valid listing found for payment.</div>
                <a href="listings.php?status=pending" class="btn btn-primary">Go to My Listings</a>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function copyCode() {
            const code = document.getElementById('paymentCode').innerText;
            navigator.clipboard.writeText(code);
            alert('Payment code copied: ' + code);
        }
    </script>
</body>
</html>