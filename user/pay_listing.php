<?php
// user/pay_listing.php - COMPLETELY FIXED VERSION

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// CRITICAL: Set PHP timezone FIRST
date_default_timezone_set('Africa/Addis_Ababa');
requireLogin();

$page_title = 'Activate Listing - Payment';
ob_start();

$conn = getDbConnection();

// CRITICAL: Set MySQL timezone to match PHP
$conn->query("SET time_zone = '+03:00'");

$user_id = $_SESSION['user_id'];
$listing_id = isset($_GET['listing_id']) ? intval($_GET['listing_id']) : 0;

$listing = $conn->query("
    SELECT l.*, 
           l.admin_deposit_percent as deposit_percent, 
           l.admin_commission_percent as commission_percent
    FROM listings l
    WHERE l.id = $listing_id AND l.seller_id = $user_id AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$listing) {
    header('Location: listings.php');
    exit;
}

$price = round($listing['price'], 2);
$deposit_amount = round($price * ($listing['deposit_percent'] / 100), 2);
$commission_amount = round($price * ($listing['commission_percent'] / 100), 2);
$total_payment = round($deposit_amount + $commission_amount, 2);

if ($listing['status'] == 'active') {
    header("Location: listings.php?msg=activated");
    exit;
}

$transaction = $conn->query("
    SELECT id FROM transactions WHERE listing_id = $listing_id AND seller_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    $stmt = $conn->prepare("
        INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_seller_deposit', NOW())
    ");
    $stmt->bind_param("iiiiddd", $listing_id, $user_id, $user_id, $price, $deposit_amount, $commission_amount, $price);
    $stmt->execute();
    $transaction_id = $conn->insert_id;
} else {
    $transaction_id = $transaction['id'];
}

// Check for existing valid code
$existing_code = $conn->query("
    SELECT code, expires_at, id as code_id, TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining
    FROM payment_codes 
    WHERE transaction_id = $transaction_id 
    AND user_id = $user_id 
    AND type = 'deposit_seller' 
    AND status = 'pending' 
    AND expires_at > NOW()
    LIMIT 1
");

if ($existing_code->num_rows > 0) {
    $code_data = $existing_code->fetch_assoc();
    $payment_code = $code_data['code'];
    $code_id = $code_data['code_id'];
    $seconds_remaining = intval($code_data['seconds_remaining']);
} else {
    // Generate new code
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
        VALUES (?, ?, ?, ?, 'deposit_seller', DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'pending', NOW())
    ");
    $stmt->bind_param("siid", $payment_code, $transaction_id, $total_payment, $user_id);
    $stmt->execute();
    $code_id = $conn->insert_id;
    $seconds_remaining = 600;
}

// Get expiration timestamp directly from MySQL
$exp_result = $conn->query("
    SELECT UNIX_TIMESTAMP(expires_at) as expires_timestamp 
    FROM payment_codes WHERE id = $code_id
");
$expires_timestamp = $exp_result->fetch_assoc()['expires_timestamp'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate Listing - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .payment-container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .payment-header { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 28px; padding: 40px; margin-bottom: 28px; color: white; text-align: center; }
        .card { background: white; border-radius: 24px; padding: 32px; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
        .card-title { font-size: 20px; font-weight: 700; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #e2e8f0; }
        .payment-code { font-size: 56px; font-weight: 800; letter-spacing: 16px; background: linear-gradient(135deg, #667eea20, #764ba220); padding: 24px; border-radius: 20px; font-family: monospace; margin: 20px 0; cursor: pointer; text-align: center; }
        .copy-btn { background: #667eea; color: white; border: none; padding: 10px 28px; border-radius: 50px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .timer { font-family: monospace; font-weight: 700; font-size: 28px; background: #1e293b; color: white; padding: 8px 20px; border-radius: 50px; display: inline-block; }
        .timer.warning { background: #f59e0b; color: #78350f; }
        .timer.danger { background: #ef4444; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
        .step { display: flex; align-items: center; gap: 14px; padding: 12px 0; }
        .step-number { width: 32px; height: 32px; background: #667eea; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .payment-status { text-align: center; padding: 24px; background: #f8fafc; border-radius: 20px; }
        .spinner { display: inline-block; width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top-color: #667eea; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status-success { color: #10b981; }
        .status-error { color: #ef4444; }
        .breakdown-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
        .breakdown-row.total { font-weight: 800; font-size: 18px; color: #667eea; border-top: 2px solid #e2e8f0; margin-top: 8px; padding-top: 16px; }
        @media (max-width: 640px) { .payment-code { font-size: 28px; letter-spacing: 8px; padding: 16px; } .timer { font-size: 20px; } }
    </style>
</head>
<body>
<div class="payment-container">
    <div class="payment-header">
        <h1>Activate Your Listing</h1>
        <p>Pay deposit + commission to start receiving bookings</p>
    </div>
    
    <div class="card">
        <div class="card-title">Payment Summary</div>
        <div><strong><?php echo htmlspecialchars($listing['title']); ?></strong></div>
        <div class="price-breakdown">
            <div class="breakdown-row"><span>Listing Price</span><span><?php echo number_format($price, 2); ?> ETB</span></div>
            <div class="breakdown-row"><span>Deposit (<?php echo $listing['deposit_percent']; ?>%)</span><span><?php echo number_format($deposit_amount, 2); ?> ETB</span></div>
            <div class="breakdown-row"><span>Commission (<?php echo $listing['commission_percent']; ?>%)</span><span><?php echo number_format($commission_amount, 2); ?> ETB</span></div>
            <div class="breakdown-row total"><span>Total to Pay</span><span><?php echo number_format($total_payment, 2); ?> ETB</span></div>
        </div>
        
        <div style="text-align: center; margin: 24px 0;">
            <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()">📋 Copy Code</button>
            <div style="margin-top: 16px;">
                ⏰ Code expires in: <span class="timer" id="timer">--:--</span>
            </div>
        </div>
        
        <div class="instructions">
            <h4>How to Pay</h4>
            <div class="step"><div class="step-number">1</div><div>Open Telebirr app</div></div>
            <div class="step"><div class="step-number">2</div><div>Go to Marketplace / Pay with Code</div></div>
            <div class="step"><div class="step-number">3</div><div>Enter code: <strong><?php echo $payment_code; ?></strong></div></div>
            <div class="step"><div class="step-number">4</div><div>Confirm payment</div></div>
        </div>
        
        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 16px;">Waiting for payment confirmation...</p>
            <p style="font-size: 12px; color: #64748b;">Page will update automatically when payment is confirmed</p>
        </div>
    </div>
</div>

<script>
// ============================================
// BACKEND-AUTHORITY ONLY - NO LOCAL CALCULATIONS
// ============================================

const paymentCode = '<?php echo $payment_code; ?>';
let pollingActive = true;
let pollInterval = null;
let countdownInterval = null;
let currentSecondsRemaining = <?php echo $seconds_remaining; ?>;

// ============================================
// Update timer display (cosmetic only)
// ============================================
function updateTimerDisplay(seconds) {
    const timerSpan = document.getElementById('timer');
    if (!timerSpan) return;
    
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    timerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    
    timerSpan.classList.remove('warning', 'danger');
    if (seconds < 60) {
        timerSpan.classList.add('danger');
    } else if (seconds < 300) {
        timerSpan.classList.add('warning');
    }
}

// ============================================
// Countdown timer (cosmetic ONLY - never decides expiration)
// ============================================
function startCosmeticCountdown() {
    if (countdownInterval) clearInterval(countdownInterval);
    
    countdownInterval = setInterval(() => {
        if (currentSecondsRemaining > 0 && pollingActive) {
            currentSecondsRemaining--;
            updateTimerDisplay(currentSecondsRemaining);
        }
    }, 1000);
}

// ============================================
// Update UI from backend (SOURCE OF TRUTH)
// ============================================
function updateUIFromBackend(data) {
    const statusDiv = document.getElementById('paymentStatus');
    const timerSpan = document.getElementById('timer');
    
    // Always update timer from backend seconds_remaining
    if (data.seconds_remaining !== undefined && data.seconds_remaining >= 0) {
        currentSecondsRemaining = data.seconds_remaining;
        updateTimerDisplay(currentSecondsRemaining);
    }
    
    // Handle different backend statuses
    if (data.payment_status === 'confirmed_activated' || data.listing_active === true) {
        // Payment confirmed and listing active
        pollingActive = false;
        if (pollInterval) clearInterval(pollInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        
        statusDiv.innerHTML = `
            <div class="status-success">
                <i class="fas fa-check-circle" style="font-size: 48px;"></i>
                <p style="font-weight: 700; font-size: 20px; margin-top: 8px;">Payment Confirmed!</p>
                <p>Your listing is now active.</p>
                <p style="margin-top: 8px;">Redirecting...</p>
            </div>
        `;
        
        setTimeout(() => {
            window.location.href = 'listings.php?activated=1';
        }, 1500);
        
    } else if (data.payment_status === 'confirmed_pending_activation') {
        // Payment confirmed, activating
        statusDiv.innerHTML = `
            <div class="spinner"></div>
            <p style="margin-top: 16px; font-weight: 500;">Payment Confirmed!</p>
            <p>Activating your listing...</p>
        `;
        
    } else if (data.payment_status === 'expired' || data.is_expired === true) {
        // Backend says expired
        pollingActive = false;
        if (pollInterval) clearInterval(pollInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        
        statusDiv.innerHTML = `
            <div class="status-error">
                <i class="fas fa-exclamation-triangle" style="font-size: 32px;"></i>
                <p style="margin-top: 8px; font-weight: 600;">Payment Code Expired</p>
                <p>Please refresh to generate a new code.</p>
                <button onclick="location.reload()" style="margin-top: 16px; padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 40px; cursor: pointer;">Refresh Page</button>
            </div>
        `;
        
    } else if (data.payment_status === 'pending') {
        // Still waiting
        statusDiv.innerHTML = `
            <div class="spinner"></div>
            <p style="margin-top: 16px;">Waiting for payment confirmation...</p>
            <p style="font-size: 12px; color: #64748b;">Code valid for ${currentSecondsRemaining} more seconds</p>
        `;
    }
}

// ============================================
// Poll backend for status (ONLY SOURCE OF TRUTH)
// ============================================
async function pollBackendStatus() {
    if (!pollingActive) return;
    
    try {
        const response = await fetch(`/broker_system/api/payment_status.php?code=${paymentCode}&_=${Date.now()}`, {
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache' }
        });
        const data = await response.json();
        
        if (!data.success) {
            console.error('API error:', data.error);
            return;
        }
        
        updateUIFromBackend(data);
        
    } catch (error) {
        console.error('Polling error:', error);
    }
}

// ============================================
// Start automatic payment detection
// ============================================
function startPaymentDetection() {
    // Initial poll
    pollBackendStatus();
    
    // Poll every 1.5 seconds
    pollInterval = setInterval(pollBackendStatus, 1500);
    
    // Start cosmetic countdown
    startCosmeticCountdown();
}

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    alert('✅ Code copied: ' + paymentCode);
}

// Start the application
startPaymentDetection();
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>