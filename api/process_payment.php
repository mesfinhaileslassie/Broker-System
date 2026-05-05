<?php
// api/process_payment.php - Process payment and activate listing

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
           l.id as listing_id, l.title, l.seller_id as listing_seller_id
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

// Get user by phone
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
$listing_id = $payment['listing_id'];
$is_seller = ($user['id'] == $payment['listing_seller_id']);

$conn->begin_transaction();

try {
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment
    $payment_type_record = ($is_seller) ? 'deposit_seller' : 'deposit_buyer';
    $stmt2 = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
    $stmt2->bind_param("iidss", $transaction_id, $user['id'], $amount, $payment_type_record, $code);
    $stmt2->execute();
    
    // Update escrow in transaction
    $conn->query("UPDATE transactions SET escrow_held = escrow_held + $amount WHERE id = $transaction_id");
    
    // Get updated transaction data
    $txn_check = $conn->query("SELECT escrow_held, deposit_amount, commission_amount FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    $required = $txn_check['deposit_amount'] * 2 + $txn_check['commission_amount'];
    
    // Update transaction status
    if ($txn_check['escrow_held'] >= $required) {
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
        
        // CRITICAL: Update listing status to ACTIVE when seller pays
        // This is the key fix - activate the listing
        $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'transaction_id' => $transaction_id,
        'item_name' => $payment['title'],
        'status' => 'success',
        'message' => 'Payment successful!'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>