<?php
// api/generate_code_session.php - Generate code and store in session

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

// Generate 5-digit code
$code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

// Store in session
$_SESSION['payment_code'] = $code;
$_SESSION['payment_amount'] = 500.00;
$_SESSION['payment_expires'] = time() + 600; // 10 minutes

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => 500.00,
    'amount_display' => '500.00 ETB',
    'expires_in' => 600
]);
?>