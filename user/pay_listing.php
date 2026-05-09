<?php
// user/pay_listing.php - Seller pays to activate listing (Already correct)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Activate Listing - Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_GET['listing_id']) ? intval($_GET['listing_id']) : 0;
$error = '';
$success = '';

// Get listing details
$listing = $conn->query("
    SELECT l.*, 
           l.admin_deposit_percent as deposit_percent, 
           l.admin_commission_percent as commission_percent
    FROM listings l
    WHERE l.id = $listing_id 
    AND l.seller_id = $user_id 
    AND l.approval_status = 'approved'
    AND l.status = 'pending'
")->fetch_assoc();

if (!$listing) {
    header('Location: listings.php');
    exit;
}

// Calculate payment amount
$deposit_amount = $listing['price'] * ($listing['deposit_percent'] / 100);
$commission_amount = $listing['price'] * ($listing['commission_percent'] / 100);
$total_payment = $deposit_amount + $commission_amount;

// Check if already paid
$existing_payment = $conn->query("
    SELECT p.* FROM payments p
    JOIN transactions t ON p.transaction_id = t.id
    WHERE t.listing_id = $listing_id AND p.user_id = $user_id AND p.status = 'confirmed'
");

if ($existing_payment->num_rows > 0) {
    // Already paid - activate listing
    $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
    header("Location: listings.php?msg=activated");
    exit;
}

// Get or create transaction
$transaction = $conn->query("
    SELECT id FROM transactions WHERE listing_id = $listing_id AND seller_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    $stmt = $conn->prepare("
        INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_seller_deposit')
    ");
    $stmt->bind_param("iiiiddd", $listing_id, $user_id, $user_id, $listing['price'], $deposit_amount, $commission_amount, $listing['price']);
    $stmt->execute();
    $transaction_id = $conn->insert_id;
} else {
    $transaction_id = $transaction['id'];
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
        VALUES (?, ?, ?, ?, 'deposit_seller', ?, 'pending')
    ");
    $stmt->bind_param("siids", $payment_code, $transaction_id, $total_payment, $user_id, $expires_at);
    $stmt->execute();
}

// Handle payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $entered_code = isset($_POST['payment_code']) ? htmlspecialchars(trim($_POST['payment_code'])) : '';
    $pin = isset($_POST['pin']) ? htmlspecialchars(trim($_POST['pin'])) : '';
    
    if ($entered_code !== $payment_code) {
        $error = "Invalid payment code";
    } elseif ($pin !== '1234') {
        $error = "Invalid PIN. Use 1234 for testing";
    } else {
        $conn->begin_transaction();
        
        try {
            // Mark payment code as used
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$payment_code'");
            
            // Record payment
            $stmt = $conn->prepare("
                INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) 
                VALUES (?, ?, ?, 'deposit_seller', ?, 'confirmed', NOW())
            ");
            $stmt->bind_param("iids", $transaction_id, $user_id, $total_payment, $payment_code);
            $stmt->execute();
            
            // Update escrow
            $conn->query("UPDATE transactions SET escrow_held = escrow_held + $total_payment WHERE id = $transaction_id");
            
            // CRITICAL: Activate the listing (make it visible in browse)
            $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
            
            $conn->commit();
            
            $success = true;
            header("Refresh: 2; URL=listings.php?activated=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
        }
    }
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
        padding: 40px;
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
        font-size: 32px;
        font-weight: 800;
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
        padding: 32px;
        margin-bottom: 24px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid var(--border);
    }
    
    .card-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .card-title i {
        color: var(--primary);
    }
    
    .item-details {
        background: linear-gradient(135deg, var(--light), white);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        border: 1px solid var(--border);
    }
    
    .item-name {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .property-type {
        display: inline-block;
        padding: 4px 12px;
        background: var(--primary);
        color: white;
        border-radius: 20px;
        font-size: 11px;
        margin-bottom: 20px;
    }
    
    .price-breakdown {
        margin-top: 20px;
    }
    
    .breakdown-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .breakdown-row.total {
        font-weight: 800;
        font-size: 20px;
        color: var(--primary);
        border-top: 2px solid var(--border);
        border-bottom: none;
        margin-top: 8px;
        padding-top: 16px;
    }
    
    .code-box {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 24px;
        padding: 40px;
        text-align: center;
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
    }
    
    .code-box::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 20px 20px;
    }
    
    .code-label {
        font-size: 13px;
        color: rgba(255,255,255,0.8);
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 2px;
    }
    
    .payment-code {
        font-size: 56px;
        font-weight: 800;
        letter-spacing: 16px;
        background: white;
        color: var(--dark);
        padding: 24px;
        border-radius: 20px;
        font-family: 'Courier New', monospace;
        margin: 20px 0;
        position: relative;
        z-index: 1;
    }
    
    .copy-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 10px 28px;
        border-radius: 50px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
        z-index: 1;
    }
    
    .copy-btn:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .expiry {
        margin-top: 16px;
        color: rgba(255,255,255,0.8);
        font-size: 13px;
        position: relative;
        z-index: 1;
    }
    
    .timer {
        font-family: 'Courier New', monospace;
        font-weight: 700;
        font-size: 16px;
    }
    
    .timer.warning {
        color: #fbbf24;
    }
    
    .timer.danger {
        color: #ef4444;
        animation: pulse 1s infinite;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.6; }
    }
    
    .instructions {
        background: var(--light);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .instructions h4 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 16px;
        color: var(--dark);
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
        font-weight: 700;
        font-size: 14px;
    }
    
    .payment-status {
        text-align: center;
        padding: 24px;
        background: var(--light);
        border-radius: 20px;
    }
    
    .spinner {
        display: inline-block;
        width: 40px;
        height: 40px;
        border: 3px solid var(--border);
        border-top-color: var(--primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .checkmark {
        width: 70px;
        height: 70px;
        background: var(--success);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        font-size: 36px;
        color: white;
        animation: scaleIn 0.5s ease;
    }
    
    @keyframes scaleIn {
        from { transform: scale(0); }
        to { transform: scale(1); }
    }
    
    .dashboard-link {
        display: inline-block;
        margin-top: 20px;
        padding: 12px 24px;
        background: var(--primary);
        color: white;
        text-decoration: none;
        border-radius: 50px;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .dashboard-link:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    @media (max-width: 640px) {
        .payment-container {
            margin: 20px auto;
            padding: 0 16px;
        }
        .payment-header {
            padding: 24px;
        }
        .payment-header h1 {
            font-size: 24px;
        }
        .payment-code {
            font-size: 28px;
            letter-spacing: 8px;
            padding: 16px;
        }
        .card {
            padding: 20px;
        }
    }
</style>

<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-rocket"></i> Activate Your Listing</h1>
        <p>Pay deposit + commission to start receiving bookings</p>
    </div>
    
    <div class="card">
        <div class="card-title">
            <i class="fas fa-receipt"></i> Payment Summary
        </div>
        
        <div class="item-details">
            <div class="item-name"><?php echo htmlspecialchars($listing['title']); ?></div>
            <span class="property-type">
                <?php 
                if ($listing['type'] == 'rental') echo '🏠 Rental Property';
                elseif ($listing['type'] == 'product') echo '🚗 Product for Sale';
                else echo '💼 Job Posting';
                ?>
            </span>
            <div class="price-breakdown">
                <div class="breakdown-row">
                    <span><?php echo ($listing['type'] == 'rental') ? 'Monthly Rent' : (($listing['type'] == 'job') ? 'Monthly Salary' : 'Selling Price'); ?></span>
                    <span><?php echo formatMoney($listing['price']); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Deposit (<?php echo $listing['deposit_percent']; ?>%)</span>
                    <span><?php echo formatMoney($deposit_amount); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Platform Commission (<?php echo $listing['commission_percent']; ?>%)</span>
                    <span><?php echo formatMoney($commission_amount); ?></span>
                </div>
                <div class="breakdown-row total">
                    <span>Total to Pay Today</span>
                    <span><?php echo formatMoney($total_payment); ?></span>
                </div>
            </div>
        </div>
        
        <div class="code-box">
            <div class="code-label">
                <i class="fas fa-key"></i> Your Telebirr Payment Code
            </div>
            <div class="payment-code" id="paymentCode"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()">
                <i class="fas fa-copy"></i> Copy Code
            </button>
            <div class="expiry">
                <i class="far fa-clock"></i> Code expires in: 
                <span class="timer" id="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></span>
            </div>
        </div>
        
        <div class="instructions">
            <h4><i class="fas fa-mobile-alt"></i> How to Complete Payment</h4>
            <div class="step">
                <div class="step-number">1</div>
                <div>Open Telebirr app on your mobile phone</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div>Navigate to <strong>Marketplace</strong> or <strong>Pay with Code</strong> section</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div>Enter the payment code: <strong style="color: var(--primary);"><?php echo $payment_code; ?></strong></div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div>Confirm payment with your Telebirr PIN (Demo PIN: <strong>1234</strong>)</div>
            </div>
        </div>
        
        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 16px; font-weight: 500;">Waiting for payment confirmation...</p>
            <p style="font-size: 12px; color: var(--gray); margin-top: 8px;">
                <i class="fas fa-info-circle"></i> This page will update automatically once payment is confirmed
            </p>
        </div>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const listingId = <?php echo $listing_id; ?>;
let checkInterval;
let timerInterval;
let timeLeft = <?php echo max(0, $time_left); ?>;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    showNotification('Payment code copied to clipboard!', 'success');
}

function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 14px 24px;
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        border-radius: 12px;
        z-index: 1000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                <p style="font-weight: 700; font-size: 18px;">Payment Code Expired</p>
                <p style="margin-top: 8px;">Please go back and generate a new code.</p>
                <a href="pay_listing.php?listing_id=${listingId}" class="dashboard-link" style="margin-top: 16px;">
                    Generate New Code
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
                        <p style="font-weight: 700; font-size: 20px; margin-top: 8px;">Payment Confirmed!</p>
                        <p style="margin-top: 8px;">Your listing is now active and visible to customers.</p>
                        <a href="listings.php" class="dashboard-link">
                            <i class="fas fa-box"></i> View My Listings
                        </a>
                    </div>
                `;
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