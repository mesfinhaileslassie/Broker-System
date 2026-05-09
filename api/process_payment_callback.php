<?php
// api/process_payment_callback.php - ATOMIC PAYMENT ACTIVATION

header('Content-Type: application/json');
require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

function activateListingByPaymentCode($conn, $code, $amount = null) {
    // First, verify the code exists and get all related data
    $result = $conn->query("
        SELECT 
            pc.id as code_id,
            pc.transaction_id,
            pc.user_id,
            pc.amount as expected_amount,
            pc.status as code_status,
            t.listing_id,
            t.status as transaction_status,
            l.status as listing_status,
            l.seller_id,
            l.price
        FROM payment_codes pc
        JOIN transactions t ON pc.transaction_id = t.id
        JOIN listings l ON t.listing_id = l.id
        WHERE pc.code = '$code'
        LIMIT 1
    ");
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'error' => 'Code not found'];
    }
    
    $data = $result->fetch_assoc();
    
    // If already active, return success (idempotent)
    if ($data['listing_status'] === 'active') {
        return ['success' => true, 'already_active' => true];
    }
    
    // BEGIN ATOMIC TRANSACTION
    $conn->begin_transaction();
    
    try {
        // 1. Insert or update payment record
        $payment_check = $conn->query("
            SELECT id FROM payments 
            WHERE telebirr_code_5digit = '$code' 
            AND type = 'deposit_seller'
        ");
        
        if ($payment_check->num_rows === 0) {
            $stmt = $conn->prepare("
                INSERT INTO payments (
                    transaction_id, user_id, amount, type, 
                    telebirr_code_5digit, status, confirmed_at, created_at
                ) VALUES (?, ?, ?, 'deposit_seller', ?, 'confirmed', NOW(), NOW())
            ");
            $stmt->bind_param("iids", 
                $data['transaction_id'], 
                $data['user_id'], 
                $data['expected_amount'], 
                $code
            );
            $stmt->execute();
        }
        
        // 2. Mark payment code as used
        $conn->query("
            UPDATE payment_codes 
            SET status = 'used', updated_at = NOW() 
            WHERE id = {$data['code_id']}
        ");
        
        // 3. Update transaction status
        $conn->query("
            UPDATE transactions 
            SET status = 'deposits_complete', 
                escrow_held = escrow_held + {$data['expected_amount']},
                updated_at = NOW() 
            WHERE id = {$data['transaction_id']}
        ");
        
        // 4. ACTIVATE LISTING - CRITICAL STEP
        $conn->query("
            UPDATE listings 
            SET status = 'active', 
                updated_at = NOW() 
            WHERE id = {$data['listing_id']}
        ");
        
        $conn->commit();
        
        return [
            'success' => true,
            'listing_id' => $data['listing_id'],
            'transaction_id' => $data['transaction_id']
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$code = isset($input['code']) ? preg_replace('/[^0-9]/', '', $input['code']) : '';
$amount = isset($input['amount']) ? floatval($input['amount']) : null;

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'No payment code provided']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$result = activateListingByPaymentCode($conn, $code, $amount);

$conn->close();
echo json_encode($result);
?>