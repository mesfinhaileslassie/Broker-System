<?php
// user/pay_rent.php - Buyer pays deposit for rental/service (UPDATED with better owner notification)

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

// Get transaction details with booking info
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.price, l.admin_deposit_percent, l.admin_commission_percent, l.id as listing_id,
           rb.id as booking_id, rb.total_months, rb.check_in_date, rb.check_out_date, rb.total_nights,
           rb.special_requests, rb.guest_name, rb.guest_phone,
           u.full_name as seller_name, u.id as seller_id, u.email as seller_email
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    LEFT JOIN rental_bookings rb ON rb.transaction_id = t.id
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
$totalPayment = $depositAmount + $commissionAmount;

// Safely calculate price per night (avoid division by zero)
$total_nights = isset($transaction['total_nights']) && $transaction['total_nights'] > 0 ? $transaction['total_nights'] : 1;
$price_per_night = $transaction['total_amount'] / $total_nights;

// Format dates safely
$check_in_date = !empty($transaction['check_in_date']) && $transaction['check_in_date'] != '0000-00-00' 
    ? date('F d, Y', strtotime($transaction['check_in_date'])) 
    : 'Not specified';
$check_out_date = !empty($transaction['check_out_date']) && $transaction['check_out_date'] != '0000-00-00' 
    ? date('F d, Y', strtotime($transaction['check_out_date'])) 
    : 'Not specified';

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
                VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW())
            ");
            $stmt->bind_param("iids", $transaction_id, $user_id, $totalPayment, $payment_code);
            $stmt->execute();
            
            // Update escrow in transaction
            $conn->query("UPDATE transactions SET escrow_held = escrow_held + $totalPayment WHERE id = $transaction_id");
            
            // Update transaction status
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
            
            // Update booking status if exists
            if ($transaction['booking_id']) {
                $conn->query("
                    UPDATE rental_bookings 
                    SET status = 'confirmed', 
                        deposit_paid = $depositAmount,
                        updated_at = NOW() 
                    WHERE id = {$transaction['booking_id']}
                ");
            }
            
            // Create escrow record
            $conn->query("
                INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
                VALUES ($transaction_id, $user_id, $depositAmount, 'deposit', 'held', NOW())
            ");
            
            // ============================================
            // ENHANCED OWNER NOTIFICATION
            // ============================================
            $guest_name = $transaction['guest_name'] ?? $_SESSION['user_name'];
            $guest_phone = $transaction['guest_phone'] ?? 'Not provided';
            $special_requests = $transaction['special_requests'] ?? 'None';
            
            $owner_message = "🏠🔔 NEW BOOKING & PAYMENT RECEIVED!\n\n";
            $owner_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $owner_message .= "📋 PROPERTY DETAILS:\n";
            $owner_message .= "   Property: {$transaction['title']}\n";
            $owner_message .= "   Type: " . ucfirst($transaction['type']) . "\n\n";
            $owner_message .= "👤 GUEST INFORMATION:\n";
            $owner_message .= "   Name: $guest_name\n";
            $owner_message .= "   Email: {$_SESSION['user_email']}\n";
            $owner_message .= "   Phone: $guest_phone\n\n";
            $owner_message .= "📅 BOOKING DATES:\n";
            $owner_message .= "   Check-in: $check_in_date\n";
            $owner_message .= "   Check-out: $check_out_date\n";
            $owner_message .= "   Nights: $total_nights\n\n";
            $owner_message .= "💰 PAYMENT DETAILS:\n";
            $owner_message .= "   Total Amount: " . formatMoney($transaction['total_amount']) . "\n";
            $owner_message .= "   Deposit Paid: " . formatMoney($depositAmount) . " (held in escrow)\n";
            $owner_message .= "   Service Fee: " . formatMoney($commissionAmount) . "\n";
            $owner_message .= "   Remaining to collect: " . formatMoney($transaction['total_amount'] - $depositAmount) . "\n\n";
            $owner_message .= "💬 SPECIAL REQUESTS:\n";
            $owner_message .= "   $special_requests\n\n";
            $owner_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $owner_message .= "✅ The property is now reserved.\n";
            $owner_message .= "📱 Click 'View Details' to see full booking information.\n";
            $owner_message .= "💰 The deposit is held securely in escrow until check-out.";
            
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                VALUES (
                    {$transaction['seller_id']}, 
                    '💰💰 NEW BOOKING - DEPOSIT PAID 💰💰', 
                    '$owner_message', 
                    'owner_bookings.php', 
                    0, 
                    NOW()
                )
            ");
            
            // Also create a second notification as a reminder
            $reminder_message = "📍 Action Required: A guest has booked your property '{$transaction['title']}' from $check_in_date to $check_out_date. Please log in to view details.";
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                VALUES (
                    {$transaction['seller_id']}, 
                    '📍 Action Required: New Booking', 
                    '$reminder_message', 
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

<!-- Keep the rest of the HTML/CSS the same as your existing pay_rent.php -->
<!-- (The style and HTML remain unchanged) -->

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
    
    .booking-dates {
        background: #dbeafe;
        border-radius: 12px;
        padding: 12px;
        margin: 12px 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .date-box {
        text-align: center;
        flex: 1;
    }
    
    .date-label {
        font-size: 10px;
        color: #1e40af;
        text-transform: uppercase;
    }
    
    .date-value {
        font-size: 14px;
        font-weight: 700;
        color: #1e3a8a;
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
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
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
        padding: 12px;
        border: 1px solid var(--border);
        border-radius: 12px;
    }
    
    @media (max-width: 640px) {
        .payment-code {
            font-size: 28px;
            letter-spacing: 6px;
        }
        .card {
            padding: 20px;
        }
        .booking-dates {
            flex-direction: column;
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
            <i class="fas fa-receipt"></i> Booking Summary
        </div>
        
        <div class="item-details">
            <div class="item-name"><?php echo htmlspecialchars($transaction['title']); ?></div>
            <span class="item-type" style="display: inline-block; padding: 4px 12px; background: var(--primary); color: white; border-radius: 20px; font-size: 11px; margin-bottom: 12px;">
                <?php 
                if ($transaction['type'] == 'rental') echo '🏠 Rental Property';
                elseif ($transaction['type'] == 'product') echo '🚗 Product';
                else echo '💼 Service';
                ?>
            </span>
            
            <?php if ($transaction['type'] == 'rental'): ?>
            <div class="booking-dates">
                <div class="date-box">
                    <div class="date-label">Check-in</div>
                    <div class="date-value"><?php echo $check_in_date; ?></div>
                </div>
                <div class="date-box">
                    <div class="date-label">Check-out</div>
                    <div class="date-value"><?php echo $check_out_date; ?></div>
                </div>
                <div class="date-box">
                    <div class="date-label">Nights</div>
                    <div class="date-value"><?php echo $total_nights; ?> nights</div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="price-breakdown">
                <div class="breakdown-row">
                    <span><?php echo ($transaction['type'] == 'rental') ? 'Total Rent' : 'Total Price'; ?></span>
                    <span><?php echo formatMoney($transaction['total_amount']); ?></span>
                </div>
                <?php if ($transaction['type'] == 'rental' && $total_nights > 0): ?>
                <div class="breakdown-row">
                    <span>Price per night</span>
                    <span><?php echo formatMoney($price_per_night); ?></span>
                </div>
                <?php endif; ?>
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
        
        <div class="code-box">
            <div class="code-label">Your Telebirr Payment Code</div>
            <div class="payment-code" id="paymentCode"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
            <div class="expiry">⏰ Expires in: <span id="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></span></div>
        </div>
        
        <div class="instructions">
            <h4>How to Pay with Telebirr</h4>
            <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your phone</div></div>
            <div class="step"><div class="step-number">2</div><div>Go to Marketplace / Payment section</div></div>
            <div class="step"><div class="step-number">3</div><div>Enter this code: <strong><?php echo $payment_code; ?></strong></div></div>
            <div class="step"><div class="step-number">4</div><div>Confirm with PIN (Test PIN: <strong>1234</strong>)</div></div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>
        
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
let timeLeft = <?php echo max(0, $time_left); ?>;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    alert('Payment code copied!');
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(checkInterval);
        document.getElementById('paymentStatus').innerHTML = `
            <div style="color: red;">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Payment Code Expired. Please go back and request a new code.</p>
                <a href="transaction.php?id=${transactionId}" class="btn" style="background: #667eea; color: white; padding: 10px 20px; border-radius: 40px; text-decoration: none;">Go Back</a>
            </div>
        `;
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    document.getElementById('timer').textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

function checkPaymentStatus() {
    fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode)
        .then(response => response.json())
        .then(data => {
            if (data.confirmed) {
                clearInterval(checkInterval);
                clearInterval(timerInterval);
                document.getElementById('paymentStatus').innerHTML = `
                    <div style="color: green;">
                        <i class="fas fa-check-circle"></i>
                        <p>Payment Confirmed! Redirecting...</p>
                    </div>
                `;
                setTimeout(() => {
                    window.location.href = 'transaction.php?id=' + transactionId;
                }, 2000);
            }
        });
}

checkInterval = setInterval(checkPaymentStatus, 3000);
timerInterval = setInterval(updateTimer, 1000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>