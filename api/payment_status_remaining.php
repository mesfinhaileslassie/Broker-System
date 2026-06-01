<?php
// api/payment_status_remaining.php - Poll remaining balance payment status

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';
require_once '../includes/seller_listing_payment.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$listing_id = isset($_GET['listing_id']) ? (int) $_GET['listing_id'] : 0;
$user_id = (int) $_SESSION['user_id'];

if (empty($code) && $listing_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'code or listing_id required']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$where = $code ? "pc.code = '$code' AND pc.user_id = $user_id" : "pc.transaction_id IN (
    SELECT id FROM transactions WHERE listing_id = $listing_id AND seller_id = $user_id LIMIT 1
) AND pc.user_id = $user_id AND pc.type = 'remaining_balance'";

$result = $conn->query("
    SELECT
        pc.code,
        pc.status AS code_status,
        pc.amount,
        pc.transaction_id,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) AS seconds_remaining,
        EXISTS(
            SELECT 1 FROM payments p
            WHERE p.telebirr_code_5digit = pc.code
              AND p.type = 'remaining_balance'
              AND p.status = 'confirmed'
        ) AS is_paid,
        t.listing_id
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    WHERE $where
      AND pc.type = 'remaining_balance'
    ORDER BY pc.id DESC
    LIMIT 1
");

if (!$result || $result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Payment code not found']);
    $conn->close();
    exit;
}

$data = $result->fetch_assoc();
$listing_id = (int) $data['listing_id'];
$info = getSellerListingPaymentInfo($conn, $listing_id, $user_id);

$is_paid = (bool) $data['is_paid'];
$is_expired = ((int) $data['seconds_remaining']) <= 0 && !$is_paid;

$response = [
    'success' => true,
    'code' => $data['code'],
    'is_paid' => $is_paid,
    'is_expired' => $is_expired,
    'seconds_remaining' => max(0, (int) $data['seconds_remaining']),
    'amount' => (float) $data['amount'],
    'summary' => $info ? [
        'total_price' => $info['total_price'],
        'total_price_formatted' => number_format($info['total_price'], 2) . ' ETB',
        'deposit_paid' => $info['deposit_paid'],
        'deposit_paid_formatted' => number_format($info['deposit_paid'], 2) . ' ETB',
        'remaining_balance' => $info['remaining_balance'],
        'remaining_balance_formatted' => number_format($info['remaining_balance'], 2) . ' ETB',
        'payment_status' => $info['payment_status'],
        'is_fully_paid' => $info['payment_status'] === 'fully_paid',
    ] : null,
];

if ($is_paid) {
    $response['payment_status'] = 'fully_paid';
    $response['message'] = 'Remaining balance paid in full';
} elseif ($is_expired) {
    $response['payment_status'] = 'expired';
    $response['message'] = 'Payment code expired';
} else {
    $response['payment_status'] = 'pending';
    $response['message'] = 'Waiting for payment';
}

$conn->close();
echo json_encode($response);
