<?php
// api/verify_simple.php - Read from DATABASE (REAL SYSTEM)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';

if (empty($code) || strlen($code) != 5 || !ctype_digit($code)) {
    echo json_encode(['success' => false, 'error' => 'Invalid code format. Must be 5 digits.']);
    exit;
}

$conn = getDbConnection();

// Check in payment_codes table
$stmt = $conn->prepare("
    SELECT pc.*, l.title as item_name 
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // Check if code exists but not pending
    $check = $conn->prepare("SELECT status FROM payment_codes WHERE code = ?");
    $check->bind_param("s", $code);
    $check->execute();
    $status_result = $check->get_result();
    
    if ($status_result->num_rows > 0) {
        $status_data = $status_result->fetch_assoc();
        echo json_encode(['success' => false, 'error' => 'Code already ' . $status_data['status']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid payment code. Please generate a code from your listing page.']);
    }
    exit;
}

$payment = $result->fetch_assoc();

// Check if expired
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Code expired. Please generate a new code.']);
    exit;
}

echo json_encode([
    'success' => true,
    'amount' => floatval($payment['amount']),
    'amount_display' => number_format($payment['amount'], 2) . ' ETB',
    'item_name' => $payment['item_name']
]);

$conn->close();
?>