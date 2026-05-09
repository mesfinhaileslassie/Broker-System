<?php
// api/check_payment_safe.php - Server-authoritative payment check

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['confirmed' => false, 'error' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['confirmed' => false, 'error' => 'invalid_code']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// STEP 1: Get payment code and check expiration using MySQL (SERVER AUTHORITY)
$code_check = $conn->query("
    SELECT 
        pc.id,
        pc.status,
        pc.transaction_id,
        pc.user_id,
        pc.amount,
        UNIX_TIMESTAMP(pc.expires_at) * 1000 as expires_at_ms,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining,
        CASE 
            WHEN pc.expires_at > NOW() THEN 1 
            ELSE 0 
        END as is_valid
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($code_check->num_rows === 0) {
    echo json_encode([
        'confirmed' => false, 
        'expired' => false,
        'code_exists' => false,
        'error' => 'code_not_found'
    ]);
    $conn->close();
    exit;
}

$code_data = $code_check->fetch_assoc();

// STEP 2: Check if code is expired (USING MYSQL - SINGLE SOURCE OF TRUTH)
$is_expired = ($code_data['is_valid'] == 0);

if ($is_expired) {
    echo json_encode([
        'confirmed' => false,
        'expired' => true,
        'expires_at' => $code_data['expires_at_ms'],
        'seconds_remaining' => 0,
        'message' => 'Code has expired'
    ]);
    $conn->close();
    exit;
}

// STEP 3: Check if code is already used
if ($code_data['status'] === 'used') {
    echo json_encode([
        'confirmed' => true,
        'already_used' => true,
        'seconds_remaining' => $code_data['seconds_remaining']
    ]);
    $conn->close();
    exit;
}

// STEP 4: Check for confirmed payment (FAST lookup)
$payment_check = $conn->query("
    SELECT p.id, p.status, p.confirmed_at
    FROM payments p
    WHERE p.telebirr_code_5digit = '$code' 
    AND p.user_id = $user_id
    AND p.type = 'deposit_seller'
    AND p.status = 'confirmed'
    LIMIT 1
");

if ($payment_check->num_rows > 0) {
    // Payment confirmed! Activate listing
    $payment_data = $payment_check->fetch_assoc();
    
    // Get transaction and listing info
    $txn_info = $conn->query("
        SELECT t.listing_id, t.id as transaction_id
        FROM transactions t
        WHERE t.id = {$code_data['transaction_id']}
        LIMIT 1
    ")->fetch_assoc();
    
    if ($txn_info) {
        $conn->begin_transaction();
        
        try {
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$code_data['id']}");
            $conn->query("UPDATE listings SET status = 'active' WHERE id = {$txn_info['listing_id']}");
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$txn_info['transaction_id']}");
            $conn->commit();
            
            echo json_encode([
                'confirmed' => true,
                'seconds_remaining' => $code_data['seconds_remaining'],
                'expires_at' => $code_data['expires_at_ms']
            ]);
            $conn->close();
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['confirmed' => false, 'error' => 'activation_failed']);
            $conn->close();
            exit;
        }
    }
}

// Not confirmed yet - return server time for sync
echo json_encode([
    'confirmed' => false,
    'seconds_remaining' => max(0, $code_data['seconds_remaining']),
    'expires_at' => $code_data['expires_at_ms'],
    'is_valid' => true,
    'server_time' => time() * 1000
]);

$conn->close();
?>