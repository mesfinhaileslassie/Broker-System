<?php
// api/verify_code_session.php - Verify code from session

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['success' => false, 'error' => 'Invalid code format']);
    exit;
}

// Check if code exists in session
if (!isset($_SESSION['payment_code']) || $_SESSION['payment_code'] !== $code) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

// Check if expired
if (isset($_SESSION['payment_expires']) && $_SESSION['payment_expires'] < time()) {
    unset($_SESSION['payment_code']);
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => $_SESSION['payment_amount'],
    'amount_display' => number_format($_SESSION['payment_amount'], 2) . ' ETB',
    'merchant' => 'Ethio Brokerplace'
]);
?>