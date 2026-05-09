<?php
// user/pay_listing.php - COMPLETE PRODUCTION READY VERSION

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// CRITICAL: Set PHP timezone to Ethiopia (UTC+3)
date_default_timezone_set('Africa/Addis_Ababa');
requireLogin();

$page_title = 'Activate Listing - Payment';
ob_start();

$conn = getDbConnection();

// CRITICAL: Set MySQL timezone to match PHP
$conn->query("SET time_zone = '+03:00'");

$user_id = $_SESSION['user_id'];
$listing_id = isset($_GET['listing_id']) ? intval($_GET['listing_id']) : 0;

// Get listing details
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

// Calculate amounts with proper rounding
$price = round($listing['price'], 2);
$deposit_amount = round($price * ($listing['deposit_percent'] / 100), 2);
$commission_amount = round($price * ($listing['commission_percent'] / 100), 2);
$total_payment = round($deposit_amount + $commission_amount, 2);

// Check if already active
if ($listing['status'] == 'active') {
    header("Location: listings.php?msg=activated");
    exit;
}

// Get or create transaction
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

// Check for existing valid payment code
$existing_code = $conn->query("
    SELECT code, expires_at, id as code_id, 
           TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining
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
    $seconds_remaining = max(0, intval($code_data['seconds_remaining']));
} else {
    // Generate new unique payment code
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

// Get expiration data from MySQL
$exp_result = $conn->query("
    SELECT 
        UNIX_TIMESTAMP(expires_at) as expires_timestamp,
        TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining
    FROM payment_codes WHERE id = $code_id
");
$exp_data = $exp_result->fetch_assoc();
$expires_timestamp = $exp_data['expires_timestamp'];
$final_seconds = max(0, intval($exp_data['seconds_remaining']));

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Activate Listing - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
        }
        
        .payment-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        /* Header */
        .payment-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
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
        
        /* Card */
        .card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .card-title i {
            color: #667eea;
        }
        
        /* Payment Summary */
        .item-details {
            background: #f8fafc;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .item-name {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .price-breakdown {
            margin-top: 20px;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .breakdown-row.total {
            font-weight: 800;
            font-size: 18px;
            color: #667eea;
            border-top: 2px solid #e2e8f0;
            border-bottom: none;
            margin-top: 8px;
            padding-top: 16px;
        }
        
        /* Code Box */
        .code-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
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
        
        .payment-code {
            font-size: 56px;
            font-weight: 800;
            letter-spacing: 16px;
            background: white;
            color: #1e293b;
            padding: 24px;
            border-radius: 20px;
            font-family: 'Courier New', monospace;
            margin: 20px 0;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
        }
        
        .payment-code:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
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
        
        /* Timer */
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
            font-size: 24px;
            background: rgba(0,0,0,0.3);
            padding: 6px 16px;
            border-radius: 40px;
            display: inline-block;
        }
        
        .timer.warning {
            background: #fbbf24;
            color: #78350f;
        }
        
        .timer.danger {
            background: #ef4444;
            color: white;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        /* Instructions */
        .instructions {
            background: #f8fafc;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .instructions h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1e293b;
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
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }
        
        /* Payment Status */
        .payment-status {
            text-align: center;
            padding: 24px;
            background: #f8fafc;
            border-radius: 20px;
        }
        
        .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .status-success {
            color: #10b981;
        }
        
        .status-success i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        
        .status-error {
            color: #ef4444;
        }
        
        .status-error i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        
        /* Loading State */
        .timer.loading {
            background: #64748b;
            font-size: 18px;
        }
        
        /* Responsive */
        @media (max-width: 640px) {
            .payment-container {
                margin: 20px auto;
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
            .timer {
                font-size: 18px;
            }
            .breakdown-row {
                font-size: 12px;
            }
            .breakdown-row.total {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
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
            <div class="price-breakdown">
                <div class="breakdown-row">
                    <span>Listing Price</span>
                    <span><?php echo number_format($price, 2); ?> ETB</span>
                </div>
                <div class="breakdown-row">
                    <span>Deposit (<?php echo $listing['deposit_percent']; ?>%)</span>
                    <span><?php echo number_format($deposit_amount, 2); ?> ETB</span>
                </div>
                <div class="breakdown-row">
                    <span>Commission (<?php echo $listing['commission_percent']; ?>%)</span>
                    <span><?php echo number_format($commission_amount, 2); ?> ETB</span>
                </div>
                <div class="breakdown-row total">
                    <span>Total to Pay Today</span>
                    <span><?php echo number_format($total_payment, 2); ?> ETB</span>
                </div>
            </div>
        </div>
        
        <div class="code-box">
            <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()">📋 Copy Code</button>
            <div class="expiry">
                ⏰ Code expires in: <span class="timer" id="timer">--:--</span>
            </div>
        </div>
        
        <div class="instructions">
            <h4><i class="fas fa-mobile-alt"></i> How to Pay with Telebirr</h4>
            <div class="step">
                <div class="step-number">1</div>
                <div>Open Telebirr app on your phone</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div>Go to Marketplace / Pay with Code</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div>Enter this code: <strong style="font-size: 18px; color: #667eea;"><?php echo $payment_code; ?></strong></div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div>Confirm payment with your Telebirr PIN</div>
            </div>
        </div>
        
        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 16px; font-weight: 500;">Loading payment information...</p>
        </div>
    </div>
</div>

<script>
// ============================================
// BACKEND-AUTHORITY ONLY - NO LOCAL CALCULATIONS
// ============================================

const paymentCode = '<?php echo $payment_code; ?>';
const listingId = <?php echo $listing_id; ?>;
let pollingActive = true;
let pollInterval = null;
let countdownInterval = null;
let currentSecondsRemaining = <?php echo $final_seconds; ?>;
let timerInitialized = false;
let firstPollCompleted = false;

// ============================================
// Update timer display (cosmetic only)
// ============================================
function updateTimerDisplay(seconds) {
    const timerSpan = document.getElementById('timer');
    if (!timerSpan) return;
    
    // Format as MM:SS
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    timerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    timerSpan.classList.remove('loading', 'warning', 'danger');
    
    // Update styles based on remaining time
    if (seconds < 60) {
        timerSpan.classList.add('danger');
    } else if (seconds < 300) {
        timerSpan.classList.add('warning');
    }
}

// ============================================
// Start countdown timer (only after receiving initial value)
// ============================================
function startCountdown(initialSeconds) {
    if (countdownInterval) clearInterval(countdownInterval);
    
    currentSecondsRemaining = initialSeconds;
    updateTimerDisplay(currentSecondsRemaining);
    timerInitialized = true;
    
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
    
    // Initialize timer with backend seconds_remaining (FIXES 00:00 issue)
    if (data.seconds_remaining !== undefined && data.seconds_remaining >= 0) {
        if (!timerInitialized) {
            startCountdown(data.seconds_remaining);
        } else if (Math.abs(currentSecondsRemaining - data.seconds_remaining) > 3) {
            // Sync timer with backend if significant drift occurs
            currentSecondsRemaining = data.seconds_remaining;
            updateTimerDisplay(currentSecondsRemaining);
        }
    }
    
    // Handle different backend statuses
    if (data.payment_status === 'confirmed_activated' || data.listing_active === true) {
        // Payment confirmed and listing active
        pollingActive = false;
        if (pollInterval) clearInterval(pollInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        
        statusDiv.innerHTML = `
            <div class="status-success">
                <i class="fas fa-check-circle"></i>
                <p style="font-weight: 700; font-size: 20px; margin-top: 8px;">Payment Confirmed!</p>
                <p>Your listing is now active and visible to customers.</p>
                <p style="margin-top: 8px;">Redirecting to your listings...</p>
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
            <p style="font-size: 12px; color: #64748b; margin-top: 8px;">Please wait...</p>
        `;
        
    } else if (data.payment_status === 'expired' || data.is_expired === true) {
        // Backend says expired
        pollingActive = false;
        if (pollInterval) clearInterval(pollInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        
        statusDiv.innerHTML = `
            <div class="status-error">
                <i class="fas fa-exclamation-triangle"></i>
                <p style="margin-top: 8px; font-weight: 600;">Payment Code Expired</p>
                <p>Please refresh the page to generate a new code.</p>
                <button onclick="location.reload()" style="margin-top: 16px; padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 40px; cursor: pointer;">Refresh Page</button>
            </div>
        `;
        
    } else if (data.payment_status === 'pending') {
        // Still waiting for payment
        if (firstPollCompleted) {
            const secondsDisplay = currentSecondsRemaining;
            const minutes = Math.floor(secondsDisplay / 60);
            const secs = secondsDisplay % 60;
            statusDiv.innerHTML = `
                <div class="spinner"></div>
                <p style="margin-top: 16px; font-weight: 500;">Waiting for payment confirmation...</p>
                <p style="font-size: 12px; color: #64748b; margin-top: 8px;">
                    <i class="fas fa-clock"></i> Code valid for ${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')} more seconds
                </p>
                <p style="font-size: 11px; color: #64748b; margin-top: 12px;">
                    After paying in Telebirr, this page will update automatically within 2-3 seconds.
                </p>
            `;
        }
        firstPollCompleted = true;
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
// Show loading state initially (NO 00:00 FLASH)
// ============================================
function showLoadingState() {
    const timerSpan = document.getElementById('timer');
    if (timerSpan) {
        timerSpan.textContent = 'Loading...';
        timerSpan.classList.add('loading');
    }
}

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    // Show temporary notification
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #10b981;
        color: white;
        padding: 12px 20px;
        border-radius: 12px;
        z-index: 1000;
        animation: slideIn 0.3s ease;
    `;
    notification.innerHTML = '<i class="fas fa-check-circle"></i> ✅ Code copied: ' + paymentCode;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 2000);
}

// ============================================
// Start automatic payment detection
// ============================================
function startPaymentDetection() {
    // Show loading state first (avoids 00:00 flash)
    showLoadingState();
    
    // Initial poll (this will set the correct timer)
    pollBackendStatus();
    
    // Poll every 1.5 seconds for fast detection
    pollInterval = setInterval(pollBackendStatus, 1500);
}

// Add CSS animation for notification
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);

// Start the application
startPaymentDetection();
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>