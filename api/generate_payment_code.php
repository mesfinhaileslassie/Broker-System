<?php
// api/generate_payment_code.php - Generate Telebirr payment code (10-minute expiry)

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_code.php';

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Please login to continue']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$transaction_id = isset($input['transaction_id']) ? (int) $input['transaction_id'] : 0;
$amount = isset($input['amount']) ? (float) $input['amount'] : 0;
$payment_type = isset($input['payment_type']) ? preg_replace('/[^a-z_]/', '', (string) $input['payment_type']) : 'deposit_buyer';
$user_id = (int) $_SESSION['user_id'];

if ($transaction_id <= 0 || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction details']);
    exit;
}

$allowed_types = ['deposit_buyer', 'deposit_seller', 'remaining_balance', 'full_payment'];
if (!in_array($payment_type, $allowed_types, true)) {
    $payment_type = 'deposit_buyer';
}

$conn = getDbConnection();
ensurePaymentCodeTimezone($conn);

$check = $conn->query("
    SELECT t.*, l.title, l.type
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
");

if (!$check || $check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    $conn->close();
    exit;
}

$transaction = $check->fetch_assoc();
$code_row = getOrCreatePaymentCode($conn, $transaction_id, $user_id, $amount, $payment_type);
$conn->close();

echo json_encode([
    'success' => true,
    'payment_code' => $code_row['code'],
    'amount' => $amount,
    'amount_formatted' => formatMoney($amount),
    'item_name' => $transaction['title'],
    'seconds_remaining' => $code_row['seconds_remaining'],
    'expires_in' => $code_row['seconds_remaining'],
    'expiry_minutes' => PAYMENT_CODE_EXPIRY_MINUTES,
]);
