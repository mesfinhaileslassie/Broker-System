<?php
// api/check_transaction_status.php - Check and fix transaction status

header('Content-Type: application/json');
require_once '../config/database.php';

$conn = getDbConnection();

// Get all transactions that need status update
$transactions = $conn->query("
    SELECT t.id, t.escrow_held, t.deposit_amount, t.commission_amount, t.status
    FROM transactions t
    WHERE t.status NOT IN ('completed', 'cancelled', 'disputed')
");

$updated = 0;
while ($txn = $transactions->fetch_assoc()) {
    $required = $txn['deposit_amount'] * 2 + $txn['commission_amount'];
    $new_status = null;
    
    if ($txn['escrow_held'] >= $required) {
        $new_status = 'deposits_complete';
    } else {
        // Check individual payments
        $buyer_paid = $conn->query("SELECT SUM(amount) as total FROM payments WHERE transaction_id = {$txn['id']} AND type IN ('deposit_buyer', 'commission') AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0;
        $seller_paid = $conn->query("SELECT SUM(amount) as total FROM payments WHERE transaction_id = {$txn['id']} AND type = 'deposit_seller' AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0;
        
        if ($buyer_paid >= $txn['deposit_amount'] + $txn['commission_amount'] && $seller_paid >= $txn['deposit_amount']) {
            $new_status = 'deposits_complete';
        } elseif ($buyer_paid >= $txn['deposit_amount'] + $txn['commission_amount']) {
            $new_status = 'awaiting_seller_deposit';
        } else {
            $new_status = 'awaiting_buyer_deposit';
        }
    }
    
    if ($new_status && $txn['status'] != $new_status) {
        $conn->query("UPDATE transactions SET status = '$new_status' WHERE id = {$txn['id']}");
        $updated++;
    }
}

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'message' => "Updated $updated transactions"
]);

$conn->close();
?>