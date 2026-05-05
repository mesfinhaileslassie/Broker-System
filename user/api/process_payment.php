<?php
// user/api/process_payment.php - Process payment (NO PIN CHECK)

session_start();
require_once '../../config/database.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$user_id = $_SESSION['user_id'];

if (!$code) {
    echo json_encode(['success' => false, 'error' => 'Missing payment code']);
    exit;
}

$conn = getDbConnection();

// Get payment code details
$payment = $conn->query("
    SELECT pc.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
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

$amount = $payment['amount'];
$transaction_id = $payment['transaction_id'];

$conn->begin_transaction();

try {
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment
    $payment_type = ($payment['user_id'] == $payment['buyer_id']) ? 'deposit_buyer' : 'deposit_seller';
    $stmt = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
    $stmt->bind_param("iidss", $transaction_id, $payment['user_id'], $amount, $payment_type, $code);
    $stmt->execute();
    
    // Update escrow
    $conn->query("UPDATE transactions SET escrow_held = escrow_held + $amount WHERE id = $transaction_id");
    
    // Check if both deposits are complete
    $txn = $conn->query("SELECT escrow_held, deposit_amount, commission_amount FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    $required = $txn['deposit_amount'] * 2 + $txn['commission_amount'];
    
    if ($txn['escrow_held'] >= $required) {
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'transaction_id' => $transaction_id
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>