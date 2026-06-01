<?php
// user/pay_rent.php - Complete with Debugging for Seller Notification

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/transaction_workflow.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create debug log file
$debug_log = __DIR__ . '/payment_debug.log';

function debug_log($message, $data = null) {
    global $debug_log;
    $log_entry = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log_entry .= " - " . print_r($data, true);
    }
    file_put_contents($debug_log, $log_entry . PHP_EOL, FILE_APPEND);
}

debug_log("========== NEW PAYMENT ATTEMPT ==========");
debug_log("Transaction ID: " . ($_GET['transaction_id'] ?? 'NOT SET'));

requireLogin();

$page_title = 'Complete Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$error = '';
$success = '';

debug_log("User ID: $user_id, Transaction ID: $transaction_id");

// Get transaction details with booking info
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.price, l.admin_deposit_percent, l.admin_commission_percent, l.id as listing_id,
           rb.id as booking_id, rb.total_months, rb.check_in_date, rb.check_out_date, rb.total_nights,
           rb.special_requests, rb.guest_name, rb.guest_phone,
           u.full_name as seller_name, u.id as seller_id, u.email as seller_email,
           buyer.full_name as buyer_name, buyer.email as buyer_email, buyer.phone as buyer_phone
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    LEFT JOIN rental_bookings rb ON rb.transaction_id = t.id
    JOIN users u ON t.seller_id = u.id
    JOIN users buyer ON t.buyer_id = buyer.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    debug_log("ERROR: Transaction not found! ID: $transaction_id");
    header('Location: dashboard.php');
    exit;
}

debug_log("Transaction found: " . $transaction['title']);
debug_log("Seller ID: " . $transaction['seller_id']);
debug_log("Seller Name: " . $transaction['seller_name']);

// Calculate payment amount
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;

debug_log("Payment amounts - Deposit: $depositAmount, Commission: $commissionAmount, Total: $totalPayment");

// Safely calculate price per night
$total_nights = isset($transaction['total_nights']) && $transaction['total_nights'] > 0 ? $transaction['total_nights'] : 1;
$price_per_night = $transaction['total_amount'] / $total_nights;

// Format dates safely
$check_in_date = !empty($transaction['check_in_date']) && $transaction['check_in_date'] != '0000-00-00' 
    ? date('F d, Y', strtotime($transaction['check_in_date'])) 
    : 'Not specified';
$check_out_date = !empty($transaction['check_out_date']) && $transaction['check_out_date'] != '0000-00-00' 
    ? date('F d, Y', strtotime($transaction['check_out_date'])) 
    : 'Not specified';

$pay_remaining_mode = (isset($_GET['pay']) && $_GET['pay'] === 'remaining');
$payment_code_type = $pay_remaining_mode ? 'remaining_balance' : 'deposit_buyer';
$calc = syncTransactionPaymentState($conn, $transaction_id);

if ($pay_remaining_mode) {
    if (!$calc || $calc['remaining_balance'] <= 0) {
        header("Location: transaction.php?id=$transaction_id");
        exit;
    }
    $totalPayment = $calc['remaining_balance'];
    $page_title = 'Pay Remaining Balance';
} else {
    $fully_paid = $conn->query("
        SELECT id FROM payments
        WHERE transaction_id = $transaction_id AND type = 'deposit_buyer' AND status = 'confirmed'
        LIMIT 1
    ");
    if ($fully_paid && $fully_paid->num_rows > 0 && $calc && $calc['payment_status'] === 'fully_paid') {
        header("Location: transaction.php?id=$transaction_id");
        exit;
    }
    if ($fully_paid && $fully_paid->num_rows > 0 && $calc && $calc['remaining_balance'] > 0) {
        header("Location: pay_rent.php?transaction_id=$transaction_id&pay=remaining");
        exit;
    }
}

// Get or generate payment code
$payment_code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND type = '$payment_code_type' AND status = 'pending'
    ORDER BY id DESC LIMIT 1
")->fetch_assoc();

if ($payment_code_data) {
    $payment_code = $payment_code_data['code'];
    $expires_at = $payment_code_data['expires_at'];
    $time_left = strtotime($expires_at) - time();
    debug_log("Existing payment code found: $payment_code");
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $time_left = 1800;
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("siidss", $payment_code, $transaction_id, $totalPayment, $user_id, $payment_code_type, $expires_at);
    $stmt->execute();
    debug_log("Generated new payment code: $payment_code type: $payment_code_type");
}

// Handle manual payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    debug_log("POST request received - Payment confirmation attempt");
    
    $entered_code = isset($_POST['payment_code']) ? htmlspecialchars(trim($_POST['payment_code'])) : '';
    $pin = isset($_POST['pin']) ? htmlspecialchars(trim($_POST['pin'])) : '';
    
    debug_log("Entered code: $entered_code, Expected code: $payment_code");
    
    if ($entered_code !== $payment_code) {
        $error = "Invalid payment code";
        debug_log("ERROR: Invalid payment code");
    } elseif ($pin !== '1234') {
        $error = "Invalid PIN. Use 1234 for testing";
        debug_log("ERROR: Invalid PIN");
    } else {
        debug_log("PIN verified, processing payment...");
        
        $conn->begin_transaction();
        
        try {
            // 1. Mark payment code as used
            $conn->query("UPDATE payment_codes SET status = 'used', updated_at = NOW() WHERE code = '$payment_code'");
            debug_log("Payment code marked as used");
            
            // 2. Record payment
            $stmt = $conn->prepare("
                INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at, created_at) 
                VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW(), NOW())
            ");
            $stmt->bind_param("iids", $transaction_id, $user_id, $totalPayment, $payment_code);
            $stmt->execute();
            debug_log("Payment recorded in payments table");
            
            // 3. Update escrow in transaction
            $conn->query("UPDATE transactions SET escrow_held = escrow_held + $totalPayment WHERE id = $transaction_id");
            
            // 4. Update transaction status
            $conn->query("UPDATE transactions SET status = 'escrow_active', escrow_status = 'active' WHERE id = $transaction_id");
            debug_log("Transaction status updated");
            
            // 5. Update booking status if exists
            if ($transaction['booking_id']) {
                $conn->query("
                    UPDATE rental_bookings 
                    SET status = 'confirmed', 
                        deposit_paid = $depositAmount,
                        updated_at = NOW() 
                    WHERE id = {$transaction['booking_id']}
                ");
                debug_log("Booking status updated for ID: {$transaction['booking_id']}");
            }
            
            // 6. CREATE ESCROW RECORD
            $escrow_stmt = $conn->prepare("
                INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
                VALUES (?, ?, ?, 'buyer_deposit', 'held', NOW())
            ");
            $escrow_stmt->bind_param("iid", $transaction_id, $user_id, $totalPayment);
            $escrow_stmt->execute();
            debug_log("Escrow record created");
            
            // 7. Schedule auto-release
            $auto_days = 7;
            if ($transaction['type'] == 'rental') $auto_days = 14;
            if ($transaction['type'] == 'product') $auto_days = 5;
            if ($transaction['type'] == 'job') $auto_days = 10;
            
            $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
            
            $conn->query("
                INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status, created_at) 
                VALUES ($transaction_id, '$release_date', 'pending', NOW())
                ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
            ");
            debug_log("Auto-release scheduled for: $release_date");
            
            // ============================================
            // 8. CRITICAL: SEND NOTIFICATION TO SELLER
            // ============================================
            
            $guest_name = $transaction['guest_name'] ?? $transaction['buyer_name'];
            $guest_phone = $transaction['guest_phone'] ?? $transaction['buyer_phone'];
            $special_requests = $transaction['special_requests'] ?? 'None';
            
            // Create notification message
            $notification_message = "💰💰 PAYMENT RECEIVED! 💰💰\n\n";
            $notification_message .= "Guest: {$transaction['buyer_name']}\n";
            $notification_message .= "Email: {$transaction['buyer_email']}\n";
            $notification_message .= "Phone: " . ($guest_phone ?: 'Not provided') . "\n";
            $notification_message .= "Property: {$transaction['title']}\n";
            $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $notification_message .= "Total Amount: " . formatMoney($transaction['total_amount']) . "\n";
            $notification_message .= "Deposit Paid (30%): " . formatMoney($depositAmount) . "\n";
            $notification_message .= "Commission: " . formatMoney($commissionAmount) . "\n";
            $notification_message .= "TOTAL PAID: " . formatMoney($totalPayment) . "\n";
            $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $notification_message .= "Check-in: $check_in_date\n";
            $notification_message .= "Check-out: $check_out_date\n";
            $notification_message .= "Nights: $total_nights\n";
            if ($special_requests !== 'None') {
                $notification_message .= "\n💬 Special Request: $special_requests\n";
            }
            $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $notification_message .= "✅ Payment is held in escrow.\n";
            $notification_message .= "📌 You will receive the remaining balance after check-out.\n";
            $notification_message .= "📱 Click to view booking details.";
            
            debug_log("Creating notification for seller ID: " . $transaction['seller_id']);
            debug_log("Notification message length: " . strlen($notification_message));
            
            // Insert notification for seller
            $notif_stmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                VALUES (?, '💰 NEW PAYMENT RECEIVED - Guest Paid 30% Deposit', ?, 'owner_bookings.php', 0, NOW())
            ");
            $notif_stmt->bind_param("is", $transaction['seller_id'], $notification_message);
            
            if ($notif_stmt->execute()) {
                debug_log("✅ SELLER NOTIFICATION SENT SUCCESSFULLY!");
                debug_log("Notification inserted for user_id: " . $transaction['seller_id']);
            } else {
                debug_log("❌ FAILED to send notification: " . $conn->error);
            }
            
            // Also send a simple notification for the bell icon
            $simple_message = "A guest has paid " . formatMoney($totalPayment) . " (30% deposit) for your property '{$transaction['title']}'. Click to view details.";
            $simple_stmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                VALUES (?, '💰 Payment Received', ?, 'owner_bookings.php', 0, NOW())
            ");
            $simple_stmt->bind_param("is", $transaction['seller_id'], $simple_message);
            $simple_stmt->execute();
            debug_log("Simple notification also sent");
            
            $conn->commit();
            debug_log("Transaction COMMITTED successfully!");
            
            $_SESSION['payment_success'] = true;
            debug_log("Redirecting to transaction.php?id=$transaction_id");
            header("Location: transaction.php?id=$transaction_id&payment_success=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
            debug_log("EXCEPTION: " . $e->getMessage());
            debug_log("Stack trace: " . $e->getTraceAsString());
        }
    }
}

$conn->close();
debug_log("Page rendering completed");
?>

<!-- HTML and CSS - Keep your existing HTML/CSS here -->
<!-- (Same as your current pay_rent.php HTML/CSS) -->

<style>
    /* Your existing styles here */
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

    .confirm-payment-box {
        margin: 24px 0;
        padding: 24px;
        background: linear-gradient(135deg, #ecfdf5, #d1fae5);
        border: 2px solid #10b981;
        border-radius: 20px;
        text-align: center;
    }

    .confirm-payment-box h4 {
        color: #065f46;
        font-size: 18px;
        margin-bottom: 8px;
    }

    .confirm-payment-box p {
        color: #047857;
        font-size: 13px;
        margin-bottom: 16px;
    }

    .confirm-pay-btn {
        width: 100%;
        max-width: 360px;
        padding: 16px 28px;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        border-radius: 50px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(16, 185, 129, 0.35);
        transition: transform 0.2s;
    }

    .confirm-pay-btn:hover:not(:disabled) {
        transform: translateY(-2px);
    }

    .confirm-pay-btn:disabled {
        opacity: 0.7;
        cursor: wait;
    }

    .confirm-pay-error {
        color: #dc2626;
        font-size: 13px;
        margin-top: 12px;
        display: none;
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
        <h1><i class="fas fa-credit-card"></i> <?php echo $pay_remaining_mode ? 'Pay Remaining Balance' : 'Complete Payment'; ?></h1>
        <p><?php echo $pay_remaining_mode ? 'Pay the remaining balance to complete your purchase' : 'Pay deposit + service fee to confirm your booking'; ?></p>
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
            <div class="step"><div class="step-number">4</div><div>Confirm with PIN in Telebirr (test PIN: <strong>1234</strong>)</div></div>
            <div class="step"><div class="step-number">5</div><div><strong>Then click the green button below</strong> on this page to record your payment</div></div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="confirm-payment-box" id="confirmPaymentBox">
            <h4><i class="fas fa-check-circle"></i> Step 2: Confirm on this website</h4>
            <p>Telebirr payment alone does not update this page. After paying in the app, click below (uses test PIN <strong>1234</strong>).</p>
            <button type="button" id="confirmPayBtn" class="confirm-pay-btn" onclick="confirmPaymentManually()">
                <i class="fas fa-check-double"></i> I Have Paid — Confirm Payment
            </button>
            <p id="confirmPayError" class="confirm-pay-error"></p>
        </div>

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
    
    if (timeLeft < 60) {
        document.getElementById('timer').classList.add('danger');
    } else if (timeLeft < 300) {
        document.getElementById('timer').classList.add('warning');
    }
}

function showPaymentSuccess() {
    clearInterval(checkInterval);
    clearInterval(timerInterval);
    document.getElementById('confirmPaymentBox').style.display = 'none';
    document.getElementById('paymentStatus').innerHTML = `
        <div class="checkmark">
            <i class="fas fa-check-circle"></i>
        </div>
        <p style="font-weight: 700; font-size: 20px; margin-top: 16px;">Payment Confirmed!</p>
        <p>Redirecting to your transaction...</p>
    `;
    setTimeout(() => {
        window.location.href = 'transaction.php?id=' + transactionId;
    }, 2000);
}

async function confirmPaymentManually() {
    const btn = document.getElementById('confirmPayBtn');
    const errEl = document.getElementById('confirmPayError');
    errEl.style.display = 'none';
    errEl.textContent = '';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Confirming...';

    try {
        const res = await fetch('/broker_system/api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ payment_code: paymentCode, pin: '1234' })
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('Server returned invalid response. Check PHP errors.');
        }
        if (data.success) {
            showPaymentSuccess();
        } else {
            errEl.textContent = data.error || 'Confirmation failed';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-double"></i> I Have Paid — Confirm Payment';
        }
    } catch (e) {
        errEl.textContent = e.message || 'Network error. Try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> I Have Paid — Confirm Payment';
    }
}

function checkPaymentStatus() {
    fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode, { credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
            if (data.confirmed) {
                showPaymentSuccess();
            }
        })
        .catch(() => {});
}

checkInterval = setInterval(checkPaymentStatus, 3000);
timerInterval = setInterval(updateTimer, 1000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>