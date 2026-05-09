<?php
// api/telebirr_callback.php - Telebirr payment callback (CRITICAL)

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);

// Validate callback data
$payment_code = isset($input['code']) ? preg_replace('/[^0-9]/', '', $input['code']) : '';
$transaction_id = isset($input['transaction_id']) ? intval($input['transaction_id']) : 0;
$amount = isset($input['amount']) ? floatval($input['amount']) : 0;
$status = isset($input['status']) ? $input['status'] : '';

if (empty($payment_code) || $status !== 'success') {
    echo json_encode(['success' => false, 'error' => 'invalid_callback']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// Start atomic transaction
$conn->begin_transaction();

try {
    // Get payment code details
    $code_data = $conn->query("
        SELECT pc.id, pc.transaction_id, pc.user_id, pc.amount,
               t.listing_id, t.seller_id
        FROM payment_codes pc
        JOIN transactions t ON pc.transaction_id = t.id
        WHERE pc.code = '$payment_code' 
        AND pc.status = 'pending'
        LIMIT 1
    ");
    
    if ($code_data->num_rows === 0) {
        throw new Exception("Payment code not found or already used");
    }
    
    $data = $code_data->fetch_assoc();
    
    // Verify amount matches
    if (abs($data['amount'] - $amount) > 0.01) {
        throw new Exception("Amount mismatch");
    }
    
    // Insert payment record
    $stmt = $conn->prepare("
        INSERT INTO payments (
            transaction_id, user_id, amount, type, 
            telebirr_code_5digit, status, confirmed_at, created_at
        ) VALUES (?, ?, ?, 'deposit_seller', ?, 'confirmed', NOW(), NOW())
    ");
    $stmt->bind_param("iids", $data['transaction_id'], $data['user_id'], $amount, $payment_code);
    $stmt->execute();
    
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$data['id']}");
    
    // Update transaction status
    $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$data['transaction_id']}");
    
    // Activate listing
    $conn->query("UPDATE listings SET status = 'active' WHERE id = {$data['listing_id']}");
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment confirmed',
        'listing_activated' => true
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>