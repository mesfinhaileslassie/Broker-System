<?php
// api/verify_code.php - Verify payment code

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';

// Validate input
if (empty($code) || strlen($code) != 5 || !ctype_digit($code)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid payment code format. Must be 5 digits.'
    ]);
    exit;
}

$conn = getDbConnection();

// Check payment code in database
$stmt = $conn->prepare("
    SELECT pc.*, t.total_amount, l.title as item_name, u.full_name as user_name
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON pc.user_id = u.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // Also check if expired
    $checkExpired = $conn->prepare("SELECT * FROM payment_codes WHERE code = ? AND status = 'expired'");
    $checkExpired->bind_param("s", $code);
    $checkExpired->execute();
    $expiredResult = $checkExpired->get_result();
    
    if ($expiredResult->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'Payment code has expired']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    }
    exit;
}

$payment = $result->fetch_assoc();

// Check if expired
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Payment code has expired']);
    exit;
}

// Return success with payment details
echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => floatval($payment['amount']),
    'amount_display' => number_format($payment['amount'], 2) . ' ETB',
    'item_name' => $payment['item_name'],
    'user_name' => $payment['user_name'],
    'expires_at' => $payment['expires_at']
]);

$conn->close();
?>