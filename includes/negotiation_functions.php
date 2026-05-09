<?php
// ============================================
// FILE: broker_system/includes/negotiation_functions.php
// ============================================
// Core Negotiation Engine Functions

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Create a new listing negotiation record
 */
function createListingNegotiation($conn, $listing_id, $seller_id) {
    $stmt = $conn->prepare("
        INSERT INTO listing_negotiations (
            listing_id, seller_id, status, created_at, updated_at
        ) VALUES (?, ?, 'under_review', NOW(), NOW())
    ");
    $stmt->bind_param("ii", $listing_id, $seller_id);
    $stmt->execute();
    return $conn->insert_id;
}

/**
 * Get negotiation details with related data
 */
function getNegotiationDetails($conn, $negotiation_id) {
    $stmt = $conn->prepare("
        SELECT ln.*, 
               l.title as listing_title,
               l.type as listing_type,
               l.description as listing_description,
               l.price as listing_price,
               l.category_id,
               u.full_name as seller_name,
               u.email as seller_email
        FROM listing_negotiations ln
        JOIN listings l ON ln.listing_id = l.id
        JOIN users u ON ln.seller_id = u.id
        WHERE ln.id = ?
    ");
    $stmt->bind_param("i", $negotiation_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get all negotiations for a user
 */
function getUserNegotiations($conn, $user_id, $status = null) {
    $sql = "
        SELECT ln.*, l.title, l.type, l.price,
               (SELECT COUNT(*) FROM negotiation_messages nm WHERE nm.negotiation_id = ln.id AND nm.is_read = 0 AND nm.sender_type != 'seller') as unread_count
        FROM listing_negotiations ln
        JOIN listings l ON ln.listing_id = l.id
        WHERE ln.seller_id = ?
    ";
    if ($status) {
        $sql .= " AND ln.status = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $user_id, $status);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get all negotiations for admin
 */
function getAdminNegotiations($conn, $status = null) {
    $sql = "
        SELECT ln.*, l.title, l.type, l.price, u.full_name as seller_name, u.email as seller_email,
               (SELECT COUNT(*) FROM negotiation_messages nm WHERE nm.negotiation_id = ln.id AND nm.is_read = 0 AND nm.sender_type != 'admin') as unread_count
        FROM listing_negotiations ln
        JOIN listings l ON ln.listing_id = l.id
        JOIN users u ON ln.seller_id = u.id
        WHERE ln.status NOT IN ('published', 'cancelled')
    ";
    if ($status) {
        $sql .= " AND ln.status = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $status);
    } else {
        $stmt = $conn->prepare($sql);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Calculate smart commission based on listing value and category
 */
function calculateSmartCommission($price, $type, $seller_trust_score = 0) {
    // Base commission by type
    $base_rates = [
        'rental' => ['min' => 5, 'max' => 10],
        'product' => ['min' => 3, 'max' => 8],
        'job' => ['min' => 4, 'max' => 12]
    ];
    
    $rate_range = $base_rates[$type] ?? ['min' => 5, 'max' => 10];
    
    // Value-based adjustment
    if ($price < 500000) {
        $rate = $rate_range['max'];
    } elseif ($price >= 500000 && $price <= 2000000) {
        $rate = ($rate_range['min'] + $rate_range['max']) / 2;
    } else {
        $rate = $rate_range['min'];
    }
    
    // Seller trust adjustment (reduce commission for trusted sellers)
    if ($seller_trust_score >= 80) {
        $rate = max($rate_range['min'], $rate - 1);
    } elseif ($seller_trust_score >= 60) {
        $rate = max($rate_range['min'], $rate - 0.5);
    }
    
    return round($rate, 1);
}

/**
 * Calculate recommended deposit
 */
function calculateRecommendedDeposit($price, $type) {
    $deposit_rates = [
        'rental' => 30,
        'product' => 25,
        'job' => 20
    ];
    
    $rate = $deposit_rates[$type] ?? 25;
    $deposit = $price * ($rate / 100);
    
    // Cap deposit
    $max_deposit = 50000;
    return min($deposit, $max_deposit);
}

/**
 * Send negotiation message
 */
function sendNegotiationMessage($conn, $negotiation_id, $sender_id, $sender_type, $message) {
    $stmt = $conn->prepare("
        INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, is_read, created_at) 
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $stmt->bind_param("iiss", $negotiation_id, $sender_id, $sender_type, $message);
    $stmt->execute();
    $message_id = $conn->insert_id;
    
    // Update negotiation updated_at
    $conn->query("UPDATE listing_negotiations SET updated_at = NOW() WHERE id = $negotiation_id");
    
    return $message_id;
}

/**
 * Get negotiation messages
 */
function getNegotiationMessages($conn, $negotiation_id, $user_id, $user_type) {
    $stmt = $conn->prepare("
        SELECT nm.*, 
               CASE WHEN nm.sender_type = 'admin' THEN 'Administrator' ELSE u.full_name END as sender_name
        FROM negotiation_messages nm
        LEFT JOIN users u ON nm.sender_id = u.id AND nm.sender_type = 'seller'
        WHERE nm.negotiation_id = ?
        ORDER BY nm.created_at ASC
    ");
    $stmt->bind_param("i", $negotiation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Mark messages as read
    $conn->query("
        UPDATE negotiation_messages 
        SET is_read = 1 
        WHERE negotiation_id = $negotiation_id AND sender_type != '$user_type'
    ");
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    return $messages;
}

/**
 * Propose commission and deposit (Admin action)
 */
function proposeCommissionDeposit($conn, $negotiation_id, $commission_percent, $deposit_amount, $featured_fee = 0, $notes = '') {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET proposed_commission = ?, 
            proposed_deposit = ?, 
            featured_listing_fee = ?,
            admin_notes = ?,
            status = 'commission_proposed',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("dddsi", $commission_percent, $deposit_amount, $featured_fee, $notes, $negotiation_id);
    $stmt->execute();
    
    // Get seller info for notification
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if ($negotiation) {
        sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
            "Admin has proposed " . $commission_percent . "% commission and " . formatMoney($deposit_amount) . " deposit for your listing.");
    }
    
    return $stmt->affected_rows > 0;
}

/**
 * Seller counter-offer
 */
function sendCounterOffer($conn, $negotiation_id, $commission_percent, $deposit_amount, $message = '') {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET counter_commission = ?, 
            counter_deposit = ?,
            counter_message = ?,
            status = 'counter_offer_sent',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddsi", $commission_percent, $deposit_amount, $message, $negotiation_id);
    $stmt->execute();
    
    // Send system message
    sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
        "Seller has sent a counter-offer: " . $commission_percent . "% commission, " . formatMoney($deposit_amount) . " deposit.");
    
    return $stmt->affected_rows > 0;
}

/**
 * Accept agreement (Seller action)
 */
function acceptAgreement($conn, $negotiation_id) {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET status = 'agreement_accepted',
            accepted_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("i", $negotiation_id);
    $stmt->execute();
    
    // Send system message
    sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
        "Seller has accepted the agreement. Deposit payment is now required to publish the listing.");
    
    // Get listing details
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if ($negotiation) {
        // Update listing with proposed commission and deposit
        $final_commission = $negotiation['counter_commission'] ?: $negotiation['proposed_commission'];
        $final_deposit = $negotiation['counter_deposit'] ?: $negotiation['proposed_deposit'];
        
        $conn->query("
            UPDATE listings 
            SET admin_commission_percent = $final_commission,
                admin_deposit_percent = ($final_deposit / price) * 100,
                status = 'pending',
                approval_status = 'approved'
            WHERE id = {$negotiation['listing_id']}
        ");
    }
    
    return $stmt->affected_rows > 0;
}

/**
 * Reject agreement (Seller action)
 */
function rejectAgreement($conn, $negotiation_id, $reason = '') {
    $stmt = $conn->prepare("
        UPDATE listing_negotiations 
        SET status = 'rejected',
            rejection_reason = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $reason, $negotiation_id);
    $stmt->execute();
    
    sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
        "Seller has rejected the agreement. Reason: " . ($reason ?: 'Not specified'));
    
    return $stmt->affected_rows > 0;
}

/**
 * Admin approve after payment verification
 */
function approveListingPublish($conn, $negotiation_id) {
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if (!$negotiation) return false;
    
    $conn->begin_transaction();
    
    try {
        // Update negotiation status
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'published', 
                published_at = NOW(),
                updated_at = NOW()
            WHERE id = $negotiation_id
        ");
        
        // Update listing status
        $conn->query("
            UPDATE listings 
            SET status = 'active', 
                approval_status = 'approved',
                updated_at = NOW()
            WHERE id = {$negotiation['listing_id']}
        ");
        
        // Record payment if paid
        $final_commission = $negotiation['counter_commission'] ?: $negotiation['proposed_commission'];
        $final_deposit = $negotiation['counter_deposit'] ?: $negotiation['proposed_deposit'];
        $total_payment = $final_deposit + ($negotiation['featured_listing_fee'] ?? 0);
        
        // Create payment record if not exists
        $check_payment = $conn->query("
            SELECT id FROM payments 
            WHERE transaction_id IN (SELECT id FROM transactions WHERE listing_id = {$negotiation['listing_id']})
            AND type = 'deposit_seller'
        ");
        
        if ($check_payment->num_rows == 0) {
            $conn->query("
                INSERT INTO payments (user_id, amount, type, status, created_at) 
                VALUES ({$negotiation['seller_id']}, $total_payment, 'deposit_seller', 'pending', NOW())
            ");
        }
        
        $conn->commit();
        sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
            "🎉 Congratulations! Your listing has been published and is now visible to buyers.");
        
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

/**
 * Get negotiation timeline
 */
function getNegotiationTimeline($conn, $negotiation_id) {
    $negotiation = getNegotiationDetails($conn, $negotiation_id);
    if (!$negotiation) return [];
    
    $timeline = [
        ['status' => 'draft', 'label' => 'Listing Created', 'date' => $negotiation['created_at'], 'completed' => true],
        ['status' => 'under_review', 'label' => 'Under Review', 'date' => $negotiation['created_at'], 'completed' => $negotiation['status'] != 'draft']
    ];
    
    if ($negotiation['proposed_commission']) {
        $timeline[] = ['status' => 'commission_proposed', 'label' => 'Commission Proposed', 'date' => $negotiation['updated_at'], 
            'completed' => in_array($negotiation['status'], ['commission_proposed', 'counter_offer_sent', 'agreement_accepted', 'agreement_pending', 'deposit_pending', 'published'])];
    }
    
    if ($negotiation['counter_commission']) {
        $timeline[] = ['status' => 'counter_offer_received', 'label' => 'Counter Offer', 'date' => $negotiation['updated_at'],
            'completed' => in_array($negotiation['status'], ['agreement_accepted', 'agreement_pending', 'deposit_pending', 'published'])];
    }
    
    if ($negotiation['accepted_at']) {
        $timeline[] = ['status' => 'agreement_accepted', 'label' => 'Agreement Accepted', 'date' => $negotiation['accepted_at'],
            'completed' => in_array($negotiation['status'], ['deposit_pending', 'published'])];
    }
    
    if ($negotiation['deposit_paid_at']) {
        $timeline[] = ['status' => 'deposit_paid', 'label' => 'Deposit Paid', 'date' => $negotiation['deposit_paid_at'],
            'completed' => in_array($negotiation['status'], ['published'])];
    }
    
    if ($negotiation['published_at']) {
        $timeline[] = ['status' => 'published', 'label' => 'Listing Published', 'date' => $negotiation['published_at'],
            'completed' => true];
    }
    
    return $timeline;
}

/**
 * Update payment status for negotiation
 */
function updateNegotiationPaymentStatus($conn, $negotiation_id, $status) {
    $field = ($status == 'paid') ? 'deposit_paid_at' : '';
    $negotiation_status = ($status == 'paid') ? 'payment_verified' : 'agreement_accepted';
    
    $sql = "UPDATE listing_negotiations SET status = '$negotiation_status'";
    if ($field) {
        $sql .= ", $field = NOW()";
    }
    $sql .= " WHERE id = $negotiation_id";
    
    $conn->query($sql);
    
    if ($status == 'paid') {
        sendNegotiationMessage($conn, $negotiation_id, 0, 'system', 
            "Deposit payment has been verified. Your listing is being prepared for publication.");
    }
    
    return true;
}
?>