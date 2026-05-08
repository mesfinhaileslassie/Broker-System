<?php
// api/generate_payment_code.php - Generate Telebirr Payment Code

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Please login to continue']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = isset($input['transaction_id']) ? intval($input['transaction_id']) : 0;
$amount = isset($input['amount']) ? floatval($input['amount']) : 0;
$payment_type = isset($input['payment_type']) ? htmlspecialchars($input['payment_type']) : 'deposit_buyer';
$user_id = $_SESSION['user_id'];

if (!$transaction_id || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction details']);
    exit;
}

$conn = getDbConnection();

// Verify transaction belongs to user
$check = $conn->query("
    SELECT t.*, l.title, l.type 
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
");

if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

$transaction = $check->fetch_assoc();

// Check if payment code already exists and is still valid
$existing = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'pending'
");

if ($existing->num_rows > 0) {
    $code_data = $existing->fetch_assoc();
    if (strtotime($code_data['expires_at']) > time()) {
        echo json_encode([
            'success' => true,
            'payment_code' => $code_data['code'],
            'amount' => $amount,
            'amount_formatted' => formatMoney($amount),
            'item_name' => $transaction['title'],
            'expires_at' => $code_data['expires_at'],
            'already_exists' => true
        ]);
        exit;
    }
}

// Generate unique 5-digit payment code
do {
    $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
} while ($code_check->num_rows > 0);

$expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));

// Insert payment code
$stmt = $conn->prepare("
    INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->bind_param("siidss", $payment_code, $transaction_id, $amount, $user_id, $payment_type, $expires_at);
$stmt->execute();

$conn->close();

echo json_encode([
    'success' => true,
    'payment_code' => $payment_code,
    'amount' => $amount,
    'amount_formatted' => formatMoney($amount),
    'item_name' => $transaction['title'],
    'expires_at' => $expires_at,
    'expires_in' => 1800
]);
?>