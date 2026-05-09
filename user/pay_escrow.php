<?php
// user/pay_escrow.php - Complete Payment Page with Escrow (FULL FILE)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

requireLogin();

$page_title = 'Make Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$error = '';

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.price, l.admin_deposit_percent, l.admin_commission_percent,
           u.full_name as seller_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

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

// Calculate payment amount
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;

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
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $time_left = 600;
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
        VALUES (?, ?, ?, ?, 'deposit_buyer', ?, 'pending', NOW())
    ");
    $stmt->bind_param("siids", $payment_code, $transaction_id, $totalPayment, $user_id, $expires_at);
    $stmt->execute();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Escrow Payment - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        .payment-container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        
        .payment-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
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
        .payment-header h1 { position: relative; z-index: 1; font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .payment-header p { position: relative; z-index: 1; opacity: 0.9; }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .item-name { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .breakdown-row.total {
            font-weight: 700;
            font-size: 18px;
            color: #667eea;
            border-top: 2px solid #e2e8f0;
            border-bottom: none;
            margin-top: 8px;
            padding-top: 16px;
        }
        
        .code-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            padding: 40px;
            text-align: center;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
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
        .timer.warning { background: #fbbf24; color: #78350f; }
        .timer.danger { background: #ef4444; color: white; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
        
        .instructions {
            background: #f8fafc;
            border-radius: 20px;
            padding: 24px;
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
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        
        .escrow-info {
            background: #dbeafe;
            padding: 16px;
            border-radius: 16px;
            margin: 20px 0;
        }
        
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
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .status-success { color: #10b981; }
        .status-success i { font-size: 48px; margin-bottom: 16px; display: block; }
        .status-error { color: #ef4444; }
        
        @media (max-width: 640px) {
            .payment-code { font-size: 28px; letter-spacing: 8px; padding: 16px; }
            .timer { font-size: 18px; }
        }
    </style>
</head>
<body>
<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-shield-alt"></i> Secure Escrow Payment</h1>
        <p>Your payment is protected until you confirm satisfaction</p>
    </div>
    
    <div class="card">
        <div class="card-title"><i class="fas fa-receipt"></i> Payment Summary</div>
        <div class="item-name"><?php echo htmlspecialchars($transaction['title']); ?></div>
        <div class="price-breakdown">
            <div class="breakdown-row">
                <span>Total Price</span>
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
                <span>Total to Pay Today</span>
                <span><?php echo formatMoney($totalPayment); ?></span>
            </div>
        </div>
    </div>
    
    <div class="code-box">
        <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
        <button class="copy-btn" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
        <div class="expiry" style="margin-top: 16px; color: rgba(255,255,255,0.8);">
            ⏰ Code expires in: <span class="timer" id="timer">--:--</span>
        </div>
    </div>
    
    <div class="instructions">
        <h4 style="margin-bottom: 16px;"><i class="fas fa-mobile-alt"></i> How to Pay with Telebirr</h4>
        <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your phone</div></div>
        <div class="step"><div class="step-number">2</div><div>Go to Marketplace / Pay with Code</div></div>
        <div class="step"><div class="step-number">3</div><div>Enter code: <strong><?php echo $payment_code; ?></strong></div></div>
        <div class="step"><div class="step-number">4</div><div>Confirm with PIN (Test: <strong>1234</strong>)</div></div>
    </div>
    
    <div class="escrow-info">
        <i class="fas fa-shield-alt"></i> <strong>Escrow Protection</strong><br>
        <small>Your payment is held securely in escrow. It will only be released to the seller after you confirm receipt of the item/service.</small>
    </div>
    
    <div class="payment-status" id="paymentStatus">
        <div class="spinner"></div>
        <p style="margin-top: 16px;">Waiting for payment confirmation...</p>
        <p style="font-size: 12px; color: #64748b; margin-top: 8px;">This page will auto-update once payment is confirmed</p>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const transactionId = <?php echo $transaction_id; ?>;
let pollInterval;
let timerInterval;
let timeLeft = <?php echo max(0, $time_left); ?>;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    alert('✓ Payment code copied!');
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(pollInterval);
        document.getElementById('paymentStatus').innerHTML = `
            <div style="color: #ef4444;">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Payment Code Expired. Please go back and request a new code.</p>
                <a href="transaction.php?id=${transactionId}" style="display: inline-block; margin-top: 16px; padding: 10px 20px; background: #667eea; color: white; border-radius: 40px; text-decoration: none;">Go Back</a>
            </div>
        `;
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    const timerSpan = document.getElementById('timer');
    timerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    if (timeLeft < 60) {
        timerSpan.classList.add('danger');
    } else if (timeLeft < 300) {
        timerSpan.classList.add('warning');
    }
}

function checkPaymentStatus() {
    fetch('/broker_system/api/confirm_escrow_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ payment_code: paymentCode, pin: '1234' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            clearInterval(pollInterval);
            clearInterval(timerInterval);
            document.getElementById('paymentStatus').innerHTML = `
                <div class="status-success">
                    <i class="fas fa-check-circle"></i>
                    <p><strong>Payment Confirmed!</strong></p>
                    <p>Your payment has been secured in escrow.</p>
                    <p style="margin-top: 16px;">Redirecting to transaction page...</p>
                </div>
            `;
            setTimeout(() => {
                window.location.href = `transaction.php?id=${transactionId}`;
            }, 2000);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Start timers
timerInterval = setInterval(updateTimer, 1000);
pollInterval = setInterval(checkPaymentStatus, 3000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>