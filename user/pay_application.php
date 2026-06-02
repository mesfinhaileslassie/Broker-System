<?php
// user/pay_application.php - Pay service fee for job application

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
")->fetch_assoc();

if (!$code_data) {
    $error = "Invalid or expired payment code";
}

$time_left = $code_data ? strtotime($code_data['expires_at']) - time() : 0;

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
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #10b981;
            --danger: #ef4444;
        }
        
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
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        
        .success-icon i {
            font-size: 40px;
            color: white;
        }
        
        .payment-code {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 20px;
            padding: 30px;
            margin: 24px 0;
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
        
        .timer {
            font-size: 24px;
            font-weight: 700;
            font-family: monospace;
            margin: 16px 0;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
        }
        
        .btn-success {
            background: var(--success);
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="payment-card">
    <div class="success-icon">
        <i class="fas fa-file-alt"></i>
    </div>
    
    <h2 style="font-size: 24px; margin-bottom: 8px;">Application Submitted!</h2>
    <p style="color: #64748b; margin-bottom: 24px;">Pay the service fee to complete your application</p>
    
    <div class="payment-code">
        <div style="font-size: 12px; opacity: 0.8;">Telebirr Payment Code</div>
        <div class="code" onclick="copyCode()"><?php echo $payment_code; ?></div>
        <button class="btn" style="background: rgba(255,255,255,0.2); margin-top: 0;" onclick="copyCode()">Copy Code</button>
        <div class="timer" id="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></div>
    </div>
    
    <div style="background: #f8fafc; border-radius: 16px; padding: 16px; margin: 20px 0;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span>Service Fee</span>
            <strong><?php echo formatMoney($serviceFee); ?></strong>
        </div>
        <div style="font-size: 12px; color: #64748b;">
            <i class="fas fa-shield-alt"></i> Payment is held in escrow
        </div>
    </div>
    
    <button id="confirmBtn" class="btn btn-success" onclick="confirmPayment()">
        I Have Paid - Confirm Payment
    </button>
    <div id="errorMsg" style="color: #dc2626; font-size: 13px; margin-top: 12px; display: none;"></div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const transactionId = <?php echo $transaction_id; ?>;
let timeLeft = <?php echo max(0, $time_left); ?>;
let timerInterval;
let checkInterval;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    alert('Code copied: ' + paymentCode);
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(checkInterval);
        document.getElementById('timer').innerHTML = 'Expired';
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    document.getElementById('timer').textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

async function confirmPayment() {
    const btn = document.getElementById('confirmBtn');
    const errorEl = document.getElementById('errorMsg');
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Confirming...';
    errorEl.style.display = 'none';
    
    try {
        const response = await fetch('/broker_system/api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_code: paymentCode, pin: '1234' })
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.location.href = 'transaction.php?id=' + transactionId;
        } else {
            errorEl.textContent = data.error || 'Confirmation failed';
            errorEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = 'I Have Paid - Confirm Payment';
        }
    } catch (error) {
        errorEl.textContent = 'Network error. Please try again.';
        errorEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = 'I Have Paid - Confirm Payment';
    }
}

timerInterval = setInterval(updateTimer, 1000);
</script>
</body>
</html>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>