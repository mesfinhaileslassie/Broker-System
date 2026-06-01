<?php
// includes/transaction_workflow.php - Unified payment & escrow workflow

require_once __DIR__ . '/functions.php';

function txnHasColumn($conn, $column) {
    static $cache = [];
    $key = 'transactions.' . $column;
    if (!isset($cache[$key])) {
        $col = $conn->real_escape_string($column);
        $r = $conn->query("SHOW COLUMNS FROM transactions LIKE '$col'");
        $cache[$key] = ($r && $r->num_rows > 0);
    }
    return $cache[$key];
}

/**
 * Compute payment totals from confirmed payments.
 */
function computeTransactionPaymentTotals($conn, $transaction_id) {
    $transaction_id = (int) $transaction_id;
    $txn = $conn->query("
        SELECT total_amount, deposit_amount, commission_amount, remaining_balance
        FROM transactions WHERE id = $transaction_id
    ")->fetch_assoc();

    if (!$txn) {
        return null;
    }

    $total = round((float) $txn['total_amount'], 2);
    $deposit_required = round((float) $txn['deposit_amount'], 2);

    $buyer_deposit = $conn->query("
        SELECT id FROM payments
        WHERE transaction_id = $transaction_id
          AND type = 'deposit_buyer' AND status = 'confirmed'
        LIMIT 1
    ");
    $has_deposit = ($buyer_deposit && $buyer_deposit->num_rows > 0);

    $full_payment = $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS s FROM payments
        WHERE transaction_id = $transaction_id
          AND type = 'full_payment' AND status = 'confirmed'
    ")->fetch_assoc();

    $remaining_paid = (float) $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS s FROM payments
        WHERE transaction_id = $transaction_id
          AND type = 'remaining_balance' AND status = 'confirmed'
    ")->fetch_assoc()['s'];

    $amount_paid = 0.0;
    if ($has_deposit) {
        $amount_paid = $deposit_required;
    }
    if ((float) $full_payment['s'] > 0) {
        $amount_paid = $total;
    } else {
        $amount_paid += $remaining_paid;
    }

    $amount_paid = min($total, round($amount_paid, 2));
    $remaining = max(0, round($total - $amount_paid, 2));

    if ($amount_paid <= 0) {
        $payment_status = 'pending';
    } elseif ($remaining <= 0) {
        $payment_status = 'fully_paid';
    } elseif ($has_deposit || $amount_paid > 0) {
        $payment_status = ($remaining_paid > 0) ? 'partially_paid' : 'deposit_paid';
    } else {
        $payment_status = 'pending';
    }

    return [
        'total_amount' => $total,
        'deposit_amount' => $deposit_required,
        'amount_paid' => $amount_paid,
        'remaining_balance' => $remaining,
        'payment_status' => $payment_status,
        'has_buyer_deposit' => $has_deposit,
    ];
}

/**
 * Sync amount_paid, remaining_balance, payment_status on transactions row.
 */
function syncTransactionPaymentState($conn, $transaction_id) {
    $transaction_id = (int) $transaction_id;
    $calc = computeTransactionPaymentTotals($conn, $transaction_id);
    if (!$calc) {
        return null;
    }

    $escrow_held = (float) ($conn->query("
        SELECT COALESCE(SUM(amount), 0) AS s FROM payments
        WHERE transaction_id = $transaction_id AND status = 'confirmed'
          AND type IN ('deposit_buyer', 'remaining_balance', 'full_payment', 'deposit_seller')
    ")->fetch_assoc()['s'] ?? 0);

    $parts = [
        "remaining_balance = {$calc['remaining_balance']}",
        "escrow_held = $escrow_held",
    ];

    if (txnHasColumn($conn, 'amount_paid')) {
        $parts[] = "amount_paid = {$calc['amount_paid']}";
    }
    if (txnHasColumn($conn, 'payment_status')) {
        $ps = $conn->real_escape_string($calc['payment_status']);
        $parts[] = "payment_status = '$ps'";
    }

    $txn = $conn->query("SELECT status, funds_status, escrow_status FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    if ($calc['payment_status'] !== 'pending' && $txn) {
        if (txnHasColumn($conn, 'funds_status') && !in_array($txn['funds_status'] ?? '', ['released', 'completed', 'disputed', 'cancelled'], true)) {
            $parts[] = "funds_status = 'held_in_escrow'";
        }
        if (!in_array($txn['status'] ?? '', ['completed', 'disputed', 'cancelled'], true) && $calc['has_buyer_deposit']) {
            if (txnHasColumn($conn, 'escrow_status')) {
                $parts[] = "escrow_status = 'active'";
            }
            if ($txn['status'] === 'awaiting_buyer_deposit' || $txn['status'] === 'pending_deposit') {
                $parts[] = "status = 'in_progress'";
            }
        }
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $parts) . ", updated_at = NOW() WHERE id = $transaction_id");

    return $calc;
}

function logTransactionAction($conn, $transaction_id, $action_type, $description, $user_id = null, $amount = null) {
    if (!function_exists('addTransactionTimeline')) {
        require_once __DIR__ . '/escrow_functions.php';
    }
    if (function_exists('addTransactionTimeline')) {
        $table = $conn->query("SHOW TABLES LIKE 'transaction_timeline'");
        if ($table && $table->num_rows > 0) {
            addTransactionTimeline($conn, $transaction_id, $action_type, $description, $user_id);
            return;
        }
    }
}

function markSellerConfirmed($conn, $transaction_id, $seller_id, $notes = '') {
    $transaction_id = (int) $transaction_id;
    $seller_id = (int) $seller_id;

    $txn = $conn->query("
        SELECT * FROM transactions
        WHERE id = $transaction_id AND seller_id = $seller_id
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized or transaction not found'];
    }

    if (($txn['funds_status'] ?? '') === 'disputed' || ($txn['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Cannot confirm while dispute is open'];
    }

    $sets = ["delivery_status = 'delivered'", "delivered_at = NOW()", "updated_at = NOW()"];
    if (txnHasColumn($conn, 'seller_confirmed')) {
        $sets[] = 'seller_confirmed = 1';
        $sets[] = 'seller_confirmed_at = NOW()';
    }
    if (txnHasColumn($conn, 'funds_status')) {
        $sets[] = "funds_status = 'seller_confirmed'";
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = $transaction_id");

    logTransactionAction($conn, $transaction_id, 'seller_confirmed',
        'Seller confirmed delivery' . ($notes ? ': ' . $notes : ''), $seller_id);

    tryAutoReleaseFunds($conn, $transaction_id, $seller_id);

    return ['success' => true, 'message' => 'Delivery confirmed. Waiting for buyer confirmation.'];
}

function markBuyerConfirmed($conn, $transaction_id, $buyer_id, $notes = '') {
    $transaction_id = (int) $transaction_id;
    $buyer_id = (int) $buyer_id;

    $txn = $conn->query("
        SELECT * FROM transactions
        WHERE id = $transaction_id AND buyer_id = $buyer_id
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized or transaction not found'];
    }

    if (($txn['funds_status'] ?? '') === 'disputed' || ($txn['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Cannot confirm while dispute is open'];
    }

    $seller_ok = (int) ($txn['seller_confirmed'] ?? 0) === 1
        || ($txn['delivery_status'] ?? '') === 'delivered';

    if (!$seller_ok) {
        return ['success' => false, 'error' => 'Seller has not confirmed delivery yet'];
    }

    $sets = ['updated_at = NOW()'];
    if (txnHasColumn($conn, 'buyer_confirmed')) {
        $sets[] = 'buyer_confirmed = 1';
        $sets[] = 'buyer_confirmed_at = NOW()';
    }
    if (txnHasColumn($conn, 'funds_status')) {
        $sets[] = "funds_status = 'buyer_confirmed'";
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = $transaction_id");

    logTransactionAction($conn, $transaction_id, 'buyer_confirmed',
        'Buyer confirmed receipt' . ($notes ? ': ' . $notes : ''), $buyer_id);

    return tryAutoReleaseFunds($conn, $transaction_id, $buyer_id);
}

function tryAutoReleaseFunds($conn, $transaction_id, $performed_by) {
    $transaction_id = (int) $transaction_id;
    $txn = $conn->query("SELECT * FROM transactions WHERE id = $transaction_id")->fetch_assoc();

    if (!$txn || ($txn['status'] ?? '') === 'completed') {
        return ['success' => true, 'already_completed' => true];
    }

    if (($txn['funds_status'] ?? '') === 'disputed' || ($txn['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Funds locked due to dispute'];
    }

    $seller_ok = (int) ($txn['seller_confirmed'] ?? 0) === 1
        || ($txn['delivery_status'] ?? '') === 'delivered';
    $buyer_ok = (int) ($txn['buyer_confirmed'] ?? 0) === 1;

    if (!$seller_ok || !$buyer_ok) {
        if (txnHasColumn($conn, 'funds_status') && $seller_ok && !$buyer_ok) {
            $conn->query("UPDATE transactions SET funds_status = 'seller_confirmed' WHERE id = $transaction_id");
        }
        return [
            'success' => true,
            'released' => false,
            'message' => 'Waiting for both parties to confirm',
        ];
    }

    if (txnHasColumn($conn, 'funds_status')) {
        $conn->query("UPDATE transactions SET funds_status = 'ready_for_release' WHERE id = $transaction_id");
    }

    if (!function_exists('releaseEscrowPayment')) {
        require_once __DIR__ . '/escrow_functions.php';
    }

    $result = releaseEscrowPayment($conn, $transaction_id, $performed_by, 'dual_confirm', 'Automatic release after buyer and seller confirmation');

    if ($result['success'] && txnHasColumn($conn, 'funds_status')) {
        $conn->query("
            UPDATE transactions
            SET funds_status = 'released', funds_released_at = NOW()
            WHERE id = $transaction_id
        ");
    }

    return $result;
}

function openTransactionDispute($conn, $transaction_id, $user_id, $reason) {
    $transaction_id = (int) $transaction_id;
    $user_id = (int) $user_id;
    $reason = trim($reason);

    if ($reason === '') {
        return ['success' => false, 'error' => 'Dispute reason is required'];
    }

    $txn = $conn->query("
        SELECT id FROM transactions
        WHERE id = $transaction_id AND (buyer_id = $user_id OR seller_id = $user_id)
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized'];
    }

    $stmt = $conn->prepare("
        INSERT INTO disputes (transaction_id, raised_by, reason, status, created_at)
        VALUES (?, ?, ?, 'open', NOW())
    ");
    $stmt->bind_param('iis', $transaction_id, $user_id, $reason);
    $stmt->execute();
    $stmt->close();

    $sets = ["status = 'disputed'", "updated_at = NOW()"];
    if (txnHasColumn($conn, 'funds_status')) {
        $sets[] = "funds_status = 'disputed'";
    }
    if (txnHasColumn($conn, 'escrow_status')) {
        $sets[] = "escrow_status = 'disputed'";
    }

    $conn->query("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = $transaction_id");

    logTransactionAction($conn, $transaction_id, 'dispute_opened', 'Dispute opened: ' . $reason, $user_id);

    return ['success' => true, 'message' => 'Dispute submitted for admin review'];
}

function getTransactionWorkflowView($conn, $transaction_id) {
    $transaction_id = (int) $transaction_id;
    syncTransactionPaymentState($conn, $transaction_id);

    $txn = $conn->query("SELECT * FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    if (!$txn) {
        return null;
    }

    $calc = computeTransactionPaymentTotals($conn, $transaction_id);

    return array_merge($txn, [
        'amount_paid' => $calc['amount_paid'],
        'remaining_balance' => $calc['remaining_balance'],
        'payment_status' => $calc['payment_status'],
        'total_amount' => $calc['total_amount'],
    ]);
}

/**
 * Create payment code for buyer remaining balance on a transaction.
 */
function initiateBuyerRemainingPayment($conn, $transaction_id, $buyer_id) {
    $transaction_id = (int) $transaction_id;
    $buyer_id = (int) $buyer_id;

    $calc = syncTransactionPaymentState($conn, $transaction_id);
    if (!$calc || $calc['remaining_balance'] <= 0) {
        return ['success' => false, 'error' => 'No remaining balance to pay'];
    }

    $txn = $conn->query("
        SELECT id FROM transactions
        WHERE id = $transaction_id AND buyer_id = $buyer_id
    ")->fetch_assoc();

    if (!$txn) {
        return ['success' => false, 'error' => 'Unauthorized'];
    }

    $pending = $conn->query("
        SELECT code FROM payment_codes
        WHERE transaction_id = $transaction_id
          AND user_id = $buyer_id
          AND type = 'remaining_balance'
          AND status = 'pending'
          AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ");

    if ($pending && $pending->num_rows > 0) {
        $row = $pending->fetch_assoc();
        return [
            'success' => true,
            'payment_code' => $row['code'],
            'amount' => $calc['remaining_balance'],
            'pay_url' => '/broker_system/user/pay_rent.php?transaction_id=' . $transaction_id . '&pay=remaining',
        ];
    }

    do {
        $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $chk = $conn->prepare('SELECT id FROM payment_codes WHERE code = ?');
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
    } while ($exists);

    $amount = $calc['remaining_balance'];
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
        VALUES (?, ?, ?, ?, 'remaining_balance', DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending', NOW())
    ");
    $stmt->bind_param('sidi', $code, $transaction_id, $amount, $buyer_id);
    $stmt->execute();
    $stmt->close();

    return [
        'success' => true,
        'payment_code' => $code,
        'amount' => $amount,
        'pay_url' => '/broker_system/user/pay_rent.php?transaction_id=' . $transaction_id . '&pay=remaining',
    ];
}
