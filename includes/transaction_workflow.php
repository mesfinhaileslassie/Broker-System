<?php
// includes/transaction_workflow.php - Transaction workflow management
// FIXED: Removed all duplicate functions (they exist in escrow_functions.php)

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/escrow_functions.php'; // Contains addTransactionTimeline(), releaseEscrowPayment(), and markDelivery()

/**
 * Sync transaction payment state - calculates actual payment status from payments table
 */
function syncTransactionPaymentState($conn, $transaction_id) {
    $transaction_id = intval($transaction_id);
    
    // First, check if transaction exists
    $check = $conn->query("SELECT id FROM transactions WHERE id = $transaction_id");
    if (!$check || $check->num_rows === 0) {
        return null;
    }
    
    // Get current transaction data
    $txn = $conn->query("
        SELECT 
            t.id,
            t.total_amount,
            t.deposit_amount,
            t.commission_amount,
            t.remaining_balance,
            t.status,
            t.escrow_held,
            t.buyer_id,
            t.seller_id,
            t.listing_id
        FROM transactions t
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    if (!$txn) {
        return null;
    }
    
    // Calculate total paid from confirmed payments
    $paid_result = $conn->query("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'deposit_buyer' THEN amount ELSE 0 END), 0) as buyer_deposit_paid,
            COALESCE(SUM(CASE WHEN type = 'deposit_seller' THEN amount ELSE 0 END), 0) as seller_deposit_paid,
            COALESCE(SUM(CASE WHEN type = 'commission' THEN amount ELSE 0 END), 0) as commission_paid,
            COALESCE(SUM(CASE WHEN type = 'remaining_balance' THEN amount ELSE 0 END), 0) as remaining_paid,
            COALESCE(SUM(amount), 0) as total_paid
        FROM payments 
        WHERE transaction_id = $transaction_id AND status = 'confirmed'
    ");
    
    $paid = $paid_result->fetch_assoc();
    
    $buyer_deposit_paid = floatval($paid['buyer_deposit_paid'] ?? 0);
    $seller_deposit_paid = floatval($paid['seller_deposit_paid'] ?? 0);
    $commission_paid = floatval($paid['commission_paid'] ?? 0);
    $remaining_paid = floatval($paid['remaining_paid'] ?? 0);
    $total_paid = floatval($paid['total_paid'] ?? 0);
    
    $total_amount = floatval($txn['total_amount']);
    $deposit_amount = floatval($txn['deposit_amount']);
    $commission_amount = floatval($txn['commission_amount']);
    
    // Calculate required amounts
    $buyer_required = $deposit_amount + $commission_amount;
    $seller_required = $deposit_amount;
    $total_required = $deposit_amount + $commission_amount + $deposit_amount;
    
    // Determine payment status
    $payment_status = 'pending';
    $remaining_balance = max(0, $total_amount - $buyer_deposit_paid - $remaining_paid);
    
    if ($buyer_deposit_paid >= $buyer_required) {
        if ($remaining_paid >= $remaining_balance || $remaining_balance <= 0) {
            $payment_status = 'fully_paid';
        } else {
            $payment_status = 'deposit_paid';
        }
    } elseif ($buyer_deposit_paid > 0) {
        $payment_status = 'partial_deposit';
    }
    
    // Determine escrow status
    $escrow_status = 'pending';
    $escrow_held = floatval($txn['escrow_held'] ?? 0);
    
    if ($escrow_held >= $total_required) {
        $escrow_status = 'fully_held';
    } elseif ($escrow_held >= $buyer_required) {
        $escrow_status = 'buyer_deposit_held';
    } elseif ($escrow_held > 0) {
        $escrow_status = 'partial_held';
    }
    
    // Check if we need to update transaction status
    $new_status = $txn['status'];
    if ($payment_status === 'fully_paid' && $escrow_status === 'fully_held') {
        $new_status = 'deposits_complete';
    } elseif ($payment_status === 'deposit_paid' && $escrow_status === 'buyer_deposit_held') {
        $new_status = 'buyer_deposit_received';
    }
    
    // Build update query dynamically (skip columns that might not exist)
    $update_fields = [];
    $update_params = [];
    $types = "";
    
    // Check existing columns
    $check_columns = $conn->query("SHOW COLUMNS FROM transactions");
    $existing_columns = [];
    while ($col = $check_columns->fetch_assoc()) {
        $existing_columns[] = $col['Field'];
    }
    
    // Update amount_paid (if column exists)
    if (in_array('amount_paid', $existing_columns)) {
        $update_fields[] = "amount_paid = ?";
        $update_params[] = $total_paid;
        $types .= "d";
    }
    
    // Update remaining_balance (if column exists)
    if (in_array('remaining_balance', $existing_columns)) {
        $update_fields[] = "remaining_balance = ?";
        $update_params[] = $remaining_balance;
        $types .= "d";
    }
    
    // Update payment_status (if column exists)
    if (in_array('payment_status', $existing_columns)) {
        $update_fields[] = "payment_status = ?";
        $update_params[] = $payment_status;
        $types .= "s";
    }
    
    // Update escrow_status (if column exists)
    if (in_array('escrow_status', $existing_columns)) {
        $update_fields[] = "escrow_status = ?";
        $update_params[] = $escrow_status;
        $types .= "s";
    }
    
    // Update status if changed
    if ($new_status !== $txn['status'] && in_array('status', $existing_columns)) {
        $update_fields[] = "status = ?";
        $update_params[] = $new_status;
        $types .= "s";
    }
    
    // Update updated_at (if column exists)
    if (in_array('updated_at', $existing_columns)) {
        $update_fields[] = "updated_at = NOW()";
    }
    
    // Execute update if there are fields to update
    if (!empty($update_fields)) {
        $update_params[] = $transaction_id;
        $types .= "i";
        
        $sql = "UPDATE transactions SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$update_params);
            $stmt->execute();
        }
    }
    
    return [
        'transaction_id' => $transaction_id,
        'total_amount' => $total_amount,
        'deposit_amount' => $deposit_amount,
        'commission_amount' => $commission_amount,
        'total_paid' => $total_paid,
        'buyer_deposit_paid' => $buyer_deposit_paid,
        'seller_deposit_paid' => $seller_deposit_paid,
        'commission_paid' => $commission_paid,
        'remaining_paid' => $remaining_paid,
        'remaining_balance' => $remaining_balance,
        'payment_status' => $payment_status,
        'escrow_status' => $escrow_status,
        'escrow_held' => $escrow_held,
        'buyer_required' => $buyer_required,
        'seller_required' => $seller_required,
        'total_required' => $total_required,
        'transaction_status' => $new_status
    ];
}

/**
 * Get complete transaction workflow view
 */
function getTransactionWorkflowView($conn, $transaction_id) {
    $transaction_id = intval($transaction_id);
    
    // Get available columns
    $columns_result = $conn->query("SHOW COLUMNS FROM transactions");
    $existing_columns = [];
    while ($col = $columns_result->fetch_assoc()) {
        $existing_columns[] = $col['Field'];
    }
    
    // Build SELECT clause with only existing columns
    $select_fields = ['t.id', 't.listing_id', 't.buyer_id', 't.seller_id', 't.total_amount', 't.status'];
    
    $optional_fields = [
        'deposit_amount', 'commission_amount', 'remaining_balance', 'escrow_held',
        'escrow_status', 'payment_status', 'amount_paid', 'delivery_status',
        'buyer_delivery_confirmed', 'seller_delivery_confirmed', 'admin_frozen',
        'frozen_reason', 'buyer_confirmed_at', 'seller_confirmed_at', 'created_at'
    ];
    
    foreach ($optional_fields as $field) {
        if (in_array($field, $existing_columns)) {
            $select_fields[] = "t.$field";
        }
    }
    
    $sql = "SELECT " . implode(", ", $select_fields) . " FROM transactions t WHERE t.id = $transaction_id";
    $txn = $conn->query($sql);
    
    if (!$txn || $txn->num_rows === 0) {
        return null;
    }
    
    $transaction = $txn->fetch_assoc();
    
    // Get payment summary
    $payment_summary = $conn->query("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'deposit_buyer' THEN amount ELSE 0 END), 0) as buyer_deposit,
            COALESCE(SUM(CASE WHEN type = 'deposit_seller' THEN amount ELSE 0 END), 0) as seller_deposit,
            COALESCE(SUM(CASE WHEN type = 'commission' THEN amount ELSE 0 END), 0) as commission,
            COALESCE(SUM(CASE WHEN type = 'remaining_balance' THEN amount ELSE 0 END), 0) as remaining,
            COALESCE(SUM(amount), 0) as total_paid
        FROM payments 
        WHERE transaction_id = $transaction_id AND status = 'confirmed'
    ")->fetch_assoc();
    
    $total_paid = floatval($payment_summary['total_paid'] ?? 0);
    $buyer_deposit = floatval($payment_summary['buyer_deposit'] ?? 0);
    $seller_deposit = floatval($payment_summary['seller_deposit'] ?? 0);
    $commission = floatval($payment_summary['commission'] ?? 0);
    $remaining_paid = floatval($payment_summary['remaining'] ?? 0);
    
    $total_amount = floatval($transaction['total_amount']);
    $deposit_amount = floatval($transaction['deposit_amount'] ?? ($total_amount * 0.3));
    $commission_amount = floatval($transaction['commission_amount'] ?? ($total_amount * 0.15));
    
    $buyer_required = $deposit_amount + $commission_amount;
    $remaining_balance = max(0, $total_amount - $buyer_deposit - $remaining_paid);
    
    // Determine payment status
    $payment_status = 'pending';
    if ($buyer_deposit >= $buyer_required) {
        if ($remaining_paid >= $remaining_balance || $remaining_balance <= 0) {
            $payment_status = 'fully_paid';
        } else {
            $payment_status = 'deposit_paid';
        }
    } elseif ($buyer_deposit > 0) {
        $payment_status = 'partial_deposit';
    }
    
    $transaction['amount_paid'] = $total_paid;
    $transaction['buyer_deposit_paid'] = $buyer_deposit;
    $transaction['seller_deposit_paid'] = $seller_deposit;
    $transaction['commission_paid'] = $commission;
    $transaction['remaining_balance_due'] = $remaining_balance;
    $transaction['payment_status_calc'] = $payment_status;
    
    return $transaction;
}

/**
 * Mark seller delivery confirmation
 * Uses releaseEscrowPayment() from escrow_functions.php
 */
function markSellerConfirmed($conn, $transaction_id, $user_id, $notes = '') {
    $transaction_id = intval($transaction_id);
    $user_id = intval($user_id);
    
    // Verify user is the seller
    $check = $conn->query("
        SELECT id, seller_id, seller_delivery_confirmed 
        FROM transactions 
        WHERE id = $transaction_id AND seller_id = $user_id
    ");
    
    if ($check->num_rows === 0) {
        return ['success' => false, 'error' => 'Unauthorized or transaction not found'];
    }
    
    $txn = $check->fetch_assoc();
    if ($txn['seller_delivery_confirmed']) {
        return ['success' => false, 'error' => 'Delivery already confirmed by seller'];
    }
    
    // Update seller confirmation
    $conn->query("
        UPDATE transactions 
        SET seller_delivery_confirmed = 1,
            seller_confirmed_at = NOW()
        WHERE id = $transaction_id
    ");
    
    // Use addTransactionTimeline from escrow_functions.php
    addTransactionTimeline($conn, $transaction_id, 'seller_confirmed_delivery', 
        "Seller confirmed delivery. Notes: " . substr($notes, 0, 500), $user_id);
    
    // Check if both confirmed
    $check_both = $conn->query("
        SELECT buyer_delivery_confirmed, seller_delivery_confirmed 
        FROM transactions WHERE id = $transaction_id
    ")->fetch_assoc();
    
    if ($check_both['buyer_delivery_confirmed'] && $check_both['seller_delivery_confirmed']) {
        // Both confirmed - trigger release using function from escrow_functions.php
        return releaseEscrowPayment($conn, $transaction_id, $user_id, 'dual_confirm', 'Both parties confirmed delivery');
    }
    
    return ['success' => true, 'message' => 'Delivery confirmed. Waiting for buyer confirmation.'];
}

/**
 * Mark buyer delivery confirmation and release payment
 * Uses releaseEscrowPayment() from escrow_functions.php
 */
function markBuyerConfirmed($conn, $transaction_id, $user_id, $notes = '') {
    $transaction_id = intval($transaction_id);
    $user_id = intval($user_id);
    
    // Verify user is the buyer
    $check = $conn->query("
        SELECT id, buyer_id, buyer_delivery_confirmed 
        FROM transactions 
        WHERE id = $transaction_id AND buyer_id = $user_id
    ");
    
    if ($check->num_rows === 0) {
        return ['success' => false, 'error' => 'Unauthorized or transaction not found'];
    }
    
    $txn = $check->fetch_assoc();
    if ($txn['buyer_delivery_confirmed']) {
        return ['success' => false, 'error' => 'Delivery already confirmed by buyer'];
    }
    
    // Update buyer confirmation
    $conn->query("
        UPDATE transactions 
        SET buyer_delivery_confirmed = 1,
            buyer_confirmed_at = NOW()
        WHERE id = $transaction_id
    ");
    
    // Use addTransactionTimeline from escrow_functions.php
    addTransactionTimeline($conn, $transaction_id, 'buyer_confirmed_receipt', 
        "Buyer confirmed receipt. Notes: " . substr($notes, 0, 500), $user_id);
    
    // Check if both confirmed
    $check_both = $conn->query("
        SELECT buyer_delivery_confirmed, seller_delivery_confirmed 
        FROM transactions WHERE id = $transaction_id
    ")->fetch_assoc();
    
    if ($check_both['buyer_delivery_confirmed'] && $check_both['seller_delivery_confirmed']) {
        // Both confirmed - release payment using function from escrow_functions.php
        return releaseEscrowPayment($conn, $transaction_id, $user_id, 'dual_confirm', 'Both parties confirmed delivery');
    }
    
    return ['success' => true, 'message' => 'Receipt confirmed. Waiting for seller confirmation to release payment.'];
}

/**
 * Open dispute for transaction
 */
function openTransactionDispute($conn, $transaction_id, $user_id, $reason) {
    $transaction_id = intval($transaction_id);
    $user_id = intval($user_id);
    $reason = $conn->real_escape_string(substr($reason, 0, 1000));
    
    // Check if dispute_reason column exists
    $check_columns = $conn->query("SHOW COLUMNS FROM transactions");
    $existing_columns = [];
    while ($col = $check_columns->fetch_assoc()) {
        $existing_columns[] = $col['Field'];
    }
    
    $update_fields = ["status = 'disputed'"];
    
    if (in_array('dispute_reason', $existing_columns)) {
        $update_fields[] = "dispute_reason = '$reason'";
    }
    if (in_array('dispute_opened_by', $existing_columns)) {
        $update_fields[] = "dispute_opened_by = $user_id";
    }
    if (in_array('dispute_opened_at', $existing_columns)) {
        $update_fields[] = "dispute_opened_at = NOW()";
    }
    if (in_array('updated_at', $existing_columns)) {
        $update_fields[] = "updated_at = NOW()";
    }
    
    $conn->query("UPDATE transactions SET " . implode(", ", $update_fields) . " WHERE id = $transaction_id");
    
    // Use addTransactionTimeline from escrow_functions.php
    addTransactionTimeline($conn, $transaction_id, 'dispute_opened', 
        "Dispute opened by user. Reason: " . substr($reason, 0, 500), $user_id);
    
    // Notify admin
    $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
    if ($admin) {
        $conn->query("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES ({$admin['id']}, '⚠️ Dispute Opened', 
                   'Transaction #$transaction_id has been disputed. Reason: " . substr($reason, 0, 200) . "', NOW())
        ");
    }
    
    return ['success' => true, 'message' => 'Dispute opened. An admin will review your case.'];
}

// NOTE: markDelivery() and confirmReceiptAndRelease() are already defined in escrow_functions.php
// They are NOT redeclared here to avoid duplication
?>