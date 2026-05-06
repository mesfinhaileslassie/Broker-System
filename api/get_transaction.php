<?php
// api/get_transaction.php - Get transaction details via API

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check authentication (optional - can be public for status checks)
$public_access = isset($_GET['public']) && $_GET['public'] == '1';

if (!$public_access) {
    session_start();
    if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $user_id = $_SESSION['user_id'];
}

$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$transaction_code = isset($_GET['code']) ? sanitizeString($_GET['code']) : '';

if (!$transaction_id && !$transaction_code) {
    echo json_encode(['success' => false, 'error' => 'Transaction ID or code required']);
    exit;
}

$conn = getDbConnection();

// Build query
if ($transaction_id) {
    $where = "t.id = $transaction_id";
    if (!$public_access && isset($user_id)) {
        $where .= " AND (t.buyer_id = $user_id OR t.seller_id = $user_id)";
    }
} else {
    $where = "t.payment_code_5digit = '$transaction_code'";
}

$result = $conn->query("
    SELECT 
        t.*,
        l.title as listing_title,
        l.type as listing_type,
        l.location as listing_location,
        l.cover_image,
        l.additional_details as listing_details,
        u1.id as buyer_id,
        u1.full_name as buyer_name,
        u1.email as buyer_email,
        u1.phone as buyer_phone,
        u2.id as seller_id,
        u2.full_name as seller_name,
        u2.email as seller_email,
        u2.phone as seller_phone,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND type IN ('deposit_buyer', 'commission') AND status = 'confirmed') as buyer_paid,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND type = 'deposit_seller' AND status = 'confirmed') as seller_paid
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE $where
");

if ($result && $result->num_rows > 0) {
    $transaction = $result->fetch_assoc();
    
    // Parse additional details
    $additional_details = [];
    if ($transaction['listing_details']) {
        $additional_details = json_decode($transaction['listing_details'], true);
    }
    
    // Calculate progress
    $deposit_percent = $transaction['admin_deposit_percent'] ?? 30;
    $commission_percent = $transaction['admin_commission_percent'] ?? 15;
    $deposit_amount = $transaction['total_amount'] * ($deposit_percent / 100);
    $commission_amount = $transaction['total_amount'] * ($commission_percent / 100);
    $buyer_required = $deposit_amount + $commission_amount;
    $seller_required = $deposit_amount;
    
    $buyer_paid = floatval($transaction['buyer_paid'] ?? 0);
    $seller_paid = floatval($transaction['seller_paid'] ?? 0);
    
    $response = [
        'success' => true,
        'transaction' => [
            'id' => $transaction['id'],
            'status' => $transaction['status'],
            'total_amount' => $transaction['total_amount'],
            'total_amount_formatted' => formatMoney($transaction['total_amount']),
            'deposit_percent' => $deposit_percent,
            'commission_percent' => $commission_percent,
            'deposit_amount' => $deposit_amount,
            'deposit_amount_formatted' => formatMoney($deposit_amount),
            'commission_amount' => $commission_amount,
            'commission_amount_formatted' => formatMoney($commission_amount),
            'buyer_required' => $buyer_required,
            'buyer_required_formatted' => formatMoney($buyer_required),
            'buyer_paid' => $buyer_paid,
            'buyer_paid_formatted' => formatMoney($buyer_paid),
            'buyer_remaining' => $buyer_required - $buyer_paid,
            'buyer_remaining_formatted' => formatMoney($buyer_required - $buyer_paid),
            'seller_required' => $seller_required,
            'seller_required_formatted' => formatMoney($seller_required),
            'seller_paid' => $seller_paid,
            'seller_paid_formatted' => formatMoney($seller_paid),
            'seller_remaining' => $seller_required - $seller_paid,
            'seller_remaining_formatted' => formatMoney($seller_required - $seller_paid),
            'both_deposits_paid' => ($buyer_paid >= $buyer_required && $seller_paid >= $seller_required),
            'created_at' => $transaction['created_at'],
            'created_at_formatted' => date('F d, Y H:i', strtotime($transaction['created_at'])),
            'completed_at' => $transaction['completed_at'],
            'payment_code' => $transaction['payment_code_5digit'],
            'legal_confirmed' => [
                'buyer' => (bool)$transaction['buyer_legal_confirmed'],
                'seller' => (bool)$transaction['seller_legal_confirmed'],
                'both' => ((bool)$transaction['buyer_legal_confirmed'] && (bool)$transaction['seller_legal_confirmed'])
            ],
            'delivery_confirmed' => [
                'buyer' => (bool)$transaction['buyer_delivery_confirmed'],
                'seller' => (bool)$transaction['seller_delivery_confirmed'],
                'both' => ((bool)$transaction['buyer_delivery_confirmed'] && (bool)$transaction['seller_delivery_confirmed'])
            ],
            'escrow_held' => $transaction['escrow_held'],
            'escrow_held_formatted' => formatMoney($transaction['escrow_held']),
            'escrow_released' => (bool)$transaction['escrow_released']
        ],
        'listing' => [
            'id' => $transaction['listing_id'],
            'title' => $transaction['listing_title'],
            'type' => $transaction['listing_type'],
            'location' => $transaction['listing_location'],
            'cover_image' => $transaction['cover_image'] ? '/broker_system/uploads/listings/' . $transaction['cover_image'] : null,
            'additional_details' => $additional_details
        ],
        'buyer' => [
            'id' => $transaction['buyer_id'],
            'name' => $transaction['buyer_name'],
            'email' => $transaction['buyer_email'],
            'phone' => $transaction['buyer_phone']
        ],
        'seller' => [
            'id' => $transaction['seller_id'],
            'name' => $transaction['seller_name'],
            'email' => $transaction['seller_email'],
            'phone' => $transaction['seller_phone']
        ]
    ];
    
    // Get payment history
    $payments = $conn->query("
        SELECT type, amount, telebirr_code_5digit, status, created_at
        FROM payments 
        WHERE transaction_id = {$transaction['id']} AND status = 'confirmed'
        ORDER BY created_at DESC
    ");
    
    $response['payments'] = [];
    while ($payment = $payments->fetch_assoc()) {
        $response['payments'][] = [
            'type' => $payment['type'],
            'type_label' => $payment['type'] == 'deposit_buyer' ? 'Buyer Deposit' : ($payment['type'] == 'deposit_seller' ? 'Seller Deposit' : ($payment['type'] == 'commission' ? 'Commission' : $payment['type'])),
            'amount' => $payment['amount'],
            'amount_formatted' => formatMoney($payment['amount']),
            'telebirr_code' => $payment['telebirr_code_5digit'],
            'status' => $payment['status'],
            'date' => $payment['created_at'],
            'date_formatted' => date('M d, Y H:i', strtotime($payment['created_at']))
        ];
    }
    
    // Get dispute info if any
    $dispute = $conn->query("
        SELECT id, reason, status, created_at
        FROM disputes 
        WHERE transaction_id = {$transaction['id']}
        ORDER BY created_at DESC LIMIT 1
    ");
    
    if ($dispute && $dispute->num_rows > 0) {
        $dispute_data = $dispute->fetch_assoc();
        $response['dispute'] = [
            'id' => $dispute_data['id'],
            'reason' => $dispute_data['reason'],
            'status' => $dispute_data['status'],
            'created_at' => $dispute_data['created_at']
        ];
    }
    
    echo json_encode($response);
} else {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
}

$conn->close();
?>