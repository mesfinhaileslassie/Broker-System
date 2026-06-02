<?php
// user/pay_application.php - Pay service fee for job application (Auto-detection)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Pay Service Fee';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$payment_code = isset($_GET['code']) ? $_GET['code'] : '';
$error = '';

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, u.full_name as company_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

$serviceFee = $transaction['commission_amount'];

// Get payment code
$code_data = $conn->query("
    SELECT * FROM payment_codes 
    WHERE transaction_id = $transaction_id AND code = '$payment_code' AND status = 'pending'
");

if ($code_data && $code_data->num_rows > 0) {
    $code_row = $code_data->fetch_assoc();
    $expires_at = $code_row['expires_at'];
    $time_left = strtotime($expires_at) - time();
} else {
    // Check if already paid
    $paid_check = $conn->query("
        SELECT id FROM payments 
        WHERE transaction_id = $transaction_id AND status = 'confirmed'
    ");
    if ($paid_check && $paid_check->num_rows > 0) {
        header("Location: transaction.php?id=$transaction_id");
        exit;
    }
    $error = "Invalid or expired payment code";
    $time_left = 0;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Service Fee - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #667eea20, #764ba220);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .payment-card {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 32px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            text-align: center;
        }
        
        .icon-circle {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        
        .icon-circle i { font-size: 40px; color: white; }
        
        h2 { font-size: 24px; color: #1e293b; margin-bottom: 8px; }
        .subtitle { color: #64748b; margin-bottom: 24px; font-size: 14px; }
        
        .job-info {
            background: #f8fafc;
            border-radius: 20px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .job-title { font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .company { font-size: 12px; color: #64748b; }
        
        .payment-code-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            padding: 30px;
            margin: 24px 0;
        }
        
        .code-label {
            font-size: 12px;
            color: rgba(255,255,255,0.8);
            margin-bottom: 8px;
        }
        
        .code {
            font-size: 48px;
            font-weight: 800;
            letter-spacing: 12px;
            background: white;
            color: #1e293b;
            padding: 20px;
            border-radius: 16px;
            font-family: monospace;
            margin: 16px 0;
            cursor: pointer;
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
        
        .copy-btn:hover { background: rgba(255,255,255,0.3); transform: scale(1.05); }
        
        .timer-box {
            font-family: monospace;
            font-size: 28px;
            font-weight: 700;
            margin: 16px 0;
        }
        
        .timer-box.warning { color: #f59e0b; }
        .timer-box.danger { color: #ef4444; animation: pulse 1s infinite; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .fee-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin: 20px 0;
        }
        
        .fee-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .fee-amount { font-size: 28px; font-weight: 800; color: #059669; }
        
        .instructions {
            text-align: left;
            background: #dbeafe;
            border-radius: 16px;
            padding: 16px;
            margin: 20px 0;
        }
        
        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
        }
        
        .step-number {
            width: 28px;
            height: 28px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }
        
        .payment-status {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 20px;
            margin-top: 20px;
        }
        
        .spinner {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .checkmark {
            width: 60px;
            height: 60px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        
        .checkmark i { font-size: 32px; color: white; }
        
        @media (max-width: 560px) {
            .payment-card { padding: 24px; margin: 16px; }
            .code { font-size: 28px; letter-spacing: 6px; }
            .timer-box { font-size: 22px; }
        }
    </style>
</head>
<body>

<div class="payment-card">
    <?php if ($error): ?>
        <div class="icon-circle" style="background: #ef4444;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h2>Payment Error</h2>
        <p class="subtitle"><?php echo $error; ?></p>
        <a href="transaction.php?id=<?php echo $transaction_id; ?>" style="color: #667eea; text-decoration: none;">← Go Back</a>
    <?php else: ?>
        <div class="icon-circle">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <h2>Pay Service Fee</h2>
        <p class="subtitle">Complete your job application</p>
        
        <div class="job-info">
            <div class="job-title"><?php echo htmlspecialchars($transaction['title']); ?></div>
            <div class="company"><?php echo htmlspecialchars($transaction['company_name']); ?></div>
        </div>
        
        <div class="payment-code-box">
            <div class="code-label">Your Telebirr Payment Code</div>
            <div class="code" onclick="copyCode()"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
            <div class="timer-box" id="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></div>
        </div>
        
        <div class="fee-box">
            <div class="fee-label">Service Fee (<?php echo $transaction['admin_commission_percent'] ?? 15; ?>%)</div>
            <div class="fee-amount"><?php echo formatMoney($serviceFee); ?></div>
        </div>
        
        <div class="instructions">
            <div style="font-weight: 600; margin-bottom: 12px;"><i class="fas fa-mobile-alt"></i> How to Pay</div>
            <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your phone</div></div>
            <div class="step"><div class="step-number">2</div><div>Go to Marketplace / Pay with Code</div></div>
            <div class="step"><div class="step-number">3</div><div>Enter code: <strong><?php echo $payment_code; ?></strong></div></div>
            <div class="step"><div class="step-number">4</div><div>Confirm payment with your PIN</div></div>
            <div class="step"><div class="step-number">5</div><div><strong>Wait 2-3 seconds</strong> - This page will update automatically</div></div>
        </div>
        
        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 12px; font-weight: 500;">Waiting for payment confirmation...</p>
            <p style="font-size: 12px; color: #64748b; margin-top: 8px;">
                <i class="fas fa-clock"></i> This page will auto-update once payment is confirmed
            </p>
        </div>
    <?php endif; ?>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const transactionId = <?php echo $transaction_id; ?>;
let pollInterval;
let timerInterval;
let timeLeft = <?php echo max(0, $time_left); ?>;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
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
    notification.innerHTML = '<i class="fas fa-check-circle"></i> Code copied: ' + paymentCode;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 2000);
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(pollInterval);
        const timerEl = document.getElementById('timer');
        if (timerEl) {
            timerEl.innerHTML = 'Expired';
            timerEl.classList.add('danger');
        }
        document.getElementById('paymentStatus').innerHTML = `
            <div style="color: #ef4444;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                <p style="font-weight: 700;">Payment Code Expired</p>
                <p>Your payment code has expired. Please go back and start over.</p>
                <a href="transaction.php?id=${transactionId}" style="display: inline-block; margin-top: 16px; padding: 10px 24px; background: #667eea; color: white; border-radius: 40px; text-decoration: none;">Go Back</a>
            </div>
        `;
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    const timerEl = document.getElementById('timer');
    if (timerEl) {
        timerEl.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        if (timeLeft < 60) {
            timerEl.classList.add('danger');
        } else if (timeLeft < 300) {
            timerEl.classList.add('warning');
        }
    }
}

function showPaymentSuccess() {
    clearInterval(pollInterval);
    clearInterval(timerInterval);
    document.getElementById('paymentStatus').innerHTML = `
        <div class="checkmark">
            <i class="fas fa-check-circle"></i>
        </div>
        <p style="font-weight: 700; font-size: 20px; margin-top: 16px;">Payment Confirmed!</p>
        <p>Your application has been submitted successfully.</p>
        <p style="margin-top: 8px;">Redirecting to your application...</p>
    `;
    setTimeout(() => {
        window.location.href = 'transaction.php?id=' + transactionId;
    }, 2000);
}

function checkPaymentStatus() {
    fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode, { 
        credentials: 'same-origin',
        cache: 'no-store'
    })
    .then(response => response.json())
    .then(data => {
        if (data.is_paid === true || data.confirmed === true) {
            showPaymentSuccess();
        }
    })
    .catch(error => console.error('Polling error:', error));
}

// Start polling and timer
pollInterval = setInterval(checkPaymentStatus, 2000);
timerInterval = setInterval(updateTimer, 1000);

// CSS for notification animation
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);
</script>

</body>
</html>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>