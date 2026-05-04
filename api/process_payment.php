<?php
// api/process_payment.php - Complete fixed version

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$user_phone = $input['user_phone'] ?? '';
$payment_type = $input['payment_type'] ?? 'deposit';

if (empty($code) || empty($user_phone)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();

// Get payment code details
$stmt = $conn->prepare("
    SELECT pc.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount, 
           t.deposit_amount, t.commission_amount, t.remaining_balance,
           l.title
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid or expired payment code']);
    exit;
}

$payment = $result->fetch_assoc();

// Check expiry
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Payment code expired']);
    exit;
}

// Get user
$user_stmt = $conn->prepare("SELECT id, full_name FROM users WHERE phone = ?");
$user_stmt->bind_param("s", $user_phone);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

$amount = $payment['amount'];
$transaction_id = $payment['transaction_id'];

$conn->begin_transaction();

try {
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Update escrow in transaction
    $conn->query("UPDATE transactions SET escrow_held = escrow_held + $amount WHERE id = $transaction_id");
    
    // Record payment
    $payment_type_record = $payment['type'];
    $stmt2 = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
    $stmt2->bind_param("iidss", $transaction_id, $user['id'], $amount, $payment_type_record, $code);
    $stmt2->execute();
    
    // Get updated transaction data
    $txn_check = $conn->query("SELECT escrow_held, deposit_amount, commission_amount, buyer_id, seller_id FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    $required = $txn_check['deposit_amount'] * 2 + $txn_check['commission_amount'];
    
    // Update status based on escrow amount
    $new_status = '';
    if ($txn_check['escrow_held'] >= $required) {
        $new_status = 'deposits_complete';
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
    } else {
        // Determine which deposit is still needed
        $buyer_paid = $conn->query("SELECT SUM(amount) as total FROM payments WHERE transaction_id = $transaction_id AND type IN ('deposit_buyer', 'commission') AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0;
        $seller_paid = $conn->query("SELECT SUM(amount) as total FROM payments WHERE transaction_id = $transaction_id AND type = 'deposit_seller' AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0;
        
        if ($buyer_paid < $txn_check['deposit_amount'] + $txn_check['commission_amount']) {
            $new_status = 'awaiting_buyer_deposit';
            $conn->query("UPDATE transactions SET status = 'awaiting_buyer_deposit' WHERE id = $transaction_id");
        } elseif ($seller_paid < $txn_check['deposit_amount']) {
            $new_status = 'awaiting_seller_deposit';
            $conn->query("UPDATE transactions SET status = 'awaiting_seller_deposit' WHERE id = $transaction_id");
        }
    }
    
    // Get transaction for response
    $transaction = $conn->query("
        SELECT t.*, l.title, u.full_name as seller_name 
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        JOIN users u ON t.seller_id = u.id
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    $conn->commit();
    
    $message = ($new_status == 'deposits_complete') 
        ? "✓ Payment successful! Both deposits are complete. Please proceed to legal confirmation."
        : "✓ Payment successful! Your payment of " . number_format($amount, 2) . " ETB has been received.";
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'transaction_id' => $transaction_id,
        'item_name' => $transaction['title'],
        'seller_name' => $transaction['seller_name'],
        'status' => $new_status ?: 'partial',
        'message' => $message,
        'receipt' => [
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'date' => date('Y-m-d H:i:s'),
            'payment_code' => $code
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>