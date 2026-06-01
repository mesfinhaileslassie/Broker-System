<?php
// includes/seller_listing_payment.php - Seller listing activation payment helpers

/**
 * Get payment state for a seller's listing activation transaction.
 *
 * @return array|null null if listing not found or not owned by seller
 */
function getSellerListingPaymentInfo($conn, $listing_id, $seller_id) {
    $listing_id = (int) $listing_id;
    $seller_id = (int) $seller_id;

    $stmt = $conn->prepare("
        SELECT l.id, l.seller_id, l.status, l.approval_status, l.price,
               l.admin_deposit_percent, l.admin_commission_percent
        FROM listings l
        WHERE l.id = ? AND l.seller_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $listing_id, $seller_id);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$listing) {
        return null;
    }

    $deposit_percent = (float) ($listing['admin_deposit_percent'] ?? 30);
    $total_price = round((float) $listing['price'], 2);
    $deposit_required = round($total_price * ($deposit_percent / 100), 2);

    $txn = $conn->query("
        SELECT id, total_amount, deposit_amount, remaining_balance
        FROM transactions
        WHERE listing_id = {$listing_id}
          AND seller_id = {$seller_id}
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc();

    $deposit_paid = 0.0;
    $remaining_paid = 0.0;
    $has_deposit_payment = false;
    $transaction_id = $txn ? (int) $txn['id'] : 0;

    if ($transaction_id) {
        $row = $conn->query("
            SELECT
                COALESCE(SUM(CASE WHEN type = 'deposit_seller' AND status = 'confirmed' THEN 1 ELSE 0 END), 0) AS deposit_count,
                COALESCE(SUM(CASE WHEN type = 'remaining_balance' AND status = 'confirmed' THEN amount ELSE 0 END), 0) AS remaining_sum
            FROM payments
            WHERE transaction_id = {$transaction_id}
        ")->fetch_assoc();

        $has_deposit_payment = ((int) ($row['deposit_count'] ?? 0)) > 0;
        $remaining_paid = round((float) ($row['remaining_sum'] ?? 0), 2);

        if ($has_deposit_payment) {
            $deposit_paid = $txn ? round((float) $txn['deposit_amount'], 2) : $deposit_required;
        }
    }

    // Listing active implies initial deposit flow completed
    if ($listing['status'] === 'active' && $listing['approval_status'] === 'approved' && !$has_deposit_payment) {
        $has_deposit_payment = true;
        $deposit_paid = $deposit_required;
    }

    $amount_paid = round($deposit_paid + $remaining_paid, 2);
    $remaining_balance = max(0, round($total_price - $amount_paid, 2));

    if (!$has_deposit_payment) {
        $payment_status = 'pending';
    } elseif ($remaining_balance <= 0) {
        $payment_status = 'fully_paid';
    } elseif ($remaining_paid > 0) {
        $payment_status = 'partially_paid';
    } else {
        $payment_status = 'deposit_paid';
    }

    $pending_remaining_code = false;
    if ($transaction_id && $remaining_balance > 0) {
        $pending = $conn->query("
            SELECT id FROM payment_codes
            WHERE transaction_id = {$transaction_id}
              AND user_id = {$seller_id}
              AND type = 'remaining_balance'
              AND status = 'pending'
              AND expires_at > NOW()
            LIMIT 1
        ");
        $pending_remaining_code = $pending && $pending->num_rows > 0;
    }

    $is_owner = true;
    $is_active = ($listing['status'] === 'active' && $listing['approval_status'] === 'approved');
    $can_pay_remaining = $is_owner
        && $is_active
        && $has_deposit_payment
        && $remaining_balance > 0
        && $payment_status !== 'fully_paid';

    return [
        'listing_id' => $listing_id,
        'transaction_id' => $transaction_id,
        'total_price' => $total_price,
        'deposit_required' => $deposit_required,
        'deposit_paid' => $deposit_paid,
        'remaining_paid' => $remaining_paid,
        'amount_paid' => $amount_paid,
        'remaining_balance' => $remaining_balance,
        'payment_status' => $payment_status,
        'has_deposit_payment' => $has_deposit_payment,
        'is_active' => $is_active,
        'can_pay_remaining' => $can_pay_remaining,
        'pending_remaining_code' => $pending_remaining_code,
        'deposit_percent' => $deposit_percent,
    ];
}

/**
 * Persist seller_payment_status on listings when column exists.
 */
function updateListingSellerPaymentStatus($conn, $listing_id, $status) {
    $allowed = ['pending', 'deposit_paid', 'partially_paid', 'fully_paid'];
    if (!in_array($status, $allowed, true)) {
        return;
    }

    $col = $conn->query("SHOW COLUMNS FROM listings LIKE 'seller_payment_status'");
    if (!$col || $col->num_rows === 0) {
        return;
    }

    $listing_id = (int) $listing_id;
    $stmt = $conn->prepare("UPDATE listings SET seller_payment_status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $listing_id);
    $stmt->execute();
    $stmt->close();
}
