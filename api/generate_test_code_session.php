<?php
// api/generate_test_code_session.php - Generate test code in session

session_start();
header('Content-Type: application/json');

// Generate a 5-digit code
$code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

// Store in session
$_SESSION['temp_payment'] = [
    'code' => $code,
    'amount' => 500.00,
    'listing_id' => 1,
    'payment_type' => 'test',
    'created_at' => time(),
    'expires_at' => time() + 600
];

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => 500.00,
    'message' => 'Test code generated. Use this code in Telebirr app.'
]);
?>