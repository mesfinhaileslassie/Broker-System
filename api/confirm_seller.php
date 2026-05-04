<?php
// api/confirm_seller.php - Seller confirms delivery completion

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../includes/auth.php';

$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = $input['transaction_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;

if (!$transaction_id || !$user_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();

// Get transaction
$txn = $conn->query("
    SELECT t.* 
    FROM transactions t
    WHERE t.id = $transaction_id AND t.seller_id = $user_id
")->fetch_assoc();

if (!$txn) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

$conn->begin_transaction();

try {
    $conn->query("UPDATE transactions SET seller_confirmed = 1 WHERE id = $transaction_id");
    
    // Check if buyer already confirmed
    $check = $conn->query("SELECT buyer_confirmed, seller_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    
    if ($check['buyer_confirmed'] && $check['seller_confirmed']) {
        // Both confirmed - release payment
        $release_amount = $txn['total_amount'] - $txn['commission_amount'];
        $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$txn['seller_id']}");
        $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
        $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW(), escrow_released = 1 WHERE id = $transaction_id");
        
        echo json_encode(['success' => true, 'message' => 'Both parties confirmed! Payment released to you.']);
    } else {
        $conn->query("UPDATE transactions SET status = 'in_progress' WHERE id = $transaction_id");
        echo json_encode(['success' => true, 'message' => 'Completion confirmed. Waiting for buyer confirmation.']);
    }
    
    $conn->commit();
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>