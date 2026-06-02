<?php
// user/pay_rent.php - Complete with Availability Reservation System

// ============================================
// DEBUGGING - Add at the very top
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Create a simple debug file
$simple_debug = __DIR__ . '/simple_debug.log';
file_put_contents($simple_debug, date('Y-m-d H:i:s') . " - pay_rent.php loaded\n", FILE_APPEND);
file_put_contents($simple_debug, date('Y-m-d H:i:s') . " - GET params: " . print_r($_GET, true) . "\n", FILE_APPEND);
file_put_contents($simple_debug, date('Y-m-d H:i:s') . " - POST params: " . print_r($_POST, true) . "\n", FILE_APPEND);

// require_once '../config/database.php';
// require_once '../includes/functions.php';
// require_once '../includes/auth.php';
// require_once '../includes/transaction_workflow.php';
// require_once '../includes/AvailabilityManager.php';

// Rest of your code continues...



// user/pay_rent.php - Complete with Availability Reservation System
// FIXED: Correct variable ordering for remaining balance payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/transaction_workflow.php';
require_once '../includes/AvailabilityManager.php';

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

// Check login FIRST
requireLogin();
// DEBUG: Confirm we reached this point
file_put_contents($simple_debug, date('Y-m-d H:i:s') . " - After requireLogin(), user_id: " . ($_SESSION['user_id'] ?? 'not set') . "\n", FILE_APPEND);
// Start output buffering
$page_title = 'Complete Payment';
ob_start();

// ============================================
// CRITICAL: Initialize database connection FIRST
// ============================================
$conn = getDbConnection();

// Verify connection
if (!$conn) {
    die("Database connection failed");
}

debug_log("Database connection established");

// Get user and transaction info FIRST
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$error = '';
$success = '';

debug_log("User ID: $user_id, Transaction ID: $transaction_id");

// Validate transaction_id
if ($transaction_id <= 0) {
    debug_log("ERROR: Invalid transaction ID");
    header('Location: dashboard.php');
    exit;
}

// ============================================
// NOW check remaining payment mode (AFTER variables are defined)
// ============================================
$pay_remaining_mode = (isset($_GET['pay']) && $_GET['pay'] === 'remaining');

if ($pay_remaining_mode) {
    debug_log("Remaining balance payment mode activated");
    
    // Verify both parties confirmed delivery
    $delivery_check = $conn->query("
        SELECT seller_delivery_confirmed, buyer_delivery_confirmed 
        FROM transactions 
        WHERE id = $transaction_id AND buyer_id = $user_id
    ");
    
    if ($delivery_check && $delivery_check->num_rows > 0) {
        $delivery_data = $delivery_check->fetch_assoc();
        if (!$delivery_data['seller_delivery_confirmed'] || !$delivery_data['buyer_delivery_confirmed']) {
            $error = "Cannot pay remaining balance until both parties confirm delivery.";
            $pay_remaining_mode = false;
            debug_log("Delivery not confirmed - Seller: {$delivery_data['seller_delivery_confirmed']}, Buyer: {$delivery_data['buyer_delivery_confirmed']}");
        } else {
            $payment_code_type = 'remaining_balance';
            $page_title = 'Pay Remaining Balance';
            debug_log("Remaining balance mode confirmed");
        }
    } else {
        $error = "Transaction not found";
        $pay_remaining_mode = false;
    }
}

// Check what columns exist in listings table
$columns_result = $conn->query("SHOW COLUMNS FROM listings");
$existing_columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}

// ============================================
// CHECK IF LISTING IS ALREADY RESERVED (RACE CONDITION FIX)
// ============================================
$listing_check_query = $conn->prepare("
    SELECT l.id, l.availability_status, l.sold_to_user_id 
    FROM listings l
    JOIN transactions t ON t.listing_id = l.id
    WHERE t.id = ?
");
$listing_check_query->bind_param("i", $transaction_id);
$listing_check_query->execute();
$listing_check_result = $listing_check_query->get_result();
$listing_check = $listing_check_result->fetch_assoc();

$availability_status = isset($listing_check['availability_status']) ? $listing_check['availability_status'] : 'available';
$sold_to_user_id = isset($listing_check['sold_to_user_id']) ? intval($listing_check['sold_to_user_id']) : null;

if ($listing_check && $availability_status === 'reserved' && $sold_to_user_id != $user_id) {
    $error = "This item has already been reserved by another buyer.";
    debug_log("BLOCKED: Item already reserved by user {$sold_to_user_id}");
    $blocked = true;
} else {
    $blocked = false;
}

// Get transaction details with booking info
$transaction_query = $conn->query("
    SELECT t.*, l.title, l.type, l.price, l.admin_deposit_percent, l.admin_commission_percent, l.id as listing_id,
           rb.id as booking_id, rb.total_months, rb.check_in_date, rb.check_out_date, rb.total_nights,
           rb.special_requests, rb.guest_name, rb.guest_phone,
           u.full_name as seller_name, u.id as seller_id, u.email as seller_email,
           buyer.full_name as buyer_name, buyer.email as buyer_email, buyer.phone as buyer_phone,
           t.seller_delivery_confirmed, t.buyer_delivery_confirmed
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    LEFT JOIN rental_bookings rb ON rb.transaction_id = t.id
    JOIN users u ON t.seller_id = u.id
    JOIN users buyer ON t.buyer_id = buyer.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
");

if (!$transaction_query) {
    debug_log("ERROR: Transaction query failed: " . $conn->error);
    header('Location: dashboard.php');
    exit;
}

$transaction = $transaction_query->fetch_assoc();

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

// Check if both parties have confirmed delivery
$both_confirmed_delivery = ($transaction['seller_delivery_confirmed'] == 1 && $transaction['buyer_delivery_confirmed'] == 1);

if ($pay_remaining_mode && !$both_confirmed_delivery) {
    debug_log("ERROR: Cannot pay remaining - both parties haven't confirmed delivery yet");
    header("Location: transaction.php?id=$transaction_id&error=waiting_for_confirmation");
    exit;
}

$payment_code_type = $pay_remaining_mode ? 'remaining_balance' : 'deposit_buyer';

// Sync payment state safely
try {
    $calc = syncTransactionPaymentState($conn, $transaction_id);
    debug_log("Payment state synced");
} catch (Exception $e) {
    debug_log("Error syncing payment state: " . $e->getMessage());
    $calc = null;
}

if ($pay_remaining_mode) {
    if (!$calc || $calc['remaining_balance'] <= 0) {
        debug_log("No remaining balance to pay");
        header("Location: transaction.php?id=$transaction_id");
        exit;
    }
    $totalPayment = $calc['remaining_balance'];
    $page_title = 'Pay Remaining Balance';
    debug_log("Remaining balance mode - Amount to pay: $totalPayment");
} else {
    // Check if deposit already paid
    $fully_paid = $conn->query("
        SELECT id FROM payments
        WHERE transaction_id = $transaction_id AND type = 'deposit_buyer' AND status = 'confirmed'
        LIMIT 1
    ");
    if ($fully_paid && $fully_paid->num_rows > 0) {
        if ($calc && $calc['payment_status'] === 'fully_paid') {
            header("Location: transaction.php?id=$transaction_id");
            exit;
        }
        if ($calc && $calc['remaining_balance'] > 0 && $both_confirmed_delivery) {
            // Redirect to remaining balance payment
            header("Location: pay_rent.php?transaction_id=$transaction_id&pay=remaining");
            exit;
        }
    }
}

// Get or generate payment code (30 minute expiry for remaining balance too)
$payment_code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND type = '$payment_code_type' AND status = 'pending'
    ORDER BY id DESC LIMIT 1
");

if ($payment_code_data && $payment_code_data->num_rows > 0) {
    $payment_code_row = $payment_code_data->fetch_assoc();
    $payment_code = $payment_code_row['code'];
    $expires_at = $payment_code_row['expires_at'];
    $time_left = strtotime($expires_at) - time();
    debug_log("Existing payment code found: $payment_code, expires in: $time_left seconds");
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    // 30 MINUTES expiry for both deposit AND remaining balance
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $time_left = 1800; // 30 minutes in seconds
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param("siidss", $payment_code, $transaction_id, $totalPayment, $user_id, $payment_code_type, $expires_at);
    $stmt->execute();
    debug_log("Generated new payment code: $payment_code type: $payment_code_type, expires: $expires_at");
}

// Handle manual payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment']) && !$blocked) {
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
            if (in_array('updated_at', $existing_columns)) {
                $conn->query("UPDATE payment_codes SET status = 'used', updated_at = NOW() WHERE code = '$payment_code'");
            } else {
                $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$payment_code'");
            }
            debug_log("Payment code marked as used");
            
            // 2. Record payment
            $payment_type_record = $pay_remaining_mode ? 'remaining_balance' : 'deposit_buyer';
            $stmt = $conn->prepare("
                INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at, created_at) 
                VALUES (?, ?, ?, ?, ?, 'confirmed', NOW(), NOW())
            ");
            $stmt->bind_param("iidss", $transaction_id, $user_id, $totalPayment, $payment_type_record, $payment_code);
            $stmt->execute();
            debug_log("Payment recorded in payments table - Type: $payment_type_record");
            
            if ($pay_remaining_mode) {
                // For remaining balance payment - release full payment to seller
                $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
                
                // Update user balance (seller gets paid)
                $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$transaction['seller_id']}");
                
                // Update transaction as completed
                $conn->query("
                    UPDATE transactions 
                    SET status = 'completed', 
                        completed_at = NOW(),
                        payment_released_at = NOW(),
                        updated_at = NOW()
                    WHERE id = $transaction_id
                ");
                
                // Add wallet transaction record for seller
                $conn->query("
                    INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                    VALUES ({$transaction['seller_id']}, $release_amount, 'deposit', 
                           'Full payment released for: {$transaction['title']}', NOW())
                ");
                
                // Update listing as sold
                if (in_array('availability_status', $existing_columns)) {
                    $conn->query("
                        UPDATE listings 
                        SET availability_status = 'sold',
                            status = 'inactive',
                            sold_at = NOW()
                        WHERE id = {$transaction['listing_id']}
                    ");
                    debug_log("Listing marked as sold");
                }
                
                // Notify seller about payment release
                $release_message = "The remaining balance of " . formatMoney($totalPayment) . " has been paid. Total payment of " . formatMoney($release_amount) . " has been released to your wallet for {$transaction['title']}.";
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                    VALUES (?, '💰 Full Payment Released', ?, 'transaction.php?id=$transaction_id', 0, NOW())
                ");
                $notif_stmt->bind_param("is", $transaction['seller_id'], $release_message);
                $notif_stmt->execute();
                
                debug_log("Remaining balance payment completed - Full amount released to seller");
                
            } else {
                // For deposit payment - update escrow
                $conn->query("UPDATE transactions SET escrow_held = escrow_held + $totalPayment WHERE id = $transaction_id");
                $conn->query("UPDATE transactions SET status = 'escrow_active', escrow_status = 'active' WHERE id = $transaction_id");
                
                // Update booking status if exists
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
                
                // Create escrow record
                $escrow_stmt = $conn->prepare("
                    INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
                    VALUES (?, ?, ?, 'buyer_deposit', 'held', NOW())
                ");
                $escrow_stmt->bind_param("iid", $transaction_id, $user_id, $totalPayment);
                $escrow_stmt->execute();
                debug_log("Escrow record created");
                
                // Schedule auto-release
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
                
                // Create reservation for rental listings
                if ($transaction['type'] == 'rental') {
                    debug_log("Creating reservation for rental listing...");
                    $availabilityManager = new AvailabilityManager($conn);
                    $reservation_result = $availabilityManager->createReservation($transaction_id, [
                        'payment_code' => $payment_code,
                        'reference' => 'DEPOSIT_' . $transaction_id
                    ]);
                    
                    if ($reservation_result['success']) {
                        debug_log("✅ Reservation created successfully!");
                    } else {
                        debug_log("❌ WARNING: Failed to create reservation: " . ($reservation_result['error'] ?? 'Unknown error'));
                    }
                }
                
                // Mark product as reserved
                if ($transaction['type'] == 'product' && in_array('availability_status', $existing_columns)) {
                    $update_listing = $conn->prepare("
                        UPDATE listings 
                        SET availability_status = 'reserved',
                            sold_to_user_id = ?,
                            sold_at = NOW()
                        WHERE id = ? AND (availability_status = 'available' OR availability_status IS NULL)
                    ");
                    $update_listing->bind_param("ii", $user_id, $transaction['listing_id']);
                    $update_listing->execute();
                    debug_log("Product marked as reserved");
                }
                
                // Notify seller about deposit payment
                $notification_message = "💰 Deposit Payment Received!\n\n";
                $notification_message .= "Buyer: {$transaction['buyer_name']}\n";
                $notification_message .= "Item: {$transaction['title']}\n";
                $notification_message .= "Deposit Paid: " . formatMoney($totalPayment) . "\n";
                $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                $notification_message .= "✅ Payment is held in escrow.\n";
                $notification_message .= "📱 Click to view transaction details.";
                
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                    VALUES (?, '💰 Deposit Payment Received', ?, 'transaction.php?id=$transaction_id', 0, NOW())
                ");
                $notif_stmt->bind_param("is", $transaction['seller_id'], $notification_message);
                $notif_stmt->execute();
                debug_log("Seller notification sent");
            }
            
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

<!-- Rest of your HTML/CSS/JS remains exactly the same -->
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
        font-size: 20px;
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
        50% { opacity: 0.7; }
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
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .alert-warning {
        background: #fed7aa;
        color: #9a3412;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
        border-left: 4px solid #f59e0b;
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
    
    @media (max-width: 640px) {
        .payment-code {
            font-size: 28px;
            letter-spacing: 6px;
        }
        .card {
            padding: 20px;
        }
        .timer {
            font-size: 16px;
        }
    }
</style>

<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-credit-card"></i> <?php echo $pay_remaining_mode ? 'Pay Remaining Balance' : 'Complete Payment'; ?></h1>
        <p><?php echo $pay_remaining_mode ? 'Pay the remaining balance to complete your purchase' : 'Pay deposit + service fee to confirm your booking'; ?></p>
    </div>
    
    <?php if ($blocked): ?>
        <div class="card">
            <div class="alert-error" style="text-align: center;">
                <i class="fas fa-ban" style="font-size: 24px; display: block; margin-bottom: 12px;"></i>
                <strong><?php echo $error; ?></strong>
                <p style="margin-top: 12px;">This item has already been purchased by another buyer.</p>
                <a href="browse.php" class="btn-primary" style="display: inline-block; margin-top: 16px; padding: 10px 24px; text-decoration: none; border-radius: 40px; background: var(--primary); color: white;">Browse Other Items</a>
            </div>
        </div>
    <?php elseif ($pay_remaining_mode && !$both_confirmed_delivery): ?>
        <div class="card">
            <div class="alert-warning" style="text-align: center;">
                <i class="fas fa-clock" style="font-size: 24px; display: block; margin-bottom: 12px;"></i>
                <strong>Waiting for Delivery Confirmation</strong>
                <p style="margin-top: 12px;">Both buyer and seller must confirm delivery before you can pay the remaining balance.</p>
                <a href="transaction.php?id=<?php echo $transaction_id; ?>" class="btn-primary" style="display: inline-block; margin-top: 16px; padding: 10px 24px; text-decoration: none; border-radius: 40px; background: var(--primary); color: white;">View Transaction</a>
            </div>
        </div>
    <?php else: ?>
    <div class="card">
        <div class="card-title">
            <i class="fas fa-receipt"></i> <?php echo $pay_remaining_mode ? 'Remaining Balance Summary' : 'Booking Summary'; ?>
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
            
            <div class="price-breakdown">
                <div class="breakdown-row">
                    <span><?php echo ($transaction['type'] == 'rental') ? 'Total Rent' : 'Total Price'; ?></span>
                    <span><?php echo formatMoney($transaction['total_amount']); ?></span>
                </div>
                <?php if (!$pay_remaining_mode): ?>
                <div class="breakdown-row">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <?php endif; ?>
                <div class="breakdown-row total">
                    <span><?php echo $pay_remaining_mode ? 'Remaining Balance to Pay' : 'Total to Pay Today'; ?></span>
                    <span><?php echo formatMoney($totalPayment); ?></span>
                </div>
            </div>
        </div>
        
        <div class="code-box">
            <div class="code-label">Your Telebirr Payment Code</div>
            <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
            <div class="expiry">
                ⏰ Code expires in: <span id="timer" class="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></span>
            </div>
        </div>
        
        <div class="instructions">
            <h4>How to Pay with Telebirr</h4>
            <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your phone</div></div>
            <div class="step"><div class="step-number">2</div><div>Go to Marketplace / Payment section</div></div>
            <div class="step"><div class="step-number">3</div><div>Enter this code: <strong style="font-size: 18px; color: var(--primary);"><?php echo $payment_code; ?></strong></div></div>
            <div class="step"><div class="step-number">4</div><div>Confirm payment with your Telebirr PIN</div></div>
            <div class="step"><div class="step-number">5</div><div><strong>Then click the green button below</strong> to complete the payment</div></div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="confirm-payment-box" id="confirmPaymentBox">
            <h4><i class="fas fa-check-circle"></i> Step 2: Confirm Payment</h4>
            <p>After paying in the Telebirr app, click the button below to complete the process.</p>
            <p style="font-size: 12px; margin-top: 8px;">Test PIN: <strong>1234</strong></p>
            <button type="button" id="confirmPayBtn" class="confirm-pay-btn" onclick="confirmPaymentManually()">
                <i class="fas fa-check-double"></i> I Have Paid — Confirm Payment
            </button>
            <p id="confirmPayError" class="confirm-pay-error"></p>
        </div>

        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 12px; font-weight: 500;">Waiting for payment confirmation...</p>
            <p style="font-size: 12px; color: var(--gray); margin-top: 8px;">
                <i class="fas fa-clock"></i> This page will auto-update once payment is confirmed
            </p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const transactionId = <?php echo $transaction_id; ?>;
const isRemainingMode = <?php echo $pay_remaining_mode ? 'true' : 'false'; ?>;
let checkInterval;
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
    notification.innerHTML = '<i class="fas fa-check-circle"></i> ✅ Code copied: ' + paymentCode;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 2000);
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(checkInterval);
        document.getElementById('paymentStatus').innerHTML = `
            <div style="color: #ef4444;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                <p style="font-weight: 700; font-size: 18px;">Payment Code Expired</p>
                <p>Your payment code has expired. Please go back and request a new code.</p>
                <a href="transaction.php?id=${transactionId}" class="btn-primary" style="display: inline-block; margin-top: 16px; padding: 10px 24px; text-decoration: none; border-radius: 40px; background: #667eea; color: white;">Go Back</a>
            </div>
        `;
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    const timerSpan = document.getElementById('timer');
    if (timerSpan) {
        timerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        
        if (timeLeft < 60) {
            timerSpan.classList.add('danger');
        } else if (timeLeft < 300) {
            timerSpan.classList.add('warning');
        }
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
        <p>${isRemainingMode ? 'Full payment completed!' : 'Deposit payment confirmed!'}</p>
        <p style="margin-top: 8px;">Redirecting to your transaction...</p>
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
        const response = await fetch('/broker_system/api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ 
                payment_code: paymentCode, 
                pin: '1234',
                payment_type: isRemainingMode ? 'remaining_balance' : 'deposit_buyer'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showPaymentSuccess();
        } else {
            errEl.textContent = data.error || 'Confirmation failed. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-double"></i> I Have Paid — Confirm Payment';
        }
    } catch (error) {
        console.error('Error:', error);
        errEl.textContent = 'Network error. Please check your connection and try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> I Have Paid — Confirm Payment';
    }
}

function checkPaymentStatus() {
    fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode, { 
        credentials: 'same-origin',
        cache: 'no-store'
    })
    .then(response => response.json())
    .then(data => {
        if (data.confirmed === true || data.is_paid === true) {
            showPaymentSuccess();
        }
    })
    .catch(error => console.error('Polling error:', error));
}

// Start timers
timerInterval = setInterval(updateTimer, 1000);
checkInterval = setInterval(checkPaymentStatus, 3000);

const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>