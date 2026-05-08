<?php
// user/api/confirm_seller_delivery.php - Seller confirms delivery

session_start();
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = isset($input['transaction_id']) ? intval($input['transaction_id']) : 0;
$user_id = $_SESSION['user_id'];

if (!$transaction_id) {
    echo json_encode(['success' => false, 'error' => 'Transaction ID required']);
    exit;
}

$conn = getDbConnection();

// Get transaction details (verify seller)
$transaction = $conn->query("
    SELECT t.*, l.title
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.id = $transaction_id AND t.seller_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

// Check if already confirmed
if ($transaction['seller_delivery_confirmed']) {
    echo json_encode(['success' => false, 'error' => 'Delivery already confirmed']);
    exit;
}

$conn->begin_transaction();

try {
    // Mark seller as confirmed
    $conn->query("UPDATE transactions SET seller_delivery_confirmed = 1 WHERE id = $transaction_id");
    
    // Check if both parties confirmed
    $check = $conn->query("
        SELECT buyer_delivery_confirmed, seller_delivery_confirmed 
        FROM transactions WHERE id = $transaction_id
    ")->fetch_assoc();
    
    $response = ['success' => true];
    
    if ($check['buyer_delivery_confirmed'] && $check['seller_delivery_confirmed']) {
        // BOTH CONFIRMED - RELEASE MONEY!
        $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
        
        // Release payment to seller
        $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$transaction['seller_id']}");
        $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
        
        $conn->query("
            UPDATE transactions 
            SET status = 'completed', 
                completed_at = NOW(),
                released_at = NOW(),
                escrow_released = 1 
            WHERE id = $transaction_id
        ");
        
        $conn->query("
            INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
            VALUES ({$transaction['seller_id']}, $release_amount, 'deposit', 
                   'Payment released for: {$transaction['title']}', NOW())
        ");
        
        $response['message'] = 'Delivery confirmed! Payment has been released to your wallet.';
        $response['release_amount'] = $release_amount;
        $response['status'] = 'completed';
    } else {
        $response['message'] = 'Delivery confirmed. Waiting for buyer confirmation to release payment.';
        $response['status'] = 'waiting';
    }
    
    $conn->commit();
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>