<?php
// includes/payment_confirm.php - Record confirmed payment from a payment code

require_once __DIR__ . '/seller_listing_payment.php';
require_once __DIR__ . '/transaction_workflow.php';

/**
 * Confirm a pending payment code and apply business rules by payment type.
 *
 * @param mysqli $conn
 * @param string $code 5-digit Telebirr code
 * @param array $options user_id (optional session check), amount (optional override)
 * @return array
 */
function confirmPaymentByCode($conn, $code, array $options = []) {
    $code = preg_replace('/[^0-9]/', '', (string) $code);
    if (strlen($code) !== 5) {
        return ['success' => false, 'error' => 'Invalid payment code'];
    }

    $session_user_id = isset($options['user_id']) ? (int) $options['user_id'] : null;

    $stmt = $conn->prepare("
        SELECT pc.*,
               t.buyer_id, t.seller_id, t.total_amount, t.deposit_amount,
               t.commission_amount, t.remaining_balance, t.escrow_held,
               l.id AS listing_id, l.status AS listing_status, l.type AS listing_type, l.title AS listing_title
        FROM payment_codes pc
        JOIN transactions t ON pc.transaction_id = t.id
        JOIN listings l ON t.listing_id = l.id
        WHERE pc.code = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $pc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pc) {
        return ['success' => false, 'error' => 'Payment code not found'];
    }

    if ($session_user_id !== null && (int) $pc['user_id'] !== $session_user_id) {
        return ['success' => false, 'error' => 'This payment code belongs to another user'];
    }

    $existing = $conn->query("
        SELECT id, type FROM payments
        WHERE telebirr_code_5digit = '$code' AND status = 'confirmed'
        LIMIT 1
    ");
    if ($existing && $existing->num_rows > 0) {
        $paid = $existing->fetch_assoc();
        return [
            'success' => true,
            'already_confirmed' => true,
            'message' => 'Payment already confirmed',
            'payment_type' => $paid['type'],
            'transaction_id' => (int) $pc['transaction_id'],
            'listing_id' => (int) $pc['listing_id'],
        ];
    }

    if ($pc['status'] === 'used') {
        return ['success' => false, 'error' => 'Payment code already used'];
    }

    $seconds_left = (int) $conn->query("
        SELECT TIMESTAMPDIFF(SECOND, NOW(), '{$pc['expires_at']}') AS s
    ")->fetch_assoc()['s'];

    if ($seconds_left <= 0 && $pc['status'] === 'pending') {
        $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = {$pc['id']}");
        return ['success' => false, 'error' => 'Payment code expired'];
    }

    $tid = (int) $pc['transaction_id'];
    $lid = (int) $pc['listing_id'];
    $uid = (int) $pc['user_id'];
    $amount = isset($options['amount']) ? (float) $options['amount'] : (float) $pc['amount'];
    $payment_type = $pc['type'];

    $conn->begin_transaction();

    try {
        $ins = $conn->prepare("
            INSERT INTO payments (
                transaction_id, user_id, amount, type,
                telebirr_code_5digit, status, confirmed_at, created_at
            ) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW(), NOW())
        ");
        $ins->bind_param('iidss', $tid, $uid, $amount, $payment_type, $code);
        $ins->execute();
        $ins->close();

        $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$pc['id']}");

        $response = [
            'success' => true,
            'message' => 'Payment confirmed',
            'payment_type' => $payment_type,
            'transaction_id' => $tid,
            'listing_id' => $lid,
        ];

        if ($payment_type === 'deposit_seller') {
            $deposit_amount = (float) $pc['deposit_amount'];
            $total_amount = (float) $pc['total_amount'];
            $new_remaining = max(0, round($total_amount - $deposit_amount, 2));

            $conn->query("
                UPDATE transactions
                SET escrow_held = escrow_held + $amount,
                    remaining_balance = $new_remaining,
                    status = 'deposits_complete'
                WHERE id = $tid
            ");
            $conn->query("UPDATE listings SET status = 'active' WHERE id = $lid");
            updateListingSellerPaymentStatus($conn, $lid, 'deposit_paid');

            $response['message'] = 'Deposit confirmed. Listing is now active.';
            $response['listing_activated'] = true;
            $response['payment_status'] = 'deposit_paid';

        } elseif ($payment_type === 'remaining_balance') {
            $txn_row = $conn->query("SELECT remaining_balance FROM transactions WHERE id = $tid")->fetch_assoc();
            $new_remaining = max(0, round((float) ($txn_row['remaining_balance'] ?? 0) - $amount, 2));
            $status_label = $new_remaining > 0 ? 'partially_paid' : 'fully_paid';

            $conn->query("
                UPDATE transactions
                SET escrow_held = escrow_held + $amount,
                    remaining_balance = $new_remaining
                WHERE id = $tid
            ");
            if ($lid && (int) $pc['user_id'] === (int) $pc['seller_id']) {
                updateListingSellerPaymentStatus($conn, $lid, $status_label);
            }

            logTransactionAction($conn, $tid, 'remaining_payment',
                'Remaining balance payment: ' . formatMoney($amount), $uid);

            $response['message'] = $new_remaining > 0
                ? 'Partial remaining balance confirmed'
                : 'Remaining balance paid in full';
            $response['payment_status'] = $status_label;
            $response['remaining_balance'] = $new_remaining;
            $response['is_fully_paid'] = $new_remaining <= 0;

        } elseif ($payment_type === 'deposit_buyer') {
            if (!function_exists('formatMoney')) {
                require_once __DIR__ . '/functions.php';
            }

            $has_escrow_status = $conn->query("SHOW COLUMNS FROM transactions LIKE 'escrow_status'");
            if ($has_escrow_status && $has_escrow_status->num_rows > 0) {
                $conn->query("
                    UPDATE transactions
                    SET escrow_held = escrow_held + $amount,
                        status = 'escrow_active',
                        escrow_status = 'active'
                    WHERE id = $tid
                ");
            } else {
                $conn->query("
                    UPDATE transactions
                    SET escrow_held = escrow_held + $amount,
                        status = 'in_progress'
                    WHERE id = $tid
                ");
            }

            $booking = $conn->query("SELECT id FROM rental_bookings WHERE transaction_id = $tid LIMIT 1");
            if ($booking && $booking->num_rows > 0) {
                $booking_row = $booking->fetch_assoc();
                $dep = (float) $pc['deposit_amount'];
                $has_deposit_col = $conn->query("SHOW COLUMNS FROM rental_bookings LIKE 'deposit_paid'");
                if ($has_deposit_col && $has_deposit_col->num_rows > 0) {
                    $conn->query("
                        UPDATE rental_bookings
                        SET status = 'confirmed', deposit_paid = $dep, updated_at = NOW()
                        WHERE id = {$booking_row['id']}
                    ");
                } else {
                    $conn->query("
                        UPDATE rental_bookings SET status = 'confirmed', updated_at = NOW()
                        WHERE id = {$booking_row['id']}
                    ");
                }
            }

            $escrow_table = $conn->query("SHOW TABLES LIKE 'escrow_accounts'");
            if ($escrow_table && $escrow_table->num_rows > 0) {
                $escrow_exists = $conn->query("
                    SELECT id FROM escrow_accounts
                    WHERE transaction_id = $tid AND status = 'held' LIMIT 1
                ");
                if (!$escrow_exists || $escrow_exists->num_rows === 0) {
                    $esc = $conn->prepare("
                        INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at)
                        VALUES (?, ?, ?, 'buyer_deposit', 'held', NOW())
                    ");
                    if ($esc) {
                        $esc->bind_param('iid', $tid, $uid, $amount);
                        $esc->execute();
                        $esc->close();
                    }
                }
            }

            $listing_type = $pc['listing_type'] ?? 'product';
            $auto_days = ($listing_type === 'rental') ? 14 : (($listing_type === 'product') ? 5 : 10);
            $release_date = date('Y-m-d H:i:s', strtotime("+{$auto_days} days"));

            $queue_table = $conn->query("SHOW TABLES LIKE 'escrow_release_queue'");
            if ($queue_table && $queue_table->num_rows > 0) {
                $conn->query("
                    INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status)
                    VALUES ($tid, '$release_date', 'pending')
                    ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
                ");
            }

            $has_release_cols = $conn->query("SHOW COLUMNS FROM transactions LIKE 'escrow_release_date'");
            if ($has_release_cols && $has_release_cols->num_rows > 0) {
                $conn->query("
                    UPDATE transactions
                    SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
                    WHERE id = $tid
                ");
            }

            $seller_id = (int) $pc['seller_id'];
            $title = $conn->real_escape_string($pc['listing_title'] ?? 'your listing');
            $msg = $conn->real_escape_string('A buyer paid ' . formatMoney($amount) . ' for "' . ($pc['listing_title'] ?? 'your listing') . '". Funds are held in escrow.');
            $notif_table = $conn->query("SHOW TABLES LIKE 'notifications'");
            if ($notif_table && $notif_table->num_rows > 0) {
                $conn->query("
                    INSERT INTO notifications (user_id, title, message, link, created_at)
                    VALUES ($seller_id, 'Payment Received', '$msg', '/broker_system/user/transaction.php?id=$tid', NOW())
                ");
            }

            logTransactionAction($conn, $tid, 'deposit_payment',
                'Buyer deposit payment confirmed: ' . formatMoney($amount), $uid);

            $response['message'] = 'Payment confirmed. Escrow is active.';
            $response['escrow_active'] = true;

        } else {
            $conn->query("UPDATE transactions SET escrow_held = escrow_held + $amount WHERE id = $tid");
            $response['message'] = 'Payment confirmed';
        }

        $sync = syncTransactionPaymentState($conn, $tid);
        if ($sync) {
            $response['payment_status'] = $sync['payment_status'];
            $response['amount_paid'] = $sync['amount_paid'];
            $response['remaining_balance'] = $sync['remaining_balance'];
            $response['is_fully_paid'] = ($sync['payment_status'] === 'fully_paid');
        }

        $conn->commit();
        return $response;

    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
