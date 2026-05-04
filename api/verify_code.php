<?php
// api/verify_code.php - Verify code from session (no database)

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['success' => false, 'error' => 'Invalid code format']);
    exit;
}

// Check session for the code
if (!isset($_SESSION['temp_payment']) || $_SESSION['temp_payment']['code'] !== $code) {
    echo json_encode(['success' => false, 'error' => 'Invalid or expired payment code']);
    exit;
}

// Check if expired
if ($_SESSION['temp_payment']['expires_at'] < time()) {
    unset($_SESSION['temp_payment']);
    echo json_encode(['success' => false, 'error' => 'Payment code expired']);
    exit;
}

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => $_SESSION['temp_payment']['amount'],
    'amount_display' => number_format($_SESSION['temp_payment']['amount'], 2) . ' ETB',
    'listing_id' => $_SESSION['temp_payment']['listing_id']
]);
?>