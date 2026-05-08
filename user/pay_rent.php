<?php
// user/pay_rent.php - Buyer pays deposit for rental/service

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Complete Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$error = '';
$success = '';

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.price, l.admin_deposit_percent, l.admin_commission_percent,
           u.full_name as seller_name, u.id as seller_id
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

// Calculate payment amount (buyer pays deposit + commission for rentals)
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;

// Check if already paid
$existing_payment = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'confirmed'
");

$already_paid = $existing_payment->num_rows > 0;

if ($already_paid) {
    header("Location: transaction.php?id=$transaction_id");
    exit;
}

// Get or generate payment code
$payment_code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'pending'
")->fetch_assoc();

if ($payment_code_data) {
    $payment_code = $payment_code_data['code'];
    $expires_at = $payment_code_data['expires_at'];
    $time_left = strtotime($expires_at) - time();
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $time_left = 1800;
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
        VALUES (?, ?, ?, ?, 'deposit_buyer', ?, 'pending')
    ");
    $stmt->bind_param("siids", $payment_code, $transaction_id, $totalPayment, $user_id, $expires_at);
    $stmt->execute();
}

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .payment-container {
        max-width: 800px;
        margin: 0 auto;
    }
    
    .payment-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .payment-header::before {
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
    
    .payment-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .payment-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    .card {
        background: white;
        border-radius: 24px;
        padding: 28px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .card-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .item-details {
        background: var(--light);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .item-name {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .item-type {
        display: inline-block;
        padding: 4px 12px;
        background: var(--primary);
        color: white;
        border-radius: 20px;
        font-size: 11px;
        margin-bottom: 16px;
    }
    
    .price-breakdown {
        margin-top: 16px;
    }
    
    .breakdown-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .breakdown-row.total {
        font-weight: 700;
        font-size: 18px;
        color: var(--primary);
        border-top: 2px solid var(--border);
        border-bottom: none;
        margin-top: 8px;
        padding-top: 16px;
    }
    
    .code-box {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 20px;
        padding: 30px;
        text-align: center;
        margin-bottom: 24px;
    }
    
    .code-label {
        font-size: 12px;
        color: rgba(255,255,255,0.8);
        margin-bottom: 8px;
    }
    
    .payment-code {
        font-size: 48px;
        font-weight: 800;
        letter-spacing: 12px;
        background: white;
        color: var(--dark);
        padding: 20px;
        border-radius: 16px;
        font-family: monospace;
        margin: 16px 0;
    }
    
    .copy-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 8px 24px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .copy-btn:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .expiry {
        margin-top: 12px;
        color: rgba(255,255,255,0.7);
        font-size: 13px;
    }
    
    .timer {
        font-family: monospace;
        font-weight: 700;
    }
    
    .timer.warning {
        color: #fbbf24;
    }
    
    .timer.danger {
        color: #ef4444;
    }
    
    .instructions {
        background: var(--light);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 24px;
    }
    
    .step {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 0;
    }
    
    .step-number {
        width: 32px;
        height: 32px;
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 14px;
    }
    
    .payment-status {
        text-align: center;
        padding: 20px;
        background: var(--light);
        border-radius: 20px;
    }
    
    .spinner {
        display: inline-block;
        width: 30px;
        height: 30px;
        border: 3px solid var(--border);
        border-top-color: var(--primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .btn {
        width: 100%;
        padding: 14px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        text-align: center;
        display: inline-block;
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
    }
    
    .checkmark {
        width: 60px;
        height: 60px;
        background: var(--success);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        font-size: 32px;
        color: white;
    }
    
    @media (max-width: 640px) {
        .payment-code {
            font-size: 28px;
            letter-spacing: 6px;
        }
        .card {
            padding: 20px;
        }
    }
</style>

<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-credit-card"></i> Complete Payment</h1>
        <p>Pay deposit + service fee to confirm your booking</p>
    </div>
    
    <div class="card">
        <div class="card-title">
            <i class="fas fa-receipt"></i> Payment Summary
        </div>
        
        <div class="item-details">
            <div class="item-name"><?php echo htmlspecialchars($transaction['title']); ?></div>
            <span class="item-type">
                <?php 
                if ($transaction['type'] == 'rental') echo '🏠 Rental Property';
                elseif ($transaction['type'] == 'product') echo '🚗 Product';
                else echo '💼 Service';
                ?>
            </span>
            <div class="price-breakdown">
                <div class="breakdown-row">
                    <span><?php echo ($transaction['type'] == 'rental') ? 'Monthly Rent' : 'Total Price'; ?></span>
                    <span><?php echo formatMoney($transaction['total_amount']); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <div class="breakdown-row total">
                    <span>Total to Pay</span>
                    <span><?php echo formatMoney($totalPayment); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Remaining Balance (pay to seller)</span>
                    <span><?php echo formatMoney($transaction['total_amount'] - $depositAmount); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Payment Code Box -->
        <div class="code-box">
            <div class="code-label">Your Telebirr Payment Code</div>
            <div class="payment-code" id="paymentCode"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()">
                <i class="fas fa-copy"></i> Copy Code
            </button>
            <div class="expiry">
                <i class="far fa-clock"></i> Code expires in: 
                <span class="timer" id="timer"><?php echo gmdate("i:s", $time_left); ?></span>
            </div>
        </div>
        
        <!-- Instructions -->
        <div class="instructions">
            <h4 style="margin-bottom: 12px;"><i class="fas fa-mobile-alt"></i> How to Pay with Telebirr</h4>
            <div class="step">
                <div class="step-number">1</div>
                <div>Open Telebirr app on your phone</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div>Go to Marketplace / Payment section</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div>Enter this code: <strong><?php echo $payment_code; ?></strong></div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div>Confirm payment with your Telebirr PIN (Test PIN: <strong>1234</strong>)</div>
            </div>
        </div>
        
        <!-- Payment Status -->
        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 12px;">Waiting for payment confirmation...</p>
            <p style="font-size: 12px; color: var(--gray); margin-top: 8px;">This page will auto-refresh once payment is confirmed</p>
        </div>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const transactionId = <?php echo $transaction_id; ?>;
let checkInterval;
let timerInterval;
let timeLeft = <?php echo $time_left; ?>;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    showNotification('Payment code copied!', 'success');
}

function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        border-radius: 12px;
        z-index: 1000;
        animation: slideIn 0.3s ease;
    `;
    notification.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 3000);
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(checkInterval);
        document.getElementById('paymentStatus').innerHTML = `
            <div style="color: var(--danger);">
                <i class="fas fa-exclamation-triangle" style="font-size: 32px;"></i>
                <p style="margin-top: 8px; font-weight: 600;">Payment Code Expired</p>
                <p>Please go back and request a new code.</p>
                <a href="transaction.php?id=${transactionId}" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                    Go Back
                </a>
            </div>
        `;
        return;
    }
    
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    const timerSpan = document.getElementById('timer');
    timerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    if (timeLeft < 300) {
        timerSpan.classList.add('warning');
    }
    if (timeLeft < 60) {
        timerSpan.classList.add('danger');
    }
}

function checkPaymentStatus() {
    fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode)
        .then(response => response.json())
        .then(data => {
            if (data.confirmed) {
                clearInterval(checkInterval);
                clearInterval(timerInterval);
                document.getElementById('paymentStatus').innerHTML = `
                    <div style="color: var(--success);">
                        <div class="checkmark">
                            <i class="fas fa-check"></i>
                        </div>
                        <p style="margin-top: 8px; font-weight: 600; font-size: 18px;">Payment Confirmed!</p>
                        <p>Your payment has been received successfully.</p>
                        <p style="margin-top: 8px;">Redirecting to transaction page...</p>
                    </div>
                `;
                setTimeout(() => {
                    window.location.href = 'transaction.php?id=' + transactionId;
                }, 3000);
            }
        })
        .catch(error => {
            console.error('Error checking payment:', error);
        });
}

// Start checking payment status
checkInterval = setInterval(checkPaymentStatus, 3000);
timerInterval = setInterval(updateTimer, 1000);

// Add CSS animation
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>