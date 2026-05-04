<?php
// api/confirm_payment.php - Confirm payment after PIN

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/auth.php';

session_start();

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$user_phone = $input['user_phone'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code) || empty($user_phone)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();

// Get payment code details
$stmt = $conn->prepare("
    SELECT pc.*, t.buyer_id, t.seller_id, t.id as transaction_id, t.total_amount
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
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
$stmt2 = $conn->prepare("SELECT id, full_name, pin FROM users WHERE phone = ?");
$stmt2->bind_param("s", $user_phone);
$stmt2->execute();
$user = $stmt2->get_result()->fetch_assoc();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Verify PIN (for simulation, accept 1234 or actual PIN)
if ($pin != '1234' && $pin != $user['pin']) {
    echo json_encode(['success' => false, 'error' => 'Incorrect PIN']);
    exit;
}

// Process payment
$conn->begin_transaction();

try {
    // Mark code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment
    $payment_type = $payment['type'];
    $stmt3 = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
    $stmt3->bind_param("iidss", $payment['transaction_id'], $user['id'], $payment['amount'], $payment['type'], $code);
    $stmt3->execute();
    
    // Update transaction escrow
    $conn->query("UPDATE transactions SET escrow_held = escrow_held + {$payment['amount']} WHERE id = {$payment['transaction_id']}");
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment confirmed successfully',
        'amount' => $payment['amount'],
        'transaction_id' => $payment['transaction_id']
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment processing failed: ' . $e->getMessage()]);
}

$conn->close();
?>