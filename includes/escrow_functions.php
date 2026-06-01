<?php
// ============================================
// FILE: includes/escrow_functions.php
// ============================================
// Complete Escrow Transaction Engine

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Add entry to transaction timeline
 */
function addTransactionTimeline($conn, $transaction_id, $status, $description, $performed_by = null) {
    $stmt = $conn->prepare("
        INSERT INTO transaction_timeline (transaction_id, status, action, description, performed_by, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $action = str_replace('_', ' ', $status);
    $stmt->bind_param("isssi", $transaction_id, $status, $action, $description, $performed_by);
    return $stmt->execute();
}

/**
 * Initialize escrow for a transaction
 */
function initializeEscrow($conn, $transaction_id, $buyer_id, $seller_id, $amount, $type = 'full_payment') {
    // Insert into escrow_accounts
    $stmt = $conn->prepare("
        INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
        VALUES (?, ?, ?, ?, 'held', NOW())
    ");
    $stmt->bind_param("iids", $transaction_id, $buyer_id, $amount, $type);
    $stmt->execute();
    
    // Update transaction escrow status
    $conn->query("
        UPDATE transactions 
        SET escrow_held = escrow_held + $amount,
            escrow_status = 'active',
            updated_at = NOW()
        WHERE id = $transaction_id
    ");
    
    // Add to timeline
    addTransactionTimeline($conn, $transaction_id, 'escrow_activated', 
        "Escrow activated with " . formatMoney($amount), $buyer_id);
    
    // Schedule auto-release based on listing type
    $listing = $conn->query("
        SELECT l.type FROM transactions t 
        JOIN listings l ON t.listing_id = l.id 
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    $auto_days = 7; // default
    if ($listing['type'] == 'rental') $auto_days = 14;
    if ($listing['type'] == 'product') $auto_days = 5;
    if ($listing['type'] == 'job') $auto_days = 10;
    
    $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
    $conn->query("
        INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
        VALUES ($transaction_id, '$release_date', 'pending')
    ");
    
    // Update transaction with release date
    $conn->query("
        UPDATE transactions 
        SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
        WHERE id = $transaction_id
    ");
    
    return true;
}

/**
 * Process payment from buyer (with Telebirr code)
 */
function processBuyerPayment($conn, $transaction_id, $buyer_id, $amount, $payment_code) {
    // Record payment
    $stmt = $conn->prepare("
        INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at, created_at) 
        VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW(), NOW())
    ");
    $stmt->bind_param("iids", $transaction_id, $buyer_id, $amount, $payment_code);
    $stmt->execute();
    
    // Initialize escrow
    $transaction = $conn->query("SELECT seller_id FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    initializeEscrow($conn, $transaction_id, $buyer_id, $transaction['seller_id'], $amount);
    
    return true;
}

/**
 * Mark delivery by seller
 */
function markDelivery($conn, $transaction_id, $seller_id, $delivery_notes = '') {
    require_once __DIR__ . '/transaction_workflow.php';
    return markSellerConfirmed($conn, $transaction_id, $seller_id, $delivery_notes);
}

/**
 * Confirm receipt by buyer and release payment
 */
function confirmReceiptAndRelease($conn, $transaction_id, $buyer_id, $notes = '') {
    require_once __DIR__ . '/transaction_workflow.php';
    return markBuyerConfirmed($conn, $transaction_id, $buyer_id, $notes);
}

/**
 * Release payment from escrow
 */
function releaseEscrowPayment($conn, $transaction_id, $released_by, $released_by_type, $notes = '') {
    $transaction = $conn->query("
        SELECT t.*, l.title, l.seller_id
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    if (!$transaction) {
        return ['success' => false, 'error' => 'Transaction not found'];
    }

    if (($transaction['status'] ?? '') === 'disputed') {
        return ['success' => false, 'error' => 'Cannot release funds while disputed'];
    }

    if ($released_by_type !== 'admin' && $released_by_type !== 'system' && $released_by_type !== 'dual_confirm') {
        $seller_ok = (int) ($transaction['seller_confirmed'] ?? 0) === 1
            || ($transaction['delivery_status'] ?? '') === 'delivered';
        $buyer_ok = (int) ($transaction['buyer_confirmed'] ?? 0) === 1;
        if (!$seller_ok || !$buyer_ok) {
            return ['success' => false, 'error' => 'Both seller and buyer must confirm before release'];
        }
    }
    
    $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
    
    $conn->begin_transaction();
    
    try {
        // Update escrow accounts
        $conn->query("
            UPDATE escrow_accounts 
            SET status = 'released', released_at = NOW()
            WHERE transaction_id = $transaction_id AND status = 'held'
        ");
        
        // Update user balance (seller gets paid)
        $conn->query("
            UPDATE users 
            SET balance = balance + $release_amount 
            WHERE id = {$transaction['seller_id']}
        ");
        
        // Update transaction
        $conn->query("
            UPDATE transactions 
            SET status = 'completed',
                escrow_status = 'released',
                payment_released_at = NOW(),
                escrow_release_method = '$released_by_type',
                confirmed_at = NOW(),
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Cancel auto-release queue
        $conn->query("
            UPDATE escrow_release_queue 
            SET status = 'cancelled' 
            WHERE transaction_id = $transaction_id AND status = 'pending'
        ");
        
        // Add release history
        $stmt = $conn->prepare("
            INSERT INTO escrow_release_history (transaction_id, released_by, released_by_type, amount, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iisds", $transaction_id, $released_by, $released_by_type, $release_amount, $notes);
        $stmt->execute();
        
        // Add wallet transaction for seller
        $conn->query("
            INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
            VALUES ({$transaction['seller_id']}, $release_amount, 'deposit', 
                   'Payment released for: {$transaction['title']}', NOW())
        ");
        
        // Add timeline entry
        addTransactionTimeline($conn, $transaction_id, 'payment_released', 
            "Payment of " . formatMoney($release_amount) . " released to seller", $released_by);
        
        // Create notification for seller
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Payment Released', 'Payment of " . formatMoney($release_amount) . " has been released to your wallet for {$transaction['title']}', NOW())
        ");
        $notif_stmt->bind_param("i", $transaction['seller_id']);
        $notif_stmt->execute();
        
        $conn->commit();
        
        return ['success' => true, 'amount' => $release_amount];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process auto-release for expired escrow
 */
function processAutoReleaseQueue($conn) {
    $pending_releases = $conn->query("
        SELECT eq.*, t.total_amount, t.commission_amount, t.seller_id,
               t.seller_confirmed, t.buyer_confirmed, t.delivery_status
        FROM escrow_release_queue eq
        JOIN transactions t ON eq.transaction_id = t.id
        WHERE eq.status = 'pending' 
        AND eq.scheduled_release_date <= NOW()
        AND t.escrow_status = 'active'
        AND t.status NOT IN ('completed', 'disputed')
    ");
    
    $released_count = 0;
    while ($release = $pending_releases->fetch_assoc()) {
        $seller_ok = (int) ($release['seller_confirmed'] ?? 0) === 1
            || ($release['delivery_status'] ?? '') === 'delivered';
        $buyer_ok = (int) ($release['buyer_confirmed'] ?? 0) === 1;
        if (!$seller_ok || !$buyer_ok) {
            continue;
        }
        $result = releaseEscrowPayment($conn, $release['transaction_id'], 0, 'system',
            "Auto-release after escrow period expired and both parties confirmed");
        if ($result['success']) {
            $released_count++;
        }
    }
    
    return $released_count;
}

/**
 * Admin manual release
 */
function adminReleasePayment($conn, $transaction_id, $admin_id, $notes = '') {
    return releaseEscrowPayment($conn, $transaction_id, $admin_id, 'admin', $notes);
}

/**
 * Admin freeze transaction
 */
function adminFreezeTransaction($conn, $transaction_id, $admin_id, $reason = '') {
    $conn->query("
        UPDATE transactions 
        SET admin_frozen = 1, frozen_reason = '$reason', updated_at = NOW()
        WHERE id = $transaction_id
    ");
    
    addTransactionTimeline($conn, $transaction_id, 'frozen', 
        "Transaction frozen by admin. Reason: " . ($reason ?: 'Not specified'), $admin_id);
    
    // Cancel auto-release
    $conn->query("
        UPDATE escrow_release_queue 
        SET status = 'cancelled' 
        WHERE transaction_id = $transaction_id AND status = 'pending'
    ");
    
    return true;
}

/**
 * Admin unfreeze transaction
 */
function adminUnfreezeTransaction($conn, $transaction_id, $admin_id) {
    $conn->query("
        UPDATE transactions 
        SET admin_frozen = 0, frozen_reason = NULL, updated_at = NOW()
        WHERE id = $transaction_id
    ");
    
    addTransactionTimeline($conn, $transaction_id, 'unfrozen', 
        "Transaction unfrozen by admin", $admin_id);
    
    // Re-schedule auto-release
    $release_date = date('Y-m-d H:i:s', strtotime('+7 days'));
    $conn->query("
        INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
        VALUES ($transaction_id, '$release_date', 'pending')
        ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
    ");
    
    return true;
}

/**
 * Get transaction status with escrow info
 */
function getTransactionEscrowStatus($conn, $transaction_id) {
    return $conn->query("
        SELECT t.*, 
               ea.amount as escrow_amount, ea.status as escrow_account_status,
               eq.scheduled_release_date,
               (SELECT COUNT(*) FROM transaction_timeline tt WHERE tt.transaction_id = t.id) as timeline_count
        FROM transactions t
        LEFT JOIN escrow_accounts ea ON t.id = ea.transaction_id
        LEFT JOIN escrow_release_queue eq ON t.id = eq.transaction_id AND eq.status = 'pending'
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
}

/**
 * Get transaction timeline
 */
function getTransactionTimeline($conn, $transaction_id) {
    return $conn->query("
        SELECT * FROM transaction_timeline 
        WHERE transaction_id = $transaction_id 
        ORDER BY created_at ASC
    ");
}

/**
 * Calculate escrow summary for admin
 */
function getEscrowSummary($conn) {
    return [
        'total_held' => $conn->query("SELECT SUM(amount) as total FROM escrow_accounts WHERE status = 'held'")->fetch_assoc()['total'] ?? 0,
        'total_released' => $conn->query("SELECT SUM(amount) as total FROM escrow_accounts WHERE status = 'released'")->fetch_assoc()['total'] ?? 0,
        'active_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE escrow_status = 'active'")->fetch_assoc()['count'],
        'pending_release' => $conn->query("SELECT COUNT(*) as count FROM escrow_release_queue WHERE status = 'pending' AND scheduled_release_date <= NOW()")->fetch_assoc()['count']
    ];
}





// Add this function to your existing escrow_functions.php

/**
 * Refund payment to buyer (for disputes)
 */
function refundEscrowPayment($conn, $transaction_id, $admin_id, $notes = '') {
    $transaction = $conn->query("
        SELECT t.*, l.title, l.buyer_id, l.seller_id
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
    
    if (!$transaction) {
        return ['success' => false, 'error' => 'Transaction not found'];
    }
    
    $refund_amount = $transaction['escrow_held'];
    
    $conn->begin_transaction();
    
    try {
        // Update escrow accounts
        $conn->query("
            UPDATE escrow_accounts 
            SET status = 'refunded', refunded_at = NOW()
            WHERE transaction_id = $transaction_id AND status = 'held'
        ");
        
        // Refund to buyer
        $conn->query("
            UPDATE users 
            SET balance = balance + $refund_amount 
            WHERE id = {$transaction['buyer_id']}
        ");
        
        // Update transaction
        $conn->query("
            UPDATE transactions 
            SET status = 'cancelled',
                escrow_status = 'refunded',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Cancel auto-release
        $conn->query("
            UPDATE escrow_release_queue 
            SET status = 'cancelled' 
            WHERE transaction_id = $transaction_id AND status = 'pending'
        ");
        
        // Add refund history
        $stmt = $conn->prepare("
            INSERT INTO escrow_release_history (transaction_id, released_by, released_by_type, amount, notes, created_at) 
            VALUES (?, ?, 'admin', ?, ?, NOW())
        ");
        $stmt->bind_param("iids", $transaction_id, $admin_id, $refund_amount, $notes);
        $stmt->execute();
        
        // Add wallet transaction for buyer
        $conn->query("
            INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
            VALUES ({$transaction['buyer_id']}, $refund_amount, 'deposit', 
                   'Refund for: {$transaction['title']}', NOW())
        ");
        
        // Add timeline
        addTransactionTimeline($conn, $transaction_id, 'refunded', 
            "Refund of " . formatMoney($refund_amount) . " processed to buyer", $admin_id);
        
        // Notify buyer
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, '💰 Refund Processed', 'A refund of " . formatMoney($refund_amount) . " has been issued for {$transaction['title']}.', NOW())
        ");
        $notif_stmt->bind_param("i", $transaction['buyer_id']);
        $notif_stmt->execute();
        
        // Notify seller
        $notif_stmt2 = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, '⚠️ Transaction Cancelled', 'Transaction for {$transaction['title']} has been cancelled and buyer refunded.', NOW())
        ");
        $notif_stmt2->bind_param("i", $transaction['seller_id']);
        $notif_stmt2->execute();
        
        $conn->commit();
        
        return ['success' => true, 'amount' => $refund_amount];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


?>