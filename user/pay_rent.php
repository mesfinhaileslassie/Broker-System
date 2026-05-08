<?php
// user/pay_rent.php - Complete Payment Page with Booking Update

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

// Get transaction and booking details
$data = $conn->query("
    SELECT t.*, l.title, l.type, l.admin_deposit_percent, l.admin_commission_percent,
           rb.id as booking_id, rb.status as booking_status, rb.check_in_date, rb.check_out_date,
           rb.total_nights, rb.guest_name, rb.guest_phone, rb.special_requests,
           u.full_name as seller_name, u.id as seller_id
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    LEFT JOIN rental_bookings rb ON rb.transaction_id = t.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$data) {
    header('Location: dashboard.php');
    exit;
}

// Calculate amounts
$depositPercent = $data['admin_deposit_percent'] ?? 30;
$commissionPercent = $data['admin_commission_percent'] ?? 15;
$depositAmount = $data['total_amount'] * ($depositPercent / 100);
$commissionAmount = $data['total_amount'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;
$remainingAmount = $data['total_amount'] - $depositAmount;

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

// Handle manual payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $entered_code = sanitizeString($_POST['payment_code']);
    $pin = sanitizeString($_POST['pin']);
    
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
                VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW())
            ");
            $stmt->bind_param("iids", $transaction_id, $user_id, $totalPayment, $payment_code);
            $stmt->execute();
            
            // Update escrow in transaction
            $conn->query("UPDATE transactions SET escrow_held = escrow_held + $totalPayment WHERE id = $transaction_id");
            
            // Update transaction status
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
            
            // UPDATE BOOKING STATUS
            if ($data['booking_id']) {
                $conn->query("
                    UPDATE rental_bookings 
                    SET status = 'confirmed', 
                        deposit_paid = $depositAmount,
                        updated_at = NOW() 
                    WHERE id = {$data['booking_id']}
                ");
            }
            
            // Create escrow record
            $conn->query("
                INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
                VALUES ($transaction_id, $user_id, $depositAmount, 'deposit', 'held', NOW())
            ");
            
            // NOTIFY OWNER - Complete notification with all details
            $check_in = date('F d, Y', strtotime($data['check_in_date']));
            $check_out = date('F d, Y', strtotime($data['check_out_date']));
            $owner_message = "🏠 NEW BOOKING PAID!\n\n";
            $owner_message .= "Property: {$data['title']}\n";
            $owner_message .= "Guest: {$data['guest_name']}\n";
            $owner_message .= "📞 Phone: " . ($data['guest_phone'] ?: 'Not provided') . "\n";
            $owner_message .= "📅 Check-in: $check_in\n";
            $owner_message .= "📅 Check-out: $check_out\n";
            $owner_message .= "🌙 Nights: {$data['total_nights']}\n";
            $owner_message .= "💰 Deposit Paid: " . formatMoney($depositAmount) . "\n";
            $owner_message .= "💵 Total Amount: " . formatMoney($data['total_amount']) . "\n\n";
            $owner_message .= "The tenant has paid the deposit. The money is held securely in escrow.";
            
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                VALUES (
                    {$data['seller_id']}, 
                    '💰 New Paid Booking - Action Required', 
                    '$owner_message', 
                    'owner_bookings.php', 
                    0, 
                    NOW()
                )
            ");
            
            $conn->commit();
            
            $_SESSION['payment_success'] = true;
            header("Location: transaction.php?id=$transaction_id&payment_success=1");
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
    
    .booking-summary {
        background: white;
        border-radius: 24px;
        padding: 28px;
        margin-bottom: 24px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid var(--border);
    }
    
    .summary-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .property-name {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .date-range {
        background: var(--light);
        border-radius: 16px;
        padding: 16px;
        margin: 16px 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .date-box {
        text-align: center;
        flex: 1;
    }
    
    .date-label {
        font-size: 11px;
        color: var(--gray);
        text-transform: uppercase;
    }
    
    .date-value {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .nights-badge {
        background: var(--primary);
        color: white;
        padding: 8px 16px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
    }
    
    .price-breakdown {
        background: var(--light);
        border-radius: 20px;
        padding: 20px;
        margin: 20px 0;
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
        border-radius: 24px;
        padding: 32px;
        text-align: center;
        margin-bottom: 24px;
    }
    
    .payment-code {
        font-size: 52px;
        font-weight: 800;
        letter-spacing: 14px;
        background: white;
        color: var(--dark);
        padding: 24px;
        border-radius: 20px;
        font-family: monospace;
        margin: 20px 0;
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
    }
    
    .copy-btn:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .instructions {
        background: var(--light);
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
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .form-group input {
        width: 100%;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 12px;
        font-size: 14px;
    }
    
    .form-group input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .btn-pay {
        width: 100%;
        padding: 16px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border: none;
        border-radius: 50px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-pay:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102,126,234,0.4);
    }
    
    .alert-error {
        background: #fee2e2;
        color: var(--danger);
        padding: 14px;
        border-radius: 12px;
        margin-bottom: 20px;
        border-left: 4px solid var(--danger);
    }
    
    .timer {
        font-family: monospace;
        font-size: 16px;
        font-weight: 700;
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
    
    @media (max-width: 640px) {
        .payment-code {
            font-size: 32px;
            letter-spacing: 8px;
        }
        .date-range {
            flex-direction: column;
        }
        .date-box {
            width: 100%;
        }
    }
</style>

<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-credit-card"></i> Complete Payment</h1>
        <p>Pay deposit to confirm your booking</p>
    </div>
    
    <!-- Booking Summary -->
    <div class="booking-summary">
        <div class="summary-title">
            <i class="fas fa-receipt"></i> Booking Summary
        </div>
        
        <div class="property-name">🏠 <?php echo htmlspecialchars($data['title']); ?></div>
        
        <div class="date-range">
            <div class="date-box">
                <div class="date-label"><i class="fas fa-calendar-check"></i> Check-in</div>
                <div class="date-value"><?php echo date('F d, Y', strtotime($data['check_in_date'])); ?></div>
            </div>
            <div class="date-box">
                <div class="date-label"><i class="fas fa-calendar-times"></i> Check-out</div>
                <div class="date-value"><?php echo date('F d, Y', strtotime($data['check_out_date'])); ?></div>
            </div>
            <div class="nights-badge">
                <i class="fas fa-moon"></i> <?php echo $data['total_nights']; ?> nights
            </div>
        </div>
        
        <?php if ($data['guest_name']): ?>
        <div style="margin: 16px 0; padding: 12px; background: var(--light); border-radius: 12px;">
            <div><strong><i class="fas fa-user"></i> Guest:</strong> <?php echo htmlspecialchars($data['guest_name']); ?></div>
            <?php if ($data['guest_phone']): ?>
            <div><strong><i class="fas fa-phone"></i> Phone:</strong> <?php echo htmlspecialchars($data['guest_phone']); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="price-breakdown">
            <div class="breakdown-row">
                <span>💰 Price per night</span>
                <span><?php echo formatMoney($data['total_amount'] / $data['total_nights']); ?></span>
            </div>
            <div class="breakdown-row">
                <span>📆 Total for <?php echo $data['total_nights']; ?> nights</span>
                <span><?php echo formatMoney($data['total_amount']); ?></span>
            </div>
            <div class="breakdown-row">
                <span>🔒 Deposit (<?php echo $depositPercent; ?>%)</span>
                <span><?php echo formatMoney($depositAmount); ?></span>
            </div>
            <div class="breakdown-row">
                <span>⚙️ Service Fee (<?php echo $commissionPercent; ?>%)</span>
                <span><?php echo formatMoney($commissionAmount); ?></span>
            </div>
            <div class="breakdown-row total">
                <span>💳 Total to Pay Today</span>
                <span><?php echo formatMoney($totalPayment); ?></span>
            </div>
            <div class="breakdown-row">
                <span>📌 Remaining (pay at check-in)</span>
                <span><?php echo formatMoney($remainingAmount); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Payment Code -->
    <div class="code-box">
        <div class="payment-code" id="paymentCode"><?php echo $payment_code; ?></div>
        <button class="copy-btn" onclick="copyCode()">
            <i class="fas fa-copy"></i> Copy Code
        </button>
        <div style="margin-top: 16px; color: rgba(255,255,255,0.8);">
            <i class="far fa-clock"></i> Code expires in: 
            <span class="timer" id="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></span>
        </div>
    </div>
    
    <!-- Instructions -->
    <div class="instructions">
        <h4 style="margin-bottom: 16px;"><i class="fas fa-mobile-alt"></i> How to Pay with Telebirr</h4>
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
            <div>Enter the payment code: <strong style="color: var(--primary);"><?php echo $payment_code; ?></strong></div>
        </div>
        <div class="step">
            <div class="step-number">4</div>
            <div>Confirm payment with your Telebirr PIN (Test PIN: <strong>1234</strong>)</div>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <!-- Payment Confirmation Form -->
    <div class="booking-summary">
        <div class="summary-title">
            <i class="fas fa-check-circle"></i> Confirm Payment
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-key"></i> Enter Payment Code</label>
                <input type="text" name="payment_code" placeholder="5-digit code" required pattern="[0-9]{5}" maxlength="5">
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> Telebirr PIN (Test: 1234)</label>
                <input type="password" name="pin" placeholder="Enter your PIN" required maxlength="4">
            </div>
            <button type="submit" name="confirm_payment" class="btn-pay">
                <i class="fas fa-check-circle"></i> Confirm Payment
            </button>
        </form>
        
        <p style="font-size: 12px; color: var(--gray); text-align: center; margin-top: 16px;">
            <i class="fas fa-shield-alt"></i> Your payment is protected by escrow. 
            The money will be held securely until you confirm your stay.
        </p>
    </div>
</div>

<script>
let timeLeft = <?php echo max(0, $time_left); ?>;

function copyCode() {
    const code = '<?php echo $payment_code; ?>';
    navigator.clipboard.writeText(code);
    alert('✅ Payment code copied: ' + code);
}

function updateTimer() {
    if (timeLeft <= 0) {
        document.getElementById('timer').textContent = 'Expired';
        document.getElementById('timer').classList.add('danger');
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

setInterval(updateTimer, 1000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>