<?php
// api/generate_code.php - Generate code in session (no database)

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$amount = floatval($input['amount'] ?? 0);
$payment_type = $input['payment_type'] ?? 'deposit_buyer';
$listing_id = intval($input['listing_id'] ?? 0);

if (!$amount || !$listing_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Generate 5-digit code
$code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

// Store in session instead of database
$_SESSION['temp_payment'] = [
    'code' => $code,
    'amount' => $amount,
    'listing_id' => $listing_id,
    'payment_type' => $payment_type,
    'created_at' => time(),
    'expires_at' => time() + 600 // 10 minutes
];

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => $amount,
    'amount_display' => number_format($amount, 2) . ' ETB',
    'expires_in' => 600
]);
?>