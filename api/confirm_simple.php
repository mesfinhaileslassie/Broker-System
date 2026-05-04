<?php
// api/confirm_simple.php - Confirm payment using DATABASE

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'No code provided']);
    exit;
}

if ($pin != '1234') {
    echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234']);
    exit;
}

$conn = getDbConnection();

// Get the payment code
$stmt = $conn->prepare("
    SELECT pc.*, t.listing_id, l.seller_id
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
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
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

$amount = $payment['amount'];
$listing_id = $payment['listing_id'];

// Process payment - update listing to active
$conn->begin_transaction();

try {
    // Update listing status to active
    $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
    
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment in payments table
    $stmt2 = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, 'telebirr_payment', ?, 'confirmed', NOW())");
    $stmt2->bind_param("iids", $payment['transaction_id'], $payment['user_id'], $amount, $code);
    $stmt2->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'message' => 'Payment confirmed! Your listing is now active.'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>