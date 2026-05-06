<?php
// user/pay_application.php - Process Payment for Application

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Complete Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = sanitizeInt($_GET['transaction_id'] ?? 0);
$payment_code = sanitizeString($_GET['code'] ?? '');
$error = '';
$success = '';
$payment_status = 'pending';

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as job_title, l.seller_id as company_id, u.full_name as company_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON l.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

// Get payment code
$payment = $conn->query("
    SELECT * FROM payment_codes 
    WHERE transaction_id = $transaction_id AND code = '$payment_code' AND status = 'pending'
")->fetch_assoc();

if (!$payment) {
    // Check if already paid
    $paid_check = $conn->query("
        SELECT * FROM payments 
        WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'confirmed'
    ");
    if ($paid_check->num_rows > 0) {
        $payment_status = 'completed';
        $success = "Payment already completed! Redirecting to transaction...";
        header("Refresh: 2; URL=transaction.php?id=$transaction_id");
    } else {
        $error = "Invalid or expired payment code. Please go back and try again.";
    }
}

// Check if expired
if ($payment && strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = {$payment['id']}");
    $error = "Payment code has expired. Please go back and submit a new application.";
}

// Handle payment confirmation (simulated Telebirr)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $entered_code = sanitizeString($_POST['payment_code']);
    $pin = sanitizeString($_POST['pin']);
    
    if ($entered_code !== $payment_code) {
        $error = "Invalid payment code";
    } elseif ($pin !== '1234') {
        $error = "Invalid PIN. Please use 1234 for testing.";
    } else {
        $conn->begin_transaction();
        
        try {
            // Mark payment code as used
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$payment['id']}");
            
            // Record payment
            $stmt = $conn->prepare("
                INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) 
                VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW())
            ");
            $stmt->bind_param("iids", $transaction_id, $user_id, $payment['amount'], $payment_code);
            $stmt->execute();
            
            // Update escrow in transaction
            $conn->query("
                UPDATE transactions 
                SET escrow_held = escrow_held + {$payment['amount']}, 
                    status = 'awaiting_seller_deposit' 
                WHERE id = $transaction_id
            ");
            
            // Create notification for company
            $conn->query("
                INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$transaction['company_id']}, 'Application Payment Received', 
                'A candidate has paid the deposit for {$transaction['job_title']}', NOW())
            ");
            
            $conn->commit();
            
            $success = "Payment successful! Your application has been submitted.";
            $payment_status = 'completed';
            header("Refresh: 3; URL=transaction.php?id=$transaction_id");
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
        }
    }
}

$conn->close();
?>

<style>
    .payment-container { max-width: 600px; margin: 0 auto; }
    .payment-header { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 24px; padding: 28px; color: white; text-align: center; margin-bottom: 28px; }
    .payment-header h1 { font-size: 24px; margin-bottom: 8px; }
    
    .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .code-display { background: #f8fafc; border-radius: 20px; padding: 24px; text-align: center; margin-bottom: 24px; }
    .code-label { font-size: 12px; color: #64748b; margin-bottom: 8px; }
    .payment-code { font-size: 48px; font-weight: 800; letter-spacing: 8px; font-family: monospace; color: #667eea; }
    .expiry { font-size: 12px; color: #64748b; margin-top: 8px; }
    
    .form-group { margin-bottom: 20px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; }
    input { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; }
    input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    
    .btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    
    .instructions { background: #f8fafc; border-radius: 16px; padding: 20px; margin-top: 20px; }
    .step { display: flex; align-items: center; gap: 12px; padding: 10px 0; }
    .step-number { width: 28px; height: 28px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
    
    @media (max-width: 640px) {
        .payment-code { font-size: 32px; letter-spacing: 4px; }
        .card { padding: 20px; }
    }
</style>

<div class="payment-container">
    <?php if ($payment_status === 'completed'): ?>
        <div class="payment-header">
            <h1><i class="fas fa-check-circle"></i> Payment Complete</h1>
            <p>Your application has been submitted</p>
        </div>
        <div class="card" style="text-align: center;">
            <div class="alert alert-success"><?php echo $success; ?></div>
            <a href="transaction.php?id=<?php echo $transaction_id; ?>" class="btn">View Application Status →</a>
        </div>
    <?php elseif ($error): ?>
        <div class="payment-header">
            <h1><i class="fas fa-exclamation-triangle"></i> Payment Error</h1>
        </div>
        <div class="card">
            <div class="alert alert-error"><?php echo $error; ?></div>
            <a href="apply_job.php?id=<?php echo $transaction['listing_id']; ?>" class="btn">Go Back & Try Again</a>
        </div>
    <?php elseif ($payment): ?>
        <div class="payment-header">
            <h1><i class="fas fa-credit-card"></i> Complete Payment</h1>
            <p>Pay deposit + service fee to submit your application</p>
        </div>
        
        <div class="card">
            <div class="code-display">
                <div class="code-label">Your Telebirr Payment Code</div>
                <div class="payment-code"><?php echo $payment_code; ?></div>
                <div class="expiry">Expires: <?php echo date('H:i:s', strtotime($payment['expires_at'])); ?></div>
            </div>
            
            <div class="instructions">
                <h3 style="font-size: 14px; margin-bottom: 12px;"><i class="fas fa-mobile-alt"></i> How to Pay</h3>
                <div class="step"><div class="step-number">1</div><span>Open Telebirr app on your phone</span></div>
                <div class="step"><div class="step-number">2</div><span>Go to Marketplace / Payment section</span></div>
                <div class="step"><div class="step-number">3</div><span>Enter code: <strong><?php echo $payment_code; ?></strong></span></div>
                <div class="step"><div class="step-number">4</div><span>Enter your Telebirr PIN to confirm</span></div>
            </div>
            
            <form method="POST" style="margin-top: 24px;">
                <div class="form-group">
                    <label>Enter Payment Code to Confirm</label>
                    <input type="text" name="payment_code" placeholder="Enter the 5-digit code" required pattern="[0-9]{5}" maxlength="5">
                </div>
                <div class="form-group">
                    <label>Telebirr PIN (Test: 1234)</label>
                    <input type="password" name="pin" placeholder="Enter your Telebirr PIN" required maxlength="4">
                </div>
                <button type="submit" name="confirm_payment" class="btn">Confirm Payment</button>
            </form>
            
            <div class="info-text" style="margin-top: 16px; text-align: center;">
                <i class="fas fa-lock"></i> Secure payment processing by Telebirr
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Auto-refresh every 5 seconds to check payment status
    let checkInterval;
    
    function checkPaymentStatus() {
        fetch('api/check_payment_status.php?code=<?php echo $payment_code; ?>')
            .then(response => response.json())
            .then(data => {
                if (data.confirmed) {
                    clearInterval(checkInterval);
                    location.reload();
                }
            });
    }
    
    <?php if ($payment && $payment_status !== 'completed'): ?>
    checkInterval = setInterval(checkPaymentStatus, 5000);
    <?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>