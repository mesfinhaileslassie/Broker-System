<?php
// api/payment_status.php - FIXED VERSION

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

// CRITICAL: Set PHP timezone FIRST
date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'no_code']);
    exit;
}

$conn = getDbConnection();

// CRITICAL: Set MySQL timezone to match PHP
$conn->query("SET time_zone = '+03:00'");

// SINGLE QUERY - Get ALL status from database (SOURCE OF TRUTH)
$result = $conn->query("
    SELECT 
        pc.code,
        pc.status as code_status,
        pc.user_id,
        pc.transaction_id,
        pc.amount,
        pc.expires_at,
        UNIX_TIMESTAMP(pc.expires_at) as expires_timestamp,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining,
        
        pc.type as payment_code_type,
        -- Check if payment exists and is confirmed (any type for this code)
        EXISTS(SELECT 1 FROM payments p 
               WHERE p.telebirr_code_5digit = pc.code 
               AND p.status = 'confirmed') as is_paid,
        
        -- Check if listing is active
        EXISTS(SELECT 1 FROM transactions t
               JOIN listings l ON t.listing_id = l.id
               WHERE t.id = pc.transaction_id 
               AND l.status = 'active') as listing_active,
        
        -- Get listing status directly
        (SELECT l.status FROM transactions t 
         JOIN listings l ON t.listing_id = l.id 
         WHERE t.id = pc.transaction_id LIMIT 1) as listing_status
        
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($result->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'status' => 'not_found',
        'error' => 'Payment code not found'
    ]);
    $conn->close();
    exit;
}

$data = $result->fetch_assoc();

// Determine expiration from database ONLY
$is_expired = ($data['seconds_remaining'] <= 0);
$is_paid = (bool)$data['is_paid'];
$is_active = (bool)$data['listing_active'];

// Build response
$response = [
    'success' => true,
    'code' => $data['code'],
    'code_status' => $data['code_status'],
    'is_paid' => $is_paid,
    'is_expired' => $is_expired,
    'listing_active' => $is_active,
    'listing_status' => $data['listing_status'],
    'seconds_remaining' => max(0, intval($data['seconds_remaining'])),
    'expires_timestamp' => intval($data['expires_timestamp']),
    'server_time' => time(),
    'display_time' => date('Y-m-d H:i:s')
];

// Determine payment status
$code_type = $data['payment_code_type'] ?? 'deposit_seller';

if ($is_paid && $code_type === 'remaining_balance') {
    $response['payment_status'] = 'fully_paid';
    $response['message'] = 'Remaining balance payment confirmed';
} elseif ($is_paid && $is_active) {
    $response['payment_status'] = 'confirmed_activated';
    $response['message'] = 'Payment confirmed and listing activated';
} elseif ($is_paid && !$is_active) {
    // Payment confirmed but listing not active - trigger activation
    $response['payment_status'] = 'confirmed_pending_activation';
    $response['message'] = 'Payment confirmed, activating listing...';
    
    // Trigger activation
    require_once 'process_payment_callback.php';
    $activation = activateListingByPaymentCode($conn, $code);
    if ($activation['success']) {
        $response['listing_activated'] = true;
        $response['payment_status'] = 'confirmed_and_activated';
        $response['listing_active'] = true;
    }
} elseif (!$is_paid && !$is_expired) {
    $response['payment_status'] = 'pending';
    $response['message'] = 'Waiting for payment';
} elseif (!$is_paid && $is_expired) {
    $response['payment_status'] = 'expired';
    $response['message'] = 'Payment code expired';
} elseif ($is_paid && $is_expired) {
    $response['payment_status'] = 'paid_but_expired';
    $response['message'] = 'Payment received';
}

$conn->close();
echo json_encode($response);
?>