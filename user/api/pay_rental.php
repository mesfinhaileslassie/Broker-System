<?php
// user/pay_rental.php - Payment page for rentals and services

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
    SELECT t.*, l.title, l.type, l.price, u.full_name as seller_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

// Calculate payment amount
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$totalDue = $depositAmount + $commissionAmount;

// Check if already paid
$payment_check = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'confirmed'
");

$already_paid = $payment_check->num_rows > 0;

// Get existing payment code
$code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'pending'
")->fetch_assoc();

$payment_code = $code_data ? $code_data['code'] : null;
$code_expires = $code_data ? $code_data['expires_at'] : null;

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
    
    /* Header */
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
    
    /* Cards */
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
    
    /* Item Details */
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
    
    /* Payment Code Box */
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
        font-size: 12px;
        color: rgba(255,255,255,0.7);
        margin-top: 12px;
    }
    
    /* Instructions */
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
    
    /* Buttons */
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
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-success {
        background: var(--success);
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    /* Timer */
    .timer {
        font-family: monospace;
        font-size: 14px;
        font-weight: 600;
    }
    
    .timer.warning {
        color: var(--warning);
    }
    
    .timer.danger {
        color: var(--danger);
    }
    
    /* Alert */
    .alert {
        padding: 14px 18px;
        border-radius: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    /* Loading */
    .loading {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 20px;
    }
    
    .spinner {
        width: 20px;
        height: 20px;
        border: 2px solid var(--border);
        border-top-color: var(--primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
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
    <!-- Header -->
    <div class="payment-header">
        <h1><i class="fas fa-credit-card"></i> Complete Payment</h1>
        <p>Pay securely using Telebirr</p>
    </div>
    
    <?php if ($already_paid): ?>
        <div class="card">
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Payment already completed!</strong><br>
                    Your payment has been confirmed. You can track your transaction progress.
                </div>
            </div>
            <a href="transaction.php?id=<?php echo $transaction_id; ?>" class="btn btn-primary">
                <i class="fas fa-eye"></i> View Transaction
            </a>
        </div>
    <?php else: ?>
        <!-- Item Details -->
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
                        <span>Total to Pay</span>
                        <span><?php echo formatMoney($totalDue); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Code Section -->
        <div class="card" id="paymentCodeCard">
            <div class="card-title">
                <i class="fas fa-mobile-alt"></i> Telebirr Payment
            </div>
            
            <?php if ($payment_code): ?>
                <!-- Existing Code Display -->
                <div class="code-box">
                    <div class="code-label">Your Telebirr Payment Code</div>
                    <div class="payment-code" id="paymentCode"><?php echo $payment_code; ?></div>
                    <button class="copy-btn" onclick="copyCode()">
                        <i class="fas fa-copy"></i> Copy Code
                    </button>
                    <div class="expiry" id="expiryDisplay">
                        <i class="far fa-clock"></i> Expires: <span id="expiryTime"><?php echo date('H:i:s', strtotime($code_expires)); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <!-- Generate New Code -->
                <div style="text-align: center; padding: 20px;">
                    <p style="margin-bottom: 16px; color: var(--gray);">
                        Click below to generate a Telebirr payment code for this transaction.
                    </p>
                    <button onclick="generatePaymentCode()" class="btn btn-primary" id="generateBtn">
                        <i class="fas fa-key"></i> Generate Payment Code
                    </button>
                </div>
                <div id="codeDisplay" style="display: none;"></div>
            <?php endif; ?>
            
            <!-- Instructions -->
            <div class="instructions">
                <h4 style="margin-bottom: 12px;"><i class="fas fa-info-circle"></i> How to Pay</h4>
                <div class="step">
                    <div class="step-number">1</div>
                    <div>Open Telebirr app on your mobile phone</div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div>Go to <strong>Marketplace</strong> or <strong>Pay with Code</strong> section</div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div>Enter the <strong>5-digit payment code</strong> shown above</div>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <div>Confirm payment with your Telebirr PIN (Demo PIN: <strong>1234</strong>)</div>
                </div>
            </div>
        </div>
        
        <!-- Payment Status -->
        <div class="card" id="statusCard" style="display: none;">
            <div class="card-title">
                <i class="fas fa-hourglass-half"></i> Payment Status
            </div>
            <div id="paymentStatus">
                <div class="loading">
                    <div class="spinner"></div>
                    <span>Waiting for payment confirmation...</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
let paymentCode = '<?php echo $payment_code; ?>';
let transactionId = <?php echo $transaction_id; ?>;
let checkInterval;
let timerInterval;
let timeLeft = <?php echo $code_expires ? max(0, strtotime($code_expires) - time()) : 0; ?>;

function copyCode() {
    const code = document.getElementById('paymentCode').innerText;
    navigator.clipboard.writeText(code);
    alert('Payment code copied: ' + code);
}

function generatePaymentCode() {
    const generateBtn = document.getElementById('generateBtn');
    generateBtn.disabled = true;
    generateBtn.innerHTML = '<div class="spinner"></div> Generating...';
    
    fetch('/broker_system/api/generate_payment_code.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            transaction_id: transactionId,
            amount: <?php echo $totalDue; ?>,
            payment_type: 'deposit_buyer'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            paymentCode = data.payment_code;
            timeLeft = data.expires_in;
            
            // Display the code
            document.getElementById('codeDisplay').innerHTML = `
                <div class="code-box">
                    <div class="code-label">Your Telebirr Payment Code</div>
                    <div class="payment-code" id="paymentCode">${data.payment_code}</div>
                    <button class="copy-btn" onclick="copyCode()">
                        <i class="fas fa-copy"></i> Copy Code
                    </button>
                    <div class="expiry" id="expiryDisplay">
                        <i class="far fa-clock"></i> Expires in: <span id="timer">${formatTime(timeLeft)}</span>
                    </div>
                </div>
                <div class="instructions">
                    <h4><i class="fas fa-info-circle"></i> How to Pay</h4>
                    <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your mobile phone</div></div>
                    <div class="step"><div class="step-number">2</div><div>Go to Marketplace or Pay with Code section</div></div>
                    <div class="step"><div class="step-number">3</div><div>Enter the 5-digit payment code: <strong>${data.payment_code}</strong></div></div>
                    <div class="step"><div class="step-number">4</div><div>Confirm payment with your Telebirr PIN (Demo: 1234)</div></div>
                </div>
            `;
            document.getElementById('codeDisplay').style.display = 'block';
            document.getElementById('generateBtn').parentElement.style.display = 'none';
            document.getElementById('statusCard').style.display = 'block';
            
            startTimer();
            startPaymentCheck();
        } else {
            alert('Error: ' + data.error);
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-key"></i> Generate Payment Code';
        }
    })
    .catch(error => {
        alert('Error generating code: ' + error);
        generateBtn.disabled = false;
        generateBtn.innerHTML = '<i class="fas fa-key"></i> Generate Payment Code';
    });
}

function formatTime(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

function startTimer() {
    if (timerInterval) clearInterval(timerInterval);
    
    timerInterval = setInterval(() => {
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            clearInterval(checkInterval);
            document.getElementById('expiryDisplay').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Code expired. Please generate a new code.';
            document.getElementById('statusCard').style.display = 'none';
        } else {
            timeLeft--;
            const timerSpan = document.getElementById('timer');
            if (timerSpan) {
                timerSpan.textContent = formatTime(timeLeft);
                if (timeLeft < 300) {
                    timerSpan.style.color = '#f59e0b';
                }
                if (timeLeft < 60) {
                    timerSpan.style.color = '#ef4444';
                }
            }
        }
    }, 1000);
}

function startPaymentCheck() {
    if (checkInterval) clearInterval(checkInterval);
    
    checkInterval = setInterval(() => {
        fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode)
            .then(response => response.json())
            .then(data => {
                if (data.confirmed) {
                    clearInterval(checkInterval);
                    clearInterval(timerInterval);
                    document.getElementById('paymentStatus').innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Payment Confirmed!</strong><br>
                                Your payment has been received. Redirecting to transaction page...
                            </div>
                        </div>
                    `;
                    setTimeout(() => {
                        window.location.href = 'transaction.php?id=' + transactionId;
                    }, 3000);
                }
            });
    }, 3000);
}

<?php if ($payment_code): ?>
// If code already exists, start checking
startTimer();
startPaymentCheck();
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>