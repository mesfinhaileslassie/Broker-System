<?php
// api/check_payment_fast.php - ULTRA FAST payment check (no JOINs)

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

// Validate request
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

// STEP 1: Check payment_codes table (lightweight, indexed)
$code_check = $conn->query("
    SELECT 
        pc.id,
        pc.status,
        pc.transaction_id,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($code_check->num_rows === 0) {
    echo json_encode(['confirmed' => false, 'error' => 'code_not_found']);
    $conn->close();
    exit;
}

$code_data = $code_check->fetch_assoc();

// Check if expired
if ($code_data['seconds_remaining'] <= 0) {
    echo json_encode(['confirmed' => false, 'expired' => true]);
    $conn->close();
    exit;
}

// STEP 2: Check if code is already marked as used
if ($code_data['status'] === 'used') {
    echo json_encode(['confirmed' => true, 'code_used' => true]);
    $conn->close();
    exit;
}

// STEP 3: Check payments table - FAST lookup by telebirr_code
$payment_check = $conn->query("
    SELECT id, status 
    FROM payments 
    WHERE telebirr_code_5digit = '$code' 
    AND user_id = $user_id
    AND type = 'deposit_seller'
    AND status = 'confirmed'
    LIMIT 1
");

if ($payment_check->num_rows > 0) {
    // Payment confirmed! Update everything atomically
    $payment_data = $payment_check->fetch_assoc();
    
    // Get transaction and listing info
    $txn_info = $conn->query("
        SELECT t.listing_id, t.id as transaction_id
        FROM transactions t
        WHERE t.id = {$code_data['transaction_id']}
        LIMIT 1
    ")->fetch_assoc();
    
    if ($txn_info) {
        // Atomic update - all in one transaction
        $conn->begin_transaction();
        
        try {
            // Update payment_codes
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$code_data['id']}");
            
            // Activate listing
            $conn->query("UPDATE listings SET status = 'active' WHERE id = {$txn_info['listing_id']}");
            
            // Update transaction if needed
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$txn_info['transaction_id']}");
            
            $conn->commit();
            
            echo json_encode([
                'confirmed' => true, 
                'listing_activated' => true,
                'seconds_remaining' => $code_data['seconds_remaining']
            ]);
            $conn->close();
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['confirmed' => false, 'error' => 'update_failed']);
            $conn->close();
            exit;
        }
    }
}

// Not confirmed yet
echo json_encode([
    'confirmed' => false, 
    'seconds_remaining' => $code_data['seconds_remaining'],
    'code_status' => $code_data['status']
]);

$conn->close();
?>