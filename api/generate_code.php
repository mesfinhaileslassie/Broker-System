<?php
// api/generate_code.php - Generate 5-digit payment code

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/auth.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['user_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Please login first']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = intval($input['transaction_id'] ?? 0);
$amount = floatval($input['amount'] ?? 0);
$payment_type = $input['payment_type'] ?? 'deposit_buyer';

if (!$transaction_id || !$amount) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();

// Verify transaction belongs to user
$check = $conn->query("
    SELECT t.*, l.title 
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
");

if ($check->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

$transaction = $check->fetch_assoc();

// Generate unique 5-digit code
do {
    $code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $check_code = $conn->query("SELECT id FROM payment_codes WHERE code = '$code'");
} while ($check_code->num_rows > 0);

$expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// Save payment code
$stmt = $conn->prepare("INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sidiss", $code, $transaction_id, $amount, $user_id, $payment_type, $expires_at);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'payment_code' => $code,
        'amount' => $amount,
        'amount_display' => number_format($amount, 2) . ' ETB',
        'expires_in' => 600,
        'transaction_id' => $transaction_id,
        'item_name' => $transaction['title']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to generate payment code: ' . $conn->error]);
}

$conn->close();
?>