<?php
// user/api/pay_remaining.php - Initiate / summarize seller remaining balance payment

session_start();
header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/seller_listing_payment.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$listing_id = (int) ($input['listing_id'] ?? $_GET['listing_id'] ?? 0);
$action = $input['action'] ?? ($_GET['action'] ?? 'summary');

if ($listing_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid listing ID']);
    $conn->close();
    exit;
}

$info = getSellerListingPaymentInfo($conn, $listing_id, $user_id);

if (!$info) {
    echo json_encode(['success' => false, 'error' => 'Listing not found or access denied']);
    $conn->close();
    exit;
}

$summary = [
    'listing_id' => $info['listing_id'],
    'total_price' => $info['total_price'],
    'total_price_formatted' => formatMoney($info['total_price']),
    'deposit_paid' => $info['deposit_paid'],
    'deposit_paid_formatted' => formatMoney($info['deposit_paid']),
    'amount_paid' => $info['amount_paid'],
    'remaining_balance' => $info['remaining_balance'],
    'remaining_balance_formatted' => formatMoney($info['remaining_balance']),
    'payment_status' => $info['payment_status'],
    'can_pay_remaining' => $info['can_pay_remaining'],
    'is_fully_paid' => $info['payment_status'] === 'fully_paid',
];

if ($action === 'summary' || $method === 'GET') {
    echo json_encode(['success' => true, 'summary' => $summary]);
    $conn->close();
    exit;
}

if ($action !== 'initiate' || $method !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    $conn->close();
    exit;
}

if (!$info['can_pay_remaining']) {
    echo json_encode([
        'success' => false,
        'error' => $info['payment_status'] === 'fully_paid'
            ? 'This listing is already fully paid'
            : 'Remaining balance cannot be paid at this time',
        'summary' => $summary,
    ]);
    $conn->close();
    exit;
}

// Prevent duplicate confirmed remaining payment
$dup = $conn->query("
    SELECT id FROM payments
    WHERE transaction_id = {$info['transaction_id']}
      AND type = 'remaining_balance'
      AND status = 'confirmed'
    LIMIT 1
");
if ($dup && $dup->num_rows > 0 && $info['remaining_balance'] <= 0) {
    echo json_encode(['success' => false, 'error' => 'Remaining balance already paid', 'summary' => $summary]);
    $conn->close();
    exit;
}

// Reuse existing pending code if valid
$existing = $conn->query("
    SELECT code, amount,
           TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
    FROM payment_codes
    WHERE transaction_id = {$info['transaction_id']}
      AND user_id = {$user_id}
      AND type = 'remaining_balance'
      AND status = 'pending'
      AND expires_at > NOW()
    ORDER BY id DESC
    LIMIT 1
");

if ($existing && $existing->num_rows > 0) {
    $code_row = $existing->fetch_assoc();
    echo json_encode([
        'success' => true,
        'message' => 'Existing payment code retrieved',
        'payment_code' => $code_row['code'],
        'amount' => (float) $code_row['amount'],
        'amount_formatted' => formatMoney($code_row['amount']),
        'seconds_remaining' => max(0, (int) $code_row['seconds_remaining']),
        'pay_url' => '/broker_system/user/pay_remaining.php?listing_id=' . $listing_id,
        'summary' => $summary,
    ]);
    $conn->close();
    exit;
}

$amount = $info['remaining_balance'];
if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'No remaining balance due', 'summary' => $summary]);
    $conn->close();
    exit;
}

do {
    $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $code_check = $conn->prepare('SELECT id FROM payment_codes WHERE code = ? LIMIT 1');
    $code_check->bind_param('s', $payment_code);
    $code_check->execute();
    $exists = $code_check->get_result()->num_rows > 0;
    $code_check->close();
} while ($exists);

$stmt = $conn->prepare("
    INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
    VALUES (?, ?, ?, ?, 'remaining_balance', DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending', NOW())
");
$stmt->bind_param('sidi', $payment_code, $info['transaction_id'], $amount, $user_id);
$stmt->execute();
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'Payment code generated',
    'payment_code' => $payment_code,
    'amount' => $amount,
    'amount_formatted' => formatMoney($amount),
    'seconds_remaining' => 1800,
    'pay_url' => '/broker_system/user/pay_remaining.php?listing_id=' . $listing_id,
    'summary' => $summary,
]);

$conn->close();
