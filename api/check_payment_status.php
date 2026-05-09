<?php
// api/check_payment_status.php - SINGLE SOURCE OF TRUTH

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['status' => 'error', 'message' => 'invalid_code']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// SINGLE QUERY - Gets ALL status information
$result = $conn->query("
    SELECT 
        pc.id,
        pc.code,
        pc.status as code_status,
        pc.user_id,
        pc.transaction_id,
        pc.amount,
        UNIX_TIMESTAMP(pc.expires_at) * 1000 as expires_at_ms,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining,
        CASE 
            WHEN pc.status = 'used' THEN 'used'
            WHEN pc.expires_at <= NOW() THEN 'expired'
            WHEN pc.expires_at > NOW() THEN 'active'
            ELSE 'unknown'
        END as calculated_status,
        -- Check if payment is confirmed
        EXISTS(
            SELECT 1 FROM payments p 
            WHERE p.telebirr_code_5digit = pc.code 
            AND p.user_id = pc.user_id 
            AND p.type = 'deposit_seller'
            AND p.status = 'confirmed'
        ) as is_paid,
        -- Check if listing is active
        EXISTS(
            SELECT 1 FROM transactions t
            JOIN listings l ON t.listing_id = l.id
            WHERE t.id = pc.transaction_id 
            AND l.status = 'active'
        ) as listing_active
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($result->num_rows === 0) {
    echo json_encode([
        'status' => 'not_found',
        'valid' => false,
        'message' => 'Payment code not found'
    ]);
    $conn->close();
    exit;
}

$data = $result->fetch_assoc();

// Build response based on backend authority ONLY
$response = [
    'code' => $data['code'],
    'status' => $data['calculated_status'],
    'valid' => ($data['calculated_status'] === 'active'),
    'is_paid' => (bool)$data['is_paid'],
    'listing_active' => (bool)$data['listing_active'],
    'seconds_remaining' => max(0, intval($data['seconds_remaining'])),
    'expires_at' => intval($data['expires_at_ms']),
    'server_time' => time() * 1000
];

// If payment is confirmed, trigger activation
if ($data['is_paid'] && !$data['listing_active']) {
    // Get transaction and listing info for activation
    $txn_info = $conn->query("
        SELECT t.listing_id, t.id as transaction_id
        FROM transactions t
        WHERE t.id = {$data['transaction_id']}
    ")->fetch_assoc();
    
    if ($txn_info) {
        $conn->query("UPDATE listings SET status = 'active' WHERE id = {$txn_info['listing_id']}");
        $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$data['id']}");
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$txn_info['transaction_id']}");
        $response['listing_activated'] = true;
    }
}

$conn->close();
echo json_encode($response);
?>