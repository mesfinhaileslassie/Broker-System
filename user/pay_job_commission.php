<?php
// user/pay_job_commission.php - Employer pays commission to activate job

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Pay Commission - Activate Job';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_GET['listing_id']) ? intval($_GET['listing_id']) : 0;
$error = '';

// Get job listing
$job = $conn->query("
    SELECT l.*, l.admin_commission_percent
    FROM listings l
    WHERE l.id = $listing_id 
    AND l.seller_id = $user_id 
    AND l.type = 'job'
    AND l.approval_status = 'approved'
    AND l.status = 'pending_payment'
")->fetch_assoc();

if (!$job) {
    header('Location: listings.php');
    exit;
}

// Calculate commission amount
$commissionPercent = $job['admin_commission_percent'] ?? 15;
$commissionAmount = round($job['price'] * ($commissionPercent / 100), 2);

// Create transaction for commission payment
$transaction = $conn->query("
    SELECT id FROM transactions 
    WHERE listing_id = $listing_id AND seller_id = $user_id AND type = 'commission'
");
if ($transaction->num_rows == 0) {
    $stmt = $conn->prepare("
        INSERT INTO transactions (listing_id, seller_id, total_amount, commission_amount, status, created_at, type) 
        VALUES (?, ?, ?, 'pending_payment', NOW(), 'commission')
    ");
    $stmt->bind_param("iid", $listing_id, $user_id, $commissionAmount, $commissionAmount);
    $stmt->execute();
    $transaction_id = $conn->insert_id;
} else {
    $transaction_id = $transaction->fetch_assoc()['id'];
}

// Get or generate payment code
$payment_code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND type = 'commission' AND status = 'pending'
    ORDER BY id DESC LIMIT 1
");

if ($payment_code_data && $payment_code_data->num_rows > 0) {
    $code_row = $payment_code_data->fetch_assoc();
    $payment_code = $code_row['code'];
    $expires_at = $code_row['expires_at'];
    $time_left = strtotime($expires_at) - time();
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $time_left = 1800;
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
        VALUES (?, ?, ?, ?, 'commission', ?, 'pending', NOW())
    ");
    $stmt->bind_param("siidss", $payment_code, $transaction_id, $commissionAmount, $user_id, $expires_at);
    $stmt->execute();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Commission - Activate Job</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .payment-container { max-width: 600px; margin: 0 auto; }
        .payment-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            color: white;
            text-align: center;
        }
        .card { background: white; border-radius: 24px; padding: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .payment-code {
            font-size: 48px;
            font-weight: 800;
            letter-spacing: 12px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 16px;
            font-family: monospace;
            text-align: center;
            margin: 16px 0;
            cursor: pointer;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
        }
        .timer { font-family: monospace; font-size: 24px; text-align: center; margin: 16px 0; }
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
    </style>
</head>
<body>
<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-rocket"></i> Activate Your Job</h1>
        <p>Pay commission to make your job visible to applicants</p>
    </div>
    
    <div class="card">
        <div class="payment-code" onclick="copyCode()"><?php echo $payment_code; ?></div>
        <button class="btn" onclick="copyCode()">Copy Code</button>
        <div class="timer" id="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></div>
        
        <div style="margin: 20px 0; padding: 16px; background: #f8fafc; border-radius: 16px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                <span>Monthly Salary:</span>
                <strong><?php echo formatMoney($job['price']); ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                <span>Commission (<?php echo $commissionPercent; ?>%):</span>
                <strong><?php echo formatMoney($commissionAmount); ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                <span>Total to Pay:</span>
                <strong style="color: #667eea;"><?php echo formatMoney($commissionAmount); ?></strong>
            </div>
        </div>
        
        <div id="paymentStatus" style="text-align: center; padding: 20px;">
            <div class="spinner"></div>
            <p style="margin-top: 12px;">Waiting for payment confirmation...</p>
        </div>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const listingId = <?php echo $listing_id; ?>;
let timeLeft = <?php echo max(0, $time_left); ?>;
let checkInterval;
let timerInterval;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    alert('Code copied: ' + paymentCode);
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(checkInterval);
        document.getElementById('paymentStatus').innerHTML = '<p style="color: red;">Code expired. Please refresh.</p>';
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    document.getElementById('timer').textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

async function checkPaymentStatus() {
    try {
        const res = await fetch('/broker_system/api/check_payment_status.php?code=' + paymentCode);
        const data = await res.json();
        if (data.is_paid) {
            clearInterval(checkInterval);
            clearInterval(timerInterval);
            document.getElementById('paymentStatus').innerHTML = `
                <div style="color: #10b981;">
                    <i class="fas fa-check-circle" style="font-size: 48px;"></i>
                    <p style="margin-top: 12px; font-weight: 600;">Payment Confirmed!</p>
                    <p>Your job is now active.</p>
                </div>
            `;
            setTimeout(() => { window.location.href = 'listings.php'; }, 2000);
        }
    } catch(e) { console.error(e); }
}

timerInterval = setInterval(updateTimer, 1000);
checkInterval = setInterval(checkPaymentStatus, 2000);
</script>
</body>
</html>