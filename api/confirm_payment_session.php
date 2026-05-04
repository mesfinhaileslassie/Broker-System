<?php
// api/confirm_payment_session.php - Confirm payment

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Missing payment code']);
    exit;
}

// Check session
if (!isset($_SESSION['payment_code']) || $_SESSION['payment_code'] !== $code) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

// Check expiry
if ($_SESSION['payment_expires'] < time()) {
    unset($_SESSION['payment_code']);
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

// Verify PIN
if ($pin != '1234') {
    echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234']);
    exit;
}

$amount = $_SESSION['payment_amount'];

// Clear session after successful payment
unset($_SESSION['payment_code']);
unset($_SESSION['payment_amount']);
unset($_SESSION['payment_expires']);

echo json_encode([
    'success' => true,
    'message' => 'Payment confirmed successfully',
    'amount' => $amount
]);
?>