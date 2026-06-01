<?php
// user/api/check_payment_status.php - Complete payment confirmation with escrow

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/escrow_functions.php';
require_once '../../includes/transaction_workflow.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['confirmed' => false, 'error' => 'Unauthorized']);
    exit;
}

$code = $_GET['code'] ?? '';

if (!$code) {
    echo json_encode(['confirmed' => false, 'error' => 'No code provided']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Check if payment exists and is confirmed
$payment = $conn->query("
    SELECT p.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount, 
           t.deposit_amount, t.commission_amount, t.escrow_held, t.escrow_status,
           l.title, l.type AS listing_type
    FROM payments p
    JOIN transactions t ON p.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE p.telebirr_code_5digit = '$code' 
    AND p.status = 'confirmed'
    LIMIT 1
")->fetch_assoc();

$confirmed = ($payment !== null);

if ($confirmed && $payment) {
    syncTransactionPaymentState($conn, (int) $payment['transaction_id']);
}

if ($confirmed) {
    // Check if escrow already exists
    $escrow_exists = $conn->query("
        SELECT id FROM escrow_accounts 
        WHERE transaction_id = {$payment['transaction_id']} AND status = 'held'
    ")->num_rows > 0;
    
    if (!$escrow_exists) {
        // Create escrow record
        $conn->query("
            INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
            VALUES ({$payment['transaction_id']}, {$payment['user_id']}, {$payment['amount']}, 'buyer_deposit', 'held', NOW())
        ");
        
        // Update transaction escrow
        $new_escrow_held = $payment['escrow_held'] + $payment['amount'];
        $conn->query("
            UPDATE transactions 
            SET escrow_held = $new_escrow_held,
                escrow_status = 'active',
                status = 'escrow_active',
                updated_at = NOW()
            WHERE id = {$payment['transaction_id']}
        ");
        
        // Schedule auto-release based on listing type
        $listing_type = $payment['listing_type'] ?? 'product';
        $auto_days = ($listing_type === 'rental') ? 14 : (($listing_type === 'product') ? 5 : 10);
        $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
        
        $conn->query("
            INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
            VALUES ({$payment['transaction_id']}, '$release_date', 'pending')
            ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
        ");
        
        $conn->query("
            UPDATE transactions 
            SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
            WHERE id = {$payment['transaction_id']}
        ");
        
        // Add timeline
        addTransactionTimeline($conn, $payment['transaction_id'], 'payment_confirmed', 
            "Payment of " . formatMoney($payment['amount']) . " confirmed. Escrow activated.", $user_id);
        
        // Notify seller
        $seller_msg = "💰 Payment Received!\n\nItem: {$payment['title']}\nAmount: " . formatMoney($payment['amount']) . "\n\nThe payment is held securely in escrow. Please prepare for delivery.";
        $conn->query("
            INSERT INTO notifications (user_id, title, message, link, created_at) 
            VALUES ({$payment['seller_id']}, '💰 Payment Received', '$seller_msg', '/broker_system/user/transaction.php?id={$payment['transaction_id']}', NOW())
        ");
    }
}

$conn->close();

echo json_encode(['confirmed' => $confirmed]);
?>