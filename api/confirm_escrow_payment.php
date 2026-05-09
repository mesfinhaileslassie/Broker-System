<?php
// ============================================
// FILE: api/confirm_escrow_payment.php
// ============================================
// Confirm Telebirr payment and activate escrow

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/escrow_functions.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Payment code required']);
    exit;
}

// Verify PIN (simulation)
if ($pin != '1234') {
    echo json_encode(['success' => false, 'error' => 'Invalid PIN. Use 1234 for testing']);
    exit;
}

$conn = getDbConnection();

// Find payment code
$payment = $conn->query("
    SELECT pc.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount,
           l.title, l.type
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = '$code' AND pc.status = 'pending'
")->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

// Check expiry
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

$conn->begin_transaction();

try {
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment
    $stmt = $conn->prepare("
        INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at, created_at) 
        VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW(), NOW())
    ");
    $stmt->bind_param("iids", $payment['transaction_id'], $payment['user_id'], $payment['amount'], $code);
    $stmt->execute();
    
    // Update transaction escrow
    $conn->query("
        UPDATE transactions 
        SET escrow_held = escrow_held + {$payment['amount']},
            escrow_status = 'active',
            status = 'escrow_active',
            updated_at = NOW()
        WHERE id = {$payment['transaction_id']}
    ");
    
    // Initialize escrow account
    $stmt2 = $conn->prepare("
        INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
        VALUES (?, ?, ?, 'buyer_deposit', 'held', NOW())
    ");
    $stmt2->bind_param("iid", $payment['transaction_id'], $payment['user_id'], $payment['amount']);
    $stmt2->execute();
    
    // Schedule auto-release based on listing type
    $auto_days = 7;
    if ($payment['type'] == 'rental') $auto_days = 14;
    if ($payment['type'] == 'product') $auto_days = 5;
    if ($payment['type'] == 'job') $auto_days = 10;
    
    $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
    $conn->query("
        INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
        VALUES ({$payment['transaction_id']}, '$release_date', 'pending')
    ");
    
    $conn->query("
        UPDATE transactions 
        SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
        WHERE id = {$payment['transaction_id']}
    ");
    
    // Add timeline
    addTransactionTimeline($conn, $payment['transaction_id'], 'payment_confirmed', 
        "Payment of " . formatMoney($payment['amount']) . " confirmed. Escrow activated.", $payment['user_id']);
    
    // Create notification for seller
    $notif_stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, created_at) 
        VALUES (?, 'Payment Received', 'Buyer has paid for: {$payment['title']}. Escrow is now active. Please proceed with delivery.', NOW())
    ");
    $notif_stmt->bind_param("i", $payment['seller_id']);
    $notif_stmt->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment confirmed! Escrow is now active.',
        'transaction_id' => $payment['transaction_id'],
        'auto_release_days' => $auto_days
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>