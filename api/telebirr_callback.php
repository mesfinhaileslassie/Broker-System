<?php
// api/telebirr_callback.php - Telebirr payment callback

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once '../config/database.php';
require_once '../includes/payment_confirm.php';

date_default_timezone_set('Africa/Addis_Ababa');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$payment_code = isset($input['code']) ? preg_replace('/[^0-9]/', '', $input['code']) : '';
$amount = isset($input['amount']) ? (float) $input['amount'] : 0;
$status = $input['status'] ?? '';

if ($payment_code === '' || $status !== 'success') {
    echo json_encode(['success' => false, 'error' => 'invalid_callback']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$check = $conn->query("
    SELECT amount FROM payment_codes
    WHERE code = '$payment_code' AND status = 'pending'
    LIMIT 1
")->fetch_assoc();

if ($check && $amount > 0 && abs((float) $check['amount'] - $amount) > 0.01) {
    echo json_encode(['success' => false, 'error' => 'Amount mismatch']);
    $conn->close();
    exit;
}

$result = confirmPaymentByCode($conn, $payment_code, [
    'amount' => $amount > 0 ? $amount : null,
]);

$conn->close();
echo json_encode($result);
