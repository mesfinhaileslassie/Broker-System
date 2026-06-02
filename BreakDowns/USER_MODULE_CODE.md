BRS/user/accept_terms.php

<?php
// user/accept_terms.php - Accept negotiation terms

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$negotiation_id = intval($_GET['negotiation_id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Verify ownership
$neg = $conn->query("SELECT * FROM listing_negotiations WHERE id = $negotiation_id AND seller_id = $user_id")->fetch_assoc();

if ($neg) {
    $conn->query("
        UPDATE listing_negotiations 
        SET status = 'agreement_accepted', accepted_at = NOW() 
        WHERE id = $negotiation_id
    ");
    
    // Add notification for admin
    $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
    $notif_stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, created_at) 
        VALUES (?, 'Terms Accepted', 'Seller has accepted the terms for listing. Awaiting deposit payment.', NOW())
    ");
    $notif_stmt->bind_param("i", $admin['id']);
    $notif_stmt->execute();
    
    $_SESSION['success'] = "Terms accepted! Please pay the deposit to publish your listing.";
} else {
    $_SESSION['error'] = "Invalid negotiation.";
}

$conn->close();
header('Location: listings.php');
exit;
?>

BRS/user/api/add_reaction.php

<?php
// user/api/add_reaction.php - Add reaction to message

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message_id = $input['message_id'] ?? $_POST['message_id'] ?? 0;
$reaction_type = $input['reaction_type'] ?? $_POST['reaction_type'] ?? '';

if (!$message_id || !$reaction_type) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$result = addReaction($conn, $message_id, $user_id, $reaction_type);

// Get updated reactions
$reactions = getMessageReactions($conn, $message_id);

$conn->close();

echo json_encode([
    'success' => true,
    'reactions' => $reactions,
    'message_id' => $message_id
]);
?>

BRS/user/api/check_payment_status.php

<?php
// user/api/check_payment_status.php - Complete payment confirmation with escrow

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/escrow_functions.php';
require_once '../../includes/transaction_workflow.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['confirmed' => false, 'error' => 'Unauthorized']);
    exit;
}

$code = $_GET['code'] ?? '';

if (!$code) {
    echo json_encode(['confirmed' => false, 'error' => 'No code provided']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Check if payment exists and is confirmed
$payment = $conn->query("
    SELECT p.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount, 
           t.deposit_amount, t.commission_amount, t.escrow_held, t.escrow_status,
           l.title, l.type AS listing_type
    FROM payments p
    JOIN transactions t ON p.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE p.telebirr_code_5digit = '$code' 
    AND p.status = 'confirmed'
    LIMIT 1
")->fetch_assoc();

$confirmed = ($payment !== null);

if ($confirmed && $payment) {
    syncTransactionPaymentState($conn, (int) $payment['transaction_id']);
}

if ($confirmed) {
    // Check if escrow already exists
    $escrow_exists = $conn->query("
        SELECT id FROM escrow_accounts 
        WHERE transaction_id = {$payment['transaction_id']} AND status = 'held'
    ")->num_rows > 0;
    
    if (!$escrow_exists) {
        // Create escrow record
        $conn->query("
            INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
            VALUES ({$payment['transaction_id']}, {$payment['user_id']}, {$payment['amount']}, 'buyer_deposit', 'held', NOW())
        ");
        
        // Update transaction escrow
        $new_escrow_held = $payment['escrow_held'] + $payment['amount'];
        $conn->query("
            UPDATE transactions 
            SET escrow_held = $new_escrow_held,
                escrow_status = 'active',
                status = 'escrow_active',
                updated_at = NOW()
            WHERE id = {$payment['transaction_id']}
        ");
        
        // Schedule auto-release based on listing type
        $listing_type = $payment['listing_type'] ?? 'product';
        $auto_days = ($listing_type === 'rental') ? 14 : (($listing_type === 'product') ? 5 : 10);
        $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
        
        $conn->query("
            INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
            VALUES ({$payment['transaction_id']}, '$release_date', 'pending')
            ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
        ");
        
        $conn->query("
            UPDATE transactions 
            SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
            WHERE id = {$payment['transaction_id']}
        ");
        
        // Add timeline
        addTransactionTimeline($conn, $payment['transaction_id'], 'payment_confirmed', 
            "Payment of " . formatMoney($payment['amount']) . " confirmed. Escrow activated.", $user_id);
        
        // Notify seller
        $seller_msg = "💰 Payment Received!\n\nItem: {$payment['title']}\nAmount: " . formatMoney($payment['amount']) . "\n\nThe payment is held securely in escrow. Please prepare for delivery.";
        $conn->query("
            INSERT INTO notifications (user_id, title, message, link, created_at) 
            VALUES ({$payment['seller_id']}, '💰 Payment Received', '$seller_msg', '/broker_system/user/transaction.php?id={$payment['transaction_id']}', NOW())
        ");
    }
}

$conn->close();

echo json_encode(['confirmed' => $confirmed]);
?>

BRS/user/api/clear_history.php

<?php
// user/api/clear_history.php - Clear all messages in conversation

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$conversation_id = $input['conversation_id'] ?? $_POST['conversation_id'] ?? 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Verify user has access to this conversation
$conv = $conn->query("SELECT user_id, broker_id FROM conversations WHERE id = $conversation_id")->fetch_assoc();

if (!$conv || ($conv['user_id'] != $user_id && $conv['broker_id'] != $user_id)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Delete all messages in this conversation for this user
$conn->query("UPDATE messages SET deleted_by_sender = 1, deleted_at = NOW() WHERE conversation_id = $conversation_id AND sender_id = $user_id");
$conn->query("UPDATE messages SET deleted_by_receiver = 1, deleted_at = NOW() WHERE conversation_id = $conversation_id AND receiver_id = $user_id");

// Check for messages that are deleted by both
$both_deleted = $conn->query("
    SELECT id FROM messages 
    WHERE conversation_id = $conversation_id 
    AND deleted_by_sender = 1 AND deleted_by_receiver = 1
");

while ($msg = $both_deleted->fetch_assoc()) {
    $conn->query("DELETE FROM messages WHERE id = {$msg['id']}");
    $conn->query("DELETE FROM message_reactions WHERE message_id = {$msg['id']}");
}

// Update conversation last message
$conn->query("UPDATE conversations SET last_message = NULL, last_message_time = NULL, updated_at = NOW() WHERE id = $conversation_id");

$conn->close();

echo json_encode(['success' => true]);
?>

BRS/user/api/confirm_delivery.php

<?php
// user/api/confirm_delivery.php - Buyer confirms delivery, triggers release

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

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, u.full_name as seller_name, u.email as seller_email
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

// Check if already confirmed
if ($transaction['buyer_delivery_confirmed']) {
    echo json_encode(['success' => false, 'error' => 'Delivery already confirmed']);
    exit;
}

$conn->begin_transaction();

try {
    // Mark buyer as confirmed
    $conn->query("UPDATE transactions SET buyer_delivery_confirmed = 1 WHERE id = $transaction_id");
    
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
        
        // Remove from admin escrow
        $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
        
        // Mark transaction as completed
        $conn->query("
            UPDATE transactions 
            SET status = 'completed', 
                completed_at = NOW(),
                released_at = NOW(),
                escrow_released = 1 
            WHERE id = $transaction_id
        ");
        
        // Add wallet transaction record
        $conn->query("
            INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
            VALUES ({$transaction['seller_id']}, $release_amount, 'deposit', 
                   'Payment released for: {$transaction['title']}', NOW())
        ");
        
        // Create notification for seller
        $conn->query("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES ({$transaction['seller_id']}, 'Payment Released', 
                   'Payment of " . formatMoney($release_amount) . " has been released to your wallet for {$transaction['title']}', NOW())
        ");
        
        $response['message'] = 'Delivery confirmed! Payment has been released to the seller.';
        $response['release_amount'] = $release_amount;
        $response['status'] = 'completed';
    } else {
        $response['message'] = 'Delivery confirmed. Waiting for seller confirmation to release payment.';
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

BRS/user/api/confirm_seller_delivery.php

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

BRS/user/api/delete_message.php

<?php
// user/api/delete_message.php - Delete message

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message_id = $input['message_id'] ?? $_POST['message_id'] ?? 0;

if (!$message_id) {
    echo json_encode(['success' => false, 'error' => 'Missing message ID']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$result = deleteMessage($conn, $message_id, $user_id);

$conn->close();

echo json_encode($result);
?>

BRS/user/api/force_activate.php

<?php
// api/force_activate.php - Force activate listing (for testing only)

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$listing_id = isset($input['listing_id']) ? intval($input['listing_id']) : 0;
$user_id = $_SESSION['user_id'];

if (!$listing_id) {
    echo json_encode(['success' => false, 'error' => 'Listing ID required']);
    exit;
}

$conn = getDbConnection();

// Verify ownership
$check = $conn->query("SELECT id FROM listings WHERE id = $listing_id AND seller_id = $user_id");
if ($check->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Force activate
$conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");

echo json_encode(['success' => true, 'message' => 'Listing activated']);

$conn->close();
?>

BRS/user/api/generate_payment_code.php

<?php
// api/generate_payment_code.php - Generate Telebirr Payment Code

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Please login to continue']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = isset($input['transaction_id']) ? intval($input['transaction_id']) : 0;
$amount = isset($input['amount']) ? floatval($input['amount']) : 0;
$payment_type = isset($input['payment_type']) ? htmlspecialchars($input['payment_type']) : 'deposit_buyer';
$user_id = $_SESSION['user_id'];

if (!$transaction_id || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction details']);
    exit;
}

$conn = getDbConnection();

// Verify transaction belongs to user
$check = $conn->query("
    SELECT t.*, l.title, l.type 
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
");

if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

$transaction = $check->fetch_assoc();

// Check if payment code already exists and is still valid
$existing = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'pending'
");

if ($existing->num_rows > 0) {
    $code_data = $existing->fetch_assoc();
    if (strtotime($code_data['expires_at']) > time()) {
        echo json_encode([
            'success' => true,
            'payment_code' => $code_data['code'],
            'amount' => $amount,
            'amount_formatted' => formatMoney($amount),
            'item_name' => $transaction['title'],
            'expires_at' => $code_data['expires_at'],
            'already_exists' => true
        ]);
        exit;
    }
}

// Generate unique 5-digit payment code
do {
    $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
} while ($code_check->num_rows > 0);

$expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));

// Insert payment code
$stmt = $conn->prepare("
    INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->bind_param("siidss", $payment_code, $transaction_id, $amount, $user_id, $payment_type, $expires_at);
$stmt->execute();

$conn->close();

echo json_encode([
    'success' => true,
    'payment_code' => $payment_code,
    'amount' => $amount,
    'amount_formatted' => formatMoney($amount),
    'item_name' => $transaction['title'],
    'expires_at' => $expires_at,
    'expires_in' => 1800
]);
?>

BRS/user/api/get_conversations.php

<?php
// user/api/get_conversations.php - Get conversations for sidebar

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$conversations = getUserConversations($conn, $user_id);

$result = [];
while ($conv = $conversations->fetch_assoc()) {
    $result[] = [
        'id' => $conv['id'],
        'other_user_name' => $conv['other_user_name'],
        'last_message' => $conv['last_message'],
        'last_message_time' => $conv['last_message_time'],
        'unread_count' => $conv['unread_count']
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'conversations' => $result
]);
?>

BRS/user/api/get_messages.php

<?php
// user/api/get_messages.php - Get messages with reactions

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conversation_id = $_GET['conversation_id'] ?? 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Verify user has access to this conversation
$conv = $conn->query("SELECT user_id, broker_id FROM conversations WHERE id = $conversation_id")->fetch_assoc();

if (!$conv || ($conv['user_id'] != $user_id && $conv['broker_id'] != $user_id)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get messages with delete filter
$messages = getMessagesWithDeleteFilter($conn, $conversation_id, $user_id, 100, 0);

$result = [];
foreach ($messages as $msg) {
    $result[] = [
        'id' => $msg['id'],
        'sender_id' => $msg['sender_id'],
        'receiver_id' => $msg['receiver_id'],
        'message' => $msg['message'],
        'time' => date('H:i', strtotime($msg['created_at'])),
        'date' => date('Y-m-d H:i:s', strtotime($msg['created_at'])),
        'reactions' => $msg['reactions'],
        'my_reaction' => $msg['my_reaction'],
        'can_delete' => ($msg['sender_id'] == $user_id || $msg['receiver_id'] == $user_id)
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'messages' => $result
]);
?>

BRS/user/api/mark_notifications_read.php

<?php
// user/api/mark_notifications_read.php - Mark all notifications as read

session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");

$conn->close();

echo json_encode(['success' => true]);
?>

BRS/user/api/mark_read.php

<?php
// user/api/mark_read.php - Mark messages as read

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conversation_id = $_POST['conversation_id'] ?? 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

markMessagesAsRead($conn, $conversation_id, $user_id);

$conn->close();

echo json_encode(['success' => true]);
?>

BRS/user/api/pay_remaining.php

<?php
// user/api/pay_remaining.php - Initiate / summarize seller remaining balance payment

session_start();
header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/seller_listing_payment.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$listing_id = (int) ($input['listing_id'] ?? $_GET['listing_id'] ?? 0);
$action = $input['action'] ?? ($_GET['action'] ?? 'summary');

if ($listing_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid listing ID']);
    $conn->close();
    exit;
}

$info = getSellerListingPaymentInfo($conn, $listing_id, $user_id);

if (!$info) {
    echo json_encode(['success' => false, 'error' => 'Listing not found or access denied']);
    $conn->close();
    exit;
}

$summary = [
    'listing_id' => $info['listing_id'],
    'total_price' => $info['total_price'],
    'total_price_formatted' => formatMoney($info['total_price']),
    'deposit_paid' => $info['deposit_paid'],
    'deposit_paid_formatted' => formatMoney($info['deposit_paid']),
    'amount_paid' => $info['amount_paid'],
    'remaining_balance' => $info['remaining_balance'],
    'remaining_balance_formatted' => formatMoney($info['remaining_balance']),
    'payment_status' => $info['payment_status'],
    'can_pay_remaining' => $info['can_pay_remaining'],
    'is_fully_paid' => $info['payment_status'] === 'fully_paid',
];

if ($action === 'summary' || $method === 'GET') {
    echo json_encode(['success' => true, 'summary' => $summary]);
    $conn->close();
    exit;
}

if ($action !== 'initiate' || $method !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    $conn->close();
    exit;
}

if (!$info['can_pay_remaining']) {
    echo json_encode([
        'success' => false,
        'error' => $info['payment_status'] === 'fully_paid'
            ? 'This listing is already fully paid'
            : 'Remaining balance cannot be paid at this time',
        'summary' => $summary,
    ]);
    $conn->close();
    exit;
}

// Prevent duplicate confirmed remaining payment
$dup = $conn->query("
    SELECT id FROM payments
    WHERE transaction_id = {$info['transaction_id']}
      AND type = 'remaining_balance'
      AND status = 'confirmed'
    LIMIT 1
");
if ($dup && $dup->num_rows > 0 && $info['remaining_balance'] <= 0) {
    echo json_encode(['success' => false, 'error' => 'Remaining balance already paid', 'summary' => $summary]);
    $conn->close();
    exit;
}

// Reuse existing pending code if valid
$existing = $conn->query("
    SELECT code, amount,
           TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
    FROM payment_codes
    WHERE transaction_id = {$info['transaction_id']}
      AND user_id = {$user_id}
      AND type = 'remaining_balance'
      AND status = 'pending'
      AND expires_at > NOW()
    ORDER BY id DESC
    LIMIT 1
");

if ($existing && $existing->num_rows > 0) {
    $code_row = $existing->fetch_assoc();
    echo json_encode([
        'success' => true,
        'message' => 'Existing payment code retrieved',
        'payment_code' => $code_row['code'],
        'amount' => (float) $code_row['amount'],
        'amount_formatted' => formatMoney($code_row['amount']),
        'seconds_remaining' => max(0, (int) $code_row['seconds_remaining']),
        'pay_url' => '/broker_system/user/pay_remaining.php?listing_id=' . $listing_id,
        'summary' => $summary,
    ]);
    $conn->close();
    exit;
}

$amount = $info['remaining_balance'];
if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'No remaining balance due', 'summary' => $summary]);
    $conn->close();
    exit;
}

do {
    $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $code_check = $conn->prepare('SELECT id FROM payment_codes WHERE code = ? LIMIT 1');
    $code_check->bind_param('s', $payment_code);
    $code_check->execute();
    $exists = $code_check->get_result()->num_rows > 0;
    $code_check->close();
} while ($exists);

$stmt = $conn->prepare("
    INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
    VALUES (?, ?, ?, ?, 'remaining_balance', DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending', NOW())
");
$stmt->bind_param('sidi', $payment_code, $info['transaction_id'], $amount, $user_id);
$stmt->execute();
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'Payment code generated',
    'payment_code' => $payment_code,
    'amount' => $amount,
    'amount_formatted' => formatMoney($amount),
    'seconds_remaining' => 1800,
    'pay_url' => '/broker_system/user/pay_remaining.php?listing_id=' . $listing_id,
    'summary' => $summary,
]);

$conn->close();


BRS/user/api/pay_rental.php

<?php
// user/pay_rental.php - Payment page for rentals and services

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Complete Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$error = '';
$success = '';

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.price, u.full_name as seller_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

// Calculate payment amount
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$totalDue = $depositAmount + $commissionAmount;

// Check if already paid
$payment_check = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'confirmed'
");

$already_paid = $payment_check->num_rows > 0;

// Get existing payment code
$code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'pending'
")->fetch_assoc();

$payment_code = $code_data ? $code_data['code'] : null;
$code_expires = $code_data ? $code_data['expires_at'] : null;

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .payment-container {
        max-width: 800px;
        margin: 0 auto;
    }
    
    /* Header */
    .payment-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .payment-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .payment-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .payment-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    /* Cards */
    .card {
        background: white;
        border-radius: 24px;
        padding: 28px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .card-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    /* Item Details */
    .item-details {
        background: var(--light);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .item-name {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .item-type {
        display: inline-block;
        padding: 4px 12px;
        background: var(--primary);
        color: white;
        border-radius: 20px;
        font-size: 11px;
        margin-bottom: 16px;
    }
    
    .price-breakdown {
        margin-top: 16px;
    }
    
    .breakdown-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .breakdown-row.total {
        font-weight: 700;
        font-size: 18px;
        color: var(--primary);
        border-top: 2px solid var(--border);
        border-bottom: none;
        margin-top: 8px;
        padding-top: 16px;
    }
    
    /* Payment Code Box */
    .code-box {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 20px;
        padding: 30px;
        text-align: center;
        margin-bottom: 24px;
    }
    
    .code-label {
        font-size: 12px;
        color: rgba(255,255,255,0.8);
        margin-bottom: 8px;
    }
    
    .payment-code {
        font-size: 48px;
        font-weight: 800;
        letter-spacing: 12px;
        background: white;
        color: var(--dark);
        padding: 20px;
        border-radius: 16px;
        font-family: monospace;
        margin: 16px 0;
    }
    
    .copy-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 8px 24px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .copy-btn:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .expiry {
        font-size: 12px;
        color: rgba(255,255,255,0.7);
        margin-top: 12px;
    }
    
    /* Instructions */
    .instructions {
        background: var(--light);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 24px;
    }
    
    .step {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 0;
    }
    
    .step-number {
        width: 32px;
        height: 32px;
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 14px;
    }
    
    /* Buttons */
    .btn {
        width: 100%;
        padding: 14px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        text-align: center;
        display: inline-block;
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-success {
        background: var(--success);
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    /* Timer */
    .timer {
        font-family: monospace;
        font-size: 14px;
        font-weight: 600;
    }
    
    .timer.warning {
        color: var(--warning);
    }
    
    .timer.danger {
        color: var(--danger);
    }
    
    /* Alert */
    .alert {
        padding: 14px 18px;
        border-radius: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    /* Loading */
    .loading {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 20px;
    }
    
    .spinner {
        width: 20px;
        height: 20px;
        border: 2px solid var(--border);
        border-top-color: var(--primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    @media (max-width: 640px) {
        .payment-code {
            font-size: 28px;
            letter-spacing: 6px;
        }
        .card {
            padding: 20px;
        }
    }
</style>

<div class="payment-container">
    <!-- Header -->
    <div class="payment-header">
        <h1><i class="fas fa-credit-card"></i> Complete Payment</h1>
        <p>Pay securely using Telebirr</p>
    </div>
    
    <?php if ($already_paid): ?>
        <div class="card">
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Payment already completed!</strong><br>
                    Your payment has been confirmed. You can track your transaction progress.
                </div>
            </div>
            <a href="transaction.php?id=<?php echo $transaction_id; ?>" class="btn btn-primary">
                <i class="fas fa-eye"></i> View Transaction
            </a>
        </div>
    <?php else: ?>
        <!-- Item Details -->
        <div class="card">
            <div class="card-title">
                <i class="fas fa-receipt"></i> Payment Summary
            </div>
            <div class="item-details">
                <div class="item-name"><?php echo htmlspecialchars($transaction['title']); ?></div>
                <span class="item-type">
                    <?php 
                    if ($transaction['type'] == 'rental') echo '🏠 Rental Property';
                    elseif ($transaction['type'] == 'product') echo '🚗 Product';
                    else echo '💼 Service';
                    ?>
                </span>
                <div class="price-breakdown">
                    <div class="breakdown-row">
                        <span>Total Price</span>
                        <span><?php echo formatMoney($transaction['total_amount']); ?></span>
                    </div>
                    <div class="breakdown-row">
                        <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                        <span><?php echo formatMoney($depositAmount); ?></span>
                    </div>
                    <div class="breakdown-row">
                        <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                        <span><?php echo formatMoney($commissionAmount); ?></span>
                    </div>
                    <div class="breakdown-row total">
                        <span>Total to Pay</span>
                        <span><?php echo formatMoney($totalDue); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Code Section -->
        <div class="card" id="paymentCodeCard">
            <div class="card-title">
                <i class="fas fa-mobile-alt"></i> Telebirr Payment
            </div>
            
            <?php if ($payment_code): ?>
                <!-- Existing Code Display -->
                <div class="code-box">
                    <div class="code-label">Your Telebirr Payment Code</div>
                    <div class="payment-code" id="paymentCode"><?php echo $payment_code; ?></div>
                    <button class="copy-btn" onclick="copyCode()">
                        <i class="fas fa-copy"></i> Copy Code
                    </button>
                    <div class="expiry" id="expiryDisplay">
                        <i class="far fa-clock"></i> Expires: <span id="expiryTime"><?php echo date('H:i:s', strtotime($code_expires)); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <!-- Generate New Code -->
                <div style="text-align: center; padding: 20px;">
                    <p style="margin-bottom: 16px; color: var(--gray);">
                        Click below to generate a Telebirr payment code for this transaction.
                    </p>
                    <button onclick="generatePaymentCode()" class="btn btn-primary" id="generateBtn">
                        <i class="fas fa-key"></i> Generate Payment Code
                    </button>
                </div>
                <div id="codeDisplay" style="display: none;"></div>
            <?php endif; ?>
            
            <!-- Instructions -->
            <div class="instructions">
                <h4 style="margin-bottom: 12px;"><i class="fas fa-info-circle"></i> How to Pay</h4>
                <div class="step">
                    <div class="step-number">1</div>
                    <div>Open Telebirr app on your mobile phone</div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div>Go to <strong>Marketplace</strong> or <strong>Pay with Code</strong> section</div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div>Enter the <strong>5-digit payment code</strong> shown above</div>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <div>Confirm payment with your Telebirr PIN (Demo PIN: <strong>1234</strong>)</div>
                </div>
            </div>
        </div>
        
        <!-- Payment Status -->
        <div class="card" id="statusCard" style="display: none;">
            <div class="card-title">
                <i class="fas fa-hourglass-half"></i> Payment Status
            </div>
            <div id="paymentStatus">
                <div class="loading">
                    <div class="spinner"></div>
                    <span>Waiting for payment confirmation...</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
let paymentCode = '<?php echo $payment_code; ?>';
let transactionId = <?php echo $transaction_id; ?>;
let checkInterval;
let timerInterval;
let timeLeft = <?php echo $code_expires ? max(0, strtotime($code_expires) - time()) : 0; ?>;

function copyCode() {
    const code = document.getElementById('paymentCode').innerText;
    navigator.clipboard.writeText(code);
    alert('Payment code copied: ' + code);
}

function generatePaymentCode() {
    const generateBtn = document.getElementById('generateBtn');
    generateBtn.disabled = true;
    generateBtn.innerHTML = '<div class="spinner"></div> Generating...';
    
    fetch('/broker_system/api/generate_payment_code.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            transaction_id: transactionId,
            amount: <?php echo $totalDue; ?>,
            payment_type: 'deposit_buyer'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            paymentCode = data.payment_code;
            timeLeft = data.expires_in;
            
            // Display the code
            document.getElementById('codeDisplay').innerHTML = `
                <div class="code-box">
                    <div class="code-label">Your Telebirr Payment Code</div>
                    <div class="payment-code" id="paymentCode">${data.payment_code}</div>
                    <button class="copy-btn" onclick="copyCode()">
                        <i class="fas fa-copy"></i> Copy Code
                    </button>
                    <div class="expiry" id="expiryDisplay">
                        <i class="far fa-clock"></i> Expires in: <span id="timer">${formatTime(timeLeft)}</span>
                    </div>
                </div>
                <div class="instructions">
                    <h4><i class="fas fa-info-circle"></i> How to Pay</h4>
                    <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your mobile phone</div></div>
                    <div class="step"><div class="step-number">2</div><div>Go to Marketplace or Pay with Code section</div></div>
                    <div class="step"><div class="step-number">3</div><div>Enter the 5-digit payment code: <strong>${data.payment_code}</strong></div></div>
                    <div class="step"><div class="step-number">4</div><div>Confirm payment with your Telebirr PIN (Demo: 1234)</div></div>
                </div>
            `;
            document.getElementById('codeDisplay').style.display = 'block';
            document.getElementById('generateBtn').parentElement.style.display = 'none';
            document.getElementById('statusCard').style.display = 'block';
            
            startTimer();
            startPaymentCheck();
        } else {
            alert('Error: ' + data.error);
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-key"></i> Generate Payment Code';
        }
    })
    .catch(error => {
        alert('Error generating code: ' + error);
        generateBtn.disabled = false;
        generateBtn.innerHTML = '<i class="fas fa-key"></i> Generate Payment Code';
    });
}

function formatTime(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

function startTimer() {
    if (timerInterval) clearInterval(timerInterval);
    
    timerInterval = setInterval(() => {
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            clearInterval(checkInterval);
            document.getElementById('expiryDisplay').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Code expired. Please generate a new code.';
            document.getElementById('statusCard').style.display = 'none';
        } else {
            timeLeft--;
            const timerSpan = document.getElementById('timer');
            if (timerSpan) {
                timerSpan.textContent = formatTime(timeLeft);
                if (timeLeft < 300) {
                    timerSpan.style.color = '#f59e0b';
                }
                if (timeLeft < 60) {
                    timerSpan.style.color = '#ef4444';
                }
            }
        }
    }, 1000);
}

function startPaymentCheck() {
    if (checkInterval) clearInterval(checkInterval);
    
    checkInterval = setInterval(() => {
        fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode)
            .then(response => response.json())
            .then(data => {
                if (data.confirmed) {
                    clearInterval(checkInterval);
                    clearInterval(timerInterval);
                    document.getElementById('paymentStatus').innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Payment Confirmed!</strong><br>
                                Your payment has been received. Redirecting to transaction page...
                            </div>
                        </div>
                    `;
                    setTimeout(() => {
                        window.location.href = 'transaction.php?id=' + transactionId;
                    }, 3000);
                }
            });
    }, 3000);
}

<?php if ($payment_code): ?>
// If code already exists, start checking
startTimer();
startPaymentCheck();
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/api/process_payment.php

<?php
// user/api/process_payment.php - Process payment (NO PIN CHECK)

session_start();
require_once '../../config/database.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$user_id = $_SESSION['user_id'];

if (!$code) {
    echo json_encode(['success' => false, 'error' => 'Missing payment code']);
    exit;
}

$conn = getDbConnection();

// Get payment code details
$payment = $conn->query("
    SELECT pc.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    WHERE pc.code = '$code' AND pc.status = 'pending'
")->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

// Check expiry
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

$amount = $payment['amount'];
$transaction_id = $payment['transaction_id'];

$conn->begin_transaction();

try {
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment
    $payment_type = ($payment['user_id'] == $payment['buyer_id']) ? 'deposit_buyer' : 'deposit_seller';
    $stmt = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
    $stmt->bind_param("iidss", $transaction_id, $payment['user_id'], $amount, $payment_type, $code);
    $stmt->execute();
    
    // Update escrow
    $conn->query("UPDATE transactions SET escrow_held = escrow_held + $amount WHERE id = $transaction_id");
    
    // Check if both deposits are complete
    $txn = $conn->query("SELECT escrow_held, deposit_amount, commission_amount FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    $required = $txn['deposit_amount'] * 2 + $txn['commission_amount'];
    
    if ($txn['escrow_held'] >= $required) {
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'transaction_id' => $transaction_id
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>

BRS/user/api/release_payment.php

<?php
// admin/release_payment.php - Admin manual payment release

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdminLogin();

$page_title = 'Manual Payment Release';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($transaction_id) {
    $transaction = $conn->query("
        SELECT t.*, l.title, u1.full_name as buyer_name, u2.full_name as seller_name
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        JOIN users u1 ON t.buyer_id = u1.id
        JOIN users u2 ON t.seller_id = u2.id
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = intval($_POST['transaction_id']);
    $action = $_POST['action'];
    
    if ($action === 'release') {
        $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
        
        $conn->begin_transaction();
        
        try {
            // Release to seller
            $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$transaction['seller_id']}");
            $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
            $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW(), released_at = NOW() WHERE id = $transaction_id");
            
            // Add wallet transaction
            $conn->query("
                INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                VALUES ({$transaction['seller_id']}, $release_amount, 'deposit', 
                       'Admin released payment for: {$transaction['title']}', NOW())
            ");
            
            $conn->commit();
            $message = "Payment released successfully to {$transaction['seller_name']}";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to release payment: " . $e->getMessage();
        }
    }
}

$conn->close();
?>

<style>
    .release-container { max-width: 800px; margin: 0 auto; }
    .card { background: white; border-radius: 20px; padding: 28px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .info-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
    .btn-release { background: #10b981; color: white; padding: 14px 28px; border: none; border-radius: 40px; font-weight: 600; cursor: pointer; width: 100%; }
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
</style>

<div class="release-container">
    <h1 style="margin-bottom: 20px;">Manual Payment Release</h1>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($transaction): ?>
        <div class="card">
            <h3>Transaction #<?php echo $transaction['id']; ?></h3>
            <div class="info-row">
                <span>Item:</span>
                <strong><?php echo htmlspecialchars($transaction['title']); ?></strong>
            </div>
            <div class="info-row">
                <span>Buyer:</span>
                <span><?php echo htmlspecialchars($transaction['buyer_name']); ?></span>
            </div>
            <div class="info-row">
                <span>Seller:</span>
                <span><?php echo htmlspecialchars($transaction['seller_name']); ?></span>
            </div>
            <div class="info-row">
                <span>Total Amount:</span>
                <strong><?php echo formatMoney($transaction['total_amount']); ?></strong>
            </div>
            <div class="info-row">
                <span>Commission (<?php echo $transaction['commission_percent']; ?>%):</span>
                <span><?php echo formatMoney($transaction['commission_amount']); ?></span>
            </div>
            <div class="info-row">
                <span>Amount to Release:</span>
                <strong style="color: #10b981;"><?php echo formatMoney($transaction['total_amount'] - $transaction['commission_amount']); ?></strong>
            </div>
            <div class="info-row">
                <span>Current Status:</span>
                <span class="badge"><?php echo $transaction['status']; ?></span>
            </div>
            
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                <input type="hidden" name="action" value="release">
                <button type="submit" class="btn-release" onclick="return confirm('Release payment to seller? This action cannot be undone.')">
                    <i class="fas fa-money-bill-wave"></i> Release Payment to Seller
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <p>Enter a transaction ID to release payment:</p>
            <form method="GET">
                <input type="number" name="id" placeholder="Transaction ID" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px;">
                <button type="submit" class="btn-release">Load Transaction</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/user/api/send_message.php

<?php
// user/api/send_message.php - Send message API

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conversation_id = $_POST['conversation_id'] ?? 0;
$message = $_POST['message'] ?? '';

if (!$conversation_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get conversation details
$conv = $conn->query("SELECT user_id, broker_id FROM conversations WHERE id = $conversation_id")->fetch_assoc();

if (!$conv) {
    echo json_encode(['success' => false, 'error' => 'Conversation not found']);
    exit;
}

$receiver_id = ($conv['user_id'] == $user_id) ? $conv['broker_id'] : $conv['user_id'];

$message_id = sendMessage($conn, $conversation_id, $user_id, $receiver_id, $message);

$conn->close();

echo json_encode([
    'success' => true,
    'message_id' => $message_id
]);
?>

BRS/user/api/start_conversation.php

<?php
// user/api/start_conversation.php - Start new conversation

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$broker_id = $_GET['broker_id'] ?? 0;

if (!$broker_id) {
    header('Location: /broker_system/user/chat.php');
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$conversation_id = getOrCreateConversation($conn, $user_id, $broker_id);

$conn->close();

header("Location: /broker_system/user/chat.php?id=$conversation_id");
?>

BRS/user/api/transaction_workflow.php

<?php
// user/api/transaction_workflow.php - Transaction actions API

session_start();
header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/transaction_workflow.php';
require_once '../../includes/payment_confirm.php';

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}

$conn = getDbConnection();
$user_id = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? ($_GET['action'] ?? '');
$transaction_id = (int) ($input['transaction_id'] ?? $_GET['transaction_id'] ?? 0);

if ($transaction_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction ID']);
    exit;
}

switch ($action) {
    case 'summary':
        $view = getTransactionWorkflowView($conn, $transaction_id);
        if (!$view) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found']);
            break;
        }
        $is_buyer = ((int) $view['buyer_id'] === $user_id);
        $is_seller = ((int) $view['seller_id'] === $user_id);
        if (!$is_buyer && !$is_seller) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        echo json_encode([
            'success' => true,
            'transaction' => [
                'id' => (int) $view['id'],
                'total_amount' => (float) $view['total_amount'],
                'amount_paid' => (float) ($view['amount_paid'] ?? 0),
                'remaining_balance' => (float) $view['remaining_balance'],
                'payment_status' => $view['payment_status'] ?? 'pending',
                'funds_status' => $view['funds_status'] ?? ($view['escrow_status'] ?? 'pending'),
                'status' => $view['status'],
                'seller_confirmed' => (bool) ($view['seller_confirmed'] ?? 0),
                'buyer_confirmed' => (bool) ($view['buyer_confirmed'] ?? 0),
            ],
            'can_pay_remaining' => $is_buyer
                && ($view['payment_status'] ?? '') !== 'fully_paid'
                && (float) $view['remaining_balance'] > 0,
        ]);
        break;

    case 'pay_remaining':
        $result = initiateBuyerRemainingPayment($conn, $transaction_id, $user_id);
        echo json_encode($result);
        break;

    case 'confirm_payment':
        $code = trim($input['payment_code'] ?? '');
        $pin = trim($input['pin'] ?? '');
        if ($pin !== '1234') {
            echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234']);
            break;
        }
        echo json_encode(confirmPaymentByCode($conn, $code, ['user_id' => $user_id]));
        break;

    case 'seller_confirm':
        echo json_encode(markSellerConfirmed($conn, $transaction_id, $user_id, $input['notes'] ?? ''));
        break;

    case 'buyer_confirm':
        echo json_encode(markBuyerConfirmed($conn, $transaction_id, $user_id, $input['notes'] ?? ''));
        break;

    case 'open_dispute':
        echo json_encode(openTransactionDispute($conn, $transaction_id, $user_id, $input['reason'] ?? ''));
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

$conn->close();


BRS/user/api/typing.php

<?php
// user/api/typing.php - Typing indicator with proper user tracking

session_start();
require_once '../../config/database.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['typing' => false, 'typing_user_id' => null]);
    exit;
}

$conversation_id = $_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0;
$is_typing = $_POST['typing'] ?? false;
$user_id = $_SESSION['user_id'];

if (!$conversation_id) {
    echo json_encode(['typing' => false, 'typing_user_id' => null]);
    exit;
}

$conn = getDbConnection();

if ($is_typing !== false) {
    // Store typing status in database
    $table_exists = $conn->query("SHOW TABLES LIKE 'conversation_typing'");
    if ($table_exists->num_rows == 0) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS conversation_typing (
                conversation_id INT PRIMARY KEY,
                user_id INT,
                typing_until DATETIME,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                INDEX idx_typing (typing_until)
            )
        ");
    }
    
    $typing_until = date('Y-m-d H:i:s', time() + 3); // Typing indicator lasts 3 seconds
    
    if ($is_typing == 'true' || $is_typing === true) {
        $conn->query("
            INSERT INTO conversation_typing (conversation_id, user_id, typing_until) 
            VALUES ($conversation_id, $user_id, '$typing_until')
            ON DUPLICATE KEY UPDATE user_id = $user_id, typing_until = '$typing_until'
        ");
    } else {
        $conn->query("DELETE FROM conversation_typing WHERE conversation_id = $conversation_id AND user_id = $user_id");
    }
}

// Check if someone is typing in this conversation (other than current user)
$typing_data = $conn->query("
    SELECT user_id, typing_until 
    FROM conversation_typing 
    WHERE conversation_id = $conversation_id 
    AND typing_until > NOW()
    AND user_id != $user_id
    LIMIT 1
");

$is_other_typing = $typing_data && $typing_data->num_rows > 0;
$typing_user_id = null;

if ($is_other_typing) {
    $row = $typing_data->fetch_assoc();
    $typing_user_id = $row['user_id'];
}

$conn->close();

echo json_encode([
    'typing' => $is_other_typing,
    'typing_user_id' => $typing_user_id
]);
?>

BRS/user/apply_job.php

<?php
// user/apply_job.php - Apply for Job with Payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Apply for Job';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$job_id = sanitizeInt($_GET['id'] ?? 0);
$error = '';
$success = '';

// Get job details
$job = $conn->query("
    SELECT l.*, u.full_name as company_name, u.id as company_id,
           l.admin_deposit_percent, l.admin_commission_percent
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $job_id 
    AND l.type = 'job' 
    AND l.status = 'active' 
    AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$job) {
    header('Location: jobs.php');
    exit;
}

// Check if already applied
$existing = $conn->query("
    SELECT id FROM transactions 
    WHERE listing_id = $job_id AND buyer_id = $user_id
");
if ($existing->num_rows > 0) {
    $existing_txn = $existing->fetch_assoc();
    header("Location: transaction.php?id={$existing_txn['id']}");
    exit;
}

// Calculate payment amounts
$depositPercent = $job['admin_deposit_percent'] ?? getSetting("deposit_percent_job", 30);
$commissionPercent = $job['admin_commission_percent'] ?? getSetting("commission_percent_job", 15);
$depositAmount = $job['price'] * ($depositPercent / 100);
$commissionAmount = $job['price'] * ($commissionPercent / 100);
$totalUpfront = $depositAmount + $commissionAmount;
$remainingAmount = $job['price'] - $depositAmount;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cover_letter = sanitizeString($_POST['cover_letter'] ?? '');
    $expected_salary = sanitizeFloat($_POST['expected_salary'] ?? $job['price']);
    
    $errors = [];
    
    if (empty($cover_letter)) {
        $errors[] = "Please provide a cover letter explaining why you're a good fit";
    }
    if (strlen($cover_letter) < 50) {
        $errors[] = "Cover letter must be at least 50 characters";
    }
    if (strlen($cover_letter) > 5000) {
        $errors[] = "Cover letter must not exceed 5000 characters";
    }
    if ($expected_salary < 0) {
        $errors[] = "Please enter a valid expected salary";
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Create transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    listing_id, buyer_id, seller_id, total_amount, 
                    deposit_amount, commission_amount, remaining_balance, 
                    status, created_at, cover_letter, expected_salary
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW(), ?, ?)
            ");
            $stmt->bind_param("iiiddddsd", 
                $job_id, $user_id, $job['company_id'], 
                $job['price'], $depositAmount, $commissionAmount, $remainingAmount,
                $cover_letter, $expected_salary
            );
            $stmt->execute();
            $transaction_id = $conn->insert_id;
            
            // Generate payment code
            do {
                $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
            } while ($code_check->num_rows > 0);
            
            $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            
            // Store payment code
            $stmt2 = $conn->prepare("
                INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
                VALUES (?, ?, ?, ?, 'deposit_buyer', ?, 'pending')
            ");
            $stmt2->bind_param("siids", $payment_code, $transaction_id, $totalUpfront, $user_id, $expires_at);
            $stmt2->execute();
            
            // Create notification for company
            $conn->query("
                INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$job['company_id']}, 'New Job Application', 
                'A new application has been submitted for {$job['title']}', NOW())
            ");
            
            $conn->commit();
            
            // Redirect to payment page
            header("Location: pay_application.php?transaction_id=$transaction_id&code=$payment_code");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to submit application: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$conn->close();
?>

<style>
    .apply-container { max-width: 800px; margin: 0 auto; }
    .job-header { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 24px; padding: 28px; color: white; margin-bottom: 28px; }
    .job-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
    .company-name { font-size: 14px; opacity: 0.9; margin-bottom: 16px; }
    .job-salary { font-size: 24px; font-weight: 700; margin-top: 16px; }
    
    .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .form-group { margin-bottom: 24px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 14px; }
    .required { color: #ef4444; }
    input, textarea { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.3s; }
    input:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    textarea { resize: vertical; min-height: 150px; }
    
    .payment-breakdown { background: #f8fafc; border-radius: 20px; padding: 20px; margin-bottom: 24px; }
    .breakdown-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
    .breakdown-item:last-child { border-bottom: none; }
    .breakdown-item.total { font-weight: 700; font-size: 18px; margin-top: 8px; padding-top: 12px; border-top: 2px solid #e2e8f0; }
    
    .btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .info-text { font-size: 11px; color: #64748b; margin-top: 6px; }
    
    @media (max-width: 640px) {
        .job-title { font-size: 22px; }
        .job-salary { font-size: 20px; }
        .card { padding: 20px; }
    }
</style>

<div class="apply-container">
    <!-- Job Header -->
    <div class="job-header">
        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
        <div class="company-name"><i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?></div>
        <div class="job-salary"><?php echo formatMoney($job['price']); ?>/month</div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Application Form -->
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;"><i class="fas fa-file-alt"></i> Job Application</h2>
        
        <form method="POST">
            <div class="form-group">
                <label>Cover Letter <span class="required">*</span></label>
                <textarea name="cover_letter" required placeholder="Introduce yourself, explain why you're interested in this position, and highlight your relevant skills and experience..."></textarea>
                <div class="info-text">Minimum 50 characters. Be specific about how you can contribute to the company.</div>
            </div>
            
            <div class="form-group">
                <label>Expected Salary (ETB/month)</label>
                <input type="number" name="expected_salary" step="100" value="<?php echo $job['price']; ?>" min="0">
                <div class="info-text">Your expected monthly salary (optional)</div>
            </div>
            
            <!-- Payment Breakdown -->
            <div class="payment-breakdown">
                <h3 style="font-size: 16px; margin-bottom: 16px;">Payment Summary</h3>
                <div class="breakdown-item">
                    <span>Monthly Salary</span>
                    <span><?php echo formatMoney($job['price']); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <div class="breakdown-item total">
                    <span>You Pay Today (Deposit + Fee)</span>
                    <span><?php echo formatMoney($totalUpfront); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Remaining (paid after job completion)</span>
                    <span><?php echo formatMoney($remainingAmount); ?></span>
                </div>
            </div>
            
            <div class="info-text" style="background: #dbeafe; padding: 12px; border-radius: 12px; margin-bottom: 20px;">
                <i class="fas fa-shield-alt"></i> <strong>Secure Escrow Payment</strong><br>
                Your deposit and fee are held in escrow until you confirm job completion. You're protected against fraud.
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Submit Application & Pay
            </button>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/browse.php

<?php
// user/browse.php - Only shows AVAILABLE listings (not reserved)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/AvailabilityManager.php';

$page_title = 'Browse Listings';
ob_start();

$conn = getDbConnection();
$availabilityManager = new AvailabilityManager($conn);

// Get and sanitize filter parameters
$type = isset($_GET['type']) ? htmlspecialchars(trim($_GET['type']), ENT_QUOTES, 'UTF-8') : '';
$search = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search']), ENT_QUOTES, 'UTF-8') : '';
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$location = isset($_GET['location']) ? htmlspecialchars(trim($_GET['location']), ENT_QUOTES, 'UTF-8') : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$sort = isset($_GET['sort']) ? htmlspecialchars(trim($_GET['sort']), ENT_QUOTES, 'UTF-8') : 'newest';

// Validate parameters
$valid_types = ['', 'product', 'job', 'rental'];
if (!in_array($type, $valid_types)) {
    $type = '';
}

$valid_sorts = ['newest', 'price_low', 'price_high', 'popular'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'newest';
}

if ($page < 1) $page = 1;
if ($page > 100) $page = 100;
if ($min_price < 0) $min_price = 0;
if ($max_price < 0) $max_price = 0;

$limit = 12;
$offset = ($page - 1) * $limit;

// IMPORTANT: Only show AVAILABLE listings (not reserved, not rented)
// availability_status must be 'available'
$where = [
    "l.status = 'active'", 
    "l.approval_status = 'approved'",
    "l.availability_status = 'available'"  // ← CRITICAL: Hide reserved/rented listings
];
$params = [];
$types_param = "";

if ($type) {
    $where[] = "l.type = ?";
    $params[] = $type;
    $types_param .= "s";
}

if ($search) {
    $where[] = "(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types_param .= "sss";
}

if ($min_price > 0) {
    $where[] = "l.price >= ?";
    $params[] = $min_price;
    $types_param .= "d";
}

if ($max_price > 0) {
    $where[] = "l.price <= ?";
    $params[] = $max_price;
    $types_param .= "d";
}

if ($location) {
    $where[] = "l.location LIKE ?";
    $params[] = "%$location%";
    $types_param .= "s";
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Sorting
switch ($sort) {
    case 'price_low':
        $orderBy = "l.price ASC";
        break;
    case 'price_high':
        $orderBy = "l.price DESC";
        break;
    case 'popular':
        $orderBy = "l.views DESC";
        break;
    default:
        $orderBy = "l.created_at DESC";
}

// Get total count using the AvailabilityManager for accuracy
$total = $availabilityManager->getAvailableListingsCount($type, [
    'search' => $search,
    'min_price' => $min_price,
    'max_price' => $max_price,
    'location' => $location
]);
$totalPages = ceil($total / $limit);

// Get listings using AvailabilityManager to ensure only available listings
$listings = $availabilityManager->getAvailableListings($type, $limit, $offset, [
    'search' => $search,
    'min_price' => $min_price,
    'max_price' => $max_price,
    'location' => $location,
    'sort' => $sort
]);

$conn->close();
?>

<style>
    .browse-header { margin-bottom: 28px; }
    .browse-header h1 { font-size: 32px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .browse-header p { color: #64748b; font-size: 15px; }
    
    .search-section { background: white; border-radius: 20px; padding: 24px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .search-bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .search-bar input { flex: 1; padding: 14px 20px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; }
    .search-bar button { padding: 14px 32px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; }
    
    .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-group label { font-size: 11px; color: #64748b; font-weight: 600; }
    .filter-group input, .filter-group select { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 13px; min-width: 130px; }
    .filter-group button { padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 12px; cursor: pointer; font-weight: 500; }
    .reset-btn { background: #94a3b8 !important; }
    
    .category-filters { display: flex; gap: 12px; margin: 20px 0; flex-wrap: wrap; }
    .filter-chip { padding: 8px 20px; background: white; border-radius: 40px; text-decoration: none; color: #334155; font-size: 13px; font-weight: 500; transition: all 0.3s; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .filter-chip:hover, .filter-chip.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; transform: translateY(-2px); }
    
    .listings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 28px; margin-bottom: 32px; }
    .listing-card { background: white; border-radius: 24px; overflow: hidden; transition: all 0.3s; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
    .listing-card:hover { transform: translateY(-6px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
    .card-image { height: 200px; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; overflow: hidden; }
    .card-image img { width: 100%; height: 100%; object-fit: cover; }
    .card-content { padding: 20px; }
    .card-type { display: inline-block; padding: 4px 12px; background: #f1f5f9; border-radius: 20px; font-size: 11px; font-weight: 600; margin-bottom: 10px; }
    .card-title { font-size: 16px; font-weight: 700; margin-bottom: 8px; color: #0f172a; line-height: 1.4; }
    .card-price { font-size: 20px; font-weight: 800; color: #667eea; margin: 10px 0; }
    .card-price small { font-size: 12px; font-weight: normal; }
    .card-location { font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px; margin-top: 8px; }
    .card-seller { font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid #f1f5f9; }
    .stats { display: flex; gap: 12px; margin-top: 8px; font-size: 11px; color: #94a3b8; }
    .availability-badge { display: inline-block; padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; margin-left: 8px; }
    .badge-available { background: #d1fae5; color: #059669; }
    
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
    .pagination a, .pagination span { padding: 8px 14px; background: white; border-radius: 10px; text-decoration: none; color: #334155; font-size: 14px; transition: all 0.3s; border: 1px solid #e2e8f0; }
    .pagination a:hover, .pagination .active { background: #667eea; color: white; border-color: #667eea; }
    .pagination .disabled { opacity: 0.5; cursor: not-allowed; }
    
    .empty-state { text-align: center; padding: 60px; background: white; border-radius: 24px; }
    .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    
    .result-count { font-size: 13px; color: #64748b; margin-bottom: 16px; }
    
    @media (max-width: 768px) {
        .listings-grid { grid-template-columns: 1fr; }
        .search-bar { flex-direction: column; }
        .search-bar button { border-radius: 12px; }
        .filters { flex-direction: column; }
        .filter-group input, .filter-group select { width: 100%; }
        .category-filters { overflow-x: auto; flex-wrap: nowrap; }
    }
</style>

<div class="browse-header">
    <h1>Find Your Perfect Match</h1>
    <p>Discover houses, cars, and job opportunities</p>
</div>

<!-- Search Section -->
<div class="search-section">
    <form method="GET" class="search-bar">
        <input type="text" name="search" placeholder="Search by title, description, or location..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit"><i class="fas fa-search"></i> Search</button>
    </form>
    
    <form method="GET" class="filters" id="filterForm">
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
        
        <div class="filter-group">
            <label>Min Price (ETB)</label>
            <input type="number" name="min_price" placeholder="Min" value="<?php echo $min_price ? number_format($min_price, 0) : ''; ?>" step="1000">
        </div>
        <div class="filter-group">
            <label>Max Price (ETB)</label>
            <input type="number" name="max_price" placeholder="Max" value="<?php echo $max_price ? number_format($max_price, 0) : ''; ?>" step="1000">
        </div>
        <div class="filter-group">
            <label>Location</label>
            <input type="text" name="location" placeholder="City/Area" value="<?php echo htmlspecialchars($location); ?>">
        </div>
        <div class="filter-group">
            <label>Sort by</label>
            <select name="sort" onchange="this.form.submit()">
                <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                <option value="price_low" <?php echo $sort == 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                <option value="price_high" <?php echo $sort == 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                <option value="popular" <?php echo $sort == 'popular' ? 'selected' : ''; ?>>Most Viewed</option>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit">Apply Filters</button>
        </div>
        <?php if ($search || $type || $min_price || $max_price || $location): ?>
            <div class="filter-group">
                <a href="browse.php" class="reset-btn" style="padding: 10px 20px; background: #94a3b8; color: white; border-radius: 12px; text-decoration: none;">Clear All</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Category Filters -->
<div class="category-filters">
    <a href="browse.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo empty($type) ? 'active' : ''; ?>">🏠 All</a>
    <a href="browse.php?type=rental<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $type == 'rental' ? 'active' : ''; ?>">🏡 Houses & Properties</a>
    <a href="browse.php?type=product<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $type == 'product' ? 'active' : ''; ?>">🚗 Cars & Vehicles</a>
    <a href="browse.php?type=job<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $type == 'job' ? 'active' : ''; ?>">💼 Jobs</a>
</div>

<!-- Result Count -->
<div class="result-count">
    <i class="fas fa-list"></i> Found <?php echo number_format($total); ?> available listing(s)
</div>

<!-- Listings Grid -->
<?php if ($listings && $listings->num_rows > 0): ?>
    <div class="listings-grid">
        <?php while($item = $listings->fetch_assoc()): 
            $cover_image = '';
            $has_image = false;
            
            if (!empty($item['cover_image'])) {
                $cover_image = '/broker_system/uploads/listings/' . $item['cover_image'];
                $file_path = $_SERVER['DOCUMENT_ROOT'] . $cover_image;
                if (file_exists($file_path)) {
                    $has_image = true;
                }
            }
            
            $additional = $item['additional_details'] ? json_decode($item['additional_details'], true) : [];
            $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
        ?>
            <div class="listing-card" onclick="location.href='product.php?id=<?php echo $item['id']; ?>'">
                <div class="card-image">
                    <?php if ($has_image): ?>
                        <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">
                            <?php echo $icons[$item['type']]; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-content">
                    <span class="card-type">
                        <?php if ($item['type'] == 'rental'): ?>🏡 For Rent
                        <?php elseif ($item['type'] == 'product'): ?>🚗 For Sale
                        <?php else: ?>💼 Job<?php endif; ?>
                        <span class="availability-badge badge-available"><i class="fas fa-check-circle"></i> Available</span>
                    </span>
                    <div class="card-title"><?php echo htmlspecialchars(substr($item['title'], 0, 50)); ?></div>
                    <div class="card-price"><?php echo formatMoney($item['price']); ?>
                        <?php if ($item['type'] == 'rental'): ?><small>/night</small><?php endif; ?>
                        <?php if ($item['type'] == 'job'): ?><small>/month</small><?php endif; ?>
                    </div>
                    
                    <?php if ($item['type'] == 'rental' && !empty($additional)): ?>
                        <div style="font-size: 12px; color: #64748b;">
                            <?php if (!empty($additional['bedrooms'])): ?>🛏️ <?php echo $additional['bedrooms']; ?> bed<?php endif; ?>
                            <?php if (!empty($additional['bathrooms'])): ?> 🚿 <?php echo $additional['bathrooms']; ?> bath<?php endif; ?>
                            <?php if (!empty($additional['area'])): ?> 📐 <?php echo $additional['area']; ?> sqm<?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($item['type'] == 'product' && !empty($additional)): ?>
                        <div style="font-size: 12px; color: #64748b;">
                            <?php if (!empty($additional['year'])): ?>📅 <?php echo $additional['year']; ?><?php endif; ?>
                            <?php if (!empty($additional['mileage'])): ?> 📊 <?php echo number_format($additional['mileage']); ?> km<?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($item['location'])): ?>
                        <div class="card-location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($item['location']); ?></div>
                    <?php endif; ?>
                    
                    <div class="card-seller"><i class="fas fa-user"></i> <?php echo htmlspecialchars($item['seller_name']); ?></div>
                    <div class="stats"><span><i class="fas fa-eye"></i> <?php echo number_format($item['views']); ?> views</span></div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&type=<?php echo urlencode($type); ?>&search=<?php echo urlencode($search); ?>&min_price=<?php echo $min_price; ?>&max_price=<?php echo $max_price; ?>&location=<?php echo urlencode($location); ?>&sort=<?php echo $sort; ?>">← Previous</a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?page=<?php echo $i; ?>&type=<?php echo urlencode($type); ?>&search=<?php echo urlencode($search); ?>&min_price=<?php echo $min_price; ?>&max_price=<?php echo $max_price; ?>&location=<?php echo urlencode($location); ?>&sort=<?php echo $sort; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&type=<?php echo urlencode($type); ?>&search=<?php echo urlencode($search); ?>&min_price=<?php echo $min_price; ?>&max_price=<?php echo $max_price; ?>&location=<?php echo urlencode($location); ?>&sort=<?php echo $sort; ?>">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-search"></i>
        <h3>No available listings found</h3>
        <p>Try adjusting your search or filter criteria, or check back later for new listings.</p>
        <a href="post_listing.php" class="btn" style="display: inline-block; margin-top: 16px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 12px 28px; border-radius: 40px; text-decoration: none;">
            <i class="fas fa-plus-circle"></i> Post a Listing
        </a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/chat.php

<?php
// user/chat.php - Modern Redesigned Chat Interface

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/chat_functions.php';

requireLogin();

$page_title = 'Messages';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get broker/admin users for new conversations
$brokers = $conn->query("SELECT id, full_name, email FROM users WHERE role IN ('admin', 'broker') ORDER BY full_name");

// Get user's conversations
$conversations = getUserConversations($conn, $user_id);

// Get conversation messages
$messages = [];
$current_conversation = null;
if ($conversation_id > 0) {
    $current_conversation = getConversationById($conn, $conversation_id, $user_id);
    
    if ($current_conversation) {
        $messages = getMessagesWithDeleteFilter($conn, $conversation_id, $user_id, 100, 0);
        markMessagesAsRead($conn, $conversation_id, $user_id);
    }
}

$unread_count = getUnreadMessageCount($conn, $user_id);
$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --primary-dark: #5a67d8;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    /* Chat Container - Uses full width but with sidebar integration */
    .chat-full-container {
        display: flex;
        gap: 0;
        background: white;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        min-height: calc(100vh - 180px);
    }
    
    /* Sidebar Styles */
    .chat-sidebar-modern {
        width: 320px;
        background: white;
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
    }
    
    .sidebar-header-modern {
        padding: 24px;
        border-bottom: 1px solid var(--border);
    }
    
    .sidebar-header-modern h2 {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .new-chat-btn-modern {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.3s;
    }
    
    .new-chat-btn-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .search-box-modern {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
    }
    
    .search-box-modern input {
        width: 100%;
        padding: 10px 16px;
        border: 1px solid var(--border);
        border-radius: 40px;
        font-size: 13px;
        background: var(--light);
        transition: all 0.3s;
    }
    
    .search-box-modern input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .conversations-list-modern {
        flex: 1;
        overflow-y: auto;
    }
    
    .conversation-item-modern {
        display: flex;
        align-items: center;
        padding: 16px 20px;
        gap: 14px;
        cursor: pointer;
        transition: all 0.2s;
        border-bottom: 1px solid var(--border);
        position: relative;
    }
    
    .conversation-item-modern:hover {
        background: var(--light);
    }
    
    .conversation-item-modern.active {
        background: linear-gradient(135deg, rgba(102,126,234,0.08), rgba(118,75,162,0.08));
        border-left: 3px solid var(--primary);
    }
    
    .conversation-avatar-modern {
        width: 52px;
        height: 52px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 18px;
        flex-shrink: 0;
    }
    
    .conversation-info-modern {
        flex: 1;
        min-width: 0;
    }
    
    .conversation-name-modern {
        font-weight: 600;
        font-size: 15px;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .conversation-last-modern {
        font-size: 12px;
        color: var(--gray);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .conversation-meta-modern {
        text-align: right;
        flex-shrink: 0;
    }
    
    .conversation-time-modern {
        font-size: 10px;
        color: var(--gray);
    }
    
    .unread-badge-modern {
        background: var(--danger);
        color: white;
        font-size: 10px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 20px;
        min-width: 20px;
        text-align: center;
        display: inline-block;
        margin-top: 6px;
    }
    
    /* Main Chat Area */
    .chat-main-modern {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: var(--light);
    }
    
    .chat-header-modern {
        padding: 20px 24px;
        background: white;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .chat-header-left-modern {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    
    .back-btn-modern {
        display: none;
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .back-btn-modern:hover {
        color: var(--primary);
    }
    
    .chat-avatar-modern {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 20px;
    }
    
    .chat-header-info-modern h3 {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .typing-status-modern {
        font-size: 11px;
        color: var(--primary);
        min-height: 18px;
    }
    
    .typing-indicator-modern {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 0;
    }
    
    .typing-dot-modern {
        width: 6px;
        height: 6px;
        background: var(--primary);
        border-radius: 50%;
        animation: typingAnimation 1.4s infinite ease-in-out;
    }
    
    @keyframes typingAnimation {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
        30% { transform: translateY(-6px); opacity: 1; }
    }
    
    .chat-actions-modern {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .dashboard-link-modern {
        background: var(--success);
        color: white;
        padding: 8px 16px;
        border-radius: 40px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.3s;
    }
    
    .dashboard-link-modern:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .clear-history-btn-modern {
        background: none;
        border: 1px solid var(--border);
        padding: 8px 14px;
        border-radius: 40px;
        font-size: 12px;
        color: var(--gray);
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .clear-history-btn-modern:hover {
        background: #fee2e2;
        border-color: var(--danger);
        color: var(--danger);
    }
    
    /* Messages Area */
    .messages-area-modern {
        flex: 1;
        overflow-y: auto;
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    
    .message-modern {
        display: flex;
        max-width: 70%;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .message-modern.sent {
        align-self: flex-end;
    }
    
    .message-modern.received {
        align-self: flex-start;
    }
    
    .message-bubble-modern {
        padding: 12px 16px;
        border-radius: 20px;
        position: relative;
        word-wrap: break-word;
        max-width: 100%;
    }
    
    .message-modern.sent .message-bubble-modern {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border-bottom-right-radius: 4px;
    }
    
    .message-modern.received .message-bubble-modern {
        background: white;
        color: var(--dark);
        border-bottom-left-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .message-text-modern {
        font-size: 14px;
        line-height: 1.5;
    }
    
    .message-time-modern {
        font-size: 9px;
        margin-top: 6px;
        opacity: 0.7;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .delete-msg-btn-modern {
        background: none;
        border: none;
        color: rgba(255,255,255,0.6);
        cursor: pointer;
        font-size: 10px;
        opacity: 0;
        transition: opacity 0.3s;
    }
    
    .message-modern.received .delete-msg-btn-modern {
        color: var(--gray);
    }
    
    .message-bubble-modern:hover .delete-msg-btn-modern {
        opacity: 1;
    }
    
    .delete-msg-btn-modern:hover {
        color: var(--danger) !important;
    }
    
    /* Reactions */
    .message-reactions-modern {
        display: flex;
        gap: 6px;
        margin-top: 8px;
        flex-wrap: wrap;
    }
    
    .reaction-btn-modern {
        background: rgba(0,0,0,0.05);
        border: none;
        cursor: pointer;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 20px;
        transition: all 0.2s;
    }
    
    .message-modern.sent .reaction-btn-modern {
        background: rgba(255,255,255,0.2);
        color: white;
    }
    
    .message-modern.received .reaction-btn-modern {
        background: #f1f5f9;
        color: var(--dark);
    }
    
    .reaction-btn-modern:hover {
        transform: scale(1.1);
    }
    
    .reaction-btn-modern.active {
        background: var(--primary);
        color: white;
    }
    
    .message-modern.sent .reaction-btn-modern.active {
        background: white;
        color: var(--primary);
    }
    
    /* Input Area */
    .chat-input-area-modern {
        padding: 20px 24px;
        background: white;
        border-top: 1px solid var(--border);
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }
    
    .chat-input-modern {
        flex: 1;
        padding: 12px 18px;
        border: 1px solid var(--border);
        border-radius: 24px;
        font-size: 14px;
        resize: none;
        font-family: inherit;
        max-height: 120px;
        transition: all 0.3s;
    }
    
    .chat-input-modern:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .send-btn-modern {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border: none;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        color: white;
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .send-btn-modern:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    /* Empty State */
    .empty-state-modern {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: var(--gray);
        flex-direction: column;
        gap: 20px;
        text-align: center;
        padding: 40px;
    }
    
    .empty-state-modern i {
        font-size: 64px;
        color: #cbd5e1;
    }
    
    .empty-state-modern h3 {
        font-size: 20px;
        color: var(--dark);
    }
    
    /* Modal */
    .modal-modern {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-content-modern {
        background: white;
        border-radius: 28px;
        padding: 28px;
        width: 450px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header-modern {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header-modern h3 {
        font-size: 20px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .close-modal-modern {
        cursor: pointer;
        font-size: 28px;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .close-modal-modern:hover {
        color: var(--danger);
    }
    
    .broker-list-modern {
        max-height: 400px;
        overflow-y: auto;
    }
    
    .broker-item-modern {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px;
        cursor: pointer;
        border-radius: 16px;
        transition: all 0.2s;
    }
    
    .broker-item-modern:hover {
        background: var(--light);
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .chat-full-container {
            flex-direction: column;
            min-height: calc(100vh - 140px);
        }
        
        .chat-sidebar-modern {
            width: 100%;
            display: none;
        }
        
        .chat-sidebar-modern.open {
            display: flex;
        }
        
        .back-btn-modern {
            display: block;
        }
        
        .message-modern {
            max-width: 85%;
        }
        
        .chat-header-modern {
            padding: 16px 20px;
        }
        
        .dashboard-link-modern span {
            display: none;
        }
        
        .dashboard-link-modern i {
            margin: 0;
        }
    }
</style>

<!-- Main Content -->
<div class="chat-full-container">
    <!-- Sidebar -->
    <div class="chat-sidebar-modern" id="chatSidebar">
        <div class="sidebar-header-modern">
            <h2><i class="fas fa-comments"></i> Messages</h2>
            <button class="new-chat-btn-modern" onclick="openNewChatModal()">
                <i class="fas fa-plus"></i> New Conversation
            </button>
        </div>
        <div class="search-box-modern">
            <input type="text" id="searchConversations" placeholder="Search conversations..." onkeyup="filterConversations(this.value)">
        </div>
        <div class="conversations-list-modern" id="conversationsList">
            <?php if ($conversations && $conversations->num_rows > 0): ?>
                <?php while($conv = $conversations->fetch_assoc()): ?>
                    <div class="conversation-item-modern <?php echo $conversation_id == $conv['id'] ? 'active' : ''; ?>" 
                         onclick="loadConversation(<?php echo $conv['id']; ?>)"
                         data-conv-id="<?php echo $conv['id']; ?>"
                         data-conv-name="<?php echo strtolower($conv['other_user_name']); ?>">
                        <div class="conversation-avatar-modern">
                            <?php echo strtoupper(substr($conv['other_user_name'], 0, 1)); ?>
                        </div>
                        <div class="conversation-info-modern">
                            <div class="conversation-name-modern"><?php echo htmlspecialchars($conv['other_user_name']); ?></div>
                            <div class="conversation-last-modern"><?php echo htmlspecialchars(substr($conv['last_message'] ?? '', 0, 35)); ?></div>
                        </div>
                        <div class="conversation-meta-modern">
                            <div class="conversation-time-modern">
                                <?php 
                                if ($conv['last_message_time']) {
                                    echo date('H:i', strtotime($conv['last_message_time']));
                                }
                                ?>
                            </div>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <div class="unread-badge-modern"><?php echo $conv['unread_count']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 60px 20px; text-align: center; color: var(--gray);">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                    <p>No messages yet</p>
                    <p style="font-size: 12px; margin-top: 8px;">Start a conversation with support</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Chat Area -->
    <div class="chat-main-modern">
        <?php if ($current_conversation): ?>
            <div class="chat-header-modern">
                <div class="chat-header-left-modern">
                    <button class="back-btn-modern" onclick="toggleSidebar()"><i class="fas fa-arrow-left"></i></button>
                    <div class="chat-avatar-modern">
                        <?php echo strtoupper(substr($current_conversation['other_user_name'], 0, 1)); ?>
                    </div>
                    <div class="chat-header-info-modern">
                        <h3><?php echo htmlspecialchars($current_conversation['other_user_name']); ?></h3>
                        <div class="typing-status-modern" id="typingStatus"></div>
                    </div>
                </div>
                <div class="chat-actions-modern">
                    <a href="dashboard.php" class="dashboard-link-modern">
                        <i class="fas fa-home"></i> <span>Dashboard</span>
                    </a>
                    <button class="clear-history-btn-modern" onclick="clearChatHistory()">
                        <i class="fas fa-trash-alt"></i> Clear
                    </button>
                </div>
            </div>

            <div class="messages-area-modern" id="messagesArea">
                <?php foreach($messages as $msg): ?>
                    <?php
                    $isSent = ($msg['sender_id'] == $user_id);
                    $reactionTypes = ['like' => '👍', 'dislike' => '👎', 'love' => '❤️', 'laugh' => '😂'];
                    ?>
                    <div class="message-modern <?php echo $isSent ? 'sent' : 'received'; ?>" data-msg-id="<?php echo $msg['id']; ?>">
                        <div class="message-bubble-modern">
                            <div class="message-text-modern"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                            <div class="message-time-modern">
                                <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                <button class="delete-msg-btn-modern" onclick="deleteMessage(<?php echo $msg['id']; ?>)" title="Delete message">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                            <div class="message-reactions-modern">
                                <?php foreach($reactionTypes as $type => $emoji): ?>
                                    <?php $count = $msg['reactions'][$type] ?? 0; ?>
                                    <button class="reaction-btn-modern <?php echo ($msg['my_reaction'] == $type) ? 'active' : ''; ?>" onclick="addReaction(<?php echo $msg['id']; ?>, '<?php echo $type; ?>')">
                                        <?php echo $emoji; ?> <?php echo $count > 0 ? $count : ''; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="chat-input-area-modern">
                <textarea class="chat-input-modern" id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                <button class="send-btn-modern" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-comments"></i>
                <h3>Select a conversation</h3>
                <p>Choose a chat to start messaging or start a new conversation</p>
                <div style="display: flex; gap: 12px; margin-top: 8px;">
                    <a href="dashboard.php" class="dashboard-link-modern" style="background: var(--success);">
                        <i class="fas fa-home"></i> Go to Dashboard
                    </a>
                    <button class="new-chat-btn-modern" onclick="openNewChatModal()" style="width: auto; padding: 10px 24px;">
                        <i class="fas fa-plus"></i> New Chat
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Chat Modal -->
<div id="newChatModal" class="modal-modern">
    <div class="modal-content-modern">
        <div class="modal-header-modern">
            <h3><i class="fas fa-plus-circle"></i> Start New Conversation</h3>
            <span class="close-modal-modern" onclick="closeNewChatModal()">&times;</span>
        </div>
        <div class="broker-list-modern">
            <?php while($broker = $brokers->fetch_assoc()): ?>
                <div class="broker-item-modern" onclick="startConversation(<?php echo $broker['id']; ?>)">
                    <div class="conversation-avatar-modern" style="width: 44px; height: 44px; font-size: 18px;">
                        <?php echo strtoupper(substr($broker['full_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($broker['full_name']); ?></div>
                        <div style="font-size: 12px; color: var(--gray);"><?php echo htmlspecialchars($broker['email']); ?></div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<!-- Clear History Modal -->
<div id="clearHistoryModal" class="modal-modern">
    <div class="modal-content-modern">
        <div class="modal-header-modern">
            <h3><i class="fas fa-trash-alt"></i> Clear Chat History</h3>
            <span class="close-modal-modern" onclick="closeClearHistoryModal()">&times;</span>
        </div>
        <p style="margin-bottom: 20px; color: var(--gray);">Are you sure you want to clear all messages in this conversation? This action cannot be undone.</p>
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button onclick="closeClearHistoryModal()" style="padding: 10px 20px; background: var(--gray); color: white; border: none; border-radius: 40px; cursor: pointer;">Cancel</button>
            <button onclick="confirmClearHistory()" style="padding: 10px 20px; background: var(--danger); color: white; border: none; border-radius: 40px; cursor: pointer;">Clear All</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let conversationId = <?php echo $conversation_id; ?>;
    let userId = <?php echo $user_id; ?>;
    let pollInterval;
    let typingTimeout;
    let typingCheckInterval;

    function scrollToBottom() {
        const messagesArea = document.getElementById('messagesArea');
        if (messagesArea) {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }
    }

    function loadConversation(id) {
        window.location.href = `chat.php?id=${id}`;
    }

    function filterConversations(searchTerm) {
        const items = document.querySelectorAll('.conversation-item-modern');
        const term = searchTerm.toLowerCase();
        
        items.forEach(item => {
            const name = item.getAttribute('data-conv-name') || '';
            if (name.includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function sendMessage() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();
        
        if (!message || !conversationId) return;
        
        const sendBtn = document.querySelector('.send-btn-modern');
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        $.ajax({
            url: 'api/send_message.php',
            method: 'POST',
            data: {
                conversation_id: conversationId,
                message: message
            },
            success: function(response) {
                if (response.success) {
                    input.value = '';
                    input.style.height = 'auto';
                    loadMessages();
                    updateConversationList();
                    setTimeout(scrollToBottom, 100);
                } else {
                    alert('Failed to send message');
                }
            },
            complete: function() {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            }
        });
    }

    function loadMessages() {
        if (!conversationId) return;
        
        $.ajax({
            url: 'api/get_messages.php',
            method: 'GET',
            data: { conversation_id: conversationId },
            success: function(response) {
                if (response.success && response.messages) {
                    const messagesArea = document.getElementById('messagesArea');
                    const currentMessageIds = Array.from(messagesArea.querySelectorAll('.message-modern')).map(el => parseInt(el.dataset.msgId));
                    const newMessages = response.messages.filter(msg => !currentMessageIds.includes(msg.id));
                    
                    if (newMessages.length > 0) {
                        newMessages.forEach(msg => {
                            appendMessage(msg);
                        });
                        scrollToBottom();
                        $.post('api/mark_read.php', { conversation_id: conversationId });
                        updateConversationList();
                    } else if (currentMessageIds.length !== response.messages.length) {
                        messagesArea.innerHTML = '';
                        response.messages.forEach(msg => {
                            appendMessage(msg);
                        });
                        scrollToBottom();
                    }
                }
            }
        });
    }

    function appendMessage(msg) {
        const messagesArea = document.getElementById('messagesArea');
        const isSent = msg.sender_id == userId;
        const reactionTypes = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `message-modern ${isSent ? 'sent' : 'received'}`;
        messageDiv.setAttribute('data-msg-id', msg.id);
        
        let reactionsHtml = '<div class="message-reactions-modern">';
        for (const [type, emoji] of Object.entries(reactionTypes)) {
            const count = msg.reactions[type] || 0;
            const isActive = (msg.my_reaction === type);
            reactionsHtml += `<button class="reaction-btn-modern ${isActive ? 'active' : ''}" onclick="addReaction(${msg.id}, '${type}')">${emoji} ${count > 0 ? count : ''}</button>`;
        }
        reactionsHtml += '</div>';
        
        messageDiv.innerHTML = `
            <div class="message-bubble-modern">
                <div class="message-text-modern">${escapeHtml(msg.message)}</div>
                <div class="message-time-modern">
                    ${msg.time}
                    <button class="delete-msg-btn-modern" onclick="deleteMessage(${msg.id})" title="Delete message">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
                ${reactionsHtml}
            </div>
        `;
        messagesArea.appendChild(messageDiv);
    }

    function addReaction(messageId, type) {
        const messageDiv = $(`.message-modern[data-msg-id="${messageId}"]`);
        const emojis = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
        const emoji = emojis[type];
        
        const reactionBtn = messageDiv.find('.reaction-btn-modern').filter(function() {
            return $(this).text().trim().startsWith(emoji);
        });
        
        const btnText = reactionBtn.text().trim();
        const currentCount = parseInt(btnText.match(/\d+/)?.[0] || 0);
        const isCurrentlyActive = reactionBtn.hasClass('active');
        
        if (isCurrentlyActive) {
            const newCount = currentCount - 1;
            reactionBtn.text(`${emoji} ${newCount > 0 ? newCount : ''}`);
            reactionBtn.removeClass('active');
        } else {
            const newCount = currentCount + 1;
            reactionBtn.text(`${emoji} ${newCount > 0 ? newCount : ''}`);
            reactionBtn.addClass('active');
            
            messageDiv.find('.reaction-btn-modern').not(reactionBtn).each(function() {
                const otherBtn = $(this);
                const otherText = otherBtn.text().trim();
                const otherCount = parseInt(otherText.match(/\d+/)?.[0] || 0);
                const otherEmoji = otherText.charAt(0);
                const newOtherCount = otherCount - 1;
                otherBtn.text(`${otherEmoji} ${newOtherCount > 0 ? newOtherCount : ''}`);
                otherBtn.removeClass('active');
            });
        }
        
        $.ajax({
            url: 'api/add_reaction.php',
            method: 'POST',
            data: { message_id: messageId, reaction_type: type },
            success: function(response) {
                if (response.success && response.reactions) {
                    syncReactions(messageId, response.reactions);
                }
            },
            error: function() {
                loadMessages();
            }
        });
    }

    function syncReactions(messageId, reactions) {
        const messageDiv = $(`.message-modern[data-msg-id="${messageId}"]`);
        const reactionTypes = ['like', 'dislike', 'love', 'laugh'];
        const emojis = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
        
        reactionTypes.forEach(type => {
            const count = reactions[type] || 0;
            const emoji = emojis[type];
            const btn = messageDiv.find('.reaction-btn-modern').filter(function() {
                return $(this).text().trim().startsWith(emoji);
            });
            btn.text(`${emoji} ${count > 0 ? count : ''}`);
        });
    }

    function deleteMessage(messageId) {
        if (confirm('Delete this message? It will be removed from your chat history.')) {
            $.ajax({
                url: 'api/delete_message.php',
                method: 'POST',
                data: { message_id: messageId },
                success: function(response) {
                    if (response.success) {
                        $(`.message-modern[data-msg-id="${messageId}"]`).remove();
                    } else {
                        alert('Failed to delete message');
                    }
                }
            });
        }
    }

    function clearChatHistory() {
        if (!conversationId) return;
        document.getElementById('clearHistoryModal').style.display = 'flex';
    }

    function closeClearHistoryModal() {
        document.getElementById('clearHistoryModal').style.display = 'none';
    }

    function confirmClearHistory() {
        if (!conversationId) return;
        
        $.ajax({
            url: 'api/clear_history.php',
            method: 'POST',
            data: { conversation_id: conversationId },
            success: function(response) {
                if (response.success) {
                    document.getElementById('messagesArea').innerHTML = '';
                    updateConversationList();
                    closeClearHistoryModal();
                    alert('Chat history cleared successfully');
                } else {
                    alert('Failed to clear history');
                }
            },
            error: function() {
                alert('Failed to clear history');
            }
        });
    }

    function sendTyping() {
        if (!conversationId) return;
        
        if (typingTimeout) clearTimeout(typingTimeout);
        
        $.post('api/typing.php', { conversation_id: conversationId, typing: true });
        
        typingTimeout = setTimeout(() => {
            $.post('api/typing.php', { conversation_id: conversationId, typing: false });
        }, 2000);
    }

    function checkOtherUserTyping() {
        if (!conversationId) return;
        
        $.get('api/typing.php', { conversation_id: conversationId }, function(response) {
            const typingStatus = document.getElementById('typingStatus');
            if (typingStatus) {
                if (response.typing && response.typing_user_id && response.typing_user_id != userId) {
                    typingStatus.innerHTML = '<div class="typing-indicator-modern"><span class="typing-dot-modern"></span><span class="typing-dot-modern"></span><span class="typing-dot-modern"></span> typing...</div>';
                } else {
                    typingStatus.innerHTML = '';
                }
            }
        });
    }

    function updateConversationList() {
        $.get('api/get_conversations.php', function(response) {
            if (response.success && response.conversations) {
                response.conversations.forEach(conv => {
                    const item = document.querySelector(`.conversation-item-modern[data-conv-id="${conv.id}"]`);
                    if (item) {
                        const badge = item.querySelector('.unread-badge-modern');
                        if (conv.unread_count > 0) {
                            if (badge) badge.textContent = conv.unread_count;
                            else {
                                const meta = item.querySelector('.conversation-meta-modern');
                                if (meta) meta.innerHTML += `<div class="unread-badge-modern">${conv.unread_count}</div>`;
                            }
                        } else if (badge) badge.remove();
                        
                        const lastMsgElem = item.querySelector('.conversation-last-modern');
                        if (lastMsgElem && conv.last_message) {
                            lastMsgElem.textContent = conv.last_message.substring(0, 35);
                        }
                    }
                });
            }
        });
    }

    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => { loadMessages(); }, 3000);
    }

    function startTypingCheck() {
        if (typingCheckInterval) clearInterval(typingCheckInterval);
        typingCheckInterval = setInterval(() => { checkOtherUserTyping(); }, 2000);
    }

    const textarea = document.getElementById('messageInput');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            sendTyping();
        });
        textarea.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }

    function openNewChatModal() { 
        document.getElementById('newChatModal').style.display = 'flex'; 
    }
    
    function closeNewChatModal() { 
        document.getElementById('newChatModal').style.display = 'none'; 
    }
    
    function startConversation(brokerId) { 
        window.location.href = `api/start_conversation.php?broker_id=${brokerId}`; 
    }
    
    function toggleSidebar() { 
        document.getElementById('chatSidebar').classList.toggle('open'); 
    }
    
    function escapeHtml(text) { 
        const div = document.createElement('div'); 
        div.textContent = text; 
        return div.innerHTML; 
    }

    if (conversationId) {
        startPolling();
        startTypingCheck();
        loadMessages();
        $.post('api/mark_read.php', { conversation_id: conversationId });
        setTimeout(scrollToBottom, 500);
    }

    window.onclick = function(event) {
        const modal = document.getElementById('newChatModal');
        const clearModal = document.getElementById('clearHistoryModal');
        if (event.target === modal) modal.style.display = 'none';
        if (event.target === clearModal) clearModal.style.display = 'none';
    }
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/confirm_booking.php

<?php
// api/confirm_booking.php - Owner confirms booking

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$booking_id = isset($input['booking_id']) ? intval($input['booking_id']) : 0;
$user_id = $_SESSION['user_id'];

if (!$booking_id) {
    echo json_encode(['success' => false, 'error' => 'Booking ID required']);
    exit;
}

$conn = getDbConnection();

// Get booking details and verify ownership
$booking = $conn->query("
    SELECT rb.*, l.title, t.status as transaction_status
    FROM rental_bookings rb
    JOIN listings l ON rb.property_id = l.id
    JOIN transactions t ON rb.transaction_id = t.id
    WHERE rb.id = $booking_id AND rb.owner_id = $user_id
")->fetch_assoc();

if (!$booking) {
    echo json_encode(['success' => false, 'error' => 'Booking not found']);
    exit;
}

if ($booking['status'] != 'pending') {
    echo json_encode(['success' => false, 'error' => 'Booking already ' . $booking['status']]);
    exit;
}

$conn->begin_transaction();

try {
    // Update booking status
    $conn->query("UPDATE rental_bookings SET status = 'confirmed' WHERE id = $booking_id");
    
    // Update transaction status if needed
    if ($booking['transaction_status'] != 'deposits_complete') {
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$booking['transaction_id']}");
    }
    
    // Create notification for tenant
    $tenant_message = "Good news! Your booking for {$booking['title']} has been confirmed by the owner. Your dates are now secured.";
    $conn->query("
        INSERT INTO notifications (user_id, title, message, link, created_at) 
        VALUES ({$booking['tenant_id']}, 'Booking Confirmed', '$tenant_message', 'my_rentals.php', NOW())
    ");
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Booking confirmed successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>

BRS/user/confirm_delivery_escrow.php



BRS/user/confirm_escrow_payment.php

<?php
// api/confirm_escrow_payment.php - Confirm Telebirr payment and activate escrow

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/escrow_functions.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Payment code required']);
    exit;
}

// Verify PIN (simulation)
if ($pin != '1234') {
    echo json_encode(['success' => false, 'error' => 'Invalid PIN. Use 1234 for testing']);
    exit;
}

$conn = getDbConnection();

// Find payment code
$payment = $conn->query("
    SELECT pc.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount, t.deposit_amount, t.commission_amount,
           l.title, l.type, l.id as listing_id, l.seller_id as listing_seller_id
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = '$code' AND pc.status = 'pending'
")->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

// Check expiry
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

$conn->begin_transaction();

try {
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment
    $payment_type = ($payment['user_id'] == $payment['buyer_id']) ? 'deposit_buyer' : 'deposit_seller';
    $stmt = $conn->prepare("
        INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at, created_at) 
        VALUES (?, ?, ?, ?, ?, 'confirmed', NOW(), NOW())
    ");
    $stmt->bind_param("iidss", $payment['transaction_id'], $payment['user_id'], $payment['amount'], $payment_type, $code);
    $stmt->execute();
    
    // Update transaction escrow
    $conn->query("
        UPDATE transactions 
        SET escrow_held = escrow_held + {$payment['amount']},
            escrow_status = 'active',
            status = 'escrow_active',
            updated_at = NOW()
        WHERE id = {$payment['transaction_id']}
    ");
    
    // Initialize escrow account
    $stmt2 = $conn->prepare("
        INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
        VALUES (?, ?, ?, 'buyer_deposit', 'held', NOW())
    ");
    $stmt2->bind_param("iid", $payment['transaction_id'], $payment['user_id'], $payment['amount']);
    $stmt2->execute();
    
    // Schedule auto-release based on listing type
    $auto_days = 7;
    if ($payment['type'] == 'rental') $auto_days = 14;
    if ($payment['type'] == 'product') $auto_days = 5;
    if ($payment['type'] == 'job') $auto_days = 10;
    
    $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
    $conn->query("
        INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status) 
        VALUES ({$payment['transaction_id']}, '$release_date', 'pending')
    ");
    
    $conn->query("
        UPDATE transactions 
        SET auto_release_days = $auto_days, escrow_release_date = '$release_date'
        WHERE id = {$payment['transaction_id']}
    ");
    
    // Add timeline
    addTransactionTimeline($conn, $payment['transaction_id'], 'payment_confirmed', 
        "Payment of " . formatMoney($payment['amount']) . " confirmed. Escrow activated.", $payment['user_id']);
    
    // Create notification for seller
    $notif_stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, created_at) 
        VALUES (?, '💰 Payment Received', 'Buyer has paid for: {$payment['title']}. Escrow is now active. Please proceed with delivery.', NOW())
    ");
    $notif_stmt->bind_param("i", $payment['seller_id']);
    $notif_stmt->execute();
    
    // Create notification for buyer
    $notif_stmt2 = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, created_at) 
        VALUES (?, '✅ Payment Confirmed', 'Your payment of " . formatMoney($payment['amount']) . " for {$payment['title']} has been confirmed. The seller will now deliver.', NOW())
    ");
    $notif_stmt2->bind_param("i", $payment['buyer_id']);
    $notif_stmt2->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment confirmed! Escrow is now active.',
        'transaction_id' => $payment['transaction_id'],
        'auto_release_days' => $auto_days,
        'release_date' => $release_date
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>

BRS/user/counter_offer.php

<?php
// user/counter_offer.php - Send counter offer

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();
    $negotiation_id = intval($_POST['negotiation_id'] ?? 0);
    $counter_commission = floatval($_POST['counter_commission'] ?? 0);
    $counter_deposit = floatval($_POST['counter_deposit'] ?? 0);
    $counter_message = $conn->real_escape_string($_POST['counter_message'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    $neg = $conn->query("SELECT id FROM listing_negotiations WHERE id = $negotiation_id AND seller_id = $user_id")->fetch_assoc();
    
    if ($neg) {
        $stmt = $conn->prepare("
            UPDATE listing_negotiations 
            SET counter_commission = ?, counter_deposit = ?, counter_message = ?, status = 'counter_offer_sent' 
            WHERE id = ?
        ");
        $stmt->bind_param("ddsi", $counter_commission, $counter_deposit, $counter_message, $negotiation_id);
        $stmt->execute();
        
        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Counter Offer Received', 'A seller has sent a counter offer. Please review.', NOW())
        ");
        $notif_stmt->bind_param("i", $admin['id']);
        $notif_stmt->execute();
        
        $_SESSION['success'] = "Counter offer sent! Admin will review your proposal.";
    }
    
    $conn->close();
}

header('Location: listings.php');
exit;
?>

BRS/user/dashboard.php

<?php
// user/dashboard.php - Complete Redesigned Dashboard with Working Notifications

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

// Set page title
$page_title = 'Dashboard';

// Start output buffering
ob_start();

// Include database and functions
require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

// Get user balance
$balance_query = $conn->query("SELECT balance FROM users WHERE id = $user_id");
$user_balance = 0;
if ($balance_query && $balance_query->num_rows > 0) {
    $user_data = $balance_query->fetch_assoc();
    $user_balance = $user_data['balance'];
    $_SESSION['user_balance'] = $user_balance;
}

// Get statistics
$stats = [
    'balance' => $user_balance,
    'active_listings' => 0,
    'pending_listings' => 0,
    'total_sales' => 0,
    'total_purchases' => 0,
    'pending_transactions' => 0,
    'total_earned' => 0,
];

// Get active listings count
$result = $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'");
if ($result && $result->num_rows > 0) {
    $stats['active_listings'] = $result->fetch_assoc()['count'];
}

// Get pending listings count
$result = $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'");
if ($result && $result->num_rows > 0) {
    $stats['pending_listings'] = $result->fetch_assoc()['count'];
}

// Get total sales
$result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE seller_id = $user_id AND status = 'completed'");
if ($result && $result->num_rows > 0) {
    $stats['total_sales'] = $result->fetch_assoc()['count'];
}

// Get total purchases
$result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE buyer_id = $user_id AND status = 'completed'");
if ($result && $result->num_rows > 0) {
    $stats['total_purchases'] = $result->fetch_assoc()['count'];
}

// Get pending transactions
$result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE (buyer_id = $user_id OR seller_id = $user_id) AND status NOT IN ('completed', 'cancelled')");
if ($result && $result->num_rows > 0) {
    $stats['pending_transactions'] = $result->fetch_assoc()['count'];
}

// Get total earned
$result = $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE seller_id = $user_id AND status = 'completed'");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $stats['total_earned'] = $row['total'] ?? 0;
}

// Get recent transactions
$recentTransactions = $conn->query("
    SELECT t.*, l.title as listing_title,
           CASE WHEN t.buyer_id = $user_id THEN 'bought' ELSE 'sold' END as action
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.buyer_id = $user_id OR t.seller_id = $user_id
    ORDER BY t.created_at DESC 
    LIMIT 5
");

// Get recent listings
$recentListings = $conn->query("
    SELECT * FROM listings 
    WHERE seller_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 4
");

// Get unread notifications count for badge
$unread_notifications = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count'];

// Get recent notifications for dropdown
$recent_notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 5
");

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --primary-dark: #5a67d8;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    /* Dashboard Container */
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
    }
    
    /* Welcome Section */
    .welcome-section {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .welcome-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .welcome-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .welcome-text h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .welcome-text p {
        font-size: 14px;
        opacity: 0.9;
    }
    
    .notification-bell {
        position: relative;
        cursor: pointer;
        background: rgba(255,255,255,0.2);
        padding: 12px;
        border-radius: 50%;
        transition: all 0.3s;
    }
    
    .notification-bell:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .notification-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: var(--danger);
        color: white;
        font-size: 10px;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 20px;
        min-width: 18px;
        text-align: center;
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 28px;
    }

    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }

    .stat-icon {
        font-size: 32px;
        margin-bottom: 12px;
    }

    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
    }

    .stat-label {
        font-size: 13px;
        color: var(--gray);
        margin-top: 6px;
    }

    .stat-trend {
        font-size: 11px;
        margin-top: 8px;
        color: var(--warning);
    }

    /* Quick Actions */
    .quick-actions {
        display: flex;
        gap: 12px;
        margin-bottom: 28px;
        flex-wrap: wrap;
    }

    .action-btn {
        background: white;
        padding: 12px 24px;
        border-radius: 40px;
        text-decoration: none;
        color: var(--dark);
        font-weight: 500;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }

    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        color: var(--primary);
        border-color: var(--primary);
    }

    /* Stepper */
    .stepper {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 28px;
        border: 1px solid var(--border);
    }

    .stepper-title {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 20px;
        color: var(--dark);
    }

    .steps {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
    }

    .step {
        flex: 1;
        text-align: center;
    }

    .step-circle {
        width: 40px;
        height: 40px;
        background: var(--light);
        border: 2px solid var(--border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 18px;
        transition: all 0.3s;
    }

    .step.completed .step-circle {
        background: var(--success);
        border-color: var(--success);
        color: white;
    }

    .step-label {
        font-size: 11px;
        font-weight: 500;
        color: var(--gray);
    }

    /* Cards */
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
    }

    .card-header h3 {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .card-header a {
        font-size: 12px;
        color: var(--primary);
        text-decoration: none;
        transition: all 0.3s;
    }
    
    .card-header a:hover {
        text-decoration: underline;
    }

    /* Listings Grid */
    .listings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 20px;
    }

    .listing-card {
        background: var(--light);
        border-radius: 16px;
        padding: 16px;
        transition: all 0.3s;
        cursor: pointer;
        border: 1px solid var(--border);
    }

    .listing-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border-color: var(--primary);
    }

    .listing-title {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--dark);
    }

    .listing-price {
        font-size: 16px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 8px;
    }

    .listing-stats {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: var(--gray);
    }

    /* Table */
    .table-wrapper {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th, td {
        padding: 12px 8px;
        text-align: left;
        border-bottom: 1px solid var(--border);
        font-size: 13px;
    }

    th {
        font-weight: 600;
        color: var(--gray);
    }

    tr {
        cursor: pointer;
        transition: background 0.3s;
    }

    tr:hover {
        background: var(--light);
    }

    /* Badges */
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }

    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-info { background: #dbeafe; color: #2563eb; }
    .badge-danger { background: #fee2e2; color: #dc2626; }

    .btn-sm {
        padding: 4px 10px;
        font-size: 11px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .btn-primary { background: var(--primary); color: white; }
    
    /* Notification Dropdown */
    .notification-dropdown {
        position: relative;
        display: inline-block;
    }
    
    .dropdown-menu {
        position: absolute;
        top: 100%;
        right: 0;
        width: 380px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        display: none;
        z-index: 1000;
        margin-top: 10px;
        border: 1px solid var(--border);
    }
    
    .dropdown-menu.show {
        display: block;
        animation: dropdownFade 0.3s ease;
    }
    
    @keyframes dropdownFade {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .dropdown-header {
        padding: 16px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .dropdown-header h4 {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .dropdown-header a {
        font-size: 11px;
        color: var(--primary);
        text-decoration: none;
    }
    
    .dropdown-header a:hover {
        text-decoration: underline;
    }
    
    .notification-item-dropdown {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        cursor: pointer;
        transition: background 0.3s;
    }
    
    .notification-item-dropdown:hover {
        background: var(--light);
    }
    
    .notification-item-dropdown.unread {
        background: #eef2ff;
    }
    
    .notification-title-dropdown {
        font-size: 13px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .notification-message-dropdown {
        font-size: 11px;
        color: var(--gray);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .notification-time-dropdown {
        font-size: 10px;
        color: var(--gray);
        margin-top: 4px;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .steps {
            flex-direction: column;
            gap: 12px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            text-align: left;
        }
        .step-circle {
            margin: 0;
        }
        .welcome-content {
            flex-direction: column;
            text-align: center;
        }
        .dropdown-menu {
            width: 300px;
            right: -50px;
        }
    }
    
    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="dashboard-container">
    <!-- Welcome Section with Notification Bell -->
    <div class="welcome-section">
        <div class="welcome-content">
            <div class="welcome-text">
                <h1>Welcome back, <?php echo htmlspecialchars($user_name); ?>! 👋</h1>
                <p>Here's what's happening with your account today</p>
            </div>
            <div class="notification-dropdown">
                <div class="notification-bell" id="notificationBell">
                    <i class="fas fa-bell fa-lg"></i>
                    <?php if ($unread_notifications > 0): ?>
                        <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </div>
                <div class="dropdown-menu" id="notificationDropdown">
                    <div class="dropdown-header">
                        <h4><i class="fas fa-bell"></i> Notifications</h4>
                        <?php if ($unread_notifications > 0): ?>
                            <a href="notifications.php?mark_all_read=1">Mark all as read</a>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-notifications">
                        <?php if ($recent_notifications && $recent_notifications->num_rows > 0): ?>
                            <?php while($notif = $recent_notifications->fetch_assoc()): ?>
                                <div class="notification-item-dropdown <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="location.href='notifications.php'">
                                    <div class="notification-title-dropdown"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notification-message-dropdown"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notification-time-dropdown"><?php echo timeAgo($notif['created_at']); ?></div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="padding: 40px; text-align: center; color: var(--gray);">
                                <i class="fas fa-bell-slash" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
                                No new notifications
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-header" style="border-top: 1px solid var(--border);">
                        <a href="notifications.php" style="width: 100%; text-align: center;">View all notifications →</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="post_listing.php" class="action-btn"><i class="fas fa-plus-circle"></i> New Listing</a>
        <a href="browse.php" class="action-btn"><i class="fas fa-search"></i> Browse</a>
        <a href="wallet.php" class="action-btn"><i class="fas fa-wallet"></i> Wallet</a>
        <a href="chat.php" class="action-btn"><i class="fas fa-comments"></i> Messages</a>
        <a href="notifications.php" class="action-btn"><i class="fas fa-bell"></i> Notifications</a>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-value"><?php echo formatMoney($stats['balance']); ?></div>
            <div class="stat-label">Wallet Balance</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-value"><?php echo $stats['active_listings']; ?></div>
            <div class="stat-label">Active Listings</div>
            <?php if ($stats['pending_listings'] > 0): ?>
                <div class="stat-trend"><?php echo $stats['pending_listings']; ?> pending approval</div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📈</div>
            <div class="stat-value"><?php echo $stats['total_sales']; ?></div>
            <div class="stat-label">Total Sales</div>
            <div class="stat-trend" style="color: var(--success);">Earned: <?php echo formatMoney($stats['total_earned']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🛒</div>
            <div class="stat-value"><?php echo $stats['total_purchases']; ?></div>
            <div class="stat-label">Total Purchases</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?php echo $stats['pending_transactions']; ?></div>
            <div class="stat-label">Pending Transactions</div>
        </div>
    </div>

    <!-- Escrow Process Stepper -->
    <div class="stepper">
        <div class="stepper-title"><i class="fas fa-shield-alt"></i> How Escrow Works</div>
        <div class="steps">
            <div class="step completed">
                <div class="step-circle">💳</div>
                <div class="step-label">Buyer Pays</div>
            </div>
            <div class="step">
                <div class="step-circle">📥</div>
                <div class="step-label">Seller Deposit</div>
            </div>
            <div class="step">
                <div class="step-circle">📄</div>
                <div class="step-label">Legal Process</div>
            </div>
            <div class="step">
                <div class="step-circle">✅</div>
                <div class="step-label">Delivery</div>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Recent Transactions</h3>
            <a href="transactions.php">View All →</a>
        </div>
        <div class="table-wrapper">
            <?php if ($recentTransactions && $recentTransactions->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr><th>ID</th><th>Item</th><th>Type</th><th>Amount</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php while($txn = $recentTransactions->fetch_assoc()): ?>
                            <tr onclick="location.href='transaction.php?id=<?php echo $txn['id']; ?>'">
                                <td>#<?php echo $txn['id']; ?></td>
                                <td><?php echo htmlspecialchars(substr($txn['listing_title'], 0, 25)); ?></td>
                                <td><span class="badge <?php echo $txn['action'] == 'bought' ? 'badge-info' : 'badge-success'; ?>"><?php echo ucfirst($txn['action']); ?></span></td>
                                <td><?php echo formatMoney($txn['total_amount']); ?></td>
                                <td><?php echo getStatusBadge($txn['status']); ?></td>
                                <td><a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">View</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--gray);">No transactions yet</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Listings -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-box"></i> Recent Listings</h3>
            <a href="listings.php">View All →</a>
        </div>
        <div class="listings-grid">
            <?php if ($recentListings && $recentListings->num_rows > 0): ?>
                <?php while($listing = $recentListings->fetch_assoc()): ?>
                    <div class="listing-card" onclick="location.href='product.php?id=<?php echo $listing['id']; ?>'">
                        <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                        <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                        <div class="listing-stats">
                            <span class="badge <?php echo $listing['approval_status'] == 'approved' ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo ucfirst($listing['approval_status']); ?>
                            </span>
                            <span><i class="fas fa-eye"></i> <?php echo $listing['views']; ?> views</span>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="listing-card" style="text-align: center; color: var(--gray);">No listings yet</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Notification dropdown toggle
const notificationBell = document.getElementById('notificationBell');
const notificationDropdown = document.getElementById('notificationDropdown');

if (notificationBell) {
    notificationBell.addEventListener('click', function(e) {
        e.stopPropagation();
        notificationDropdown.classList.toggle('show');
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function() {
    if (notificationDropdown) {
        notificationDropdown.classList.remove('show');
    }
});

// Prevent closing when clicking inside dropdown
if (notificationDropdown) {
    notificationDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}
</script>

<?php
// Get the content and include the layout
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/initiate_purchase.php

<?php
// user/initiate_rental.php - Create transaction and redirect to payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_POST['listing_id']) ? intval($_POST['listing_id']) : 0;

// Get listing details
$listing = $conn->query("
    SELECT l.*, u.id as seller_id 
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $listing_id AND l.status = 'active' AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$listing) {
    header('Location: browse.php');
    exit;
}

// Calculate amounts
$depositPercent = $listing['admin_deposit_percent'] ?? 30;
$commissionPercent = $listing['admin_commission_percent'] ?? 15;
$depositAmount = $listing['price'] * ($depositPercent / 100);
$commissionAmount = $listing['price'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;
$remainingAmount = $listing['price'] - $depositAmount;

// Check if transaction already exists
$existing = $conn->query("
    SELECT id FROM transactions 
    WHERE listing_id = $listing_id AND buyer_id = $user_id
");

if ($existing->num_rows > 0) {
    $txn = $existing->fetch_assoc();
    header("Location: pay_rent.php?transaction_id={$txn['id']}");
    exit;
}

// Create transaction
$stmt = $conn->prepare("
    INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW())
");
$stmt->bind_param("iiiiddd", $listing_id, $user_id, $listing['seller_id'], $listing['price'], $depositAmount, $commissionAmount, $remainingAmount);
$stmt->execute();
$transaction_id = $conn->insert_id;

$conn->close();

// Redirect to payment page
header("Location: pay_rent.php?transaction_id=$transaction_id");
exit;
?>

BRS/user/initiate_rental.php

<?php
// user/initiate_rental.php - Create rental booking and redirect to payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_POST['listing_id']) ? intval($_POST['listing_id']) : 0;

// Get listing details
$listing = $conn->query("
    SELECT l.*, u.id as owner_id, u.full_name as owner_name, u.email as owner_email
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $listing_id AND l.type = 'rental' AND l.status = 'active'
")->fetch_assoc();

if (!$listing) {
    header('Location: browse.php');
    exit;
}

// Get form data
$check_in = isset($_POST['check_in']) ? $_POST['check_in'] : '';
$check_out = isset($_POST['check_out']) ? $_POST['check_out'] : '';
$guests = isset($_POST['guests']) ? intval($_POST['guests']) : 1;
$guest_name = isset($_POST['guest_name']) ? $_POST['guest_name'] : $_SESSION['user_name'];
$guest_phone = isset($_POST['phone']) ? $_POST['phone'] : '';
$special_requests = isset($_POST['message']) ? $_POST['message'] : '';

// Validate dates
if (empty($check_in) || empty($check_out)) {
    $_SESSION['error'] = "Please select check-in and check-out dates";
    header("Location: rental_booking.php?id=$listing_id");
    exit;
}

// Calculate nights and totals
$check_in_date = new DateTime($check_in);
$check_out_date = new DateTime($check_out);
$nights = $check_in_date->diff($check_out_date)->days;

if ($nights <= 0) {
    $_SESSION['error'] = "Check-out date must be after check-in date";
    header("Location: rental_booking.php?id=$listing_id");
    exit;
}

$depositPercent = $listing['admin_deposit_percent'] ?? 30;
$commissionPercent = $listing['admin_commission_percent'] ?? 15;
$total_rent = $listing['price'] * $nights;
$deposit_amount = $total_rent * ($depositPercent / 100);
$commission_amount = $total_rent * ($commissionPercent / 100);
$total_payment = $deposit_amount + $commission_amount;
$remaining_amount = $total_rent - $deposit_amount;

// Check if already has a pending booking for this property
$existing_booking = $conn->query("
    SELECT rb.id, rb.status, t.status as transaction_status
    FROM rental_bookings rb
    JOIN transactions t ON rb.transaction_id = t.id
    WHERE rb.property_id = $listing_id AND rb.tenant_id = $user_id 
    AND rb.status IN ('pending', 'confirmed')
");

if ($existing_booking->num_rows > 0) {
    $booking = $existing_booking->fetch_assoc();
    if ($booking['status'] == 'pending') {
        // Get the transaction
        $txn = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id AND buyer_id = $user_id")->fetch_assoc();
        if ($txn) {
            header("Location: pay_rent.php?transaction_id={$txn['id']}");
            exit;
        }
    }
}

$conn->begin_transaction();

try {
    // Create transaction
    $stmt = $conn->prepare("
        INSERT INTO transactions (
            listing_id, buyer_id, seller_id, total_amount, 
            deposit_amount, commission_amount, remaining_balance, 
            status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW())
    ");
    $stmt->bind_param("iiiiddd", $listing_id, $user_id, $listing['owner_id'], $total_rent, $deposit_amount, $commission_amount, $remaining_amount);
    $stmt->execute();
    $transaction_id = $conn->insert_id;
    
    // Create rental booking record
    $stmt2 = $conn->prepare("
        INSERT INTO rental_bookings (
            transaction_id, property_id, tenant_id, owner_id, 
            check_in_date, check_out_date, total_nights, total_amount, deposit_paid,
            guest_name, guest_phone, special_requests, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt2->bind_param("iiiiissdssss", 
        $transaction_id, $listing_id, $user_id, $listing['owner_id'],
        $check_in, $check_out, $nights, $total_rent, $deposit_amount,
        $guest_name, $guest_phone, $special_requests
    );
    $stmt2->execute();
    
    $conn->commit();
    
    // Redirect to payment
    header("Location: pay_rent.php?transaction_id=$transaction_id");
    exit;
    
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Booking failed: " . $e->getMessage();
    header("Location: rental_booking.php?id=$listing_id");
    exit;
}

$conn->close();
?>

BRS/user/jobs.php

<?php
// user/jobs.php - Complete Job Search and Browse with Filters

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

$page_title = 'Find Jobs';
ob_start();

$conn = getDbConnection();

// Get and sanitize filter parameters
$category = sanitizeInt($_GET['category'] ?? 0);
$search = sanitizeString($_GET['search'] ?? '');
$employment_type = sanitizeString($_GET['employment_type'] ?? '');
$min_salary = sanitizeFloat($_GET['min_salary'] ?? 0);
$max_salary = sanitizeFloat($_GET['max_salary'] ?? 0);
$location = sanitizeString($_GET['location'] ?? '');
$page = sanitizeInt($_GET['page'] ?? 1);
$sort = sanitizeString($_GET['sort'] ?? 'newest');

// Validate parameters
$valid_employment = ['', 'Full-time', 'Part-time', 'Contract', 'Remote', 'Internship'];
if (!in_array($employment_type, $valid_employment)) {
    $employment_type = '';
}

$valid_sorts = ['newest', 'salary_low', 'salary_high', 'company'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'newest';
}

if ($page < 1) $page = 1;
if ($page > 100) $page = 100;

if ($min_salary < 0) $min_salary = 0;
if ($max_salary < 0) $max_salary = 0;

$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$where = [
    "l.type = 'job'", 
    "l.status = 'active'", 
    "l.approval_status = 'approved'"
];
$params = [];
$types = "";

if ($search) {
    $where[] = "(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if ($category > 0) {
    $where[] = "l.category_id = ?";
    $params[] = $category;
    $types .= "i";
}

if ($employment_type) {
    $where[] = "JSON_EXTRACT(l.additional_details, '$.employment_type') = ?";
    $params[] = $employment_type;
    $types .= "s";
}

if ($min_salary > 0) {
    $where[] = "l.price >= ?";
    $params[] = $min_salary;
    $types .= "d";
}

if ($max_salary > 0) {
    $where[] = "l.price <= ?";
    $params[] = $max_salary;
    $types .= "d";
}

if ($location) {
    $where[] = "l.location LIKE ?";
    $params[] = "%$location%";
    $types .= "s";
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Sorting
switch ($sort) {
    case 'salary_low':
        $orderBy = "l.price ASC";
        break;
    case 'salary_high':
        $orderBy = "l.price DESC";
        break;
    case 'company':
        $orderBy = "u.full_name ASC";
        break;
    default:
        $orderBy = "l.created_at DESC";
}

// Get total count
$countSql = "SELECT COUNT(*) as total FROM listings l JOIN users u ON l.seller_id = u.id $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Get jobs
$sql = "SELECT l.*, u.full_name as company_name, u.id as company_id, u.is_verified as company_verified,
        c.name as category_name
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        LEFT JOIN categories c ON l.category_id = c.id
        $whereClause
        ORDER BY $orderBy
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$jobs = $stmt->get_result();

// Get categories for filter
$categories = $conn->query("SELECT id, name FROM categories WHERE type = 'job' AND is_active = 1 ORDER BY name");

// Get salary ranges for filter
$salary_ranges = [
    ['min' => 0, 'max' => 5000, 'label' => 'Under 5,000 ETB'],
    ['min' => 5000, 'max' => 10000, 'label' => '5,000 - 10,000 ETB'],
    ['min' => 10000, 'max' => 20000, 'label' => '10,000 - 20,000 ETB'],
    ['min' => 20000, 'max' => 50000, 'label' => '20,000 - 50,000 ETB'],
    ['min' => 50000, 'max' => 100000, 'label' => '50,000 - 100,000 ETB'],
    ['min' => 100000, 'max' => 999999999, 'label' => '100,000+ ETB']
];

$conn->close();
?>

<style>
    .jobs-container { max-width: 1400px; margin: 0 auto; }
    
    /* Header */
    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 32px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .page-header p { color: #64748b; font-size: 15px; }
    
    /* Search Section */
    .search-section { background: white; border-radius: 24px; padding: 24px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .search-bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .search-bar input { flex: 1; padding: 14px 20px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; }
    .search-bar button { padding: 14px 32px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
    .search-bar button:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    
    /* Filters */
    .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-group label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .filter-group select, .filter-group input { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; background: white; min-width: 140px; }
    .filter-group select:focus, .filter-group input:focus { outline: none; border-color: #667eea; }
    .reset-btn { background: #94a3b8 !important; color: white; padding: 10px 20px; border-radius: 10px; text-decoration: none; font-size: 13px; font-weight: 500; display: inline-block; transition: all 0.3s; }
    .reset-btn:hover { background: #64748b !important; transform: translateY(-2px); }
    
    /* Category Chips */
    .category-filters { display: flex; gap: 10px; margin: 20px 0; flex-wrap: wrap; }
    .filter-chip { padding: 8px 20px; background: white; border-radius: 40px; text-decoration: none; color: #334155; font-size: 13px; font-weight: 500; transition: all 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .filter-chip:hover, .filter-chip.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; transform: translateY(-2px); }
    
    /* Result Header */
    .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
    .result-count { font-size: 13px; color: #64748b; }
    .sort-select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; background: white; }
    
    /* Jobs Grid */
    .jobs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 24px; margin-bottom: 32px; }
    
    /* Job Card */
    .job-card { background: white; border-radius: 20px; overflow: hidden; transition: all 0.3s; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.05); position: relative; }
    .job-card:hover { transform: translateY(-6px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
    
    .job-card.featured { border: 2px solid #f59e0b; }
    .featured-badge { position: absolute; top: 12px; right: 12px; background: #f59e0b; color: white; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
    
    .job-header { padding: 20px; border-bottom: 1px solid #f1f5f9; }
    .job-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .company { font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
    .verified-badge { color: #10b981; font-size: 12px; }
    .salary { font-size: 22px; font-weight: 700; color: #667eea; margin-top: 8px; }
    .salary small { font-size: 12px; font-weight: normal; }
    
    .job-details { padding: 16px 20px; background: #f8fafc; display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: #475569; border-bottom: 1px solid #f1f5f9; }
    .job-details i { width: 16px; margin-right: 4px; color: #667eea; }
    
    .job-description { padding: 20px; font-size: 13px; color: #475569; line-height: 1.5; max-height: 100px; overflow: hidden; }
    
    .job-footer { padding: 16px 20px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: white; }
    
    /* Badges */
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-fulltime { background: #d1fae5; color: #059669; }
    .badge-parttime { background: #fed7aa; color: #ea580c; }
    .badge-remote { background: #dbeafe; color: #1e40af; }
    .badge-contract { background: #f3e8ff; color: #9333ea; }
    .badge-internship { background: #fce7f3; color: #db2777; }
    
    /* Buttons */
    .btn-apply { padding: 8px 20px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 30px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; }
    .btn-apply:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    .btn-save { background: none; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 30px; cursor: pointer; transition: all 0.3s; color: #64748b; }
    .btn-save:hover { background: #f1f5f9; color: #667eea; }
    
    /* Pagination */
    .pagination { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 14px; background: white; border-radius: 10px; text-decoration: none; color: #334155; font-size: 14px; transition: all 0.3s; border: 1px solid #e2e8f0; }
    .pagination a:hover, .pagination .active { background: #667eea; color: white; border-color: #667eea; }
    .pagination .disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
    
    /* Empty State */
    .empty-state { text-align: center; padding: 60px; background: white; border-radius: 24px; }
    .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    .empty-state h3 { font-size: 20px; color: #334155; margin-bottom: 8px; }
    
    /* Loading */
    .loading { text-align: center; padding: 40px; }
    .loading-spinner { width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top-color: #667eea; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto; }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    @media (max-width: 768px) {
        .jobs-grid { grid-template-columns: 1fr; }
        .search-bar { flex-direction: column; }
        .filters { flex-direction: column; align-items: stretch; }
        .filter-group select, .filter-group input { width: 100%; }
        .category-filters { overflow-x: auto; flex-wrap: nowrap; }
        .result-header { flex-direction: column; align-items: flex-start; }
    }
</style>

<div class="jobs-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-briefcase"></i> Find Your Next Opportunity</h1>
        <p>Browse thousands of job opportunities from trusted employers in Ethiopia</p>
    </div>
    
    <!-- Search Section -->
    <div class="search-section">
        <form method="GET" class="search-bar" id="searchForm">
            <input type="text" name="search" placeholder="Job title, keywords, or company" 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit"><i class="fas fa-search"></i> Search Jobs</button>
        </form>
        
        <div class="filters">
            <div class="filter-group">
                <label>Category</label>
                <select name="category" form="searchForm" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php while($cat = $categories->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Employment Type</label>
                <select name="employment_type" form="searchForm" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="Full-time" <?php echo $employment_type == 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
                    <option value="Part-time" <?php echo $employment_type == 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
                    <option value="Contract" <?php echo $employment_type == 'Contract' ? 'selected' : ''; ?>>Contract</option>
                    <option value="Remote" <?php echo $employment_type == 'Remote' ? 'selected' : ''; ?>>Remote</option>
                    <option value="Internship" <?php echo $employment_type == 'Internship' ? 'selected' : ''; ?>>Internship</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Location</label>
                <input type="text" name="location" placeholder="City/Area" value="<?php echo htmlspecialchars($location); ?>" form="searchForm">
            </div>
            
            <div class="filter-group">
                <label>Min Salary (ETB)</label>
                <input type="number" name="min_salary" placeholder="Min" value="<?php echo $min_salary ? number_format($min_salary, 0) : ''; ?>" step="1000" form="searchForm">
            </div>
            
            <div class="filter-group">
                <label>Max Salary (ETB)</label>
                <input type="number" name="max_salary" placeholder="Max" value="<?php echo $max_salary ? number_format($max_salary, 0) : ''; ?>" step="1000" form="searchForm">
            </div>
            
            <?php if ($search || $category || $employment_type || $location || $min_salary || $max_salary): ?>
                <div class="filter-group">
                    <a href="jobs.php" class="reset-btn">Clear All Filters</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Category Quick Filters -->
    <div class="category-filters">
        <a href="jobs.php" class="filter-chip <?php echo empty($_GET['category']) ? 'active' : ''; ?>">All Jobs</a>
        <a href="?employment_type=Full-time<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Full-time' ? 'active' : ''; ?>">Full-time</a>
        <a href="?employment_type=Remote<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Remote' ? 'active' : ''; ?>">Remote</a>
        <a href="?employment_type=Contract<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Contract' ? 'active' : ''; ?>">Contract</a>
        <a href="?employment_type=Internship<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Internship' ? 'active' : ''; ?>">Internship</a>
    </div>
    
    <!-- Results Header -->
    <div class="result-header">
        <div class="result-count">
            <i class="fas fa-list"></i> Found <strong><?php echo number_format($total); ?></strong> job opportunity(ies)
        </div>
        <div class="sort-options">
            <select class="sort-select" onchange="location.href=this.value">
                <option value="?sort=newest<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                <option value="?sort=salary_low<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'salary_low' ? 'selected' : ''; ?>>Salary: Low to High</option>
                <option value="?sort=salary_high<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'salary_high' ? 'selected' : ''; ?>>Salary: High to Low</option>
                <option value="?sort=company<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'company' ? 'selected' : ''; ?>>Company Name</option>
            </select>
        </div>
    </div>
    
    <!-- Jobs Grid -->
    <?php if ($jobs && $jobs->num_rows > 0): ?>
        <div class="jobs-grid">
            <?php while($job = $jobs->fetch_assoc()): 
                $additional = $job['additional_details'] ? json_decode($job['additional_details'], true) : [];
                $emp_type = $additional['employment_type'] ?? '';
                $requirements = $additional['requirements'] ?? '';
                $is_featured = $job['featured'] ?? false;
                
                // Badge class based on employment type
                $badge_class = '';
                switch($emp_type) {
                    case 'Full-time': $badge_class = 'badge-fulltime'; break;
                    case 'Part-time': $badge_class = 'badge-parttime'; break;
                    case 'Remote': $badge_class = 'badge-remote'; break;
                    case 'Contract': $badge_class = 'badge-contract'; break;
                    case 'Internship': $badge_class = 'badge-internship'; break;
                }
            ?>
                <div class="job-card <?php echo $is_featured ? 'featured' : ''; ?>" onclick="location.href='product.php?id=<?php echo $job['id']; ?>'">
                    <?php if ($is_featured): ?>
                        <div class="featured-badge"><i class="fas fa-star"></i> Featured</div>
                    <?php endif; ?>
                    
                    <div class="job-header">
                        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                        <div class="company">
                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?>
                            <?php if ($job['company_verified']): ?>
                                <i class="fas fa-check-circle verified-badge" title="Verified Company"></i>
                            <?php endif; ?>
                        </div>
                        <div class="salary">
                            <?php echo formatMoney($job['price']); ?><small>/month</small>
                        </div>
                    </div>
                    
                    <div class="job-details">
                        <?php if ($emp_type): ?>
                            <span><i class="fas fa-clock"></i> <?php echo $emp_type; ?></span>
                        <?php endif; ?>
                        <?php if ($job['location']): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['location']); ?></span>
                        <?php endif; ?>
                        <?php if ($job['category_name']): ?>
                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($job['category_name']); ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-calendar"></i> Posted <?php echo timeAgo($job['created_at']); ?></span>
                    </div>
                    
                    <div class="job-description">
                        <?php 
                        $desc = strip_tags($job['description']);
                        echo htmlspecialchars(substr($desc, 0, 120));
                        if (strlen($desc) > 120): ?>...<?php endif; ?>
                    </div>
                    
                    <div class="job-footer">
                        <?php if ($emp_type): ?>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo $emp_type; ?></span>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 10px;">
                            <?php if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in']): ?>
                                <?php if ($_SESSION['user_role'] != 'company'): ?>
                                    <a href="apply_job.php?id=<?php echo $job['id']; ?>" class="btn-apply" onclick="event.stopPropagation()">
                                        Apply Now →
                                    </a>
                                <?php else: ?>
                                    <span class="badge" style="background: #e2e8f0; color: #64748b;">Company Account</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="/broker_system/auth/login.php" class="btn-apply" onclick="event.stopPropagation()">
                                    Login to Apply →
                                </a>
                            <?php endif; ?>
                            <button class="btn-save" onclick="event.stopPropagation(); saveJob(<?php echo $job['id']; ?>, this)" title="Save for later">
                                <i class="far fa-bookmark"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category; ?>&employment_type=<?php echo urlencode($employment_type); ?>&location=<?php echo urlencode($location); ?>&min_salary=<?php echo $min_salary; ?>&max_salary=<?php echo $max_salary; ?>&sort=<?php echo $sort; ?>">
                        ← Previous
                    </a>
                <?php else: ?>
                    <span class="disabled">← Previous</span>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category; ?>&employment_type=<?php echo urlencode($employment_type); ?>&location=<?php echo urlencode($location); ?>&min_salary=<?php echo $min_salary; ?>&max_salary=<?php echo $max_salary; ?>&sort=<?php echo $sort; ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category; ?>&employment_type=<?php echo urlencode($employment_type); ?>&location=<?php echo urlencode($location); ?>&min_salary=<?php echo $min_salary; ?>&max_salary=<?php echo $max_salary; ?>&sort=<?php echo $sort; ?>">
                        Next →
                    </a>
                <?php else: ?>
                    <span class="disabled">Next →</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-search"></i>
            <h3>No jobs found</h3>
            <p>Try adjusting your search or filter criteria</p>
            <?php if ($search || $category || $employment_type || $location): ?>
                <a href="jobs.php" class="btn-apply" style="margin-top: 16px; display: inline-block;">Clear All Filters</a>
            <?php endif; ?>
            <?php if (!isset($_SESSION['user_logged_in'])): ?>
                <p style="margin-top: 16px;">
                    <a href="/broker_system/auth/register.php" style="color: #667eea;">Create an account</a> to apply for jobs
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Save job for later
function saveJob(jobId, button) {
    fetch('api/save_job.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ job_id: jobId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const icon = button.querySelector('i');
            if (data.saved) {
                icon.classList.remove('far');
                icon.classList.add('fas');
                button.style.color = '#f59e0b';
            } else {
                icon.classList.remove('fas');
                icon.classList.add('far');
                button.style.color = '#64748b';
            }
        } else if (data.require_login) {
            window.location.href = '/broker_system/auth/login.php';
        }
    });
}

// Quick filter chips
document.querySelectorAll('.filter-chip').forEach(chip => {
    chip.addEventListener('click', function(e) {
        if (!this.getAttribute('href')?.includes('?')) {
            e.preventDefault();
            const params = new URLSearchParams(window.location.search);
            params.set('employment_type', this.textContent.trim());
            window.location.href = '?' + params.toString();
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/layout.php

<?php
// user/layout.php - Complete layout with all working sidebar links

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/chat_functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

$notifications_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count'];
$pending_legal_count = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id) AND t.status = 'deposits_complete' AND ((t.buyer_legal_confirmed = 0 AND t.buyer_id = $user_id) OR (t.seller_legal_confirmed = 0 AND t.seller_id = $user_id))")->fetch_assoc()['count'];
$unread_chat_count = getUnreadMessageCount($conn, $user_id);
$notifications = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 10");

$conn->close();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $page_title ?? 'Dashboard'; ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            transition: all 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 10px; }
        .sidebar { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.3) rgba(255,255,255,0.1); }
        
        .sidebar.collapsed { width: 80px; }
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-label,
        .sidebar.collapsed .profile-name,
        .sidebar.collapsed .profile-email { display: none; }
        .sidebar.collapsed .menu-item { justify-content: center; padding: 12px; }
        .sidebar.collapsed .menu-item i { margin-right: 0; }
        .sidebar.collapsed .section-header { display: none; }
        
        .sidebar-header {
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            position: sticky;
            top: 0;
            background: #1e293b;
            z-index: 10;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-icon { font-size: 28px; }
        .logo-text { font-size: 18px; font-weight: 700; }
        
        .collapse-btn {
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        
        .collapse-btn:hover { background: rgba(255,255,255,0.2); }
        
        .nav-menu {
            list-style: none;
            padding: 20px 16px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            margin: 2px 0;
            border-radius: 10px;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            white-space: nowrap;
        }
        
        .menu-item i {
            width: 24px;
            font-size: 16px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .menu-item span { font-size: 13px; font-weight: 500; }
        .menu-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .menu-item.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        
        .badge-count {
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 20px;
            margin-left: auto;
            min-width: 18px;
            text-align: center;
        }
        
        .section-header {
            padding: 8px 16px;
            margin-top: 8px;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .sidebar-footer {
            position: sticky;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            background: #0f172a;
            margin-top: 20px;
        }
        
        .profile-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: white;
        }
        
        .profile-item:hover { background: rgba(255,255,255,0.1); }
        
        .profile-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .profile-info {
            flex: 1;
            min-width: 0;
        }
        
        .profile-name {
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .profile-email {
            font-size: 10px;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        .main-content.expanded { margin-left: 80px; }
        
        .top-bar {
            background: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }
        
        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        /* Notification Dropdown */
        .notification-dropdown { position: relative; }
        .notification-icon {
            position: relative;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: background 0.3s;
        }
        .notification-icon:hover { background: #f1f5f9; }
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 10px;
        }
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            display: none;
            z-index: 1000;
            margin-top: 8px;
        }
        .dropdown-menu.show { display: block; }
        .dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dropdown-header h4 { font-size: 14px; font-weight: 600; }
        .dropdown-header a { font-size: 11px; color: #667eea; text-decoration: none; cursor: pointer; }
        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.3s;
        }
        .notification-item:hover { background: #f8fafc; }
        .notification-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .notification-message { font-size: 11px; color: #64748b; }
        .notification-time { font-size: 10px; color: #94a3b8; margin-top: 4px; }
        
        /* User Dropdown */
        .user-dropdown { position: relative; cursor: pointer; }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        .user-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 200px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            display: none;
            margin-top: 8px;
            z-index: 1000;
        }
        .user-menu.show { display: block; }
        .user-menu-item {
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #334155;
            text-decoration: none;
            transition: background 0.3s;
        }
        .user-menu-item:hover { background: #f1f5f9; }
        
        .container { padding: 24px; }
        
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar .logo-text, .sidebar .menu-label, .sidebar .profile-name, .sidebar .profile-email, .sidebar .section-header { display: none; }
            .sidebar .menu-item { justify-content: center; padding: 12px; }
            .sidebar .menu-item i { margin-right: 0; }
            .main-content { margin-left: 80px; }
        }
        
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); width: 280px; }
            .sidebar.mobile-open .logo-text, .sidebar.mobile-open .menu-label, .sidebar.mobile-open .profile-name, .sidebar.mobile-open .profile-email, .sidebar.mobile-open .section-header { display: block; }
            .sidebar.mobile-open .menu-item { justify-content: flex-start; }
            .sidebar.mobile-open .menu-item i { margin-right: 12px; }
            .main-content { margin-left: 0; }
            .dropdown-menu { width: 300px; right: -50px; }
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <span class="logo-icon">🏪</span>
                <span class="logo-text">Brokerplace</span>
            </div>
            <button class="collapse-btn" id="collapseBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>
        
        <ul class="nav-menu">
            <!-- Dashboard -->
            <a href="/broker_system/user/dashboard.php" class="menu-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span class="menu-label">Dashboard</span>
            </a>
            
            <!-- Browse -->
            <a href="/broker_system/user/browse.php" class="menu-item <?php echo $current_page == 'browse.php' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i>
                <span class="menu-label">Browse</span>
            </a>
            
            <!-- My Listings -->
            <a href="/broker_system/user/listings.php" class="menu-item <?php echo $current_page == 'listings.php' ? 'active' : ''; ?>">
                <i class="fas fa-box"></i>
                <span class="menu-label">My Listings</span>
            </a>
            
            <!-- Wallet -->
            <a href="/broker_system/user/wallet.php" class="menu-item <?php echo $current_page == 'wallet.php' ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i>
                <span class="menu-label">Wallet</span>
            </a>
            
            <!-- Notifications -->
            <a href="/broker_system/user/notifications.php" class="menu-item <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span class="menu-label">Notifications</span>
                <?php if ($notifications_count > 0): ?>
                    <span class="badge-count"><?php echo $notifications_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Chat / Messages -->
            <a href="/broker_system/user/chat.php" class="menu-item <?php echo $current_page == 'chat.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i>
                <span class="menu-label">Messages</span>
                <?php if ($unread_chat_count > 0): ?>
                    <span class="badge-count"><?php echo $unread_chat_count; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Activity Section -->
            <div class="section-header">Activity</div>
            
            <!-- Transactions -->
            <a href="/broker_system/user/transactions.php" class="menu-item <?php echo $current_page == 'transactions.php' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span class="menu-label">Transactions</span>
            </a>


            <!-- Add to admin/layout.php sidebar -->
<a href="withdrawal_approval.php" class="menu-item">
    <i class="fas fa-money-bill-wave"></i>
    <span class="menu-label">Withdrawals</span>
    <?php 
    $pending_count = $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc()['count'];
    if ($pending_count > 0): ?>
        <span class="badge-count"><?php echo $pending_count; ?></span>
    <?php endif; ?>
</a>

        <a href="dispute_resolution.php" class="menu-item">
            <i class="fas fa-gavel"></i>
            <span class="menu-label">Disputes</span>
        </a>

        <!-- Add to user/layout.php sidebar -->
        <a href="withdrawal_request.php" class="menu-item">
            <i class="fas fa-money-bill-wave"></i>
            <span class="menu-label">Withdraw</span>
        </a>
            
            <!-- Legal Process -->
            <a href="/broker_system/user/legal_process.php" class="menu-item <?php echo $current_page == 'legal_process.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span class="menu-label">Legal Process</span>
                <?php if ($pending_legal_count > 0): ?>
                    <span class="badge-count"><?php echo $pending_legal_count; ?></span>
                <?php endif; ?>
            </a>
        </ul>
        
        <div class="sidebar-footer">
            <!-- Profile -->
            <a href="/broker_system/user/profile.php" class="profile-item">
                <div class="profile-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="profile-email"><?php echo htmlspecialchars($user_email); ?></div>
                </div>
            </a>
            
            <!-- Settings -->
            <a href="/broker_system/user/settings.php" class="menu-item" style="margin-top: 4px;">
                <i class="fas fa-cog"></i>
                <span class="menu-label">Settings</span>
            </a>
            
            <!-- Logout -->
            <a href="/broker_system/auth/logout.php" class="menu-item" style="margin-top: 4px;">
                <i class="fas fa-sign-out-alt logout-icon"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>
    </div>
    
    <!-- MAIN CONTENT -->
    <div class="main-content" id="mainContent">
        <div class="top-bar">
            <h1 class="page-title"><?php echo $page_title ?? 'Dashboard'; ?></h1>
            <div class="top-bar-actions">
                <!-- Notifications Dropdown -->
                <div class="notification-dropdown">
                    <div class="notification-icon" id="notificationIcon">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifications_count > 0): ?>
                            <span class="notification-badge"><?php echo $notifications_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-menu" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h4>Notifications</h4>
                            <a href="/broker_system/user/notifications.php">View all</a>
                        </div>
                        <div id="notificationList">
                            <?php if ($notifications && $notifications->num_rows > 0): ?>
                                <?php while($notif = $notifications->fetch_assoc()): ?>
                                    <div class="notification-item" onclick="location.href='/broker_system/user/notifications.php'">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="notification-time"><?php echo timeAgo($notif['created_at']); ?></div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="notification-item">No new notifications</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- User Dropdown -->
                <div class="user-dropdown">
                    <div class="user-avatar" id="userAvatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                    <div class="user-menu" id="userMenu">
                        <a href="/broker_system/user/profile.php" class="user-menu-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="/broker_system/user/wallet.php" class="user-menu-item"><i class="fas fa-wallet"></i> Wallet</a>
                        <a href="/broker_system/user/notifications.php" class="user-menu-item"><i class="fas fa-bell"></i> Notifications</a>
                        <a href="/broker_system/user/settings.php" class="user-menu-item"><i class="fas fa-cog"></i> Settings</a>
                        <hr style="margin: 8px 0; border-color: #f1f5f9;">
                        <a href="/broker_system/auth/logout.php" class="user-menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="container">
            <?php echo $content ?? ''; ?>
        </div>
    </div>
    
    <script>
        // Sidebar collapse
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseBtn');
        
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                const icon = collapseBtn.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                } else {
                    icon.classList.remove('fa-chevron-right');
                    icon.classList.add('fa-chevron-left');
                }
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }
        
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            if (collapseBtn) {
                const icon = collapseBtn.querySelector('i');
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            }
        }
        
        // Notification dropdown
        const notificationIcon = document.getElementById('notificationIcon');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
        if (notificationIcon) {
            notificationIcon.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
                if (userMenu) userMenu.classList.remove('show');
            });
        }
        
        // User dropdown
        const userAvatar = document.getElementById('userAvatar');
        const userMenu = document.getElementById('userMenu');
        
        if (userAvatar) {
            userAvatar.addEventListener('click', function(e) {
                e.stopPropagation();
                userMenu.classList.toggle('show');
                if (notificationDropdown) notificationDropdown.classList.remove('show');
            });
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            if (notificationDropdown) notificationDropdown.classList.remove('show');
            if (userMenu) userMenu.classList.remove('show');
        });
        
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            sidebar.classList.toggle('mobile-open');
        }
    </script>
</body>
</html>

BRS/user/legal_process.php

<?php
// user/legal_process.php - Modern Redesigned Legal Process Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Legal Process';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get transactions pending legal confirmation
$pending_legal = $conn->query("
    SELECT t.id, l.title, t.total_amount,
           CASE WHEN t.buyer_id = $user_id THEN 'buyer' ELSE 'seller' END as my_role,
           t.buyer_legal_confirmed, t.seller_legal_confirmed,
           t.status, t.created_at
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id)
    AND t.status = 'deposits_complete'
    ORDER BY t.created_at DESC
");

// Get statistics
$total_pending = $pending_legal ? $pending_legal->num_rows : 0;
$my_pending = 0;
$other_pending = 0;

if ($pending_legal) {
    $pending_legal_data = [];
    while ($row = $pending_legal->fetch_assoc()) {
        $pending_legal_data[] = $row;
        $my_confirmed = ($row['my_role'] == 'buyer') ? $row['buyer_legal_confirmed'] : $row['seller_legal_confirmed'];
        $other_confirmed = ($row['my_role'] == 'buyer') ? $row['seller_legal_confirmed'] : $row['buyer_legal_confirmed'];
        
        if (!$my_confirmed) $my_pending++;
        if (!$other_confirmed) $other_pending++;
    }
    // Reset pointer
    $pending_legal = $pending_legal_data;
}

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --primary-dark: #5a67d8;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --info: #3b82f6;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .legal-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    /* Header Section */
    .legal-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 40px;
        margin-bottom: 32px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .legal-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .legal-header-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .legal-header h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .legal-header p {
        font-size: 14px;
        opacity: 0.9;
    }
    
    .dashboard-link {
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 10px 20px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .dashboard-link:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
    }
    
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 32px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 24px;
        color: white;
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 12px;
        color: var(--gray);
        margin-top: 4px;
    }
    
    /* Legal Cards */
    .legal-card {
        background: white;
        border-radius: 24px;
        margin-bottom: 24px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .legal-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .card-status-banner {
        padding: 12px 24px;
        background: linear-gradient(135deg, var(--warning), #ea580c);
        color: white;
        font-size: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .card-status-banner.completed {
        background: linear-gradient(135deg, var(--success), #059669);
    }
    
    .card-status-banner i {
        margin-right: 6px;
    }
    
    .card-body {
        padding: 24px;
    }
    
    .transaction-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .transaction-id {
        background: var(--light);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        color: var(--gray);
        font-weight: normal;
    }
    
    .transaction-amount {
        font-size: 24px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 20px;
    }
    
    /* Progress Steps */
    .progress-steps {
        margin: 24px 0;
    }
    
    .step {
        display: flex;
        align-items: center;
        margin-bottom: 16px;
        position: relative;
    }
    
    .step-marker {
        width: 40px;
        height: 40px;
        background: var(--light);
        border: 2px solid var(--border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: var(--gray);
        margin-right: 16px;
        z-index: 1;
        background: white;
    }
    
    .step.completed .step-marker {
        background: var(--success);
        border-color: var(--success);
        color: white;
    }
    
    .step.active .step-marker {
        border-color: var(--primary);
        color: var(--primary);
    }
    
    .step-content {
        flex: 1;
    }
    
    .step-title {
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .step-desc {
        font-size: 11px;
        color: var(--gray);
    }
    
    .step-status {
        font-size: 12px;
        font-weight: 500;
    }
    
    .step-status.completed {
        color: var(--success);
    }
    
    .step-status.pending {
        color: var(--warning);
    }
    
    /* Confirmation Cards */
    .confirmation-card {
        background: var(--light);
        border-radius: 16px;
        padding: 16px;
        margin: 16px 0;
    }
    
    .confirmation-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .confirmation-row:last-child {
        border-bottom: none;
    }
    
    .confirmation-label {
        font-size: 13px;
        color: var(--gray);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .badge-success {
        background: #d1fae5;
        color: #059669;
    }
    
    .badge-warning {
        background: #fed7aa;
        color: #ea580c;
    }
    
    .badge-info {
        background: #dbeafe;
        color: #1e40af;
    }
    
    /* Buttons */
    .btn {
        padding: 12px 28px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-success {
        background: var(--success);
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--gray);
    }
    
    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    
    /* Empty State */
    .empty-state {
        background: white;
        border-radius: 28px;
        padding: 60px;
        text-align: center;
        border: 1px solid var(--border);
    }
    
    .empty-state-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 36px;
        color: white;
    }
    
    .empty-state h3 {
        font-size: 20px;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .empty-state p {
        color: var(--gray);
        margin-bottom: 20px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .legal-header {
            padding: 24px;
        }
        .legal-header-content {
            flex-direction: column;
            text-align: center;
        }
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .step {
            flex-direction: column;
            text-align: center;
        }
        .step-marker {
            margin-right: 0;
            margin-bottom: 8px;
        }
        .confirmation-row {
            flex-direction: column;
            gap: 8px;
            align-items: flex-start;
        }
        .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="legal-container">
    <!-- Header -->
    <div class="legal-header">
        <div class="legal-header-content">
            <div>
                <h1><i class="fas fa-gavel"></i> Legal Process</h1>
                <p>Complete legal documentation and confirmations for your transactions</p>
            </div>
            <a href="dashboard.php" class="dashboard-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-value"><?php echo $total_pending; ?></div>
            <div class="stat-label">Pending Transactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-value"><?php echo $my_pending; ?></div>
            <div class="stat-label">Waiting for You</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?php echo $other_pending; ?></div>
            <div class="stat-label">Waiting for Other Party</div>
        </div>
    </div>
    
    <!-- Legal Process Cards -->
    <?php if (!empty($pending_legal)): ?>
        <?php foreach($pending_legal as $legal): 
            $my_role = $legal['my_role'];
            $my_confirmed = ($my_role == 'buyer') ? $legal['buyer_legal_confirmed'] : $legal['seller_legal_confirmed'];
            $other_confirmed = ($my_role == 'buyer') ? $legal['seller_legal_confirmed'] : $legal['buyer_legal_confirmed'];
            $both_confirmed = ($my_confirmed && $other_confirmed);
        ?>
            <div class="legal-card">
                <!-- Status Banner -->
                <div class="card-status-banner <?php echo $both_confirmed ? 'completed' : ''; ?>">
                    <span>
                        <i class="fas <?php echo $both_confirmed ? 'fa-check-circle' : 'fa-hourglass-half'; ?>"></i>
                        <?php echo $both_confirmed ? 'Legal Process Complete!' : 'Legal Process in Progress'; ?>
                    </span>
                    <span>
                        <i class="fas fa-calendar"></i> 
                        <?php echo date('M d, Y', strtotime($legal['created_at'])); ?>
                    </span>
                </div>
                
                <div class="card-body">
                    <!-- Transaction Info -->
                    <div class="transaction-title">
                        <?php echo htmlspecialchars($legal['title']); ?>
                        <span class="transaction-id">#<?php echo $legal['id']; ?></span>
                    </div>
                    <div class="transaction-amount">
                        <?php echo formatMoney($legal['total_amount']); ?>
                    </div>
                    
                    <!-- Progress Steps -->
                    <div class="progress-steps">
                        <div class="step <?php echo $my_confirmed ? 'completed' : 'active'; ?>">
                            <div class="step-marker">
                                <?php if ($my_confirmed): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    1
                                <?php endif; ?>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Your Confirmation</div>
                                <div class="step-desc">You need to confirm the legal process</div>
                            </div>
                            <div class="step-status <?php echo $my_confirmed ? 'completed' : 'pending'; ?>">
                                <?php if ($my_confirmed): ?>
                                    <i class="fas fa-check-circle"></i> Completed
                                <?php else: ?>
                                    <i class="fas fa-clock"></i> Pending
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="step <?php echo $other_confirmed ? 'completed' : ($my_confirmed ? 'active' : ''); ?>">
                            <div class="step-marker">
                                <?php if ($other_confirmed): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    2
                                <?php endif; ?>
                            </div>
                            <div class="step-content">
                                <div class="step-title">Other Party Confirmation</div>
                                <div class="step-desc">Waiting for <?php echo ($my_role == 'buyer') ? 'seller' : 'buyer'; ?> to confirm</div>
                            </div>
                            <div class="step-status <?php echo $other_confirmed ? 'completed' : 'pending'; ?>">
                                <?php if ($other_confirmed): ?>
                                    <i class="fas fa-check-circle"></i> Completed
                                <?php else: ?>
                                    <i class="fas fa-clock"></i> Pending
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Confirmation Details -->
                    <div class="confirmation-card">
                        <div class="confirmation-row">
                            <span class="confirmation-label">
                                <i class="fas fa-user"></i> Your Role
                            </span>
                            <span class="badge badge-info">
                                <i class="fas <?php echo $my_role == 'buyer' ? 'fa-shopping-cart' : 'fa-store'; ?>"></i>
                                <?php echo ucfirst($my_role); ?>
                            </span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-label">
                                <i class="fas fa-gavel"></i> Your Legal Status
                            </span>
                            <?php if ($my_confirmed): ?>
                                <span class="badge badge-success">
                                    <i class="fas fa-check-circle"></i> Confirmed
                                </span>
                            <?php else: ?>
                                <span class="badge badge-warning">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-label">
                                <i class="fas fa-store"></i> Other Party Status
                            </span>
                            <?php if ($other_confirmed): ?>
                                <span class="badge badge-success">
                                    <i class="fas fa-check-circle"></i> Confirmed
                                </span>
                            <?php else: ?>
                                <span class="badge badge-warning">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <?php if (!$my_confirmed): ?>
                        <a href="transaction.php?id=<?php echo $legal['id']; ?>" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-file-signature"></i> Complete Legal Process
                        </a>
                    <?php elseif ($my_confirmed && !$other_confirmed): ?>
                        <div style="text-align: center;">
                            <span class="badge badge-warning" style="background: #fed7aa; color: #ea580c; padding: 10px 20px;">
                                <i class="fas fa-clock"></i> Waiting for <?php echo ($my_role == 'buyer') ? 'Seller' : 'Buyer'; ?> to Confirm
                            </span>
                        </div>
                    <?php else: ?>
                        <a href="transaction.php?id=<?php echo $legal['id']; ?>" class="btn btn-success" style="width: 100%;">
                            <i class="fas fa-truck"></i> Proceed to Delivery Confirmation
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>No Pending Legal Processes</h3>
            <p>All your transactions have completed legal confirmation or are awaiting deposits.</p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Go to Dashboard
                </a>
                <a href="browse.php" class="btn btn-outline">
                    <i class="fas fa-search"></i> Browse Listings
                </a>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Information Note -->
    <div style="background: linear-gradient(135deg, #dbeafe, #e0e7ff); border-radius: 20px; padding: 20px; margin-top: 24px;">
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <i class="fas fa-info-circle" style="font-size: 32px; color: var(--primary);"></i>
            <div style="flex: 1;">
                <strong style="color: var(--dark);">What is the Legal Process?</strong>
                <p style="font-size: 13px; color: var(--gray); margin-top: 4px;">
                    Both buyer and seller must confirm that all legal documentation, contracts, and requirements 
                    for this transaction are completed. This protects both parties and ensures a smooth transfer 
                    of ownership or service delivery.
                </p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/listings.php

<?php
// user/listings.php - My Listings Page with Full Negotiation Buttons

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'My Listings';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/seller_listing_payment.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle negotiation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accept_terms'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'agreement_accepted', accepted_at = NOW() 
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        
        // Get listing info for notification
        $neg = $conn->query("SELECT listing_id FROM listing_negotiations WHERE id = $negotiation_id")->fetch_assoc();
        $listing = $conn->query("SELECT title FROM listings WHERE id = {$neg['listing_id']}")->fetch_assoc();
        
        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Terms Accepted', 'Seller has accepted the terms for listing \"{$listing['title']}\". Awaiting deposit payment.', NOW())
        ");
        $notif_stmt->bind_param("i", $admin['id']);
        $notif_stmt->execute();
        
        $message = "Terms accepted! Please pay the deposit to publish your listing.";
    }
    
    if (isset($_POST['reject_terms'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $conn->query("
            UPDATE listing_negotiations 
            SET status = 'rejected', rejection_reason = 'Seller rejected the proposal'
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        $message = "Listing rejected. You can submit a new listing if you change your mind.";
    }
    
    if (isset($_POST['send_counter'])) {
        $negotiation_id = intval($_POST['negotiation_id']);
        $counter_commission = floatval($_POST['counter_commission']);
        $counter_deposit = floatval($_POST['counter_deposit']);
        $counter_message = $conn->real_escape_string($_POST['counter_message'] ?? '');
        
        $conn->query("
            UPDATE listing_negotiations 
            SET counter_commission = $counter_commission, 
                counter_deposit = $counter_deposit, 
                counter_message = '$counter_message', 
                status = 'counter_offer_sent' 
            WHERE id = $negotiation_id AND seller_id = $user_id
        ");
        
        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Counter Offer Received', 'A seller has sent a counter offer. Please review.', NOW())
        ");
        $notif_stmt->bind_param("i", $admin['id']);
        $notif_stmt->execute();
        
        $message = "Counter offer sent! Admin will review your proposal.";
    }
}

// Get filter status
$status = $_GET['status'] ?? 'all';

// Build query
$where = "l.seller_id = $user_id";
if ($status == 'active') {
    $where .= " AND l.status = 'active' AND l.approval_status = 'approved'";
} elseif ($status == 'pending') {
    $where .= " AND l.approval_status = 'approved' AND l.status = 'pending'";
} elseif ($status == 'waiting') {
    $where .= " AND l.approval_status = 'pending'";
} elseif ($status == 'negotiating') {
    $where .= " AND l.id IN (SELECT listing_id FROM listing_negotiations WHERE seller_id = $user_id AND status IN ('commission_proposed', 'counter_offer_sent'))";
} elseif ($status == 'rejected') {
    $where .= " AND l.approval_status = 'rejected'";
}

$listings = $conn->query("
    SELECT l.*, c.name as category_name,
           ln.id as negotiation_id, ln.status as negotiation_status,
           ln.proposed_commission, ln.proposed_deposit,
           ln.counter_commission, ln.counter_deposit, ln.counter_message,
           ln.accepted_at
    FROM listings l
    LEFT JOIN categories c ON l.category_id = c.id
    LEFT JOIN listing_negotiations ln ON l.id = ln.listing_id AND ln.seller_id = $user_id
    WHERE $where
    ORDER BY l.created_at DESC
");

// Get counts for tabs
$counts = [
    'all' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'],
    'pending_payment' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'approved' AND status = 'pending'")->fetch_assoc()['count'],
    'waiting' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'pending'")->fetch_assoc()['count'],
    'negotiating' => $conn->query("
        SELECT COUNT(*) as count FROM listing_negotiations ln 
        JOIN listings l ON ln.listing_id = l.id 
        WHERE ln.seller_id = $user_id AND ln.status IN ('commission_proposed', 'counter_offer_sent')
    ")->fetch_assoc()['count'],
    'rejected' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND approval_status = 'rejected'")->fetch_assoc()['count'],
];

$listings_rows = [];
if ($listings && $listings->num_rows > 0) {
    while ($row = $listings->fetch_assoc()) {
        $row['seller_payment'] = null;
        if ($row['status'] === 'active' && $row['approval_status'] === 'approved') {
            $row['seller_payment'] = getSellerListingPaymentInfo($conn, $row['id'], $user_id);
        }
        $listings_rows[] = $row;
    }
}

$conn->close();
?>

<style>
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .page-header p {
        color: #64748b;
        font-size: 14px;
    }
    
    .stats-banner {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 20px;
        padding: 20px 28px;
        margin-bottom: 28px;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .stats-banner h3 {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .stats-banner p {
        opacity: 0.9;
        font-size: 13px;
    }
    
    .stats-banner .badge {
        background: rgba(255,255,255,0.2);
        padding: 8px 20px;
        border-radius: 40px;
        font-weight: 600;
    }
    
    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 12px;
    }
    
    .tab {
        padding: 8px 20px;
        background: transparent;
        border-radius: 30px;
        text-decoration: none;
        color: #64748b;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .tab:hover {
        background: #f1f5f9;
        color: #334155;
    }
    
    .tab.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .tab .count {
        background: rgba(0,0,0,0.1);
        padding: 2px 6px;
        border-radius: 20px;
        margin-left: 6px;
        font-size: 11px;
    }
    
    .listings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
        gap: 24px;
    }
    
    .listing-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .listing-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .card-image {
        height: 180px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
        overflow: hidden;
        position: relative;
    }
    
    .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .card-content {
        padding: 20px;
    }
    
    .listing-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #0f172a;
    }
    
    .listing-price {
        font-size: 20px;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 12px;
    }
    
    .listing-stats {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        font-size: 13px;
        color: #64748b;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-info { background: #dbeafe; color: #2563eb; }
    .badge-negotiating { background: #ede9fe; color: #6b21a5; }
    
    /* Negotiation Box */
    .negotiation-box {
        background: #f8fafc;
        border-radius: 16px;
        padding: 16px;
        margin: 12px 0;
        border: 1px solid #e2e8f0;
    }
    
    .offer-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 12px;
    }
    
    .offer-item {
        flex: 1;
        text-align: center;
    }
    
    .offer-label {
        font-size: 11px;
        color: #64748b;
        margin-bottom: 4px;
    }
    
    .offer-value {
        font-size: 18px;
        font-weight: 700;
    }
    
    .offer-value.proposed {
        color: #667eea;
    }
    
    .offer-value.counter {
        color: #f59e0b;
    }
    
    .counter-message {
        background: #fef3c7;
        padding: 10px;
        border-radius: 10px;
        font-size: 12px;
        margin-top: 12px;
        color: #92400e;
    }
    
    .btn-group {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 12px;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 40px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        text-align: center;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .btn-warning {
        background: #f59e0b;
        color: white;
    }
    
    .btn-warning:hover {
        background: #d97706;
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-2px);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid #e2e8f0;
        color: #64748b;
    }
    
    .btn-outline:hover {
        border-color: #667eea;
        color: #667eea;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .payment-info {
        background: #fef3c7;
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
        font-size: 12px;
    }

    .seller-payment-summary {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
        font-size: 12px;
    }

    .seller-payment-summary .pay-row {
        display: flex;
        justify-content: space-between;
        margin: 4px 0;
    }

    .seller-payment-summary .pay-row.remaining {
        font-weight: 700;
        color: #059669;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #bbf7d0;
    }

    .badge-fully-paid {
        background: #d1fae5;
        color: #059669;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        margin-top: 8px;
    }

    .pay-remaining-btn.loading {
        opacity: 0.7;
        pointer-events: none;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
    
    .empty-state h3 {
        font-size: 20px;
        color: #334155;
        margin-bottom: 8px;
    }
    
    .empty-state p {
        color: #64748b;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-content {
        background: white;
        border-radius: 24px;
        padding: 28px;
        width: 500px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header h3 {
        font-size: 20px;
        font-weight: 600;
        color: #0f172a;
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 28px;
        color: #94a3b8;
        transition: color 0.3s;
    }
    
    .close-modal:hover {
        color: #ef4444;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #334155;
        font-size: 13px;
    }
    
    .form-group input, .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
    }
    
    .form-group input:focus, .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    @media (max-width: 768px) {
        .listings-grid {
            grid-template-columns: 1fr;
        }
        .tabs {
            overflow-x: auto;
            flex-wrap: nowrap;
        }
        .offer-row {
            flex-direction: column;
            text-align: center;
        }
        .btn-group {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-header">
    <h1>My Listings</h1>
    <p>Manage your products, jobs, and rental listings</p>
</div>

<!-- Stats Banner for Negotiations -->
<?php if ($counts['negotiating'] > 0): ?>
<div class="stats-banner">
    <div>
        <h3><i class="fas fa-handshake"></i> Active Negotiations</h3>
        <p>You have <?php echo $counts['negotiating']; ?> listing(s) waiting for your response</p>
    </div>
    <div class="badge">Action Required!</div>
</div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
    <a href="?status=all" class="tab <?php echo $status == 'all' ? 'active' : ''; ?>">
        All <span class="count"><?php echo $counts['all']; ?></span>
    </a>
    <a href="?status=active" class="tab <?php echo $status == 'active' ? 'active' : ''; ?>">
        Active <span class="count"><?php echo $counts['active']; ?></span>
    </a>
    <a href="?status=pending" class="tab <?php echo $status == 'pending' ? 'active' : ''; ?>">
        Need Payment <span class="count"><?php echo $counts['pending_payment']; ?></span>
    </a>
    <a href="?status=negotiating" class="tab <?php echo $status == 'negotiating' ? 'active' : ''; ?>">
        🤝 Negotiating <span class="count"><?php echo $counts['negotiating']; ?></span>
    </a>
    <a href="?status=waiting" class="tab <?php echo $status == 'waiting' ? 'active' : ''; ?>">
        Pending Approval <span class="count"><?php echo $counts['waiting']; ?></span>
    </a>
    <a href="?status=rejected" class="tab <?php echo $status == 'rejected' ? 'active' : ''; ?>">
        Rejected <span class="count"><?php echo $counts['rejected']; ?></span>
    </a>
</div>

<?php if (!empty($listings_rows)): ?>
    <div class="listings-grid">
        <?php foreach ($listings_rows as $listing): 
            $cover_image = $listing['cover_image'] ? '/broker_system/uploads/listings/' . $listing['cover_image'] : '';
            $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
            $has_negotiation = $listing['negotiation_id'];
            $neg_status = $listing['negotiation_status'];
            $is_waiting_for_admin = ($neg_status == 'counter_offer_sent');
            $is_awaiting_payment = ($neg_status == 'agreement_accepted');
            $can_accept = ($neg_status == 'commission_proposed');
            $can_counter = ($neg_status == 'commission_proposed');
        ?>
            <div class="listing-card">
                <div class="card-image">
                    <?php if ($cover_image && file_exists(str_replace('/broker_system/', '../', $cover_image))): ?>
                        <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>">
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">
                            <?php echo $icons[$listing['type']]; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-content">
                    <div class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                    <div class="listing-price"><?php echo formatMoney($listing['price']); ?></div>
                    <div class="listing-stats">
                        <?php
                        $status_badge = '';
                        if ($listing['approval_status'] == 'approved' && $listing['status'] == 'active') {
                            $status_badge = '<span class="badge badge-success">✓ Active</span>';
                        } elseif ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending') {
                            $status_badge = '<span class="badge badge-warning">⏳ Awaiting Payment</span>';
                        } elseif ($listing['approval_status'] == 'pending') {
                            $status_badge = '<span class="badge badge-warning">⏳ Pending Approval</span>';
                        } elseif ($listing['approval_status'] == 'rejected') {
                            $status_badge = '<span class="badge badge-danger">✗ Rejected</span>';
                        } elseif ($neg_status == 'commission_proposed') {
                            $status_badge = '<span class="badge badge-negotiating">🤝 Offer Received - Action Required!</span>';
                        } elseif ($neg_status == 'counter_offer_sent') {
                            $status_badge = '<span class="badge badge-negotiating">⏳ Waiting for Admin Response</span>';
                        } elseif ($neg_status == 'agreement_accepted') {
                            $status_badge = '<span class="badge badge-success">✓ Agreement Signed - Pay to Publish</span>';
                        } else {
                            $status_badge = '<span class="badge badge-info">' . ucfirst($listing['approval_status']) . '</span>';
                        }
                        echo $status_badge;
                        ?>
                        <span><i class="fas fa-eye"></i> <?php echo $listing['views']; ?> views</span>
                    </div>
                    
                    <!-- NEGOTIATION SECTION -->
                    <?php if ($has_negotiation && $listing['proposed_commission']): ?>
                        <div class="negotiation-box">
                            <div class="offer-row">
                                <div class="offer-item">
                                    <div class="offer-label">Proposed Commission</div>
                                    <div class="offer-value proposed"><?php echo $listing['proposed_commission']; ?>%</div>
                                </div>
                                <div class="offer-item">
                                    <div class="offer-label">Proposed Deposit</div>
                                    <div class="offer-value proposed"><?php echo formatMoney($listing['proposed_deposit']); ?></div>
                                </div>
                            </div>
                            
                            <?php if ($listing['counter_commission']): ?>
                                <div class="offer-row" style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
                                    <div class="offer-item">
                                        <div class="offer-label">Your Counter - Commission</div>
                                        <div class="offer-value counter"><?php echo $listing['counter_commission']; ?>%</div>
                                    </div>
                                    <div class="offer-item">
                                        <div class="offer-label">Your Counter - Deposit</div>
                                        <div class="offer-value counter"><?php echo formatMoney($listing['counter_deposit']); ?></div>
                                    </div>
                                </div>
                                <?php if ($listing['counter_message']): ?>
                                    <div class="counter-message">
                                        <i class="fas fa-quote-left"></i> <?php echo htmlspecialchars($listing['counter_message']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <!-- NEGOTIATION BUTTONS -->
                            <div class="btn-group">
                                <?php if ($can_accept && !$is_waiting_for_admin && !$is_awaiting_payment): ?>
                                    <form method="POST" style="display: inline; flex: 1;">
                                        <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                                        <button type="submit" name="accept_terms" class="btn btn-success" style="width: 100%;" onclick="return confirm('Accept these terms? You will need to pay the deposit to publish your listing.')">
                                            <i class="fas fa-check-circle"></i> ✅ Accept Terms
                                        </button>
                                    </form>
                                    <button onclick="openCounterModal(<?php echo $listing['negotiation_id']; ?>, <?php echo $listing['proposed_commission']; ?>, <?php echo $listing['proposed_deposit']; ?>)" class="btn btn-warning" style="flex: 1;">
                                        <i class="fas fa-exchange-alt"></i> 🔄 Counter Offer
                                    </button>
                                    <form method="POST" style="display: inline; flex: 1;">
                                        <input type="hidden" name="negotiation_id" value="<?php echo $listing['negotiation_id']; ?>">
                                        <button type="submit" name="reject_terms" class="btn btn-danger" style="width: 100%;" onclick="return confirm('Reject this listing? This will cancel the negotiation.')">
                                            <i class="fas fa-times-circle"></i> ❌ Reject
                                        </button>
                                    </form>
                                    
                                <?php elseif ($is_waiting_for_admin): ?>
                                    <button class="btn btn-outline" disabled style="width: 100%;">
                                        <i class="fas fa-hourglass-half"></i> ⏳ Waiting for Admin Response
                                    </button>
                                    
                                <?php elseif ($is_awaiting_payment): ?>
                                    <a href="pay_deposit.php?negotiation_id=<?php echo $listing['negotiation_id']; ?>" class="btn btn-success" style="flex: 1;">
                                        <i class="fas fa-credit-card"></i> 💰 Pay Deposit to Publish
                                    </a>
                                    <button class="btn btn-outline" disabled style="flex: 1;">
                                        <i class="fas fa-check-circle"></i> ✓ Terms Accepted
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Payment Required Box (for approved listings waiting for payment) -->
                    <?php if ($listing['approval_status'] == 'approved' && $listing['status'] == 'pending' && !$has_negotiation): ?>
                        <?php
                        $deposit_percent = $listing['admin_deposit_percent'] ?? 30;
                        $commission_percent = $listing['admin_commission_percent'] ?? 15;
                        $deposit_amount = $listing['price'] * ($deposit_percent / 100);
                        $commission_amount = $listing['price'] * ($commission_percent / 100);
                        $total_payment = $deposit_amount + $commission_amount;
                        ?>
                        <div class="payment-info">
                            <strong>💰 Payment Required to Activate:</strong><br>
                            Deposit (<?php echo $deposit_percent; ?>%): <?php echo formatMoney($deposit_amount); ?> + 
                            Commission (<?php echo $commission_percent; ?>%): <?php echo formatMoney($commission_amount); ?>
                            = <strong><?php echo formatMoney($total_payment); ?></strong>
                        </div>
                        <div class="btn-group">
                            <a href="pay_listing.php?listing_id=<?php echo $listing['id']; ?>" class="btn btn-success" style="flex: 1;">
                                <i class="fas fa-credit-card"></i> 💰 Pay Now
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php
                    $sp = $listing['seller_payment'] ?? null;
                    if ($sp && $sp['has_deposit_payment']): ?>
                        <div class="seller-payment-summary" id="payment-summary-<?php echo $listing['id']; ?>">
                            <strong><i class="fas fa-chart-pie"></i> Your Payment Status</strong>
                            <div class="pay-row">
                                <span>Total Price</span>
                                <span><?php echo formatMoney($sp['total_price']); ?></span>
                            </div>
                            <div class="pay-row">
                                <span>Deposit Paid</span>
                                <span><?php echo formatMoney($sp['deposit_paid']); ?></span>
                            </div>
                            <div class="pay-row remaining">
                                <span>Remaining Balance</span>
                                <span class="remaining-amount"><?php echo formatMoney($sp['remaining_balance']); ?></span>
                            </div>
                            <?php if ($sp['payment_status'] === 'fully_paid'): ?>
                                <span class="badge-fully-paid"><i class="fas fa-check-circle"></i> Fully Paid</span>
                            <?php elseif ($sp['can_pay_remaining']): ?>
                                <button type="button"
                                    class="btn btn-success pay-remaining-btn"
                                    style="width: 100%; margin-top: 12px;"
                                    data-listing-id="<?php echo $listing['id']; ?>">
                                    <i class="fas fa-wallet"></i> Pay Remaining Balance
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Regular Action Buttons (for non-negotiation listings) -->
                    <?php if (!$has_negotiation && !($listing['approval_status'] == 'approved' && $listing['status'] == 'pending')): ?>
                        <div class="btn-group">
                            <a href="product.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline" style="flex: 1;">
                                <i class="fas fa-eye"></i> 👁️ View
                            </a>
                            <a href="edit_listing.php?id=<?php echo $listing['id']; ?>" class="btn btn-outline" style="flex: 1;">
                                <i class="fas fa-edit"></i> ✏️ Edit
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-box-open"></i>
        <h3>No listings found</h3>
        <p>You haven't posted any listings yet.</p>
        <a href="post_listing.php" class="btn btn-primary" style="display: inline-block; margin-top: 16px; padding: 10px 24px;">
            <i class="fas fa-plus-circle"></i> Create Your First Listing
        </a>
    </div>
<?php endif; ?>

<!-- Counter Offer Modal -->
<div id="counterModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-exchange-alt"></i> Send Counter Offer</h3>
            <span class="close-modal" onclick="closeCounterModal()">&times;</span>
        </div>
        <form method="POST" id="counterForm">
            <input type="hidden" name="negotiation_id" id="counterNegotiationId">
            <input type="hidden" name="send_counter" value="1">
            
            <div class="form-row">
                <div class="form-group">
                    <label>Your Proposed Commission (%)</label>
                    <input type="number" name="counter_commission" id="counterCommission" step="0.5" min="1" max="20" required>
                </div>
                <div class="form-group">
                    <label>Your Proposed Deposit (ETB)</label>
                    <input type="number" name="counter_deposit" id="counterDeposit" step="100" min="0" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Message (Optional)</label>
                <textarea name="counter_message" rows="4" placeholder="Explain why you're suggesting these terms..."></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-paper-plane"></i> Send Counter Offer
            </button>
        </form>
    </div>
</div>

<script>
function openCounterModal(negotiationId, currentCommission, currentDeposit) {
    document.getElementById('counterNegotiationId').value = negotiationId;
    document.getElementById('counterCommission').value = currentCommission;
    document.getElementById('counterDeposit').value = currentDeposit;
    document.getElementById('counterModal').style.display = 'flex';
}

function closeCounterModal() {
    document.getElementById('counterModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('counterModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

document.querySelectorAll('.pay-remaining-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const listingId = this.dataset.listingId;
        if (!confirm('Are you sure you want to pay the remaining balance?')) {
            return;
        }

        const originalHtml = this.innerHTML;
        this.classList.add('loading');
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const res = await fetch('/broker_system/user/api/pay_remaining.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: parseInt(listingId, 10), action: 'initiate' })
            });
            const data = await res.json();

            if (data.success && data.pay_url) {
                window.location.href = data.pay_url;
                return;
            }

            alert(data.error || 'Could not start remaining payment');
            this.classList.remove('loading');
            this.innerHTML = originalHtml;
        } catch (err) {
            alert('Network error. Please try again.');
            this.classList.remove('loading');
            this.innerHTML = originalHtml;
        }
    });
});

<?php if (isset($_GET['fully_paid'])): ?>
(function() {
    const n = document.createElement('div');
    n.style.cssText = 'position:fixed;top:20px;right:20px;background:#10b981;color:#fff;padding:14px 20px;border-radius:12px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
    n.innerHTML = '<i class="fas fa-check-circle"></i> Remaining balance paid successfully!';
    document.body.appendChild(n);
    setTimeout(() => n.remove(), 5000);
})();
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/messages.php

<?php
// user/messages.php - User Messages Page (Redirect to Chat)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

// Redirect to the chat page since we have a full chat system
header('Location: chat.php');
exit;
?>

BRS/user/negotiate.php

<?php
// ============================================
// FILE: broker_system/user/negotiate.php
// ============================================
// Detailed Negotiation Page - FIXED

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check login FIRST
requireLogin();

$page_title = 'Negotiate Listing';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$negotiation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Get negotiation details
$stmt = $conn->prepare("
    SELECT ln.*, l.title, l.type, l.price, l.description, l.cover_image,
           l.location, l.category_id
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    WHERE ln.id = ? AND ln.seller_id = ?
");
$stmt->bind_param("ii", $negotiation_id, $user_id);
$stmt->execute();
$negotiation = $stmt->get_result()->fetch_assoc();

if (!$negotiation) {
    header('Location: negotiations.php');
    exit;
}

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    if ($post_action === 'accept_terms') {
        $update = $conn->prepare("
            UPDATE listing_negotiations 
            SET status = 'agreement_accepted', accepted_at = NOW() 
            WHERE id = ? AND seller_id = ?
        ");
        $update->bind_param("ii", $negotiation_id, $user_id);
        if ($update->execute()) {
            $message = "Terms accepted! Please pay the deposit to publish your listing.";
            // Refresh data
            $stmt2 = $conn->prepare("
                SELECT ln.*, l.title, l.type, l.price 
                FROM listing_negotiations ln
                JOIN listings l ON ln.listing_id = l.id
                WHERE ln.id = ?
            ");
            $stmt2->bind_param("i", $negotiation_id);
            $stmt2->execute();
            $negotiation = $stmt2->get_result()->fetch_assoc();
        }
    } 
    elseif ($post_action === 'send_counter') {
        $counter_commission = floatval($_POST['counter_commission'] ?? 0);
        $counter_deposit = floatval($_POST['counter_deposit'] ?? 0);
        $counter_message = $_POST['counter_message'] ?? '';
        
        if ($counter_commission > 0 && $counter_deposit > 0) {
            $update = $conn->prepare("
                UPDATE listing_negotiations 
                SET counter_commission = ?, counter_deposit = ?, 
                    counter_message = ?, status = 'counter_offer_sent' 
                WHERE id = ? AND seller_id = ?
            ");
            $update->bind_param("ddssi", $counter_commission, $counter_deposit, $counter_message, $negotiation_id, $user_id);
            if ($update->execute()) {
                $message = "Counter offer sent successfully! Waiting for admin response.";
                // Refresh data
                $stmt2 = $conn->prepare("
                    SELECT ln.*, l.title, l.type, l.price 
                    FROM listing_negotiations ln
                    JOIN listings l ON ln.listing_id = l.id
                    WHERE ln.id = ?
                ");
                $stmt2->bind_param("i", $negotiation_id);
                $stmt2->execute();
                $negotiation = $stmt2->get_result()->fetch_assoc();
            }
        } else {
            $error = "Please enter valid commission and deposit amounts.";
        }
    }
    elseif ($post_action === 'send_message') {
        $msg_text = $_POST['message'] ?? '';
        if (!empty($msg_text)) {
            $table_check = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
            if ($table_check->num_rows > 0) {
                $msg_stmt = $conn->prepare("
                    INSERT INTO negotiation_messages (negotiation_id, sender_id, sender_type, message, created_at) 
                    VALUES (?, ?, 'seller', ?, NOW())
                ");
                $msg_stmt->bind_param("iis", $negotiation_id, $user_id, $msg_text);
                $msg_stmt->execute();
                $message = "Message sent!";
            } else {
                $message = "Message sent! Admin will review your message.";
            }
        }
    }
}

// Get messages
$messages = array();
$table_check = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
if ($table_check->num_rows > 0) {
    $msg_result = $conn->query("
        SELECT nm.*, 
               CASE WHEN nm.sender_type = 'admin' THEN 'Admin' ELSE 'You' END as sender_name
        FROM negotiation_messages nm
        WHERE nm.negotiation_id = $negotiation_id
        ORDER BY nm.created_at ASC
    ");
    while($row = $msg_result->fetch_assoc()) {
        $messages[] = $row;
    }
}

$conn->close();

// Determine which display values to use
$display_commission = $negotiation['counter_commission'] ?: $negotiation['proposed_commission'];
$display_deposit = $negotiation['counter_deposit'] ?: $negotiation['proposed_deposit'];
$is_accepted = ($negotiation['status'] == 'agreement_accepted');
$is_published = ($negotiation['status'] == 'published');
$can_accept = ($negotiation['status'] == 'commission_proposed' || $negotiation['status'] == 'counter_offer_sent');
$can_counter = ($negotiation['status'] == 'commission_proposed');
?>

<style>
    .negotiate-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .page-header {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        color: white;
    }
    
    .page-header h1 {
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .listing-info {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .listing-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .listing-price {
        font-size: 24px;
        font-weight: 700;
        color: #667eea;
    }
    
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-top: 12px;
    }
    
    .offer-section {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .section-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .offer-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .offer-card {
        background: #f8fafc;
        border-radius: 16px;
        padding: 20px;
        text-align: center;
        border-left: 4px solid #667eea;
    }
    
    .offer-card.counter {
        border-left-color: #f59e0b;
    }
    
    .offer-label {
        font-size: 12px;
        color: #64748b;
        margin-bottom: 8px;
    }
    
    .offer-value {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .offer-value.proposed {
        color: #667eea;
    }
    
    .offer-value.counter {
        color: #f59e0b;
    }
    
    .counter-form {
        background: #fef3c7;
        border-radius: 16px;
        padding: 20px;
        margin-top: 20px;
    }
    
    .form-group {
        margin-bottom: 16px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        font-size: 13px;
        color: #334155;
    }
    
    .form-group input, .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 14px;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    
    .chat-section {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .chat-messages {
        height: 300px;
        overflow-y: auto;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
        background: #f8fafc;
    }
    
    .message {
        margin-bottom: 16px;
        padding: 10px 14px;
        border-radius: 12px;
        max-width: 80%;
    }
    
    .message.admin {
        background: #e0e7ff;
        margin-right: auto;
    }
    
    .message.seller {
        background: #d1fae5;
        margin-left: auto;
    }
    
    .system-message {
        background: #fef3c7;
        text-align: center;
        margin: 8px auto;
        max-width: 90%;
        font-size: 12px;
        color: #92400e;
    }
    
    .message-sender {
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .message-text {
        font-size: 13px;
    }
    
    .message-time {
        font-size: 9px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .chat-input {
        display: flex;
        gap: 12px;
    }
    
    .chat-input textarea {
        flex: 1;
        padding: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        resize: none;
        font-family: inherit;
    }
    
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
    }
    
    .btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-warning {
        background: #f59e0b;
        color: white;
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid #e2e8f0;
        color: #64748b;
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    @media (max-width: 768px) {
        .offer-grid {
            grid-template-columns: 1fr;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
    }
</style>

<div class="negotiate-container">
    <div class="page-header">
        <h1><i class="fas fa-handshake"></i> Negotiate Listing</h1>
        <p>Review terms, send counter offers, or accept the agreement</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Listing Information -->
    <div class="listing-info">
        <div class="listing-title"><?php echo htmlspecialchars($negotiation['title']); ?></div>
        <div class="listing-price"><?php echo formatMoney($negotiation['price']); ?></div>
        <?php if ($negotiation['location']): ?>
            <div style="color: #64748b; margin-top: 8px;">
                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($negotiation['location']); ?>
            </div>
        <?php endif; ?>
        <div class="status-badge" style="background: #e2e8f0; color: #475569;">
            Status: <?php echo ucfirst(str_replace('_', ' ', $negotiation['status'])); ?>
        </div>
    </div>
    
    <!-- Offer Details -->
    <div class="offer-section">
        <div class="section-title"><i class="fas fa-percent"></i> Current Offer</div>
        
        <div class="offer-grid">
            <div class="offer-card">
                <div class="offer-label">Commission Rate</div>
                <div class="offer-value proposed">
                    <?php echo $negotiation['proposed_commission'] ? $negotiation['proposed_commission'] . '%' : '—'; ?>
                </div>
                <div style="font-size: 12px; color: #64748b; margin-top: 8px;">Proposed by Admin</div>
            </div>
            <div class="offer-card">
                <div class="offer-label">Deposit Amount</div>
                <div class="offer-value proposed">
                    <?php echo $negotiation['proposed_deposit'] ? formatMoney($negotiation['proposed_deposit']) : '—'; ?>
                </div>
                <div style="font-size: 12px; color: #64748b; margin-top: 8px;">Proposed by Admin</div>
            </div>
        </div>
        
        <?php if ($negotiation['counter_commission']): ?>
        <div class="offer-grid" style="margin-top: 16px;">
            <div class="offer-card counter">
                <div class="offer-label">Your Counter Offer - Commission</div>
                <div class="offer-value counter"><?php echo $negotiation['counter_commission']; ?>%</div>
            </div>
            <div class="offer-card counter">
                <div class="offer-label">Your Counter Offer - Deposit</div>
                <div class="offer-value counter"><?php echo formatMoney($negotiation['counter_deposit']); ?></div>
            </div>
        </div>
        <?php if ($negotiation['counter_message']): ?>
            <div style="background: #fef3c7; padding: 12px; border-radius: 12px; margin-top: 12px;">
                <strong>Your Note:</strong> <?php echo htmlspecialchars($negotiation['counter_message']); ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Counter Offer Form -->
        <?php if ($can_counter && !$is_accepted && !$is_published): ?>
        <div class="counter-form">
            <h4 style="margin-bottom: 16px; font-weight: 600;">Send Counter Offer</h4>
            <form method="POST">
                <input type="hidden" name="action" value="send_counter">
                <div class="form-row">
                    <div class="form-group">
                        <label>Your Proposed Commission (%)</label>
                        <input type="number" name="counter_commission" step="0.5" min="1" max="20" required 
                               value="<?php echo $negotiation['proposed_commission']; ?>">
                    </div>
                    <div class="form-group">
                        <label>Your Proposed Deposit (ETB)</label>
                        <input type="number" name="counter_deposit" step="100" min="0" required
                               value="<?php echo $negotiation['proposed_deposit']; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Message (Optional)</label>
                    <textarea name="counter_message" rows="3" placeholder="Explain your counter offer..."></textarea>
                </div>
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-paper-plane"></i> Send Counter Offer
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <?php if ($can_accept && !$is_accepted && !$is_published): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="accept_terms">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Accept these terms? You will need to pay the deposit to publish your listing.')">
                        <i class="fas fa-check-circle"></i> Accept Terms & Proceed to Payment
                    </button>
                </form>
            <?php endif; ?>
            
            <?php if ($is_accepted && !$is_published): ?>
                <a href="pay_deposit.php?negotiation_id=<?php echo $negotiation_id; ?>" class="btn btn-success">
                    <i class="fas fa-credit-card"></i> Pay Deposit to Publish
                </a>
            <?php endif; ?>
            
            <?php if ($is_published): ?>
                <a href="product.php?id=<?php echo $negotiation['listing_id']; ?>" class="btn btn-primary">
                    <i class="fas fa-eye"></i> View Published Listing
                </a>
            <?php endif; ?>
            
            <a href="negotiations.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Negotiations
            </a>
        </div>
    </div>
    
    <!-- Chat Section -->
    <div class="chat-section">
        <div class="section-title"><i class="fas fa-comments"></i> Negotiation Chat</div>
        
        <div class="chat-messages" id="chatMessages">
            <?php if (!empty($messages)): ?>
                <?php foreach($messages as $msg): ?>
                    <?php
                    $msg_class = ($msg['sender_type'] == 'admin') ? 'admin' : 'seller';
                    ?>
                    <div class="message <?php echo $msg_class; ?>">
                        <div class="message-sender"><?php echo htmlspecialchars($msg['sender_name']); ?></div>
                        <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                        <div class="message-time"><?php echo date('M d, H:i', strtotime($msg['created_at'])); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="system-message" style="padding: 10px; text-align: center;">
                    No messages yet. Start the conversation!
                </div>
            <?php endif; ?>
        </div>
        
        <form method="POST" class="chat-input">
            <input type="hidden" name="action" value="send_message">
            <textarea name="message" rows="2" placeholder="Type your message here..." required></textarea>
            <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">
                <i class="fas fa-paper-plane"></i> Send
            </button>
        </form>
    </div>
</div>

<script>
    // Scroll chat to bottom
    var chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/negotiations.php

<?php
// ============================================
// FILE: broker_system/user/negotiations.php
// ============================================
// User Negotiations Dashboard with Buttons - FIXED

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check login FIRST
requireLogin();

$page_title = 'My Negotiations';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get all negotiations for this user
$negotiations = $conn->query("
    SELECT ln.*, l.title, l.type, l.price, l.cover_image,
           l.approval_status, l.status as listing_status
    FROM listing_negotiations ln
    JOIN listings l ON ln.listing_id = l.id
    WHERE ln.seller_id = $user_id
    ORDER BY ln.created_at DESC
");

// Calculate stats
$total = 0;
$pending = 0;
$agreed = 0;
$published = 0;
$negotiations_array = array();

if ($negotiations) {
    while($row = $negotiations->fetch_assoc()) {
        $negotiations_array[] = $row;
        $total++;
        if ($row['status'] == 'under_review' || $row['status'] == 'commission_proposed' || $row['status'] == 'counter_offer_sent') {
            $pending++;
        } elseif ($row['status'] == 'agreement_accepted') {
            $agreed++;
        } elseif ($row['status'] == 'published') {
            $published++;
        }
    }
}

$conn->close();
?>

<style>
    .negotiations-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .page-header p {
        color: #64748b;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 12px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .negotiation-card {
        background: white;
        border-radius: 20px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .negotiation-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    
    .card-header {
        padding: 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .listing-title {
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .listing-price {
        font-size: 20px;
        font-weight: 700;
        color: #667eea;
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .status-under_review { background: #fef3c7; color: #92400e; }
    .status-commission_proposed { background: #dbeafe; color: #1e40af; }
    .status-counter_offer_sent { background: #ede9fe; color: #6b21a5; }
    .status-agreement_accepted { background: #d1fae5; color: #065f46; }
    .status-published { background: #10b98120; color: #059669; }
    .status-rejected { background: #fee2e2; color: #dc2626; }
    
    .card-body {
        padding: 20px;
    }
    
    .offer-details {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 20px;
        padding: 16px;
        background: #f8fafc;
        border-radius: 12px;
    }
    
    .offer-item {
        text-align: center;
    }
    
    .offer-label {
        font-size: 11px;
        color: #64748b;
        margin-bottom: 4px;
    }
    
    .offer-value {
        font-size: 18px;
        font-weight: 700;
    }
    
    .offer-value.proposed {
        color: #667eea;
    }
    
    .offer-value.counter {
        color: #f59e0b;
    }
    
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #e2e8f0;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-warning {
        background: #f59e0b;
        color: white;
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid #e2e8f0;
        color: #64748b;
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .offer-details {
            grid-template-columns: 1fr;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
    }
</style>

<div class="negotiations-container">
    <div class="page-header">
        <h1><i class="fas fa-handshake"></i> My Negotiations</h1>
        <p>Track and manage your listing negotiations</p>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $total; ?></div>
            <div class="stat-label">Total Negotiations</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $pending; ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $agreed; ?></div>
            <div class="stat-label">Awaiting Payment</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $published; ?></div>
            <div class="stat-label">Published</div>
        </div>
    </div>
    
    <?php if (!empty($negotiations_array)): ?>
        <?php foreach($negotiations_array as $neg): 
            $status_class = 'status-' . str_replace('_', '-', $neg['status']);
        ?>
            <div class="negotiation-card">
                <div class="card-header">
                    <div>
                        <div class="listing-title"><?php echo htmlspecialchars($neg['title']); ?></div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                            <?php 
                            if ($neg['type'] == 'rental') echo '🏠 Property Rental';
                            elseif ($neg['type'] == 'product') echo '🚗 Car/Product';
                            else echo '💼 Job Listing';
                            ?>
                        </div>
                    </div>
                    <div>
                        <div class="listing-price"><?php echo formatMoney($neg['price']); ?></div>
                        <div class="status-badge <?php echo $status_class; ?>" style="margin-top: 8px;">
                            <?php 
                            $status_text = str_replace('_', ' ', $neg['status']);
                            echo ucfirst($status_text);
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Offer Details -->
                    <div class="offer-details">
                        <div class="offer-item">
                            <div class="offer-label">Proposed Commission</div>
                            <div class="offer-value proposed">
                                <?php echo $neg['proposed_commission'] ? $neg['proposed_commission'] . '%' : '—'; ?>
                            </div>
                        </div>
                        <div class="offer-item">
                            <div class="offer-label">Proposed Deposit</div>
                            <div class="offer-value proposed">
                                <?php echo $neg['proposed_deposit'] ? formatMoney($neg['proposed_deposit']) : '—'; ?>
                            </div>
                        </div>
                        <?php if ($neg['counter_commission']): ?>
                        <div class="offer-item">
                            <div class="offer-label">Your Counter Offer</div>
                            <div class="offer-value counter">
                                <?php echo $neg['counter_commission'] . '% / ' . formatMoney($neg['counter_deposit']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- ACTION BUTTONS -->
                    <div class="action-buttons">
                        <!-- View Details Button -->
                        <a href="negotiate.php?id=<?php echo $neg['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-comments"></i> View & Negotiate
                        </a>
                        
                        <!-- Accept Terms Button -->
                        <?php if ($neg['status'] == 'commission_proposed' || $neg['status'] == 'counter_offer_sent'): ?>
                            <a href="negotiate.php?id=<?php echo $neg['id']; ?>&action=accept" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> Accept Terms
                            </a>
                        <?php endif; ?>
                        
                        <!-- Counter Offer Button -->
                        <?php if ($neg['status'] == 'commission_proposed'): ?>
                            <a href="negotiate.php?id=<?php echo $neg['id']; ?>&action=counter" class="btn btn-warning">
                                <i class="fas fa-exchange-alt"></i> Send Counter Offer
                            </a>
                        <?php endif; ?>
                        
                        <!-- Pay Deposit Button -->
                        <?php if ($neg['status'] == 'agreement_accepted'): ?>
                            <a href="pay_deposit.php?negotiation_id=<?php echo $neg['id']; ?>" class="btn btn-success">
                                <i class="fas fa-credit-card"></i> Pay Deposit to Publish
                            </a>
                        <?php endif; ?>
                        
                        <!-- View Listing Button -->
                        <?php if ($neg['status'] == 'published'): ?>
                            <a href="product.php?id=<?php echo $neg['listing_id']; ?>" class="btn btn-outline">
                                <i class="fas fa-eye"></i> View Published Listing
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-handshake"></i>
            <h3>No Negotiations Yet</h3>
            <p>When you submit a listing, our team will review it and start negotiations here.</p>
            <a href="post_listing.php" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                <i class="fas fa-plus-circle"></i> Submit a Listing
            </a>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/notifications.php

<?php
// user/notifications.php - Complete Working Notifications Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Notifications';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Handle mark as read
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $notif_id = intval($_GET['mark_read']);
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $notif_id AND user_id = $user_id");
    header('Location: notifications.php');
    exit;
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    header('Location: notifications.php');
    exit;
}

// Handle delete notification
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $notif_id = intval($_GET['delete']);
    $conn->query("DELETE FROM notifications WHERE id = $notif_id AND user_id = $user_id");
    header('Location: notifications.php');
    exit;
}

// Get filter
$filter = $_GET['filter'] ?? 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query
$where = "user_id = $user_id";
if ($filter == 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter == 'read') {
    $where .= " AND is_read = 1";
}

// Get total count
$total_result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE $where");
$total = $total_result ? $total_result->fetch_assoc()['count'] : 0;
$totalPages = $total > 0 ? ceil($total / $limit) : 1;

// Get notifications
$notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE $where 
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
");

// Get counts for stats
$stats = [
    'total' => ($conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id")->fetch_assoc()['count']) ?? 0,
    'unread' => ($conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count']) ?? 0,
    'read' => ($conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 1")->fetch_assoc()['count']) ?? 0,
];

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .notifications-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    /* Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .page-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .page-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .page-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    .dashboard-link {
        position: relative;
        z-index: 1;
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 10px 20px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .dashboard-link:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 13px;
        color: var(--gray);
        margin-top: 6px;
    }
    
    /* Filters */
    .filters {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .filter-btn {
        padding: 8px 20px;
        background: white;
        border-radius: 30px;
        text-decoration: none;
        color: var(--gray);
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .filter-btn:hover, .filter-btn.active {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border-color: transparent;
    }
    
    .mark-all-btn {
        margin-left: auto;
        padding: 8px 20px;
        background: var(--success);
        color: white;
        border-radius: 30px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .mark-all-btn:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    /* Notifications List */
    .notifications-list {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .notification-item {
        display: flex;
        align-items: flex-start;
        padding: 20px;
        border-bottom: 1px solid var(--border);
        transition: all 0.3s;
        position: relative;
    }
    
    .notification-item:last-child {
        border-bottom: none;
    }
    
    .notification-item:hover {
        background: var(--light);
    }
    
    .notification-item.unread {
        background: #eef2ff;
        border-left: 4px solid var(--primary);
    }
    
    .notification-item.unread:hover {
        background: #e0e7ff;
    }
    
    .notification-icon {
        width: 48px;
        height: 48px;
        background: var(--light);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 16px;
        flex-shrink: 0;
    }
    
    .notification-icon i {
        font-size: 20px;
        color: var(--primary);
    }
    
    .notification-content {
        flex: 1;
    }
    
    .notification-title {
        font-size: 15px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .notification-message {
        font-size: 13px;
        color: var(--gray);
        margin-bottom: 6px;
        line-height: 1.5;
    }
    
    .notification-time {
        font-size: 11px;
        color: var(--gray);
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .notification-actions {
        display: flex;
        gap: 12px;
        margin-top: 8px;
    }
    
    .action-link {
        font-size: 12px;
        text-decoration: none;
        padding: 4px 12px;
        border-radius: 20px;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .action-link.mark-read {
        color: var(--primary);
        background: #e0e7ff;
    }
    
    .action-link.mark-read:hover {
        background: var(--primary);
        color: white;
    }
    
    .action-link.delete {
        color: var(--danger);
        background: #fee2e2;
    }
    
    .action-link.delete:hover {
        background: var(--danger);
        color: white;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
        border: 1px solid var(--border);
    }
    
    .empty-state-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 36px;
        color: white;
    }
    
    .empty-state h3 {
        font-size: 20px;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .empty-state p {
        color: var(--gray);
        margin-bottom: 20px;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border-radius: 10px;
        text-decoration: none;
        color: var(--dark);
        font-size: 14px;
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .pagination a:hover, .pagination .active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .filters {
            flex-direction: column;
            align-items: stretch;
        }
        .mark-all-btn {
            margin-left: 0;
            text-align: center;
            justify-content: center;
        }
        .notification-item {
            flex-direction: column;
        }
        .notification-icon {
            margin-bottom: 12px;
        }
        .notification-actions {
            flex-wrap: wrap;
        }
        .page-header {
            flex-direction: column;
            text-align: center;
        }
    }
</style>

<div class="notifications-container">
    <!-- Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-bell"></i> Notifications</h1>
            <p>Stay updated with your account activity</p>
        </div>
        <a href="dashboard.php" class="dashboard-link">
            <i class="fas fa-home"></i> Back to Dashboard
        </a>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-label">Total Notifications</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['unread']); ?></div>
            <div class="stat-label">Unread</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['read']); ?></div>
            <div class="stat-label">Read</div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filters">
        <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">All</a>
        <a href="?filter=unread" class="filter-btn <?php echo $filter == 'unread' ? 'active' : ''; ?>">Unread</a>
        <a href="?filter=read" class="filter-btn <?php echo $filter == 'read' ? 'active' : ''; ?>">Read</a>
        <?php if ($stats['unread'] > 0): ?>
            <a href="?mark_all_read=1" class="mark-all-btn" onclick="return confirm('Mark all notifications as read?')">
                <i class="fas fa-check-double"></i> Mark All as Read
            </a>
        <?php endif; ?>
    </div>
    
    <!-- Notifications List -->
    <?php if ($notifications && $notifications->num_rows > 0): ?>
        <div class="notifications-list">
            <?php while($notif = $notifications->fetch_assoc()): 
                // Determine icon based on notification type
                if (strpos($notif['title'], 'Approved') !== false) {
                    $icon = 'check-circle';
                    $icon_color = '#10b981';
                    $bg_color = '#d1fae5';
                } elseif (strpos($notif['title'], 'Rejected') !== false) {
                    $icon = 'times-circle';
                    $icon_color = '#ef4444';
                    $bg_color = '#fee2e2';
                } elseif (strpos($notif['title'], 'Payment') !== false) {
                    $icon = 'credit-card';
                    $icon_color = '#f59e0b';
                    $bg_color = '#fed7aa';
                } elseif (strpos($notif['title'], 'Legal') !== false) {
                    $icon = 'gavel';
                    $icon_color = '#8b5cf6';
                    $bg_color = '#ede9fe';
                } elseif (strpos($notif['message'], 'message') !== false) {
                    $icon = 'comment';
                    $icon_color = '#3b82f6';
                    $bg_color = '#dbeafe';
                } else {
                    $icon = 'bell';
                    $icon_color = '#667eea';
                    $bg_color = '#e0e7ff';
                }
            ?>
                <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                    <div class="notification-icon" style="background: <?php echo $bg_color; ?>;">
                        <i class="fas fa-<?php echo $icon; ?>" style="color: <?php echo $icon_color; ?>;"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                        <div class="notification-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                        <div class="notification-time">
                            <span><i class="far fa-clock"></i> <?php echo timeAgo($notif['created_at']); ?></span>
                            <div class="notification-actions">
                                <?php if (!$notif['is_read']): ?>
                                    <a href="?mark_read=1&id=<?php echo $notif['id']; ?>" class="action-link mark-read" onclick="event.stopPropagation()">
                                        <i class="fas fa-check"></i> Mark as read
                                    </a>
                                <?php endif; ?>
                                <a href="?delete=1&id=<?php echo $notif['id']; ?>" class="action-link delete" onclick="event.stopPropagation(); return confirm('Delete this notification?')">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-bell-slash"></i>
            </div>
            <h3>No notifications</h3>
            <p>You don't have any notifications at the moment.</p>
            <a href="dashboard.php" class="dashboard-link" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); display: inline-block;">
                <i class="fas fa-home"></i> Go to Dashboard
            </a>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/owner_bookings.php

<?php
// user/owner_bookings.php - Complete Owner Dashboard with All Renter Info

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

requireLogin();

$page_title = 'My Renters';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get all bookings for owner's properties with complete details
$bookings = $conn->query("
    SELECT 
        rb.*, 
        l.title as property_title, 
        l.location as property_location,
        l.price as nightly_price,
        u.full_name as tenant_name, 
        u.email as tenant_email, 
        u.phone as tenant_phone,
        u.address as tenant_address,
        u.city as tenant_city,
        t.status as transaction_status, 
        t.total_amount, 
        t.deposit_amount, 
        t.commission_amount,
        t.escrow_held,
        t.created_at as transaction_date,
        t.escrow_status,
        t.delivery_status,
        t.escrow_release_date,
        t.auto_release_days,
        t.handover_confirmed,
        t.handover_confirmed_at,
        t.buyer_confirmed_at,
        t.payment_released_at,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND status = 'confirmed' AND type = 'deposit_buyer') as buyer_deposit_paid,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND status = 'confirmed' AND type = 'commission') as commission_paid,
        (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND status = 'confirmed') as total_paid,
        p.amount as paid_amount,
        p.confirmed_at as payment_date,
        p.telebirr_code_5digit as payment_code
    FROM rental_bookings rb
    JOIN listings l ON rb.property_id = l.id
    JOIN users u ON rb.tenant_id = u.id
    JOIN transactions t ON rb.transaction_id = t.id
    LEFT JOIN payments p ON p.transaction_id = t.id AND p.status = 'confirmed'
    WHERE rb.owner_id = $user_id
    ORDER BY rb.created_at DESC
");

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'paid' => 0,
    'deposit_received' => 0,
    'total_earnings' => 0,
    'total_deposits' => 0,
    'total_commission' => 0,
    'escrow_held' => 0
];

$bookings_list = [];
if ($bookings && $bookings->num_rows > 0) {
    while ($row = $bookings->fetch_assoc()) {
        $bookings_list[] = $row;
        $stats['total']++;
        
        if ($row['status'] == 'pending') $stats['pending']++;
        if ($row['status'] == 'confirmed') $stats['confirmed']++;
        if ($row['status'] == 'completed') $stats['completed']++;
        
        $has_payment = ($row['buyer_deposit_paid'] > 0 || $row['commission_paid'] > 0);
        if ($has_payment) $stats['paid']++;
        if ($row['deposit_paid'] > 0) $stats['deposit_received']++;
        
        $stats['total_deposits'] += $row['deposit_paid'];
        $stats['total_commission'] += $row['commission_amount'];
        $stats['escrow_held'] += $row['escrow_held'];
        
        if ($row['status'] == 'completed') {
            $stats['total_earnings'] += $row['total_amount'] - $row['commission_amount'];
        }
    }
}

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --info: #3b82f6;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .renters-container {
        max-width: 1400px;
        margin: 0 auto;
    }
    
    /* Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .page-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .page-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .page-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 11px;
        color: var(--gray);
        margin-top: 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Booking Cards */
    .booking-card {
        background: white;
        border-radius: 24px;
        margin-bottom: 24px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .booking-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
    }
    
    .booking-header {
        padding: 16px 24px;
        background: var(--light);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .status-pending { background: #fed7aa; color: #ea580c; }
    .status-confirmed { background: #d1fae5; color: #059669; }
    .status-completed { background: #dbeafe; color: #1e40af; }
    .status-cancelled { background: #fee2e2; color: #dc2626; }
    
    .payment-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .payment-paid { background: #d1fae5; color: #059669; }
    .payment-pending { background: #fed7aa; color: #ea580c; }
    .payment-partial { background: #fef3c7; color: #92400e; }
    
    .booking-body {
        padding: 24px;
    }
    
    /* Property Section */
    .property-section {
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--border);
    }
    
    .property-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .property-location {
        color: var(--gray);
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    /* Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .info-card {
        background: var(--light);
        border-radius: 16px;
        padding: 16px;
    }
    
    .info-card-title {
        font-size: 12px;
        font-weight: 600;
        color: var(--gray);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-card-title i {
        color: var(--primary);
    }
    
    .info-row {
        margin-bottom: 10px;
    }
    
    .info-label {
        font-size: 11px;
        color: var(--gray);
        display: block;
    }
    
    .info-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
        margin-top: 2px;
    }
    
    .amount-large {
        font-size: 24px;
        font-weight: 700;
        color: var(--primary);
    }
    
    /* Payment Status Card */
    .payment-status-card {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 20px;
        border: 1px solid #10b981;
    }
    
    .payment-status-card h4 {
        color: #065f46;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Renter Highlight Card */
    .renter-highlight-card {
        background: linear-gradient(135deg, #e0f2fe, #bae6fd);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 20px;
        border: 2px solid #38bdf8;
    }
    
    .renter-highlight-card h4 {
        color: #0369a1;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 16px;
    }
    
    .renter-detail-row {
        display: flex;
        margin-bottom: 12px;
        padding: 8px;
        background: white;
        border-radius: 12px;
    }
    
    .renter-label {
        width: 100px;
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
    }
    
    .renter-value {
        flex: 1;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
    }
    
    /* Dates Section */
    .dates-section {
        background: linear-gradient(135deg, #667eea10, #764ba210);
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .date-item {
        text-align: center;
        flex: 1;
    }
    
    .date-label {
        font-size: 11px;
        color: var(--gray);
    }
    
    .date-value {
        font-size: 16px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .nights-count {
        background: var(--primary);
        color: white;
        padding: 8px 20px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
    }
    
    /* Escrow Info */
    .escrow-info {
        background: #dbeafe;
        border-radius: 12px;
        padding: 12px;
        margin: 12px 0;
        font-size: 12px;
    }
    
    /* Special Requests */
    .special-requests {
        background: #fef3c7;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-success {
        background: var(--success);
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--gray);
    }
    
    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    
    .btn-warning {
        background: var(--warning);
        color: white;
    }
    
    .btn-warning:hover {
        background: #d97706;
    }
    
    .btn-danger {
        background: var(--danger);
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
    }
    
    /* Alert Banner */
    .alert-banner {
        background: #fef3c7;
        border-left: 4px solid #f59e0b;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-content {
        background: white;
        border-radius: 24px;
        padding: 28px;
        width: 550px;
        max-width: 90%;
        max-height: 80vh;
        overflow-y: auto;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border);
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 24px;
        color: var(--gray);
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 24px;
        border: 1px solid var(--border);
    }
    
    .empty-state-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 36px;
        color: white;
    }
    
    .refresh-btn {
        background: white;
        border: 1px solid var(--border);
        padding: 8px 16px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 13px;
        transition: all 0.3s;
    }
    
    .refresh-btn:hover {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        .info-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .dates-section {
            flex-direction: column;
        }
        .date-item {
            width: 100%;
        }
        .booking-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            justify-content: center;
        }
        .renter-detail-row {
            flex-direction: column;
        }
        .renter-label {
            width: 100%;
            margin-bottom: 5px;
        }
    }
</style>

<div class="renters-container">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-users"></i> My Renters</h1>
            <p>Manage tenants who have booked your properties</p>
        </div>
        <div style="position: absolute; right: 32px; top: 32px;">
            <button class="refresh-btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
    
    <!-- Alert for New Bookings or Payments -->
    <?php if ($stats['pending'] > 0 || $stats['paid'] > 0): ?>
    <div class="alert-banner">
        <i class="fas fa-bell"></i>
        <div class="alert-banner-content">
            <div class="alert-banner-title">
                <?php if ($stats['pending'] > 0 && $stats['paid'] > 0): ?>
                    📢 You have <?php echo $stats['pending']; ?> pending booking(s) and <?php echo $stats['paid']; ?> new payment(s) received!
                <?php elseif ($stats['pending'] > 0): ?>
                    📢 You have <?php echo $stats['pending']; ?> new pending booking request(s)!
                <?php elseif ($stats['paid'] > 0): ?>
                    💰 You have <?php echo $stats['paid']; ?> new deposit payment(s) received!
                <?php endif; ?>
            </div>
            <div class="alert-banner-message">
                Click on any booking to view full details and take action.
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Bookings</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['deposit_received']; ?></div>
            <div class="stat-label">Deposits Received</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($stats['escrow_held']); ?></div>
            <div class="stat-label">Escrow Held</div>
        </div>
    </div>
    
    <?php if (!empty($bookings_list)): ?>
        <?php foreach($bookings_list as $booking): 
            $has_payment = ($booking['buyer_deposit_paid'] > 0 || $booking['commission_paid'] > 0);
            $payment_status = 'pending';
            if ($booking['buyer_deposit_paid'] > 0 && $booking['commission_paid'] > 0) {
                $payment_status = 'paid';
            } elseif ($booking['buyer_deposit_paid'] > 0) {
                $payment_status = 'partial';
            }
        ?>
            <div class="booking-card">
                <!-- Header with Status -->
                <div class="booking-header">
                    <div>
                        <span class="status-badge <?php 
                            echo $booking['status'] == 'pending' ? 'status-pending' : 
                                ($booking['status'] == 'confirmed' ? 'status-confirmed' : 
                                ($booking['status'] == 'completed' ? 'status-completed' : 'status-cancelled')); 
                        ?>">
                            <i class="fas <?php 
                                echo $booking['status'] == 'pending' ? 'fa-clock' : 
                                    ($booking['status'] == 'confirmed' ? 'fa-check-circle' : 
                                    ($booking['status'] == 'completed' ? 'fa-check-double' : 'fa-times-circle')); 
                            ?>"></i>
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                        
                        <span class="payment-badge payment-<?php echo $payment_status; ?>" style="margin-left: 8px;">
                            <i class="fas fa-credit-card"></i>
                            <?php if ($payment_status == 'paid'): ?>
                                💰 Deposit & Commission Paid
                            <?php elseif ($payment_status == 'partial'): ?>
                                ⏳ Deposit Paid (Commission Pending)
                            <?php else: ?>
                                ⏳ Awaiting Payment
                            <?php endif; ?>
                        </span>
                        
                        <?php if ($booking['escrow_status'] == 'active'): ?>
                            <span class="payment-badge" style="background: #dbeafe; color: #1e40af; margin-left: 8px;">
                                <i class="fas fa-shield-alt"></i> Escrow Active
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <i class="fas fa-calendar"></i> 
                        Booked: <?php echo date('M d, Y', strtotime($booking['created_at'])); ?>
                    </div>
                </div>
                
                <div class="booking-body">
                    <!-- Property Info -->
                    <div class="property-section">
                        <div class="property-title">🏠 <?php echo htmlspecialchars($booking['property_title']); ?></div>
                        <div class="property-location">
                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($booking['property_location'] ?: 'Location not specified'); ?>
                        </div>
                    </div>
                    
                    <!-- RENTER / GUEST HIGHLIGHT CARD - WHO IS BOOKING -->
                    <div class="renter-highlight-card">
                        <h4><i class="fas fa-user-circle"></i> 🧑 GUEST / RENTER INFORMATION</h4>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-user"></i> Full Name:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_name']); ?></div>
                        </div>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-envelope"></i> Email:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_email']); ?></div>
                        </div>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-phone"></i> Phone:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_phone'] ?: 'Not provided'); ?></div>
                        </div>
                        <?php if ($booking['tenant_address'] || $booking['tenant_city']): ?>
                        <div class="renter-detail-row">
                            <div class="renter-label"><i class="fas fa-map-marker-alt"></i> Address:</div>
                            <div class="renter-value"><?php echo htmlspecialchars($booking['tenant_address'] ?: ''); ?> <?php echo htmlspecialchars($booking['tenant_city'] ?: ''); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Quick Contact Buttons -->
                        <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="mailto:<?php echo $booking['tenant_email']; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 12px;">
                                <i class="fas fa-envelope"></i> Send Email
                            </a>
                            <?php if ($booking['tenant_phone']): ?>
                            <a href="tel:<?php echo $booking['tenant_phone']; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 12px;">
                                <i class="fas fa-phone"></i> Call
                            </a>
                            <?php endif; ?>
                            <a href="/broker_system/user/chat.php?user=<?php echo $booking['tenant_id']; ?>" class="btn btn-outline" style="font-size: 12px; padding: 6px 12px;">
                                <i class="fas fa-comment"></i> Chat
                            </a>
                        </div>
                    </div>
                    
                    <!-- PAYMENT INFORMATION -->
                    <?php if ($has_payment): ?>
                    <div class="payment-status-card">
                        <h4><i class="fas fa-check-circle"></i> ✅ Payment Received!</h4>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                            <div>
                                <div class="info-label">Deposit Paid</div>
                                <div class="info-value" style="color: #059669; font-size: 18px;">
                                    <?php echo formatMoney($booking['deposit_paid']); ?>
                                </div>
                            </div>
                            <div>
                                <div class="info-label">Commission Paid</div>
                                <div class="info-value" style="color: #059669; font-size: 18px;">
                                    <?php echo formatMoney($booking['commission_amount']); ?>
                                </div>
                            </div>
                            <div>
                                <div class="info-label">Total in Escrow</div>
                                <div class="info-value"><?php echo formatMoney($booking['escrow_held']); ?></div>
                            </div>
                            <?php if ($booking['payment_code']): ?>
                            <div>
                                <div class="info-label">Payment Code</div>
                                <div class="info-value"><code><?php echo $booking['payment_code']; ?></code></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($booking['payment_date']): ?>
                        <div style="margin-top: 12px; font-size: 11px; color: #065f46;">
                            <i class="fas fa-clock"></i> Payment confirmed on: <?php echo date('M d, Y H:i', strtotime($booking['payment_date'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="payment-status-card" style="background: #fef3c7; border-color: #f59e0b;">
                        <h4 style="color: #92400e;"><i class="fas fa-clock"></i> ⏳ Awaiting Payment</h4>
                        <p style="font-size: 12px; color: #92400e;">The guest has not completed payment yet. They will pay deposit + commission to secure the booking.</p>
                        <div class="info-row" style="margin-top: 8px;">
                            <span class="info-label">Deposit Required:</span>
                            <span class="info-value"><?php echo formatMoney($booking['deposit_amount']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Commission:</span>
                            <span class="info-value"><?php echo formatMoney($booking['commission_amount']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Dates Section -->
                    <div class="dates-section">
                        <div class="date-item">
                            <div class="date-label"><i class="fas fa-calendar-check"></i> Check-in</div>
                            <div class="date-value"><?php echo date('F d, Y', strtotime($booking['check_in_date'])); ?></div>
                            <div style="font-size: 12px; color: var(--gray);">
                                <?php echo date('l', strtotime($booking['check_in_date'])); ?> at 3:00 PM
                            </div>
                        </div>
                        <div class="date-item">
                            <div class="date-label"><i class="fas fa-calendar-times"></i> Check-out</div>
                            <div class="date-value"><?php echo date('F d, Y', strtotime($booking['check_out_date'])); ?></div>
                            <div style="font-size: 12px; color: var(--gray);">
                                <?php echo date('l', strtotime($booking['check_out_date'])); ?> at 11:00 AM
                            </div>
                        </div>
                        <div class="nights-count">
                            <i class="fas fa-moon"></i> <?php echo $booking['total_nights']; ?> nights
                        </div>
                    </div>
                    
                    <!-- Escrow Information -->
                    <?php if ($booking['escrow_status'] == 'active'): ?>
                    <div class="escrow-info">
                        <i class="fas fa-shield-alt"></i> <strong>🔒 Escrow Protection Active</strong><br>
                        <small>Funds are held securely in escrow. They will be released after check-out or when you confirm handover.</small>
                        <?php if ($booking['escrow_release_date']): ?>
                        <div style="margin-top: 8px;">
                            <i class="fas fa-clock"></i> Auto-release scheduled: <?php echo date('M d, Y', strtotime($booking['escrow_release_date'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Special Requests -->
                    <?php if (!empty($booking['special_requests'])): ?>
                    <div class="special-requests">
                        <strong><i class="fas fa-comment-dots"></i> 💬 Special Request from Guest:</strong>
                        <p style="margin-top: 8px; font-size: 13px;"><?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button onclick="viewRenterDetails(<?php echo htmlspecialchars(json_encode($booking)); ?>)" class="btn btn-primary">
                            <i class="fas fa-user-circle"></i> View Full Details
                        </button>
                        
                        <?php if ($booking['status'] == 'pending'): ?>
                            <button onclick="processBooking(<?php echo $booking['id']; ?>, 'approve')" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> ✅ Approve Booking
                            </button>
                            <button onclick="processBooking(<?php echo $booking['id']; ?>, 'reject')" class="btn btn-danger">
                                <i class="fas fa-times-circle"></i> ❌ Reject Booking
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($booking['status'] == 'confirmed' && $has_payment && $booking['handover_confirmed'] != 1): ?>
                            <button onclick="confirmHandover(<?php echo $booking['id']; ?>)" class="btn btn-success">
                                <i class="fas fa-key"></i> 🏠 Confirm Handover
                            </button>
                        <?php endif; ?>
                        
                        <a href="/broker_system/user/transaction.php?id=<?php echo $booking['transaction_id']; ?>" class="btn btn-outline">
                            <i class="fas fa-receipt"></i> View Transaction
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-users"></i>
            </div>
            <h3>No Renters Yet</h3>
            <p>When someone books your property, they'll appear here with all their details.</p>
            <a href="listings.php" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                <i class="fas fa-plus-circle"></i> List Your Property
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Renter Details Modal -->
<div id="renterModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-circle"></i> Complete Renter Details</h3>
            <span class="close-modal" onclick="closeRenterModal()">&times;</span>
        </div>
        <div id="renterDetailsContent"></div>
    </div>
</div>

<!-- Handover Modal -->
<div id="handoverModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Confirm Handover</h3>
            <span class="close-modal" onclick="closeHandoverModal()">&times;</span>
        </div>
        <form method="POST" action="confirm_handover.php">
            <input type="hidden" name="booking_id" id="handoverBookingId">
            <div class="form-group">
                <label>Handover Notes (Optional)</label>
                <textarea name="handover_notes" rows="3" placeholder="Add any notes about the handover..."></textarea>
            </div>
            <button type="submit" class="btn btn-success" style="width: 100%;">
                <i class="fas fa-check-circle"></i> Confirm Handover
            </button>
        </form>
    </div>
</div>

<script>
let currentBookingId = null;

function viewRenterDetails(booking) {
    const modalContent = document.getElementById('renterDetailsContent');
    const hasPayment = booking.buyer_deposit_paid > 0 || booking.commission_paid > 0;
    
    modalContent.innerHTML = `
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;"><i class="fas fa-user"></i> Personal Information</h4>
            <p><strong>Full Name:</strong> ${escapeHtml(booking.tenant_name)}</p>
            <p><strong>Email:</strong> ${escapeHtml(booking.tenant_email)}</p>
            <p><strong>Phone:</strong> ${escapeHtml(booking.tenant_phone || 'Not provided')}</p>
            <p><strong>Address:</strong> ${escapeHtml(booking.tenant_address || 'Not provided')}</p>
            <p><strong>City:</strong> ${escapeHtml(booking.tenant_city || 'Not provided')}</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;"><i class="fas fa-home"></i> Booking Information</h4>
            <p><strong>Property:</strong> ${escapeHtml(booking.property_title)}</p>
            <p><strong>Check-in:</strong> ${new Date(booking.check_in_date).toLocaleDateString()}</p>
            <p><strong>Check-out:</strong> ${new Date(booking.check_out_date).toLocaleDateString()}</p>
            <p><strong>Nights:</strong> ${booking.total_nights}</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #667eea; margin-bottom: 10px;"><i class="fas fa-money-bill-wave"></i> Payment Information</h4>
            ${hasPayment ? `
                <div style="background: #d1fae5; padding: 12px; border-radius: 12px; margin-bottom: 12px;">
                    <p><strong>✅ Payment Status:</strong> Payment Received!</p>
                    <p><strong>Deposit Paid:</strong> ${formatMoney(booking.deposit_paid)}</p>
                    <p><strong>Commission Paid:</strong> ${formatMoney(booking.commission_amount)}</p>
                    <p><strong>Total in Escrow:</strong> ${formatMoney(booking.escrow_held)}</p>
                </div>
            ` : `
                <div style="background: #fef3c7; padding: 12px; border-radius: 12px; margin-bottom: 12px;">
                    <p><strong>⚠️ Payment Status:</strong> Awaiting Payment</p>
                    <p><strong>Deposit Required:</strong> ${formatMoney(booking.deposit_amount)}</p>
                    <p><strong>Commission:</strong> ${formatMoney(booking.commission_amount)}</p>
                </div>
            `}
            <p><strong>Total Amount:</strong> ${formatMoney(booking.total_amount)}</p>
            <p><strong>You Will Receive:</strong> ${formatMoney(booking.total_amount - booking.commission_amount)}</p>
            ${booking.payment_date ? `<p><strong>Paid on:</strong> ${new Date(booking.payment_date).toLocaleString()}</p>` : ''}
        </div>
        
        ${booking.special_requests ? `
        <div style="margin-top: 20px; padding: 12px; background: #fef3c7; border-radius: 12px;">
            <strong>💬 Special Request:</strong>
            <p style="margin-top: 8px;">${escapeHtml(booking.special_requests)}</p>
        </div>
        ` : ''}
        
        <div style="margin-top: 20px; padding: 12px; background: #dbeafe; border-radius: 12px;">
            <strong>ℹ️ Important Information:</strong>
            <p style="margin-top: 8px; font-size: 12px;">
                • The deposit is held in escrow and will be released after check-out or when you confirm handover.<br>
                • You will receive the remaining payment directly from the guest.<br>
                • Contact the guest for any special arrangements.
            </p>
        </div>
        
        <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="mailto:${escapeHtml(booking.tenant_email)}" class="btn btn-primary" style="flex: 1; justify-content: center;">
                <i class="fas fa-envelope"></i> Send Email
            </a>
            <a href="tel:${escapeHtml(booking.tenant_phone)}" class="btn btn-outline" style="flex: 1; justify-content: center;">
                <i class="fas fa-phone"></i> Call
            </a>
            <button onclick="closeRenterModal()" class="btn btn-outline">Close</button>
        </div>
    `;
    document.getElementById('renterModal').style.display = 'flex';
}

function processBooking(bookingId, action) {
    if (!confirm(`Are you sure you want to ${action} this booking?`)) return;
    
    fetch('/broker_system/user/transaction_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: action === 'approve' ? 'approve_booking' : 'reject_booking',
            transaction_id: bookingId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

function confirmHandover(bookingId) {
    if (!confirm('Confirm that you have handed over the property to the guest? The deposit will be released after guest confirmation.')) return;
    
    fetch('/broker_system/user/transaction_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'confirm_handover',
            transaction_id: bookingId,
            notes: 'Property handed over to guest'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Handover confirmed! Waiting for guest confirmation to release deposit.');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

function closeRenterModal() {
    document.getElementById('renterModal').style.display = 'none';
}

function closeHandoverModal() {
    document.getElementById('handoverModal').style.display = 'none';
}

function formatMoney(amount) {
    if (!amount) return '0.00 ETB';
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount) + ' ETB';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.onclick = function(event) {
    const modal = document.getElementById('renterModal');
    const handoverModal = document.getElementById('handoverModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
    if (event.target === handoverModal) {
        handoverModal.style.display = 'none';
    }
}

// Auto-refresh every 30 seconds
setInterval(function() {
    location.reload();
}, 30000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/pay_application.php

<?php
// user/pay_application.php - Process Payment for Application

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Complete Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = sanitizeInt($_GET['transaction_id'] ?? 0);
$payment_code = sanitizeString($_GET['code'] ?? '');
$error = '';
$success = '';
$payment_status = 'pending';

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as job_title, l.seller_id as company_id, u.full_name as company_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON l.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

// Get payment code
$payment = $conn->query("
    SELECT * FROM payment_codes 
    WHERE transaction_id = $transaction_id AND code = '$payment_code' AND status = 'pending'
")->fetch_assoc();

if (!$payment) {
    // Check if already paid
    $paid_check = $conn->query("
        SELECT * FROM payments 
        WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'confirmed'
    ");
    if ($paid_check->num_rows > 0) {
        $payment_status = 'completed';
        $success = "Payment already completed! Redirecting to transaction...";
        header("Refresh: 2; URL=transaction.php?id=$transaction_id");
    } else {
        $error = "Invalid or expired payment code. Please go back and try again.";
    }
}

// Check if expired
if ($payment && strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE id = {$payment['id']}");
    $error = "Payment code has expired. Please go back and submit a new application.";
}

// Handle payment confirmation (simulated Telebirr)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $entered_code = sanitizeString($_POST['payment_code']);
    $pin = sanitizeString($_POST['pin']);
    
    if ($entered_code !== $payment_code) {
        $error = "Invalid payment code";
    } elseif ($pin !== '1234') {
        $error = "Invalid PIN. Please use 1234 for testing.";
    } else {
        $conn->begin_transaction();
        
        try {
            // Mark payment code as used
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$payment['id']}");
            
            // Record payment
            $stmt = $conn->prepare("
                INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) 
                VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW())
            ");
            $stmt->bind_param("iids", $transaction_id, $user_id, $payment['amount'], $payment_code);
            $stmt->execute();
            
            // Update escrow in transaction
            $conn->query("
                UPDATE transactions 
                SET escrow_held = escrow_held + {$payment['amount']}, 
                    status = 'awaiting_seller_deposit' 
                WHERE id = $transaction_id
            ");
            
            // Create notification for company
            $conn->query("
                INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$transaction['company_id']}, 'Application Payment Received', 
                'A candidate has paid the deposit for {$transaction['job_title']}', NOW())
            ");
            
            $conn->commit();
            
            $success = "Payment successful! Your application has been submitted.";
            $payment_status = 'completed';
            header("Refresh: 3; URL=transaction.php?id=$transaction_id");
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
        }
    }
}

$conn->close();
?>

<style>
    .payment-container { max-width: 600px; margin: 0 auto; }
    .payment-header { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 24px; padding: 28px; color: white; text-align: center; margin-bottom: 28px; }
    .payment-header h1 { font-size: 24px; margin-bottom: 8px; }
    
    .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .code-display { background: #f8fafc; border-radius: 20px; padding: 24px; text-align: center; margin-bottom: 24px; }
    .code-label { font-size: 12px; color: #64748b; margin-bottom: 8px; }
    .payment-code { font-size: 48px; font-weight: 800; letter-spacing: 8px; font-family: monospace; color: #667eea; }
    .expiry { font-size: 12px; color: #64748b; margin-top: 8px; }
    
    .form-group { margin-bottom: 20px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; }
    input { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; }
    input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    
    .btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    
    .instructions { background: #f8fafc; border-radius: 16px; padding: 20px; margin-top: 20px; }
    .step { display: flex; align-items: center; gap: 12px; padding: 10px 0; }
    .step-number { width: 28px; height: 28px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
    
    @media (max-width: 640px) {
        .payment-code { font-size: 32px; letter-spacing: 4px; }
        .card { padding: 20px; }
    }
</style>

<div class="payment-container">
    <?php if ($payment_status === 'completed'): ?>
        <div class="payment-header">
            <h1><i class="fas fa-check-circle"></i> Payment Complete</h1>
            <p>Your application has been submitted</p>
        </div>
        <div class="card" style="text-align: center;">
            <div class="alert alert-success"><?php echo $success; ?></div>
            <a href="transaction.php?id=<?php echo $transaction_id; ?>" class="btn">View Application Status →</a>
        </div>
    <?php elseif ($error): ?>
        <div class="payment-header">
            <h1><i class="fas fa-exclamation-triangle"></i> Payment Error</h1>
        </div>
        <div class="card">
            <div class="alert alert-error"><?php echo $error; ?></div>
            <a href="apply_job.php?id=<?php echo $transaction['listing_id']; ?>" class="btn">Go Back & Try Again</a>
        </div>
    <?php elseif ($payment): ?>
        <div class="payment-header">
            <h1><i class="fas fa-credit-card"></i> Complete Payment</h1>
            <p>Pay deposit + service fee to submit your application</p>
        </div>
        
        <div class="card">
            <div class="code-display">
                <div class="code-label">Your Telebirr Payment Code</div>
                <div class="payment-code"><?php echo $payment_code; ?></div>
                <div class="expiry">Expires: <?php echo date('H:i:s', strtotime($payment['expires_at'])); ?></div>
            </div>
            
            <div class="instructions">
                <h3 style="font-size: 14px; margin-bottom: 12px;"><i class="fas fa-mobile-alt"></i> How to Pay</h3>
                <div class="step"><div class="step-number">1</div><span>Open Telebirr app on your phone</span></div>
                <div class="step"><div class="step-number">2</div><span>Go to Marketplace / Payment section</span></div>
                <div class="step"><div class="step-number">3</div><span>Enter code: <strong><?php echo $payment_code; ?></strong></span></div>
                <div class="step"><div class="step-number">4</div><span>Enter your Telebirr PIN to confirm</span></div>
            </div>
            
            <form method="POST" style="margin-top: 24px;">
                <div class="form-group">
                    <label>Enter Payment Code to Confirm</label>
                    <input type="text" name="payment_code" placeholder="Enter the 5-digit code" required pattern="[0-9]{5}" maxlength="5">
                </div>
                <div class="form-group">
                    <label>Telebirr PIN (Test: 1234)</label>
                    <input type="password" name="pin" placeholder="Enter your Telebirr PIN" required maxlength="4">
                </div>
                <button type="submit" name="confirm_payment" class="btn">Confirm Payment</button>
            </form>
            
            <div class="info-text" style="margin-top: 16px; text-align: center;">
                <i class="fas fa-lock"></i> Secure payment processing by Telebirr
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Auto-refresh every 5 seconds to check payment status
    let checkInterval;
    
    function checkPaymentStatus() {
        fetch('api/check_payment_status.php?code=<?php echo $payment_code; ?>')
            .then(response => response.json())
            .then(data => {
                if (data.confirmed) {
                    clearInterval(checkInterval);
                    location.reload();
                }
            });
    }
    
    <?php if ($payment && $payment_status !== 'completed'): ?>
    checkInterval = setInterval(checkPaymentStatus, 5000);
    <?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/pay_deposit.php



BRS/user/pay_escrow.php

<?php
// user/pay_escrow.php - Complete Payment Page with Escrow (FULL FILE)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

requireLogin();

$page_title = 'Make Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$error = '';

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.price, l.admin_deposit_percent, l.admin_commission_percent,
           u.full_name as seller_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.seller_id = u.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

// Check if already paid
$existing_payment = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'confirmed'
");
$already_paid = $existing_payment->num_rows > 0;

if ($already_paid) {
    header("Location: transaction.php?id=$transaction_id");
    exit;
}

// Calculate payment amount
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;

// Get or generate payment code
$payment_code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND status = 'pending'
")->fetch_assoc();

if ($payment_code_data) {
    $payment_code = $payment_code_data['code'];
    $expires_at = $payment_code_data['expires_at'];
    $time_left = strtotime($expires_at) - time();
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $time_left = 600;
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
        VALUES (?, ?, ?, ?, 'deposit_buyer', ?, 'pending', NOW())
    ");
    $stmt->bind_param("siids", $payment_code, $transaction_id, $totalPayment, $user_id, $expires_at);
    $stmt->execute();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Escrow Payment - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        .payment-container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        
        .payment-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .payment-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            animation: moveBackground 40s linear infinite;
        }
        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(30px, 30px); }
        }
        .payment-header h1 { position: relative; z-index: 1; font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .payment-header p { position: relative; z-index: 1; opacity: 0.9; }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .item-name { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .breakdown-row.total {
            font-weight: 700;
            font-size: 18px;
            color: #667eea;
            border-top: 2px solid #e2e8f0;
            border-bottom: none;
            margin-top: 8px;
            padding-top: 16px;
        }
        
        .code-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            padding: 40px;
            text-align: center;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        .payment-code {
            font-size: 56px;
            font-weight: 800;
            letter-spacing: 16px;
            background: white;
            color: #1e293b;
            padding: 24px;
            border-radius: 20px;
            font-family: 'Courier New', monospace;
            margin: 20px 0;
            cursor: pointer;
        }
        .copy-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 10px 28px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .timer {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 24px;
            background: rgba(0,0,0,0.3);
            padding: 6px 16px;
            border-radius: 40px;
            display: inline-block;
        }
        .timer.warning { background: #fbbf24; color: #78350f; }
        .timer.danger { background: #ef4444; color: white; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
        
        .instructions {
            background: #f8fafc;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
        }
        .step-number {
            width: 32px;
            height: 32px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        
        .escrow-info {
            background: #dbeafe;
            padding: 16px;
            border-radius: 16px;
            margin: 20px 0;
        }
        
        .payment-status {
            text-align: center;
            padding: 24px;
            background: #f8fafc;
            border-radius: 20px;
        }
        .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .status-success { color: #10b981; }
        .status-success i { font-size: 48px; margin-bottom: 16px; display: block; }
        .status-error { color: #ef4444; }
        
        @media (max-width: 640px) {
            .payment-code { font-size: 28px; letter-spacing: 8px; padding: 16px; }
            .timer { font-size: 18px; }
        }
    </style>
</head>
<body>
<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-shield-alt"></i> Secure Escrow Payment</h1>
        <p>Your payment is protected until you confirm satisfaction</p>
    </div>
    
    <div class="card">
        <div class="card-title"><i class="fas fa-receipt"></i> Payment Summary</div>
        <div class="item-name"><?php echo htmlspecialchars($transaction['title']); ?></div>
        <div class="price-breakdown">
            <div class="breakdown-row">
                <span>Total Price</span>
                <span><?php echo formatMoney($transaction['total_amount']); ?></span>
            </div>
            <div class="breakdown-row">
                <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                <span><?php echo formatMoney($depositAmount); ?></span>
            </div>
            <div class="breakdown-row">
                <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                <span><?php echo formatMoney($commissionAmount); ?></span>
            </div>
            <div class="breakdown-row total">
                <span>Total to Pay Today</span>
                <span><?php echo formatMoney($totalPayment); ?></span>
            </div>
        </div>
    </div>
    
    <div class="code-box">
        <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
        <button class="copy-btn" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
        <div class="expiry" style="margin-top: 16px; color: rgba(255,255,255,0.8);">
            ⏰ Code expires in: <span class="timer" id="timer">--:--</span>
        </div>
    </div>
    
    <div class="instructions">
        <h4 style="margin-bottom: 16px;"><i class="fas fa-mobile-alt"></i> How to Pay with Telebirr</h4>
        <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your phone</div></div>
        <div class="step"><div class="step-number">2</div><div>Go to Marketplace / Pay with Code</div></div>
        <div class="step"><div class="step-number">3</div><div>Enter code: <strong><?php echo $payment_code; ?></strong></div></div>
        <div class="step"><div class="step-number">4</div><div>Confirm with PIN (Test: <strong>1234</strong>)</div></div>
    </div>
    
    <div class="escrow-info">
        <i class="fas fa-shield-alt"></i> <strong>Escrow Protection</strong><br>
        <small>Your payment is held securely in escrow. It will only be released to the seller after you confirm receipt of the item/service.</small>
    </div>
    
    <div class="payment-status" id="paymentStatus">
        <div class="spinner"></div>
        <p style="margin-top: 16px;">Waiting for payment confirmation...</p>
        <p style="font-size: 12px; color: #64748b; margin-top: 8px;">This page will auto-update once payment is confirmed</p>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const transactionId = <?php echo $transaction_id; ?>;
let pollInterval;
let timerInterval;
let timeLeft = <?php echo max(0, $time_left); ?>;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    alert('✓ Payment code copied!');
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(pollInterval);
        document.getElementById('paymentStatus').innerHTML = `
            <div style="color: #ef4444;">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Payment Code Expired. Please go back and request a new code.</p>
                <a href="transaction.php?id=${transactionId}" style="display: inline-block; margin-top: 16px; padding: 10px 20px; background: #667eea; color: white; border-radius: 40px; text-decoration: none;">Go Back</a>
            </div>
        `;
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    const timerSpan = document.getElementById('timer');
    timerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    if (timeLeft < 60) {
        timerSpan.classList.add('danger');
    } else if (timeLeft < 300) {
        timerSpan.classList.add('warning');
    }
}

function checkPaymentStatus() {
    fetch('/broker_system/api/confirm_escrow_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ payment_code: paymentCode, pin: '1234' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            clearInterval(pollInterval);
            clearInterval(timerInterval);
            document.getElementById('paymentStatus').innerHTML = `
                <div class="status-success">
                    <i class="fas fa-check-circle"></i>
                    <p><strong>Payment Confirmed!</strong></p>
                    <p>Your payment has been secured in escrow.</p>
                    <p style="margin-top: 16px;">Redirecting to transaction page...</p>
                </div>
            `;
            setTimeout(() => {
                window.location.href = `transaction.php?id=${transactionId}`;
            }, 2000);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Start timers
timerInterval = setInterval(updateTimer, 1000);
pollInterval = setInterval(checkPaymentStatus, 3000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/pay_listing.php

<?php
// user/pay_listing.php - COMPLETE PRODUCTION READY VERSION

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// CRITICAL: Set PHP timezone to Ethiopia (UTC+3)
date_default_timezone_set('Africa/Addis_Ababa');
requireLogin();

$page_title = 'Activate Listing - Payment';
ob_start();

$conn = getDbConnection();

// CRITICAL: Set MySQL timezone to match PHP
$conn->query("SET time_zone = '+03:00'");

$user_id = $_SESSION['user_id'];
$listing_id = isset($_GET['listing_id']) ? intval($_GET['listing_id']) : 0;

// Get listing details
$listing = $conn->query("
    SELECT l.*, 
           l.admin_deposit_percent as deposit_percent, 
           l.admin_commission_percent as commission_percent
    FROM listings l
    WHERE l.id = $listing_id AND l.seller_id = $user_id AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$listing) {
    header('Location: listings.php');
    exit;
}

// Calculate amounts with proper rounding
$price = round($listing['price'], 2);
$deposit_amount = round($price * ($listing['deposit_percent'] / 100), 2);
$commission_amount = round($price * ($listing['commission_percent'] / 100), 2);
$total_payment = round($deposit_amount + $commission_amount, 2);

// Check if already active
if ($listing['status'] == 'active') {
    header("Location: listings.php?msg=activated");
    exit;
}

// Get or create transaction
$transaction = $conn->query("
    SELECT id FROM transactions WHERE listing_id = $listing_id AND seller_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    $stmt = $conn->prepare("
        INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_seller_deposit', NOW())
    ");
    $remaining_after_deposit = max(0, round($price - $deposit_amount, 2));
    $stmt->bind_param("iiiiddd", $listing_id, $user_id, $user_id, $price, $deposit_amount, $commission_amount, $remaining_after_deposit);
    $stmt->execute();
    $transaction_id = $conn->insert_id;
} else {
    $transaction_id = $transaction['id'];
}

// Check for existing valid payment code
$existing_code = $conn->query("
    SELECT code, expires_at, id as code_id, 
           TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining
    FROM payment_codes 
    WHERE transaction_id = $transaction_id 
    AND user_id = $user_id 
    AND type = 'deposit_seller' 
    AND status = 'pending' 
    AND expires_at > NOW()
    LIMIT 1
");

if ($existing_code->num_rows > 0) {
    $code_data = $existing_code->fetch_assoc();
    $payment_code = $code_data['code'];
    $code_id = $code_data['code_id'];
    $seconds_remaining = max(0, intval($code_data['seconds_remaining']));
} else {
    // Generate new unique payment code
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
        VALUES (?, ?, ?, ?, 'deposit_seller', DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'pending', NOW())
    ");
    $stmt->bind_param("siid", $payment_code, $transaction_id, $total_payment, $user_id);
    $stmt->execute();
    $code_id = $conn->insert_id;
    $seconds_remaining = 600;
}

// Get expiration data from MySQL
$exp_result = $conn->query("
    SELECT 
        UNIX_TIMESTAMP(expires_at) as expires_timestamp,
        TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining
    FROM payment_codes WHERE id = $code_id
");
$exp_data = $exp_result->fetch_assoc();
$expires_timestamp = $exp_data['expires_timestamp'];
$final_seconds = max(0, intval($exp_data['seconds_remaining']));

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Activate Listing - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
        }
        
        .payment-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        /* Header */
        .payment-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 28px;
            padding: 40px;
            margin-bottom: 28px;
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .payment-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            animation: moveBackground 40s linear infinite;
        }
        
        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(30px, 30px); }
        }
        
        .payment-header h1 {
            position: relative;
            z-index: 1;
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        
        .payment-header p {
            position: relative;
            z-index: 1;
            font-size: 14px;
            opacity: 0.9;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .card-title i {
            color: #667eea;
        }
        
        /* Payment Summary */
        .item-details {
            background: #f8fafc;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .item-name {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .price-breakdown {
            margin-top: 20px;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .breakdown-row.total {
            font-weight: 800;
            font-size: 18px;
            color: #667eea;
            border-top: 2px solid #e2e8f0;
            border-bottom: none;
            margin-top: 8px;
            padding-top: 16px;
        }
        
        /* Code Box */
        .code-box {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            padding: 40px;
            text-align: center;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        
        .code-box::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        
        .payment-code {
            font-size: 56px;
            font-weight: 800;
            letter-spacing: 16px;
            background: white;
            color: #1e293b;
            padding: 24px;
            border-radius: 20px;
            font-family: 'Courier New', monospace;
            margin: 20px 0;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
        }
        
        .payment-code:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .copy-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 10px 28px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
        }
        
        .copy-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }
        
        /* Timer */
        .expiry {
            margin-top: 16px;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
            position: relative;
            z-index: 1;
        }
        
        .timer {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 24px;
            background: rgba(0,0,0,0.3);
            padding: 6px 16px;
            border-radius: 40px;
            display: inline-block;
        }
        
        .timer.warning {
            background: #fbbf24;
            color: #78350f;
        }
        
        .timer.danger {
            background: #ef4444;
            color: white;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        /* Instructions */
        .instructions {
            background: #f8fafc;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .instructions h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1e293b;
        }
        
        .step {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
        }
        
        .step-number {
            width: 32px;
            height: 32px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }
        
        /* Payment Status */
        .payment-status {
            text-align: center;
            padding: 24px;
            background: #f8fafc;
            border-radius: 20px;
        }
        
        .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .status-success {
            color: #10b981;
        }
        
        .status-success i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        
        .status-error {
            color: #ef4444;
        }
        
        .status-error i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        
        /* Loading State */
        .timer.loading {
            background: #64748b;
            font-size: 18px;
        }
        
        /* Responsive */
        @media (max-width: 640px) {
            .payment-container {
                margin: 20px auto;
            }
            .payment-header {
                padding: 24px;
            }
            .payment-header h1 {
                font-size: 24px;
            }
            .payment-code {
                font-size: 28px;
                letter-spacing: 8px;
                padding: 16px;
            }
            .card {
                padding: 20px;
            }
            .timer {
                font-size: 18px;
            }
            .breakdown-row {
                font-size: 12px;
            }
            .breakdown-row.total {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-rocket"></i> Activate Your Listing</h1>
        <p>Pay deposit + commission to start receiving bookings</p>
    </div>
    
    <div class="card">
        <div class="card-title">
            <i class="fas fa-receipt"></i> Payment Summary
        </div>
        
        <div class="item-details">
            <div class="item-name"><?php echo htmlspecialchars($listing['title']); ?></div>
            <div class="price-breakdown">
                <div class="breakdown-row">
                    <span>Listing Price</span>
                    <span><?php echo number_format($price, 2); ?> ETB</span>
                </div>
                <div class="breakdown-row">
                    <span>Deposit (<?php echo $listing['deposit_percent']; ?>%)</span>
                    <span><?php echo number_format($deposit_amount, 2); ?> ETB</span>
                </div>
                <div class="breakdown-row">
                    <span>Commission (<?php echo $listing['commission_percent']; ?>%)</span>
                    <span><?php echo number_format($commission_amount, 2); ?> ETB</span>
                </div>
                <div class="breakdown-row total">
                    <span>Total to Pay Today</span>
                    <span><?php echo number_format($total_payment, 2); ?> ETB</span>
                </div>
            </div>
        </div>
        
        <div class="code-box">
            <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()">📋 Copy Code</button>
            <div class="expiry">
                ⏰ Code expires in: <span class="timer" id="timer">--:--</span>
            </div>
        </div>
        
        <div class="instructions">
            <h4><i class="fas fa-mobile-alt"></i> How to Pay with Telebirr</h4>
            <div class="step">
                <div class="step-number">1</div>
                <div>Open Telebirr app on your phone</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div>Go to Marketplace / Pay with Code</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div>Enter this code: <strong style="font-size: 18px; color: #667eea;"><?php echo $payment_code; ?></strong></div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div>Confirm payment with your Telebirr PIN</div>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding: 16px; background: #f8fafc; border-radius: 16px; border: 1px dashed #cbd5e1;">
            <p style="font-size: 13px; font-weight: 600; margin-bottom: 10px;">
                <i class="fas fa-check-double"></i> Paid in Telebirr? Confirm here
            </p>
            <p style="font-size: 12px; color: #64748b; margin-bottom: 12px;">After paying, enter test PIN <strong>1234</strong> and click confirm.</p>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <input type="password" id="confirmPin" value="1234" maxlength="4" placeholder="PIN"
                    style="flex:1;min-width:100px;padding:10px;border:1px solid #e2e8f0;border-radius:10px;">
                <button type="button" id="confirmPayBtn" onclick="confirmPaymentManually()"
                    style="padding:10px 20px;background:#10b981;color:#fff;border:none;border-radius:40px;font-weight:600;cursor:pointer;">
                    Confirm Payment
                </button>
            </div>
            <p id="confirmPayError" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></p>
        </div>

        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 16px; font-weight: 500;">Loading payment information...</p>
        </div>
    </div>
</div>

<script>
// ============================================
// BACKEND-AUTHORITY ONLY - NO LOCAL CALCULATIONS
// ============================================

const paymentCode = '<?php echo $payment_code; ?>';
const listingId = <?php echo $listing_id; ?>;
let pollingActive = true;
let pollInterval = null;
let countdownInterval = null;
let currentSecondsRemaining = <?php echo $final_seconds; ?>;
let timerInitialized = false;
let firstPollCompleted = false;

// ============================================
// Update timer display (cosmetic only)
// ============================================
function updateTimerDisplay(seconds) {
    const timerSpan = document.getElementById('timer');
    if (!timerSpan) return;
    
    // Format as MM:SS
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    timerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    timerSpan.classList.remove('loading', 'warning', 'danger');
    
    // Update styles based on remaining time
    if (seconds < 60) {
        timerSpan.classList.add('danger');
    } else if (seconds < 300) {
        timerSpan.classList.add('warning');
    }
}

// ============================================
// Start countdown timer (only after receiving initial value)
// ============================================
function startCountdown(initialSeconds) {
    if (countdownInterval) clearInterval(countdownInterval);
    
    currentSecondsRemaining = initialSeconds;
    updateTimerDisplay(currentSecondsRemaining);
    timerInitialized = true;
    
    countdownInterval = setInterval(() => {
        if (currentSecondsRemaining > 0 && pollingActive) {
            currentSecondsRemaining--;
            updateTimerDisplay(currentSecondsRemaining);
        }
    }, 1000);
}

// ============================================
// Update UI from backend (SOURCE OF TRUTH)
// ============================================
function updateUIFromBackend(data) {
    const statusDiv = document.getElementById('paymentStatus');
    
    // Initialize timer with backend seconds_remaining (FIXES 00:00 issue)
    if (data.seconds_remaining !== undefined && data.seconds_remaining >= 0) {
        if (!timerInitialized) {
            startCountdown(data.seconds_remaining);
        } else if (Math.abs(currentSecondsRemaining - data.seconds_remaining) > 3) {
            // Sync timer with backend if significant drift occurs
            currentSecondsRemaining = data.seconds_remaining;
            updateTimerDisplay(currentSecondsRemaining);
        }
    }
    
    // Handle different backend statuses
    if (data.payment_status === 'confirmed_activated' || data.listing_active === true) {
        // Payment confirmed and listing active
        pollingActive = false;
        if (pollInterval) clearInterval(pollInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        
        statusDiv.innerHTML = `
            <div class="status-success">
                <i class="fas fa-check-circle"></i>
                <p style="font-weight: 700; font-size: 20px; margin-top: 8px;">Payment Confirmed!</p>
                <p>Your listing is now active and visible to customers.</p>
                <p style="margin-top: 8px;">Redirecting to your listings...</p>
            </div>
        `;
        
        setTimeout(() => {
            window.location.href = 'listings.php?activated=1';
        }, 1500);
        
    } else if (data.payment_status === 'confirmed_pending_activation') {
        // Payment confirmed, activating
        statusDiv.innerHTML = `
            <div class="spinner"></div>
            <p style="margin-top: 16px; font-weight: 500;">Payment Confirmed!</p>
            <p>Activating your listing...</p>
            <p style="font-size: 12px; color: #64748b; margin-top: 8px;">Please wait...</p>
        `;
        
    } else if (data.payment_status === 'expired' || data.is_expired === true) {
        // Backend says expired
        pollingActive = false;
        if (pollInterval) clearInterval(pollInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        
        statusDiv.innerHTML = `
            <div class="status-error">
                <i class="fas fa-exclamation-triangle"></i>
                <p style="margin-top: 8px; font-weight: 600;">Payment Code Expired</p>
                <p>Please refresh the page to generate a new code.</p>
                <button onclick="location.reload()" style="margin-top: 16px; padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 40px; cursor: pointer;">Refresh Page</button>
            </div>
        `;
        
    } else if (data.payment_status === 'pending') {
        // Still waiting for payment
        if (firstPollCompleted) {
            const secondsDisplay = currentSecondsRemaining;
            const minutes = Math.floor(secondsDisplay / 60);
            const secs = secondsDisplay % 60;
            statusDiv.innerHTML = `
                <div class="spinner"></div>
                <p style="margin-top: 16px; font-weight: 500;">Waiting for payment confirmation...</p>
                <p style="font-size: 12px; color: #64748b; margin-top: 8px;">
                    <i class="fas fa-clock"></i> Code valid for ${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')} more seconds
                </p>
                <p style="font-size: 11px; color: #64748b; margin-top: 12px;">
                    After paying in Telebirr, this page will update automatically within 2-3 seconds.
                </p>
            `;
        }
        firstPollCompleted = true;
    }
}

// ============================================
// Poll backend for status (ONLY SOURCE OF TRUTH)
// ============================================
async function pollBackendStatus() {
    if (!pollingActive) return;
    
    try {
        const response = await fetch(`/broker_system/api/payment_status.php?code=${paymentCode}&_=${Date.now()}`, {
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache' }
        });
        const data = await response.json();
        
        if (!data.success) {
            console.error('API error:', data.error);
            return;
        }
        
        updateUIFromBackend(data);
        
    } catch (error) {
        console.error('Polling error:', error);
    }
}

// ============================================
// Show loading state initially (NO 00:00 FLASH)
// ============================================
function showLoadingState() {
    const timerSpan = document.getElementById('timer');
    if (timerSpan) {
        timerSpan.textContent = 'Loading...';
        timerSpan.classList.add('loading');
    }
}

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    // Show temporary notification
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #10b981;
        color: white;
        padding: 12px 20px;
        border-radius: 12px;
        z-index: 1000;
        animation: slideIn 0.3s ease;
    `;
    notification.innerHTML = '<i class="fas fa-check-circle"></i> ✅ Code copied: ' + paymentCode;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 2000);
}

// ============================================
// Start automatic payment detection
// ============================================
function startPaymentDetection() {
    // Show loading state first (avoids 00:00 flash)
    showLoadingState();
    
    // Initial poll (this will set the correct timer)
    pollBackendStatus();
    
    // Poll every 1.5 seconds for fast detection
    pollInterval = setInterval(pollBackendStatus, 1500);
}

// Add CSS animation for notification
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);

async function confirmPaymentManually() {
    const btn = document.getElementById('confirmPayBtn');
    const errEl = document.getElementById('confirmPayError');
    const pin = document.getElementById('confirmPin').value.trim();
    errEl.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Confirming...';

    try {
        const res = await fetch('/broker_system/api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ payment_code: paymentCode, pin: pin })
        });
        const data = await res.json();
        if (data.success) {
            pollBackendStatus();
        } else {
            errEl.textContent = data.error || 'Confirmation failed';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = 'Confirm Payment';
        }
    } catch (e) {
        errEl.textContent = 'Network error. Try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = 'Confirm Payment';
    }
}

// Start the application
startPaymentDetection();
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/pay_remaining.php

<?php
// user/pay_remaining.php - Pay remaining listing balance (seller)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/seller_listing_payment.php';

date_default_timezone_set('Africa/Addis_Ababa');
requireLogin();

$page_title = 'Pay Remaining Balance';
ob_start();

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$user_id = (int) $_SESSION['user_id'];
$listing_id = isset($_GET['listing_id']) ? (int) $_GET['listing_id'] : 0;

$info = getSellerListingPaymentInfo($conn, $listing_id, $user_id);

if (!$info || !$info['can_pay_remaining']) {
    header('Location: listings.php');
    exit;
}

$listing = $conn->query("
    SELECT title, price FROM listings WHERE id = $listing_id AND seller_id = $user_id
")->fetch_assoc();

$transaction_id = $info['transaction_id'];
$amount = $info['remaining_balance'];

$existing_code = $conn->query("
    SELECT code, id AS code_id,
           TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
    FROM payment_codes
    WHERE transaction_id = $transaction_id
      AND user_id = $user_id
      AND type = 'remaining_balance'
      AND status = 'pending'
      AND expires_at > NOW()
    ORDER BY id DESC
    LIMIT 1
");

if ($existing_code && $existing_code->num_rows > 0) {
    $code_data = $existing_code->fetch_assoc();
    $payment_code = $code_data['code'];
    $code_id = (int) $code_data['code_id'];
    $final_seconds = max(0, (int) $code_data['seconds_remaining']);
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->prepare('SELECT id FROM payment_codes WHERE code = ? LIMIT 1');
        $code_check->bind_param('s', $payment_code);
        $code_check->execute();
        $exists = $code_check->get_result()->num_rows > 0;
        $code_check->close();
    } while ($exists);

    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
        VALUES (?, ?, ?, ?, 'remaining_balance', DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending', NOW())
    ");
    $stmt->bind_param('sidi', $payment_code, $transaction_id, $amount, $user_id);
    $stmt->execute();
    $code_id = $conn->insert_id;
    $stmt->close();
    $final_seconds = 1800;
}

$conn->close();
?>

<style>
    .payment-container { max-width: 520px; margin: 24px auto; }
    .payment-header {
        background: linear-gradient(135deg, #10b981, #059669);
        border-radius: 20px;
        padding: 28px;
        color: white;
        margin-bottom: 20px;
        text-align: center;
    }
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e2e8f0;
    }
    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 14px;
    }
    .summary-row.total { font-weight: 700; font-size: 16px; border-bottom: none; color: #059669; }
    .payment-code {
        font-size: 32px;
        font-weight: 800;
        letter-spacing: 10px;
        text-align: center;
        padding: 20px;
        background: #f0fdf4;
        border-radius: 16px;
        margin: 16px 0;
        cursor: pointer;
    }
    .timer { text-align: center; font-weight: 600; color: #64748b; }
    .payment-status { text-align: center; margin-top: 20px; }
    .spinner {
        width: 36px; height: 36px;
        border: 3px solid #e2e8f0;
        border-top-color: #10b981;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin: 0 auto;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .btn-back {
        display: inline-block;
        margin-top: 16px;
        color: #64748b;
        text-decoration: none;
    }
</style>

<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-wallet"></i> Pay Remaining Balance</h1>
        <p><?php echo htmlspecialchars($listing['title']); ?></p>
    </div>

    <div class="card">
        <h3 style="margin-bottom: 12px;"><i class="fas fa-receipt"></i> Payment Summary</h3>
        <div class="summary-row">
            <span>Total Price</span>
            <span id="summaryTotal"><?php echo formatMoney($info['total_price']); ?></span>
        </div>
        <div class="summary-row">
            <span>Deposit Paid</span>
            <span id="summaryDeposit"><?php echo formatMoney($info['deposit_paid']); ?></span>
        </div>
        <div class="summary-row total">
            <span>Remaining Balance</span>
            <span id="summaryRemaining"><?php echo formatMoney($info['remaining_balance']); ?></span>
        </div>

        <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
        <p class="timer">Code expires in: <span id="timer">--:--</span></p>

        <div style="margin-top: 16px; padding: 14px; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
            <p style="font-size: 13px; font-weight: 600; margin-bottom: 8px;">Paid in Telebirr? Confirm here</p>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <input type="password" id="confirmPin" value="1234" maxlength="4" placeholder="PIN"
                    style="flex:1;min-width:90px;padding:8px;border:1px solid #e2e8f0;border-radius:8px;">
                <button type="button" id="confirmPayBtn" onclick="confirmPaymentManually()"
                    style="padding:8px 16px;background:#10b981;color:#fff;border:none;border-radius:30px;font-weight:600;cursor:pointer;">
                    Confirm Payment
                </button>
            </div>
            <p id="confirmPayError" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></p>
        </div>

        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 12px;">Waiting for payment confirmation...</p>
        </div>

        <a href="listings.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to My Listings</a>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const listingId = <?php echo $listing_id; ?>;
let pollingActive = true;
let pollInterval = null;
let countdownInterval = null;
let currentSecondsRemaining = <?php echo (int) $final_seconds; ?>;

function updateTimerDisplay(seconds) {
    const el = document.getElementById('timer');
    if (!el) return;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    el.textContent = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function startCountdown(sec) {
    currentSecondsRemaining = sec;
    updateTimerDisplay(sec);
    if (countdownInterval) clearInterval(countdownInterval);
    countdownInterval = setInterval(() => {
        if (currentSecondsRemaining > 0 && pollingActive) {
            currentSecondsRemaining--;
            updateTimerDisplay(currentSecondsRemaining);
        }
    }, 1000);
}

function updateSummary(summary) {
    if (!summary) return;
    if (summary.total_price_formatted) document.getElementById('summaryTotal').textContent = summary.total_price_formatted;
    if (summary.deposit_paid_formatted) document.getElementById('summaryDeposit').textContent = summary.deposit_paid_formatted;
    if (summary.remaining_balance_formatted) document.getElementById('summaryRemaining').textContent = summary.remaining_balance_formatted;
}

function updateUIFromBackend(data) {
    const statusDiv = document.getElementById('paymentStatus');
    if (data.seconds_remaining !== undefined) {
        startCountdown(Math.max(0, data.seconds_remaining));
    }
    if (data.summary) updateSummary(data.summary);

    if (data.payment_status === 'fully_paid' || data.is_paid) {
        pollingActive = false;
        clearInterval(pollInterval);
        clearInterval(countdownInterval);
        statusDiv.innerHTML = `
            <div style="color:#059669;">
                <i class="fas fa-check-circle" style="font-size:48px;"></i>
                <p style="font-weight:700;font-size:18px;margin-top:8px;">Fully Paid</p>
                <p>Your listing balance has been paid in full.</p>
            </div>`;
        setTimeout(() => { window.location.href = 'listings.php?fully_paid=1'; }, 2000);
    } else if (data.payment_status === 'expired' || data.is_expired) {
        pollingActive = false;
        clearInterval(pollInterval);
        statusDiv.innerHTML = '<p style="color:#dc2626;">Code expired. <a href="pay_remaining.php?listing_id=' + listingId + '">Refresh</a></p>';
    }
}

async function pollBackendStatus() {
    if (!pollingActive) return;
    try {
        const res = await fetch(`/broker_system/api/payment_status_remaining.php?code=${paymentCode}&listing_id=${listingId}&_=${Date.now()}`, { cache: 'no-store' });
        const data = await res.json();
        if (data.success) updateUIFromBackend(data);
    } catch (e) {
        console.error(e);
    }
}

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
}

async function confirmPaymentManually() {
    const btn = document.getElementById('confirmPayBtn');
    const errEl = document.getElementById('confirmPayError');
    const pin = document.getElementById('confirmPin').value.trim();
    errEl.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Confirming...';

    try {
        const res = await fetch('/broker_system/api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ payment_code: paymentCode, pin: pin })
        });
        const data = await res.json();
        if (data.success) {
            pollBackendStatus();
        } else {
            errEl.textContent = data.error || 'Confirmation failed';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Confirm Payment';
        }
    } catch (e) {
        errEl.textContent = 'Network error';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Confirm Payment';
    }
}

startCountdown(currentSecondsRemaining);
pollBackendStatus();
pollInterval = setInterval(pollBackendStatus, 1500);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';


BRS/user/pay_rent.php

<?php
// user/pay_rent.php - Complete with Availability Reservation System

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/transaction_workflow.php';
require_once '../includes/AvailabilityManager.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create debug log file
$debug_log = __DIR__ . '/payment_debug.log';

function debug_log($message, $data = null) {
    global $debug_log;
    $log_entry = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log_entry .= " - " . print_r($data, true);
    }
    file_put_contents($debug_log, $log_entry . PHP_EOL, FILE_APPEND);
}

debug_log("========== NEW PAYMENT ATTEMPT ==========");
debug_log("Transaction ID: " . ($_GET['transaction_id'] ?? 'NOT SET'));

requireLogin();

$page_title = 'Complete Payment';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
$error = '';
$success = '';

debug_log("User ID: $user_id, Transaction ID: $transaction_id");

// Get transaction details with booking info
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.price, l.admin_deposit_percent, l.admin_commission_percent, l.id as listing_id,
           rb.id as booking_id, rb.total_months, rb.check_in_date, rb.check_out_date, rb.total_nights,
           rb.special_requests, rb.guest_name, rb.guest_phone,
           u.full_name as seller_name, u.id as seller_id, u.email as seller_email,
           buyer.full_name as buyer_name, buyer.email as buyer_email, buyer.phone as buyer_phone
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    LEFT JOIN rental_bookings rb ON rb.transaction_id = t.id
    JOIN users u ON t.seller_id = u.id
    JOIN users buyer ON t.buyer_id = buyer.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$transaction) {
    debug_log("ERROR: Transaction not found! ID: $transaction_id");
    header('Location: dashboard.php');
    exit;
}

debug_log("Transaction found: " . $transaction['title']);
debug_log("Seller ID: " . $transaction['seller_id']);
debug_log("Seller Name: " . $transaction['seller_name']);

// Calculate payment amount
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;

debug_log("Payment amounts - Deposit: $depositAmount, Commission: $commissionAmount, Total: $totalPayment");

// Safely calculate price per night
$total_nights = isset($transaction['total_nights']) && $transaction['total_nights'] > 0 ? $transaction['total_nights'] : 1;
$price_per_night = $transaction['total_amount'] / $total_nights;

// Format dates safely
$check_in_date = !empty($transaction['check_in_date']) && $transaction['check_in_date'] != '0000-00-00' 
    ? date('F d, Y', strtotime($transaction['check_in_date'])) 
    : 'Not specified';
$check_out_date = !empty($transaction['check_out_date']) && $transaction['check_out_date'] != '0000-00-00' 
    ? date('F d, Y', strtotime($transaction['check_out_date'])) 
    : 'Not specified';

$pay_remaining_mode = (isset($_GET['pay']) && $_GET['pay'] === 'remaining');
$payment_code_type = $pay_remaining_mode ? 'remaining_balance' : 'deposit_buyer';
$calc = syncTransactionPaymentState($conn, $transaction_id);

if ($pay_remaining_mode) {
    if (!$calc || $calc['remaining_balance'] <= 0) {
        header("Location: transaction.php?id=$transaction_id");
        exit;
    }
    $totalPayment = $calc['remaining_balance'];
    $page_title = 'Pay Remaining Balance';
} else {
    $fully_paid = $conn->query("
        SELECT id FROM payments
        WHERE transaction_id = $transaction_id AND type = 'deposit_buyer' AND status = 'confirmed'
        LIMIT 1
    ");
    if ($fully_paid && $fully_paid->num_rows > 0 && $calc && $calc['payment_status'] === 'fully_paid') {
        header("Location: transaction.php?id=$transaction_id");
        exit;
    }
    if ($fully_paid && $fully_paid->num_rows > 0 && $calc && $calc['remaining_balance'] > 0) {
        header("Location: pay_rent.php?transaction_id=$transaction_id&pay=remaining");
        exit;
    }
}

// Get or generate payment code
$payment_code_data = $conn->query("
    SELECT code, expires_at FROM payment_codes 
    WHERE transaction_id = $transaction_id AND user_id = $user_id AND type = '$payment_code_type' AND status = 'pending'
    ORDER BY id DESC LIMIT 1
")->fetch_assoc();

if ($payment_code_data) {
    $payment_code = $payment_code_data['code'];
    $expires_at = $payment_code_data['expires_at'];
    $time_left = strtotime($expires_at) - time();
    debug_log("Existing payment code found: $payment_code");
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    $time_left = 1800;
    
    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("siidss", $payment_code, $transaction_id, $totalPayment, $user_id, $payment_code_type, $expires_at);
    $stmt->execute();
    debug_log("Generated new payment code: $payment_code type: $payment_code_type");
}

// Handle manual payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    debug_log("POST request received - Payment confirmation attempt");
    
    $entered_code = isset($_POST['payment_code']) ? htmlspecialchars(trim($_POST['payment_code'])) : '';
    $pin = isset($_POST['pin']) ? htmlspecialchars(trim($_POST['pin'])) : '';
    
    debug_log("Entered code: $entered_code, Expected code: $payment_code");
    
    if ($entered_code !== $payment_code) {
        $error = "Invalid payment code";
        debug_log("ERROR: Invalid payment code");
    } elseif ($pin !== '1234') {
        $error = "Invalid PIN. Use 1234 for testing";
        debug_log("ERROR: Invalid PIN");
    } else {
        debug_log("PIN verified, processing payment...");
        
        $conn->begin_transaction();
        
        try {
            // 1. Mark payment code as used
            $conn->query("UPDATE payment_codes SET status = 'used', updated_at = NOW() WHERE code = '$payment_code'");
            debug_log("Payment code marked as used");
            
            // 2. Record payment
            $stmt = $conn->prepare("
                INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at, created_at) 
                VALUES (?, ?, ?, 'deposit_buyer', ?, 'confirmed', NOW(), NOW())
            ");
            $stmt->bind_param("iids", $transaction_id, $user_id, $totalPayment, $payment_code);
            $stmt->execute();
            debug_log("Payment recorded in payments table");
            
            // 3. Update escrow in transaction
            $conn->query("UPDATE transactions SET escrow_held = escrow_held + $totalPayment WHERE id = $transaction_id");
            
            // 4. Update transaction status
            $conn->query("UPDATE transactions SET status = 'escrow_active', escrow_status = 'active' WHERE id = $transaction_id");
            debug_log("Transaction status updated");
            
            // 5. Update booking status if exists
            if ($transaction['booking_id']) {
                $conn->query("
                    UPDATE rental_bookings 
                    SET status = 'confirmed', 
                        deposit_paid = $depositAmount,
                        updated_at = NOW() 
                    WHERE id = {$transaction['booking_id']}
                ");
                debug_log("Booking status updated for ID: {$transaction['booking_id']}");
            }
            
            // 6. CREATE ESCROW RECORD
            $escrow_stmt = $conn->prepare("
                INSERT INTO escrow_accounts (transaction_id, user_id, amount, type, status, created_at) 
                VALUES (?, ?, ?, 'buyer_deposit', 'held', NOW())
            ");
            $escrow_stmt->bind_param("iid", $transaction_id, $user_id, $totalPayment);
            $escrow_stmt->execute();
            debug_log("Escrow record created");
            
            // 7. Schedule auto-release
            $auto_days = 7;
            if ($transaction['type'] == 'rental') $auto_days = 14;
            if ($transaction['type'] == 'product') $auto_days = 5;
            if ($transaction['type'] == 'job') $auto_days = 10;
            
            $release_date = date('Y-m-d H:i:s', strtotime("+$auto_days days"));
            
            $conn->query("
                INSERT INTO escrow_release_queue (transaction_id, scheduled_release_date, status, created_at) 
                VALUES ($transaction_id, '$release_date', 'pending', NOW())
                ON DUPLICATE KEY UPDATE scheduled_release_date = '$release_date', status = 'pending'
            ");
            debug_log("Auto-release scheduled for: $release_date");
            
            // ============================================
            // 8. CREATE RESERVATION FOR RENTAL LISTINGS
            // ============================================
            
            $reservation_id = null;
            if ($transaction['type'] == 'rental') {
                debug_log("Creating reservation for rental listing...");
                
                $availabilityManager = new AvailabilityManager($conn);
                $reservation_result = $availabilityManager->createReservation($transaction_id, [
                    'payment_code' => $payment_code,
                    'reference' => 'DEPOSIT_' . $transaction_id
                ]);
                
                if ($reservation_result['success']) {
                    $reservation_id = $reservation_result['reservation_id'] ?? null;
                    debug_log("✅ Reservation created successfully! ID: " . ($reservation_id ?? 'N/A'));
                    
                    // Store reservation_id in session
                    $_SESSION['last_reservation_id'] = $reservation_id;
                } else {
                    debug_log("❌ WARNING: Failed to create reservation: " . ($reservation_result['error'] ?? 'Unknown error'));
                    // Don't fail the payment if reservation fails - log only
                }
            } else {
                debug_log("Not a rental listing, skipping reservation creation");
            }
            
            // ============================================
            // 9. SEND NOTIFICATION TO SELLER
            // ============================================
            
            $guest_name = $transaction['guest_name'] ?? $transaction['buyer_name'];
            $guest_phone = $transaction['guest_phone'] ?? $transaction['buyer_phone'];
            $special_requests = $transaction['special_requests'] ?? 'None';
            
            // Create notification message
            $notification_message = "💰💰 PAYMENT RECEIVED! 💰💰\n\n";
            $notification_message .= "Guest: {$transaction['buyer_name']}\n";
            $notification_message .= "Email: {$transaction['buyer_email']}\n";
            $notification_message .= "Phone: " . ($guest_phone ?: 'Not provided') . "\n";
            $notification_message .= "Property: {$transaction['title']}\n";
            $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $notification_message .= "Total Amount: " . formatMoney($transaction['total_amount']) . "\n";
            $notification_message .= "Deposit Paid (30%): " . formatMoney($depositAmount) . "\n";
            $notification_message .= "Commission: " . formatMoney($commissionAmount) . "\n";
            $notification_message .= "TOTAL PAID: " . formatMoney($totalPayment) . "\n";
            $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $notification_message .= "Check-in: $check_in_date\n";
            $notification_message .= "Check-out: $check_out_date\n";
            $notification_message .= "Nights: $total_nights\n";
            if ($special_requests !== 'None') {
                $notification_message .= "\n💬 Special Request: $special_requests\n";
            }
            $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $notification_message .= "✅ Payment is held in escrow.\n";
            $notification_message .= "📌 You will receive the remaining balance after check-out.\n";
            $notification_message .= "📱 Click to view booking details.";
            
            debug_log("Creating notification for seller ID: " . $transaction['seller_id']);
            
            // Insert notification for seller
            $notif_stmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                VALUES (?, '💰 NEW PAYMENT RECEIVED - Guest Paid 30% Deposit', ?, 'owner_bookings.php', 0, NOW())
            ");
            $notif_stmt->bind_param("is", $transaction['seller_id'], $notification_message);
            
            if ($notif_stmt->execute()) {
                debug_log("✅ SELLER NOTIFICATION SENT SUCCESSFULLY!");
                debug_log("Notification inserted for user_id: " . $transaction['seller_id']);
            } else {
                debug_log("❌ FAILED to send notification: " . $conn->error);
            }
            
            // Also send a simple notification for the bell icon
            $simple_message = "A guest has paid " . formatMoney($totalPayment) . " (30% deposit) for your property '{$transaction['title']}'. Click to view details.";
            $simple_stmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                VALUES (?, '💰 Payment Received', ?, 'owner_bookings.php', 0, NOW())
            ");
            $simple_stmt->bind_param("is", $transaction['seller_id'], $simple_message);
            $simple_stmt->execute();
            debug_log("Simple notification also sent");
            
            $conn->commit();
            debug_log("Transaction COMMITTED successfully!");
            
            $_SESSION['payment_success'] = true;
            debug_log("Redirecting to transaction.php?id=$transaction_id");
            header("Location: transaction.php?id=$transaction_id&payment_success=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
            debug_log("EXCEPTION: " . $e->getMessage());
            debug_log("Stack trace: " . $e->getTraceAsString());
        }
    }
}

$conn->close();
debug_log("Page rendering completed");
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .payment-container {
        max-width: 800px;
        margin: 0 auto;
    }
    
    .payment-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .payment-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .payment-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .payment-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    .card {
        background: white;
        border-radius: 24px;
        padding: 28px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .card-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .item-details {
        background: var(--light);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .item-name {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .booking-dates {
        background: #dbeafe;
        border-radius: 12px;
        padding: 12px;
        margin: 12px 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .date-box {
        text-align: center;
        flex: 1;
    }
    
    .date-label {
        font-size: 10px;
        color: #1e40af;
        text-transform: uppercase;
    }
    
    .date-value {
        font-size: 14px;
        font-weight: 700;
        color: #1e3a8a;
    }
    
    .price-breakdown {
        margin-top: 16px;
    }
    
    .breakdown-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    
    .breakdown-row.total {
        font-weight: 700;
        font-size: 18px;
        color: var(--primary);
        border-top: 2px solid var(--border);
        border-bottom: none;
        margin-top: 8px;
        padding-top: 16px;
    }
    
    .code-box {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 20px;
        padding: 30px;
        text-align: center;
        margin-bottom: 24px;
    }
    
    .payment-code {
        font-size: 48px;
        font-weight: 800;
        letter-spacing: 12px;
        background: white;
        color: var(--dark);
        padding: 20px;
        border-radius: 16px;
        font-family: monospace;
        margin: 16px 0;
    }
    
    .copy-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 8px 24px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .copy-btn:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .expiry {
        margin-top: 12px;
        color: rgba(255,255,255,0.7);
        font-size: 13px;
    }
    
    .timer {
        font-family: monospace;
        font-weight: 700;
    }
    
    .timer.warning {
        color: #fbbf24;
    }
    
    .timer.danger {
        color: #ef4444;
    }
    
    .instructions {
        background: var(--light);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 24px;
    }
    
    .step {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 0;
    }
    
    .step-number {
        width: 32px;
        height: 32px;
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 14px;
    }
    
    .payment-status {
        text-align: center;
        padding: 20px;
        background: var(--light);
        border-radius: 20px;
    }
    
    .spinner {
        display: inline-block;
        width: 30px;
        height: 30px;
        border: 3px solid var(--border);
        border-top-color: var(--primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .btn {
        width: 100%;
        padding: 14px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        text-align: center;
        display: inline-block;
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
    }
    
    .checkmark {
        width: 60px;
        height: 60px;
        background: var(--success);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        font-size: 32px;
        color: white;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .confirm-payment-box {
        margin: 24px 0;
        padding: 24px;
        background: linear-gradient(135deg, #ecfdf5, #d1fae5);
        border: 2px solid #10b981;
        border-radius: 20px;
        text-align: center;
    }

    .confirm-payment-box h4 {
        color: #065f46;
        font-size: 18px;
        margin-bottom: 8px;
    }

    .confirm-payment-box p {
        color: #047857;
        font-size: 13px;
        margin-bottom: 16px;
    }

    .confirm-pay-btn {
        width: 100%;
        max-width: 360px;
        padding: 16px 28px;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        border-radius: 50px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(16, 185, 129, 0.35);
        transition: transform 0.2s;
    }

    .confirm-pay-btn:hover:not(:disabled) {
        transform: translateY(-2px);
    }

    .confirm-pay-btn:disabled {
        opacity: 0.7;
        cursor: wait;
    }

    .confirm-pay-error {
        color: #dc2626;
        font-size: 13px;
        margin-top: 12px;
        display: none;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .form-group input {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--border);
        border-radius: 12px;
    }
    
    @media (max-width: 640px) {
        .payment-code {
            font-size: 28px;
            letter-spacing: 6px;
        }
        .card {
            padding: 20px;
        }
        .booking-dates {
            flex-direction: column;
        }
    }
</style>

<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-credit-card"></i> <?php echo $pay_remaining_mode ? 'Pay Remaining Balance' : 'Complete Payment'; ?></h1>
        <p><?php echo $pay_remaining_mode ? 'Pay the remaining balance to complete your purchase' : 'Pay deposit + service fee to confirm your booking'; ?></p>
    </div>
    
    <div class="card">
        <div class="card-title">
            <i class="fas fa-receipt"></i> Booking Summary
        </div>
        
        <div class="item-details">
            <div class="item-name"><?php echo htmlspecialchars($transaction['title']); ?></div>
            <span class="item-type" style="display: inline-block; padding: 4px 12px; background: var(--primary); color: white; border-radius: 20px; font-size: 11px; margin-bottom: 12px;">
                <?php 
                if ($transaction['type'] == 'rental') echo '🏠 Rental Property';
                elseif ($transaction['type'] == 'product') echo '🚗 Product';
                else echo '💼 Service';
                ?>
            </span>
            
            <?php if ($transaction['type'] == 'rental'): ?>
            <div class="booking-dates">
                <div class="date-box">
                    <div class="date-label">Check-in</div>
                    <div class="date-value"><?php echo $check_in_date; ?></div>
                </div>
                <div class="date-box">
                    <div class="date-label">Check-out</div>
                    <div class="date-value"><?php echo $check_out_date; ?></div>
                </div>
                <div class="date-box">
                    <div class="date-label">Nights</div>
                    <div class="date-value"><?php echo $total_nights; ?> nights</div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="price-breakdown">
                <div class="breakdown-row">
                    <span><?php echo ($transaction['type'] == 'rental') ? 'Total Rent' : 'Total Price'; ?></span>
                    <span><?php echo formatMoney($transaction['total_amount']); ?></span>
                </div>
                <?php if ($transaction['type'] == 'rental' && $total_nights > 0): ?>
                <div class="breakdown-row">
                    <span>Price per night</span>
                    <span><?php echo formatMoney($price_per_night); ?></span>
                </div>
                <?php endif; ?>
                <div class="breakdown-row">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <div class="breakdown-row total">
                    <span>Total to Pay</span>
                    <span><?php echo formatMoney($totalPayment); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>Remaining Balance (pay to seller)</span>
                    <span><?php echo formatMoney($transaction['total_amount'] - $depositAmount); ?></span>
                </div>
            </div>
        </div>
        
        <div class="code-box">
            <div class="code-label">Your Telebirr Payment Code</div>
            <div class="payment-code" id="paymentCode"><?php echo $payment_code; ?></div>
            <button class="copy-btn" onclick="copyCode()"><i class="fas fa-copy"></i> Copy Code</button>
            <div class="expiry">⏰ Expires in: <span id="timer"><?php echo gmdate("i:s", max(0, $time_left)); ?></span></div>
        </div>
        
        <div class="instructions">
            <h4>How to Pay with Telebirr</h4>
            <div class="step"><div class="step-number">1</div><div>Open Telebirr app on your phone</div></div>
            <div class="step"><div class="step-number">2</div><div>Go to Marketplace / Payment section</div></div>
            <div class="step"><div class="step-number">3</div><div>Enter this code: <strong><?php echo $payment_code; ?></strong></div></div>
            <div class="step"><div class="step-number">4</div><div>Confirm with PIN in Telebirr (test PIN: <strong>1234</strong>)</div></div>
            <div class="step"><div class="step-number">5</div><div><strong>Then click the green button below</strong> on this page to record your payment</div></div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="confirm-payment-box" id="confirmPaymentBox">
            <h4><i class="fas fa-check-circle"></i> Step 2: Confirm on this website</h4>
            <p>Telebirr payment alone does not update this page. After paying in the app, click below (uses test PIN <strong>1234</strong>).</p>
            <button type="button" id="confirmPayBtn" class="confirm-pay-btn" onclick="confirmPaymentManually()">
                <i class="fas fa-check-double"></i> I Have Paid — Confirm Payment
            </button>
            <p id="confirmPayError" class="confirm-pay-error"></p>
        </div>

        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 12px;">Waiting for payment confirmation...</p>
            <p style="font-size: 12px; color: var(--gray); margin-top: 8px;">This page will auto-refresh once payment is confirmed</p>
        </div>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const transactionId = <?php echo $transaction_id; ?>;
let checkInterval;
let timerInterval;
let timeLeft = <?php echo max(0, $time_left); ?>;

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
    alert('Payment code copied!');
}

function updateTimer() {
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(checkInterval);
        document.getElementById('paymentStatus').innerHTML = `
            <div style="color: red;">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Payment Code Expired. Please go back and request a new code.</p>
                <a href="transaction.php?id=${transactionId}" class="btn" style="background: #667eea; color: white; padding: 10px 20px; border-radius: 40px; text-decoration: none;">Go Back</a>
            </div>
        `;
        return;
    }
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    document.getElementById('timer').textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    if (timeLeft < 60) {
        document.getElementById('timer').classList.add('danger');
    } else if (timeLeft < 300) {
        document.getElementById('timer').classList.add('warning');
    }
}

function showPaymentSuccess() {
    clearInterval(checkInterval);
    clearInterval(timerInterval);
    document.getElementById('confirmPaymentBox').style.display = 'none';
    document.getElementById('paymentStatus').innerHTML = `
        <div class="checkmark">
            <i class="fas fa-check-circle"></i>
        </div>
        <p style="font-weight: 700; font-size: 20px; margin-top: 16px;">Payment Confirmed!</p>
        <p>Redirecting to your transaction...</p>
    `;
    setTimeout(() => {
        window.location.href = 'transaction.php?id=' + transactionId;
    }, 2000);
}

async function confirmPaymentManually() {
    const btn = document.getElementById('confirmPayBtn');
    const errEl = document.getElementById('confirmPayError');
    errEl.style.display = 'none';
    errEl.textContent = '';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Confirming...';

    try {
        const res = await fetch('/broker_system/api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ payment_code: paymentCode, pin: '1234' })
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('Server returned invalid response. Check PHP errors.');
        }
        if (data.success) {
            showPaymentSuccess();
        } else {
            errEl.textContent = data.error || 'Confirmation failed';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-double"></i> I Have Paid — Confirm Payment';
        }
    } catch (e) {
        errEl.textContent = e.message || 'Network error. Try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> I Have Paid — Confirm Payment';
    }
}

function checkPaymentStatus() {
    fetch('/broker_system/user/api/check_payment_status.php?code=' + paymentCode, { credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
            if (data.confirmed) {
                showPaymentSuccess();
            }
        })
        .catch(() => {});
}

checkInterval = setInterval(checkPaymentStatus, 3000);
timerInterval = setInterval(updateTimer, 1000);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/payment_debug.log

2026-05-10 14:07:12 - ========== NEW PAYMENT ATTEMPT ==========
2026-05-10 14:07:12 - Transaction ID: 65
2026-05-10 14:07:12 - User ID: 5, Transaction ID: 65
2026-05-10 14:07:12 - ERROR: Transaction not found! ID: 65
2026-06-01 12:15:33 - ========== NEW PAYMENT ATTEMPT ==========
2026-06-01 12:15:33 - Transaction ID: 67
2026-06-01 12:15:33 - User ID: 18, Transaction ID: 67
2026-06-01 12:15:33 - Transaction found: FNL car
2026-06-01 12:15:33 - Seller ID: 5
2026-06-01 12:15:33 - Seller Name: Mesfin Haileslassie
2026-06-01 12:15:33 - Payment amounts - Deposit: 1260000, Commission: 630000, Total: 1890000
2026-06-01 12:15:33 - Existing payment code found: 93745
2026-06-01 12:15:33 - Page rendering completed
2026-06-01 12:15:44 - ========== NEW PAYMENT ATTEMPT ==========
2026-06-01 12:15:44 - Transaction ID: 67
2026-06-01 12:15:44 - User ID: 18, Transaction ID: 67
2026-06-01 12:15:44 - Transaction found: FNL car
2026-06-01 12:15:44 - Seller ID: 5
2026-06-01 12:15:44 - Seller Name: Mesfin Haileslassie
2026-06-01 12:15:44 - Payment amounts - Deposit: 1260000, Commission: 630000, Total: 1890000
2026-06-01 12:15:44 - Existing payment code found: 93745
2026-06-01 12:15:44 - Page rendering completed
2026-06-01 18:20:17 - ========== NEW PAYMENT ATTEMPT ==========
2026-06-01 18:20:17 - Transaction ID: 73
2026-06-01 18:20:17 - User ID: 19, Transaction ID: 73
2026-06-01 18:20:17 - Transaction found: FNL car
2026-06-01 18:20:17 - Seller ID: 5
2026-06-01 18:20:17 - Seller Name: Mesfin Haileslassie
2026-06-01 18:20:17 - Payment amounts - Deposit: 1260000, Commission: 630000, Total: 1890000
2026-06-01 18:20:42 - ========== NEW PAYMENT ATTEMPT ==========
2026-06-01 18:20:42 - Transaction ID: 73
2026-06-01 18:20:42 - User ID: 19, Transaction ID: 73
2026-06-01 18:20:42 - Transaction found: FNL car
2026-06-01 18:20:42 - Seller ID: 5
2026-06-01 18:20:42 - Seller Name: Mesfin Haileslassie
2026-06-01 18:20:42 - Payment amounts - Deposit: 1260000, Commission: 630000, Total: 1890000


BRS/user/post_listing.php

<?php
// ============================================
// FILE: broker_system/user/post_listing.php
// ============================================
// Post Listing with Negotiation System - ERROR FREE

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';

requireLogin();

$page_title = 'Post New Listing';
ob_start();

$conn = getDbConnection();
$error = '';
$success = '';

// Get categories
$categories = $conn->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY type, name");

// Create uploads directory if not exists
$upload_dir = '../uploads/listings/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize inputs
    $type = isset($_POST['type']) ? trim($_POST['type']) : '';
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    
    // Rental fields
    $bedrooms = isset($_POST['bedrooms']) ? intval($_POST['bedrooms']) : 0;
    $bathrooms = isset($_POST['bathrooms']) ? intval($_POST['bathrooms']) : 0;
    $area = isset($_POST['area']) ? floatval($_POST['area']) : 0;
    
    // Car fields
    $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
    $mileage = isset($_POST['mileage']) ? intval($_POST['mileage']) : 0;
    $fuel_type = isset($_POST['fuel_type']) ? trim($_POST['fuel_type']) : '';
    $transmission = isset($_POST['transmission']) ? trim($_POST['transmission']) : '';
    
    // Job fields
    $employment_type = isset($_POST['employment_type']) ? trim($_POST['employment_type']) : '';
    $requirements = isset($_POST['requirements']) ? trim($_POST['requirements']) : '';
    
    $errors = array();
    
    // Validation
    $valid_types = array('product', 'job', 'rental');
    if (!in_array($type, $valid_types)) {
        $errors[] = "Invalid listing type selected";
    }
    
    if (empty($title)) {
        $errors[] = "Title is required";
    } elseif (strlen($title) < 3) {
        $errors[] = "Title must be at least 3 characters";
    } elseif (strlen($title) > 100) {
        $errors[] = "Title must not exceed 100 characters";
    }
    
    if (empty($description)) {
        $errors[] = "Description is required";
    } elseif (strlen($description) < 20) {
        $errors[] = "Description must be at least 20 characters";
    }
    
    if ($price <= 0) {
        $errors[] = "Please enter a valid price greater than 0";
    }
    
    // File upload
    $cover_image = '';
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadImage($_FILES['cover_image'], $upload_dir);
        if ($upload['success']) {
            $cover_image = $upload['filename'];
        } else {
            $errors[] = $upload['error'];
        }
    } else {
        $errors[] = "Cover image is required";
    }
    
    // Gallery images upload
    $gallery_images = array();
    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
        $total_files = count($_FILES['gallery_images']['name']);
        for ($i = 0; $i < min($total_files, 10); $i++) {
            if ($_FILES['gallery_images']['error'][$i] === UPLOAD_ERR_OK) {
                $file = array(
                    'name' => $_FILES['gallery_images']['name'][$i],
                    'type' => $_FILES['gallery_images']['type'][$i],
                    'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i],
                    'error' => $_FILES['gallery_images']['error'][$i],
                    'size' => $_FILES['gallery_images']['size'][$i]
                );
                $upload = uploadImage($file, $upload_dir);
                if ($upload['success']) {
                    $gallery_images[] = $upload['filename'];
                }
            }
        }
    }
    
    if (empty($errors)) {
        // Build additional details JSON
        $additional_json = null;
        if ($type == 'rental') {
            $additional_json = json_encode(array(
                'bedrooms' => $bedrooms,
                'bathrooms' => $bathrooms,
                'area' => $area
            ));
        } elseif ($type == 'product') {
            $additional_json = json_encode(array(
                'year' => $year,
                'mileage' => $mileage,
                'fuel_type' => $fuel_type,
                'transmission' => $transmission
            ));
        } elseif ($type == 'job') {
            $additional_json = json_encode(array(
                'employment_type' => $employment_type,
                'requirements' => $requirements
            ));
        }
        
        $gallery_json = !empty($gallery_images) ? json_encode($gallery_images) : null;
        $user_id = $_SESSION['user_id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert listing using prepared statement
            $stmt = $conn->prepare("
                INSERT INTO listings (
                    seller_id, type, title, description, price, category_id, location, 
                    cover_image, gallery_images, additional_details, approval_status, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())
            ");
            
            $stmt->bind_param(
                "isssdsssss", 
                $user_id, $type, $title, $description, $price, $category_id, $location,
                $cover_image, $gallery_json, $additional_json
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert listing: " . $stmt->error);
            }
            
            $listing_id = $conn->insert_id;
            
            // Check if negotiation tables exist and create negotiation
            $table_check = $conn->query("SHOW TABLES LIKE 'listing_negotiations'");
            if ($table_check->num_rows > 0) {
                $neg_stmt = $conn->prepare("
                    INSERT INTO listing_negotiations (listing_id, seller_id, status, created_at, updated_at) 
                    VALUES (?, ?, 'under_review', NOW(), NOW())
                ");
                $neg_stmt->bind_param("ii", $listing_id, $user_id);
                $neg_stmt->execute();
                $negotiation_id = $conn->insert_id;
                
                // Update listing with negotiation ID
                $update_stmt = $conn->prepare("UPDATE listings SET negotiation_id = ? WHERE id = ?");
                $update_stmt->bind_param("ii", $negotiation_id, $listing_id);
                $update_stmt->execute();
            }
            
            $conn->commit();
            
            $success = "✓ Listing submitted successfully! Our team will review your listing within 24-48 hours.";
            
            // Redirect
            echo '<meta http-equiv="refresh" content="2;url=dashboard.php">';
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to submit listing: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$conn->close();
?>

<style>
    .post-form { max-width: 900px; margin: 0 auto; }
    .card { background: white; border-radius: 28px; padding: 32px; box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1); }
    .card h1 { font-size: 28px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .card > p { color: #64748b; margin-bottom: 28px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; }
    .form-group { margin-bottom: 24px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b; font-size: 14px; }
    .required { color: #ef4444; }
    input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.3s; }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    textarea { resize: vertical; min-height: 120px; }
    .type-selector { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
    .type-option { flex: 1; min-width: 150px; padding: 20px; border: 2px solid #e2e8f0; border-radius: 16px; text-align: center; cursor: pointer; transition: all 0.3s; }
    .type-option:hover { border-color: #667eea; background: #f8fafc; }
    .type-option.selected { border-color: #667eea; background: #eef2ff; }
    .type-option i { font-size: 36px; margin-bottom: 12px; display: block; }
    .type-option strong { display: block; margin-bottom: 4px; font-size: 16px; }
    .type-option small { font-size: 11px; color: #64748b; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; margin-top: 16px; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    .error { background: #fee2e2; color: #dc2626; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #dc2626; }
    .success { background: #d1fae5; color: #059669; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #059669; }
    .info-text { font-size: 12px; color: #64748b; margin-top: 6px; }
    .dynamic-fields { display: none; }
    .dynamic-fields.active { display: block; }
    .negotiation-info {
        background: #eef2ff;
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 24px;
        border-left: 4px solid #667eea;
    }
    .negotiation-info h4 {
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .negotiation-info p {
        font-size: 12px;
        color: #475569;
        line-height: 1.5;
    }
    @media (max-width: 768px) { 
        .card { padding: 20px; } 
        .type-selector { flex-direction: column; } 
        .form-row, .form-row-3 { grid-template-columns: 1fr; } 
    }
</style>

<div class="post-form">
    <div class="card">
        <h1><i class="fas fa-plus-circle"></i> Post New Listing</h1>
        <p>Your listing will be reviewed by our team before publication</p>
        
        <div class="negotiation-info">
            <h4><i class="fas fa-handshake"></i> How It Works</h4>
            <p>
                <strong>1. Submit your listing</strong> → Our team reviews your listing (24-48 hours)<br>
                <strong>2. Receive proposal</strong> → We will propose commission and deposit terms<br>
                <strong>3. Negotiate or accept</strong> → You can counter-offer or accept the terms<br>
                <strong>4. Pay deposit</strong> → After agreement, pay the deposit to publish<br>
                <strong>5. Go live</strong> → Your listing becomes visible to buyers!
            </p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="type-selector" id="typeSelector">
                <div class="type-option" data-type="rental" onclick="selectType('rental')">
                    <i class="fas fa-home"></i>
                    <strong>House/Property</strong>
                    <small>Apartment, Condominium, Villa, Land</small>
                </div>
                <div class="type-option" data-type="product" onclick="selectType('product')">
                    <i class="fas fa-car"></i>
                    <strong>Car/Vehicle</strong>
                    <small>Sell your car</small>
                </div>
                <div class="type-option" data-type="job" onclick="selectType('job')">
                    <i class="fas fa-briefcase"></i>
                    <strong>Job Opportunity</strong>
                    <small>Hire employees</small>
                </div>
            </div>
            <input type="hidden" name="type" id="listingType" value="rental" required>
            
            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <input type="text" name="title" required placeholder="e.g., Modern 2BR Apartment for Rent, 2020 Toyota Camry">
            </div>
            
            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <select name="category_id" required>
                    <option value="">Select category</option>
                    <?php while($cat = $categories->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <!-- Property Fields -->
            <div id="propertyFields" class="dynamic-fields active">
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Bedrooms</label>
                        <input type="number" name="bedrooms" min="0" placeholder="Number of bedrooms">
                    </div>
                    <div class="form-group">
                        <label>Bathrooms</label>
                        <input type="number" name="bathrooms" min="0" placeholder="Number of bathrooms">
                    </div>
                    <div class="form-group">
                        <label>Area (sqm)</label>
                        <input type="number" name="area" min="0" placeholder="Size in sqm">
                    </div>
                </div>
            </div>
            
            <!-- Car Fields -->
            <div id="carFields" class="dynamic-fields">
                <div class="form-row">
                    <div class="form-group">
                        <label>Year</label>
                        <input type="number" name="year" placeholder="Year">
                    </div>
                    <div class="form-group">
                        <label>Mileage (km)</label>
                        <input type="number" name="mileage" placeholder="Kilometers">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Fuel Type</label>
                        <select name="fuel_type">
                            <option value="">Select</option>
                            <option value="Petrol">Petrol</option>
                            <option value="Diesel">Diesel</option>
                            <option value="Electric">Electric</option>
                            <option value="Hybrid">Hybrid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Transmission</label>
                        <select name="transmission">
                            <option value="">Select</option>
                            <option value="Manual">Manual</option>
                            <option value="Automatic">Automatic</option>
                            <option value="Semi-Automatic">Semi-Automatic</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Job Fields -->
            <div id="jobFields" class="dynamic-fields">
                <div class="form-group">
                    <label>Employment Type</label>
                    <select name="employment_type">
                        <option value="">Select</option>
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                        <option value="Contract">Contract</option>
                        <option value="Remote">Remote</option>
                        <option value="Internship">Internship</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Requirements</label>
                    <textarea name="requirements" rows="4" placeholder="List required qualifications, experience, and skills..."></textarea>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description <span class="required">*</span></label>
                <textarea name="description" required placeholder="Describe your listing in detail..."></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Price (ETB) <span class="required">*</span></label>
                    <input type="number" name="price" step="1" min="1" required placeholder="0">
                    <div class="info-text">For properties: monthly rent or sale price | For cars: selling price | For jobs: monthly salary</div>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g., Addis Ababa, Bole">
                </div>
            </div>
            
            <div class="form-group">
                <label>Cover Image <span class="required">*</span></label>
                <input type="file" name="cover_image" accept="image/*" required>
                <div class="info-text">Main image displayed in listings (max 5MB, JPG/PNG/GIF/WEBP)</div>
            </div>
            
            <div class="form-group">
                <label>Gallery Images (Optional)</label>
                <input type="file" name="gallery_images[]" accept="image/*" multiple>
                <div class="info-text">Additional images (max 5MB each, max 10 images)</div>
            </div>
            
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit for Review</button>
        </form>
        
        <div class="info-text" style="margin-top: 20px; text-align: center; background: #fef3c7; padding: 12px; border-radius: 12px;">
            <i class="fas fa-clock"></i> <strong>Note:</strong> Your listing will be reviewed within 24-48 hours.
        </div>
    </div>
</div>

<script>
    function selectType(type) {
        document.getElementById('listingType').value = type;
        
        // Update selected class
        document.querySelectorAll('.type-option').forEach(function(opt) {
            opt.classList.remove('selected');
        });
        document.querySelector('.type-option[data-type="' + type + '"]').classList.add('selected');
        
        // Hide all dynamic fields
        document.getElementById('propertyFields').classList.remove('active');
        document.getElementById('carFields').classList.remove('active');
        document.getElementById('jobFields').classList.remove('active');
        
        // Show selected type fields
        if (type === 'rental') {
            document.getElementById('propertyFields').classList.add('active');
        } else if (type === 'product') {
            document.getElementById('carFields').classList.add('active');
        } else if (type === 'job') {
            document.getElementById('jobFields').classList.add('active');
        }
    }
    
    // Initialize with rental selected
    selectType('rental');
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/product.php

<?php
// user/product.php - Complete Product Page with Availability Check

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/seller_listing_payment.php';
require_once '../includes/AvailabilityManager.php';

requireLogin();

$conn = getDbConnection();
$listing_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$availabilityManager = new AvailabilityManager($conn);

// Get listing details - only show if available
$listing = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.id as seller_id, u.email as seller_email, u.is_verified as seller_verified,
           c.name as category_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE l.id = $listing_id AND l.status = 'active' AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$listing) {
    header('Location: browse.php');
    exit;
}

// Check if listing is available for booking
$is_available_for_booking = ($listing['availability_status'] === 'available');
$unavailable_reason = '';
if (!$is_available_for_booking) {
    switch ($listing['availability_status']) {
        case 'reserved':
            $unavailable_reason = 'This property is currently reserved. Please check back later.';
            break;
        case 'rented':
            $unavailable_reason = 'This property is currently rented and unavailable.';
            break;
        case 'unavailable':
            $unavailable_reason = 'This property is temporarily unavailable.';
            break;
        default:
            $unavailable_reason = 'This property is not available for booking.';
    }
}

// Increment view count
$conn->query("UPDATE listings SET views = views + 1 WHERE id = $listing_id");

// Check if user is the seller
$is_seller = ($listing['seller_id'] == $user_id);

// Calculate payment amounts
$depositPercent = $listing['admin_deposit_percent'] ?? getSetting("deposit_percent_{$listing['type']}", 30);
$commissionPercent = $listing['admin_commission_percent'] ?? getSetting("commission_percent_{$listing['type']}", 15);
$depositAmount = $listing['price'] * ($depositPercent / 100);
$commissionAmount = $listing['price'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;
$remainingAmount = $listing['price'] - $depositAmount;

$error = '';

// Handle product purchase (for non-rental items)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase']) && !$is_seller && $listing['type'] != 'rental') {
    $buyer_id = $user_id;
    
    $existing = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id AND buyer_id = $buyer_id");
    if ($existing->num_rows > 0) {
        $txn = $existing->fetch_assoc();
        header("Location: transaction.php?id={$txn['id']}");
        exit;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW())
    ");
    $stmt->bind_param("iiiiddd", $listing_id, $buyer_id, $listing['seller_id'], $listing['price'], $depositAmount, $commissionAmount, $remainingAmount);
    
    if ($stmt->execute()) {
        $transaction_id = $conn->insert_id;
        header("Location: pay_rent.php?transaction_id=$transaction_id");
        exit;
    } else {
        $error = "Failed to create transaction. Please try again.";
    }
}

// Get gallery images
$cover_image = $listing['cover_image'] && file_exists('../uploads/listings/' . $listing['cover_image']) 
    ? '/broker_system/uploads/listings/' . $listing['cover_image'] 
    : '';
$gallery_images = $listing['gallery_images'] ? json_decode($listing['gallery_images'], true) : [];
$gallery_paths = [];
foreach ($gallery_images as $img) {
    if (file_exists('../uploads/listings/' . $img)) {
        $gallery_paths[] = '/broker_system/uploads/listings/' . $img;
    }
}

$additional = $listing['additional_details'] ? json_decode($listing['additional_details'], true) : [];

$seller_payment = null;
if ($is_seller) {
    $seller_payment = getSellerListingPaymentInfo($conn, $listing_id, $user_id);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($listing['title']); ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .header {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
        }
        
        .back-btn {
            background: var(--light);
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            color: var(--gray);
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .product-container {
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 32px;
        }
        
        .main-content {
            background: white;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .image-gallery {
            position: relative;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
        
        .main-image {
            width: 100%;
            height: 450px;
            object-fit: cover;
            display: block;
        }
        
        .thumbnail-gallery {
            display: flex;
            gap: 12px;
            padding: 16px;
            background: white;
            overflow-x: auto;
        }
        
        .thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 12px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .thumbnail:hover, .thumbnail.active {
            border-color: var(--primary);
            transform: scale(1.05);
        }
        
        .type-badge {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 30px;
            color: white;
            font-size: 13px;
            font-weight: 500;
            z-index: 10;
        }
        
        .availability-status-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 30px;
            color: white;
            font-size: 13px;
            font-weight: 500;
            z-index: 10;
        }
        
        .availability-status-badge.available { background: #10b981; }
        .availability-status-badge.reserved { background: #f59e0b; }
        .availability-status-badge.rented { background: #ef4444; }
        .availability-status-badge.unavailable { background: #64748b; }
        
        .product-info {
            padding: 28px;
        }
        
        .title {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 12px;
        }
        
        .price {
            font-size: 36px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 16px;
        }
        
        .price small {
            font-size: 14px;
            font-weight: normal;
            color: var(--gray);
        }
        
        .seller-card {
            background: var(--light);
            border-radius: 20px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .seller-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .seller-details h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .seller-details p {
            font-size: 12px;
            color: var(--gray);
        }
        
        .verified-badge {
            color: var(--success);
            margin-left: 6px;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 20px 0;
            padding: 20px;
            background: var(--light);
            border-radius: 20px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .detail-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--primary);
        }
        
        .detail-info label {
            font-size: 11px;
            color: var(--gray);
            display: block;
        }
        
        .detail-info span {
            font-size: 14px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .description {
            margin-top: 24px;
        }
        
        .description h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .description p {
            line-height: 1.7;
            color: var(--gray);
            font-size: 14px;
        }
        
        .sidebar {
            background: white;
            border-radius: 28px;
            padding: 28px;
            position: sticky;
            top: 20px;
            height: fit-content;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .sidebar-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .payment-breakdown {
            background: var(--light);
            border-radius: 20px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .breakdown-item:last-child {
            border-bottom: none;
        }
        
        .breakdown-item.total {
            font-weight: 700;
            font-size: 18px;
            color: var(--primary);
            border-top: 2px solid var(--border);
            margin-top: 8px;
            padding-top: 16px;
        }
        
        .btn-purchase {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-purchase:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102,126,234,0.4);
        }
        
        .btn-purchase:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .security-badge {
            background: #e0e7ff;
            border-radius: 16px;
            padding: 16px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: var(--primary);
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-info {
            background: #dbeafe;
            color: var(--primary);
            border-left: 4px solid var(--primary);
        }
        
        .alert-warning {
            background: #fed7aa;
            color: #9a3412;
            border-left: 4px solid #f59e0b;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 968px) {
            .product-container {
                grid-template-columns: 1fr;
            }
            .main-image {
                height: 350px;
            }
            .sidebar {
                position: static;
            }
        }
        
        @media (max-width: 640px) {
            .product-container {
                padding: 0 16px;
                margin: 20px auto;
            }
            .title {
                font-size: 22px;
            }
            .price {
                font-size: 28px;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .product-info {
                padding: 20px;
            }
            .thumbnail {
                width: 60px;
                height: 60px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">
                <i class="fas fa-store"></i> Ethio Brokerplace
            </a>
            <a href="browse.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Browse
            </a>
        </div>
    </header>
    
    <div class="product-container">
        <div class="main-content">
            <div class="image-gallery">
                <span class="type-badge">
                    <?php 
                    if ($listing['type'] == 'rental') echo '🏠 For Rent';
                    elseif ($listing['type'] == 'product') echo '🚗 For Sale';
                    else echo '💼 Job Opportunity';
                    ?>
                </span>
                <span class="availability-status-badge <?php echo $listing['availability_status']; ?>">
                    <?php 
                    if ($listing['availability_status'] == 'available') echo '✓ Available';
                    elseif ($listing['availability_status'] == 'reserved') echo '⏳ Reserved';
                    elseif ($listing['availability_status'] == 'rented') echo '🔒 Rented';
                    else echo '⚡ Unavailable';
                    ?>
                </span>
                
                <?php if ($cover_image): ?>
                    <img src="<?php echo $cover_image; ?>" class="main-image" id="mainImage">
                <?php else: ?>
                    <div class="main-image" style="display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-image" style="font-size: 80px; color: rgba(255,255,255,0.5);"></i>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($gallery_paths) || $cover_image): ?>
                <div class="thumbnail-gallery">
                    <?php if ($cover_image): ?>
                        <img src="<?php echo $cover_image; ?>" class="thumbnail active" onclick="changeImage(this.src, this)">
                    <?php endif; ?>
                    <?php foreach ($gallery_paths as $index => $img): ?>
                        <img src="<?php echo $img; ?>" class="thumbnail" onclick="changeImage(this.src, this)">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="product-info">
                <h1 class="title"><?php echo htmlspecialchars($listing['title']); ?></h1>
                <div class="price">
                    <?php echo formatMoney($listing['price']); ?>
                    <?php if ($listing['type'] == 'rental'): ?>
                        <small>/night</small>
                    <?php elseif ($listing['type'] == 'job'): ?>
                        <small>/month</small>
                    <?php endif; ?>
                </div>
                
                <div class="seller-card">
                    <div class="seller-avatar"><?php echo strtoupper(substr($listing['seller_name'], 0, 1)); ?></div>
                    <div class="seller-details">
                        <h4><?php echo htmlspecialchars($listing['seller_name']); ?></h4>
                        <p><i class="fas fa-store"></i> Member since <?php echo date('Y', strtotime($listing['created_at'] ?? 'now')); ?></p>
                    </div>
                    <div style="margin-left: auto;">
                        <a href="chat.php?user=<?php echo $listing['seller_id']; ?>" class="back-btn" style="background: var(--primary); color: white;">
                            <i class="fas fa-comment"></i> Contact
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($additional)): ?>
                <div class="details-grid">
                    <?php if ($listing['type'] == 'rental'): ?>
                        <?php if (!empty($additional['bedrooms'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-bed"></i></div>
                            <div class="detail-info"><label>Bedrooms</label><span><?php echo $additional['bedrooms']; ?></span></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['bathrooms'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-bath"></i></div>
                            <div class="detail-info"><label>Bathrooms</label><span><?php echo $additional['bathrooms']; ?></span></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['area'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-arrows-alt"></i></div>
                            <div class="detail-info"><label>Area</label><span><?php echo $additional['area']; ?> sqm</span></div>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($listing['type'] == 'product'): ?>
                        <?php if (!empty($additional['year'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-calendar"></i></div>
                            <div class="detail-info"><label>Year</label><span><?php echo $additional['year']; ?></span></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['mileage'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-tachometer-alt"></i></div>
                            <div class="detail-info"><label>Mileage</label><span><?php echo number_format($additional['mileage']); ?> km</span></div>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($listing['type'] == 'job'): ?>
                        <?php if (!empty($additional['employment_type'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-clock"></i></div>
                            <div class="detail-info"><label>Employment</label><span><?php echo $additional['employment_type']; ?></span></div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($listing['location']): ?>
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="detail-info"><label>Location</label><span><?php echo htmlspecialchars($listing['location']); ?></span></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-eye"></i></div>
                        <div class="detail-info"><label>Views</label><span><?php echo number_format($listing['views']); ?></span></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="description">
                    <h3><i class="fas fa-align-left"></i> Description</h3>
                    <p><?php echo nl2br(htmlspecialchars($listing['description'])); ?></p>
                </div>
            </div>
        </div>
        
        <div class="sidebar">
            <div class="sidebar-title">
                <i class="fas fa-tag"></i> Pricing Summary
            </div>
            
            <div class="payment-breakdown">
                <div class="breakdown-item">
                    <span><?php echo ($listing['type'] == 'rental') ? 'Price per night' : (($listing['type'] == 'job') ? 'Monthly Salary' : 'Total Price'); ?></span>
                    <span><?php echo formatMoney($listing['price']); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <div class="breakdown-item total">
                    <span>Total to Pay Now</span>
                    <span><?php echo formatMoney($totalPayment); ?></span>
                </div>
                <?php if ($listing['type'] == 'rental'): ?>
                <div class="breakdown-item">
                    <span>Remaining (pay at check-in)</span>
                    <span><?php echo formatMoney($remainingAmount); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($is_seller): ?>
                <?php if ($seller_payment && $seller_payment['has_deposit_payment']): ?>
                <div class="payment-breakdown" style="margin-bottom: 16px; border: 1px solid #bbf7d0; background: #f0fdf4;">
                    <div class="breakdown-item">
                        <span>Total Price</span>
                        <span><?php echo formatMoney($seller_payment['total_price']); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>Deposit Paid</span>
                        <span><?php echo formatMoney($seller_payment['deposit_paid']); ?></span>
                    </div>
                    <div class="breakdown-item total">
                        <span>Remaining Balance</span>
                        <span><?php echo formatMoney($seller_payment['remaining_balance']); ?></span>
                    </div>
                    <?php if ($seller_payment['payment_status'] === 'fully_paid'): ?>
                        <p style="text-align:center;color:#059669;font-weight:600;margin-top:8px;">
                            <i class="fas fa-check-circle"></i> Fully Paid
                        </p>
                    <?php elseif ($seller_payment['can_pay_remaining']): ?>
                        <button type="button" class="btn-purchase pay-remaining-btn" style="margin-top:12px;border:none;width:100%;cursor:pointer;background:#10b981;" data-listing-id="<?php echo $listing_id; ?>">
                            <i class="fas fa-wallet"></i> Pay Remaining Balance
                        </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>This is your <?php echo $listing['type']; ?>. View bookings in "My Renters".</div>
                </div>
                <?php if ($listing['type'] == 'rental'): ?>
                    <a href="owner_bookings.php" class="btn-purchase" style="background: var(--primary); text-decoration: none;">
                        <i class="fas fa-users"></i> View My Renters
                    </a>
                <?php else: ?>
                    <a href="listings.php" class="btn-purchase" style="background: var(--gray); text-decoration: none;">
                        <i class="fas fa-box"></i> Manage My Listings
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($listing['type'] == 'rental'): ?>
                    <?php if ($is_available_for_booking): ?>
                        <a href="rental_booking.php?id=<?php echo $listing['id']; ?>" class="btn-purchase">
                            <i class="fas fa-calendar-check"></i> Check Availability & Book
                        </a>
                        <p style="font-size: 11px; color: var(--gray); text-align: center; margin-top: 12px;">
                            <i class="fas fa-shield-alt"></i> Pay deposit to secure your booking
                        </p>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-ban"></i>
                            <div><?php echo $unavailable_reason; ?></div>
                        </div>
                        <button class="btn-purchase" disabled style="opacity:0.5; cursor:not-allowed;">
                            <i class="fas fa-calendar-check"></i> Not Available
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="purchase" value="1">
                        <button type="submit" class="btn-purchase">
                            <i class="fas fa-shopping-cart"></i> Purchase Now
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="security-badge">
                <i class="fas fa-lock" style="font-size: 24px;"></i>
                <div>
                    <strong>Secure Escrow Protection</strong><br>
                    <small>Your payment is protected until you confirm satisfaction</small>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function changeImage(src, element) {
            document.getElementById('mainImage').src = src;
            document.querySelectorAll('.thumbnail').forEach(thumb => {
                thumb.classList.remove('active');
            });
            element.classList.add('active');
        }
        
        document.querySelectorAll('img').forEach(img => {
            img.onerror = function() {
                this.style.display = 'none';
            };
        });

        document.querySelectorAll('.pay-remaining-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const listingId = this.dataset.listingId;
                if (!confirm('Are you sure you want to pay the remaining balance?')) return;
                const original = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                try {
                    const res = await fetch('/broker_system/user/api/pay_remaining.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ listing_id: parseInt(listingId, 10), action: 'initiate' })
                    });
                    const data = await res.json();
                    if (data.success && data.pay_url) {
                        window.location.href = data.pay_url;
                    } else {
                        alert(data.error || 'Could not start payment');
                        this.disabled = false;
                        this.innerHTML = original;
                    }
                } catch (e) {
                    alert('Network error');
                    this.disabled = false;
                    this.innerHTML = original;
                }
            });
        });
    </script>
</body>
</html>

BRS/user/profile.php

<?php
// user/profile.php - Works with any column configuration

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'My Profile';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get existing columns
$columns_result = $conn->query("SHOW COLUMNS FROM users");
$existing_columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}

// Get user data - only select columns that exist
$select_fields = ['id'];
$available_fields = ['full_name', 'email', 'phone', 'role', 'balance', 'is_verified', 'is_suspended', 'address', 'city', 'bio', 'avatar', 'created_at', 'updated_at', 'last_login'];

foreach ($available_fields as $field) {
    if (in_array($field, $existing_columns)) {
        $select_fields[] = $field;
    }
}

$select_sql = "SELECT " . implode(", ", $select_fields) . " FROM users WHERE id = $user_id";
$user = $conn->query($select_sql)->fetch_assoc();

// Get statistics
$stats = [
    'listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id")->fetch_assoc()['count'] ?? 0,
    'active_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'] ?? 0,
    'transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE buyer_id = $user_id OR seller_id = $user_id")->fetch_assoc()['count'] ?? 0,
    'completed_deals' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE (buyer_id = $user_id OR seller_id = $user_id) AND status = 'completed'")->fetch_assoc()['count'] ?? 0,
];

$message = '';
$error = '';

// Handle profile update - only update columns that exist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $update_fields = [];
    $update_values = [];
    $types = "";
    
    if (in_array('full_name', $existing_columns)) {
        $full_name = trim($_POST['full_name'] ?? '');
        if (empty($full_name)) {
            $error = "Full name is required";
        } else {
            $update_fields[] = "full_name = ?";
            $update_values[] = $full_name;
            $types .= "s";
            $_SESSION['user_name'] = $full_name;
        }
    }
    
    if (in_array('phone', $existing_columns) && !$error) {
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
        $update_fields[] = "phone = ?";
        $update_values[] = $phone;
        $types .= "s";
    }
    
    if (in_array('address', $existing_columns) && !$error) {
        $address = trim($_POST['address'] ?? '');
        $update_fields[] = "address = ?";
        $update_values[] = $address;
        $types .= "s";
    }
    
    if (in_array('city', $existing_columns) && !$error) {
        $city = trim($_POST['city'] ?? '');
        $update_fields[] = "city = ?";
        $update_values[] = $city;
        $types .= "s";
    }
    
    if (in_array('bio', $existing_columns) && !$error) {
        $bio = trim($_POST['bio'] ?? '');
        $update_fields[] = "bio = ?";
        $update_values[] = $bio;
        $types .= "s";
    }
    
    if (!empty($update_fields) && !$error) {
        $update_values[] = $user_id;
        $types .= "i";
        $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$update_values);
        
        if ($stmt->execute()) {
            $message = "Profile updated successfully!";
            // Refresh user data
            $select_sql = "SELECT " . implode(", ", $select_fields) . " FROM users WHERE id = $user_id";
            $user = $conn->query($select_sql)->fetch_assoc();
        } else {
            $error = "Failed to update profile: " . $conn->error;
        }
    }
}

// Handle avatar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar']) && isset($_FILES['avatar'])) {
    $upload_dir = '../uploads/avatars/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_errors = [];
    
    if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $file_errors[] = "Upload failed";
    }
    if ($_FILES['avatar']['size'] > 2097152) {
        $file_errors[] = "File too large (max 2MB)";
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        $file_errors[] = "Invalid file type. Use JPG, PNG, GIF, or WEBP";
    }
    
    if (empty($file_errors)) {
        $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
        $target_file = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $target_file)) {
            if (!empty($user['avatar']) && file_exists($upload_dir . $user['avatar'])) {
                unlink($upload_dir . $user['avatar']);
            }
            
            if (in_array('avatar', $existing_columns)) {
                $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $stmt->bind_param("si", $filename, $user_id);
                $stmt->execute();
            }
            $message = "Profile picture updated!";
            $user = $conn->query($select_sql)->fetch_assoc();
        } else {
            $error = "Failed to upload image";
        }
    } else {
        $error = implode('<br>', $file_errors);
    }
}

$conn->close();
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #f0f2f5; font-family: 'Inter', sans-serif; }
    
    .profile-page { max-width: 1200px; margin: 0 auto; padding: 20px; }
    
    /* Hero Section */
    .hero-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 30px;
        padding: 40px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }
    
    .hero-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .hero-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .user-info { display: flex; align-items: center; gap: 25px; flex-wrap: wrap; }
    
    .avatar-large {
        width: 100px;
        height: 100px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
        border: 3px solid rgba(255,255,255,0.5);
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
        overflow: hidden;
    }
    
    .avatar-large:hover { transform: scale(1.05); border-color: white; }
    .avatar-large img { width: 100%; height: 100%; object-fit: cover; }
    
    .avatar-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s;
        font-size: 24px;
    }
    
    .avatar-large:hover .avatar-overlay { opacity: 1; }
    
    .user-details h1 {
        font-size: 28px;
        font-weight: 700;
        color: white;
        margin-bottom: 8px;
    }
    
    .user-details p {
        color: rgba(255,255,255,0.9);
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }
    
    .badge-verified {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
    }
    
    .stats-row {
        display: flex;
        gap: 30px;
        background: rgba(255,255,255,0.15);
        padding: 15px 25px;
        border-radius: 50px;
        backdrop-filter: blur(10px);
    }
    
    .stat-item { text-align: center; }
    .stat-number { font-size: 24px; font-weight: 700; color: white; }
    .stat-label { font-size: 11px; color: rgba(255,255,255,0.8); text-transform: uppercase; }
    
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 25px rgba(0,0,0,0.1);
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #667eea20, #764ba220);
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    
    .stat-info h3 { font-size: 24px; font-weight: 700; color: #1e293b; }
    .stat-info p { font-size: 12px; color: #64748b; }
    
    /* Layout */
    .profile-layout {
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        gap: 25px;
    }
    
    /* Cards */
    .info-card, .edit-card {
        background: white;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .card-title {
        padding: 20px 25px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 16px;
        font-weight: 600;
        color: #1e293b;
    }
    
    .card-title i { margin-right: 10px; color: #667eea; }
    
    .info-list { padding: 20px 25px; }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .info-row:last-child { border-bottom: none; }
    
    .info-label {
        color: #64748b;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-label i { width: 18px; color: #667eea; }
    .info-value { font-weight: 500; color: #1e293b; font-size: 13px; text-align: right; }
    
    /* Form */
    .form-container { padding: 25px; }
    
    .form-group { margin-bottom: 20px; }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
        font-size: 13px;
    }
    
    .form-group label i { margin-right: 8px; color: #667eea; }
    
    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
    }
    
    .form-group input:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .form-group input:disabled {
        background: #f8fafc;
        color: #64748b;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .btn-save {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 10px;
    }
    
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .quick-actions {
        padding: 0 25px 25px 25px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .action-btn {
        flex: 1;
        padding: 10px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 40px;
        text-align: center;
        text-decoration: none;
        color: #64748b;
        font-size: 12px;
        font-weight: 500;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    
    .action-btn:hover {
        border-color: #667eea;
        color: #667eea;
        transform: translateY(-2px);
    }
    
    /* Alert */
    .alert {
        padding: 14px 18px;
        border-radius: 16px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-content {
        background: white;
        border-radius: 28px;
        padding: 30px;
        width: 450px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header h3 { font-size: 20px; font-weight: 600; }
    
    .close-modal {
        cursor: pointer;
        font-size: 28px;
        color: #94a3b8;
        transition: color 0.3s;
    }
    
    .close-modal:hover { color: #ef4444; }
    
    .hint-text {
        font-size: 11px;
        color: #64748b;
        margin-top: 6px;
        display: block;
    }
    
    @media (max-width: 900px) {
        .profile-layout { grid-template-columns: 1fr; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .hero-content { flex-direction: column; text-align: center; }
        .user-info { justify-content: center; }
        .form-row { grid-template-columns: 1fr; }
    }
    
    @media (max-width: 500px) {
        .stats-grid { grid-template-columns: 1fr; }
        .stats-row { flex-wrap: wrap; justify-content: center; border-radius: 20px; }
        .quick-actions { flex-direction: column; }
    }
</style>

<div class="profile-page">
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="hero-content">
            <div class="user-info">
                <div class="avatar-large" onclick="openAvatarModal()">
                    <?php 
                    $avatar_path = !empty($user['avatar']) && file_exists('../uploads/avatars/' . $user['avatar']) 
                        ? '/broker_system/uploads/avatars/' . $user['avatar'] 
                        : null;
                    ?>
                    <?php if ($avatar_path): ?>
                        <img src="<?php echo $avatar_path; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                    <?php endif; ?>
                    <div class="avatar-overlay">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                <div class="user-details">
                    <h1><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></h1>
                    <p>
                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                        <?php if (isset($user['is_verified']) && $user['is_verified']): ?>
                            <span class="badge-verified"><i class="fas fa-check-circle"></i> Verified</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['active_listings']; ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['completed_deals']; ?></div>
                    <div class="stat-label">Deals</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-info">
                <h3><?php echo $stats['listings']; ?></h3>
                <p>Total Listings</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔄</div>
            <div class="stat-info">
                <h3><?php echo $stats['transactions']; ?></h3>
                <p>Transactions</p>
            </div>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="profile-layout">
        <!-- Left Column - Information -->
        <div class="info-card">
            <div class="card-title">
                <i class="fas fa-info-circle"></i> About
            </div>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-user"></i> Full Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['full_name'] ?? 'Not set'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                </div>
                <?php if (in_array('phone', $existing_columns)): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></span>
                </div>
                <?php endif; ?>
                <?php if (in_array('city', $existing_columns)): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['city'] ?? 'Not provided'); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-tag"></i> Account Type</span>
                    <span class="info-value"><?php echo ucfirst($user['role'] ?? 'User'); ?></span>
                </div>
            </div>

            <?php if (in_array('bio', $existing_columns)): ?>
            <div class="card-title" style="border-top: 1px solid #e2e8f0;">
                <i class="fas fa-align-left"></i> Bio
            </div>
            <div class="info-list">
                <p style="font-size: 13px; color: #475569; line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($user['bio'] ?? 'No bio added yet.')); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="card-title" style="border-top: 1px solid #e2e8f0;">
                <i class="fas fa-rocket"></i> Quick Actions
            </div>
            <div class="quick-actions">
                <a href="post_listing.php" class="action-btn"><i class="fas fa-plus-circle"></i> Post</a>
                <a href="listings.php" class="action-btn"><i class="fas fa-box"></i> Listings</a>
                <a href="wallet.php" class="action-btn"><i class="fas fa-wallet"></i> Wallet</a>
                <a href="transactions.php" class="action-btn"><i class="fas fa-exchange-alt"></i> History</a>
                <a href="chat.php" class="action-btn"><i class="fas fa-comments"></i> Chat</a>
            </div>
        </div>

        <!-- Right Column - Edit Form -->
        <div class="edit-card">
            <div class="card-title">
                <i class="fas fa-edit"></i> Edit Profile
            </div>
            <div class="form-container">
                <?php if ($message): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <?php if (in_array('full_name', $existing_columns)): ?>
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                        <span class="hint-text">Email cannot be changed</span>
                    </div>
                    
                    <div class="form-row">
                        <?php if (in_array('phone', $existing_columns)): ?>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+251XXXXXXXXX">
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array('city', $existing_columns)): ?>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>" placeholder="Your city">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (in_array('address', $existing_columns)): ?>
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" placeholder="Your full address">
                    </div>
                    <?php endif; ?>
                    
                    <?php if (in_array('bio', $existing_columns)): ?>
                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Bio</label>
                        <textarea name="bio" rows="4" placeholder="Tell others about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" name="update_profile" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Avatar Upload Modal -->
<div id="avatarModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-camera"></i> Change Profile Picture</h3>
            <span class="close-modal" onclick="closeAvatarModal()">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Choose New Image</label>
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <span class="hint-text"><i class="fas fa-info-circle"></i> Max 2MB. Recommended: Square image, min 200x200px</span>
            </div>
            <button type="submit" name="upload_avatar" class="btn-save">
                <i class="fas fa-upload"></i> Upload Avatar
            </button>
        </form>
    </div>
</div>

<script>
    function openAvatarModal() {
        document.getElementById('avatarModal').style.display = 'flex';
    }
    
    function closeAvatarModal() {
        document.getElementById('avatarModal').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('avatarModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/rental_booking.php

<?php
// ============================================
// FILE: user/rental_booking.php
// Description: Complete Rental Booking Form with Availability Checking
// ============================================

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/AvailabilityManager.php';

requireLogin();

$page_title = 'Book Property';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get property details
$property = $conn->query("
    SELECT l.*, u.full_name as owner_name, u.id as owner_id, u.email as owner_email
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $listing_id AND l.type = 'rental' AND l.status = 'active' AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$property) {
    header('Location: browse.php');
    exit;
}

// Initialize availability manager
$availabilityManager = new AvailabilityManager($conn);

// Check if property is generally available
$is_generally_available = ($property['availability_status'] == 'available');

// Get user info
$user = $conn->query("SELECT full_name, phone, email FROM users WHERE id = $user_id")->fetch_assoc();

// Calculate deposit and commission percentages
$depositPercent = $property['admin_deposit_percent'] ?? 30;
$commissionPercent = $property['admin_commission_percent'] ?? 15;

// Get blocked dates for calendar display
$blocked_dates = [];
$reservations = $conn->query("
    SELECT check_in_date, check_out_date 
    FROM reservation_records 
    WHERE listing_id = $listing_id 
    AND status IN ('reserved', 'active')
");
while ($res = $reservations->fetch_assoc()) {
    $current = strtotime($res['check_in_date']);
    $end = strtotime($res['check_out_date']);
    while ($current < $end) {
        $blocked_dates[] = date('Y-m-d', $current);
        $current = strtotime('+1 day', $current);
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?php echo htmlspecialchars($property['title']); ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .header {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
        }
        
        .back-btn {
            background: var(--light);
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            color: var(--gray);
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .booking-container {
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 32px;
        }
        
        .property-card {
            background: white;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .property-image {
            width: 100%;
            height: 320px;
            object-fit: cover;
        }
        
        .property-info {
            padding: 28px;
        }
        
        .property-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .property-location {
            color: #64748b;
            margin-bottom: 16px;
            font-size: 14px;
        }
        
        .property-description {
            color: #475569;
            line-height: 1.6;
            margin-top: 16px;
        }
        
        .property-features {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            padding: 16px;
            background: #f8fafc;
            border-radius: 16px;
            flex-wrap: wrap;
        }
        
        .feature {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #475569;
        }
        
        .booking-form {
            background: white;
            border-radius: 28px;
            padding: 28px;
            position: sticky;
            top: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .price {
            font-size: 32px;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .price small {
            font-size: 14px;
            font-weight: normal;
            color: #64748b;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
            font-size: 13px;
        }
        
        .form-group label i {
            margin-right: 6px;
            color: #667eea;
        }
        
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }
        
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        
        .date-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .price-breakdown {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin: 20px 0;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .breakdown-item:last-child {
            border-bottom: none;
        }
        
        .breakdown-item.total {
            font-weight: 700;
            font-size: 16px;
            color: #667eea;
            border-top: 2px solid #e2e8f0;
            margin-top: 8px;
            padding-top: 12px;
        }
        
        .btn-book {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 20px;
        }
        
        .btn-book:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102,126,234,0.4);
        }
        
        .btn-book:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .info-note {
            background: #dbeafe;
            border-radius: 12px;
            padding: 12px;
            margin-top: 16px;
            font-size: 12px;
            color: #1e40af;
            text-align: center;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .alert-warning {
            background: #fed7aa;
            color: #9a3412;
            border-left: 4px solid #f59e0b;
        }
        
        .availability-loading {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: #f1f5f9;
            border-radius: 12px;
            margin: 12px 0;
        }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 968px) {
            .booking-container {
                grid-template-columns: 1fr;
            }
            .booking-form {
                position: static;
            }
        }
        
        @media (max-width: 640px) {
            .booking-container {
                padding: 0 16px;
                margin: 20px auto;
            }
            .property-title {
                font-size: 20px;
            }
            .price {
                font-size: 28px;
            }
            .property-info {
                padding: 20px;
            }
            .date-row {
                grid-template-columns: 1fr;
            }
            .property-features {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        /* Flatpickr customization */
        .flatpickr-day.disabled, .flatpickr-day.disabled:hover {
            background: #fee2e2 !important;
            color: #dc2626 !important;
            text-decoration: line-through;
            cursor: not-allowed;
        }
        
        .flatpickr-day.inRange, .flatpickr-day.prevMonthDay.inRange, .flatpickr-day.nextMonthDay.inRange {
            background: #dbeafe !important;
            border-color: #dbeafe !important;
        }
        
        .flatpickr-day.selected, .flatpickr-day.selected:hover {
            background: #667eea !important;
            border-color: #667eea !important;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">
                <i class="fas fa-store"></i> Ethio Brokerplace
            </a>
            <a href="browse.php?type=rental" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Rentals
            </a>
        </div>
    </header>
    
    <div class="booking-container">
        <!-- Property Details -->
        <div class="property-card">
            <?php 
            $cover_image = $property['cover_image'] && file_exists('../uploads/listings/' . $property['cover_image']) 
                ? '/broker_system/uploads/listings/' . $property['cover_image'] 
                : '';
            ?>
            <img src="<?php echo $cover_image ?: 'https://via.placeholder.com/800x400?text=Property+Image'; ?>" class="property-image" onerror="this.src='https://via.placeholder.com/800x400?text=No+Image'">
            <div class="property-info">
                <h1 class="property-title"><?php echo htmlspecialchars($property['title']); ?></h1>
                <div class="property-location">
                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($property['location'] ?: 'Location not specified'); ?>
                </div>
                
                <?php 
                $additional = $property['additional_details'] ? json_decode($property['additional_details'], true) : [];
                if (!empty($additional)): 
                ?>
                <div class="property-features">
                    <?php if (!empty($additional['bedrooms'])): ?>
                    <div class="feature"><i class="fas fa-bed"></i> <?php echo $additional['bedrooms']; ?> bedrooms</div>
                    <?php endif; ?>
                    <?php if (!empty($additional['bathrooms'])): ?>
                    <div class="feature"><i class="fas fa-bath"></i> <?php echo $additional['bathrooms']; ?> bathrooms</div>
                    <?php endif; ?>
                    <?php if (!empty($additional['area'])): ?>
                    <div class="feature"><i class="fas fa-arrows-alt"></i> <?php echo $additional['area']; ?> sqm</div>
                    <?php endif; ?>
                    <?php if (!empty($additional['parking'])): ?>
                    <div class="feature"><i class="fas fa-car"></i> Parking available</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="property-description">
                    <h3 style="margin-bottom: 12px; font-size: 18px;">Description</h3>
                    <p><?php echo nl2br(htmlspecialchars($property['description'])); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Booking Form -->
        <div class="booking-form">
            <div class="price">
                <?php echo formatMoney($property['price']); ?><small>/night</small>
            </div>
            
            <?php if (!$is_generally_available): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-ban"></i>
                    <div>This property is currently <strong><?php echo ucfirst($property['availability_status']); ?></strong> and cannot be booked.</div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="initiate_rental.php" id="bookingForm">
                <input type="hidden" name="listing_id" value="<?php echo $listing_id; ?>">
                
                <div class="date-row">
                    <div class="form-group">
                        <label><i class="fas fa-calendar-check"></i> Check-in Date</label>
                        <input type="text" name="check_in" id="check_in" 
                               placeholder="Select check-in date" 
                               <?php echo !$is_generally_available ? 'disabled' : ''; ?>
                               required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-times"></i> Check-out Date</label>
                        <input type="text" name="check_out" id="check_out" 
                               placeholder="Select check-out date" 
                               <?php echo !$is_generally_available ? 'disabled' : ''; ?>
                               required>
                    </div>
                </div>
                
                <!-- Availability Message Container -->
                <div id="availabilityMessage" style="display: none;"></div>
                <div id="availabilityLoading" class="availability-loading" style="display: none;">
                    <div class="spinner"></div>
                    <span>Checking availability...</span>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-users"></i> Number of Guests</label>
                    <input type="number" name="guests" id="guests" min="1" max="20" value="2" required <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Full Name</label>
                    <input type="text" name="guest_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+251XXXXXXXXX" <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required <?php echo !$is_generally_available ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Special Requests (Optional)</label>
                    <textarea name="message" id="message" rows="3" placeholder="Any special requests or questions for the owner?" <?php echo !$is_generally_available ? 'disabled' : ''; ?>></textarea>
                </div>
                
                <div class="price-breakdown" id="priceBreakdown">
                    <div class="breakdown-item">
                        <span>🏠 Price per night</span>
                        <span><?php echo formatMoney($property['price']); ?></span>
                    </div>
                    <div class="breakdown-item" id="nightsRow" style="display: none;">
                        <span><span id="nightsCount">0</span> nights</span>
                        <span id="nightsTotal"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>💰 Deposit (<?php echo $depositPercent; ?>%)</span>
                        <span id="depositAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>📋 Service Fee (<?php echo $commissionPercent; ?>%)</span>
                        <span id="feeAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item total">
                        <span>💳 Total to Pay Today</span>
                        <span id="totalAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>⏰ Remaining Balance</span>
                        <span id="remainingAmount"><?php echo formatMoney(0); ?></span>
                    </div>
                </div>
                
                <div class="info-note">
                    <i class="fas fa-shield-alt"></i> Your payment is protected by escrow. 
                    Deposit is fully refundable if the owner cancels. The remaining balance is paid at check-in.
                </div>
                
                <button type="submit" class="btn-book" id="bookBtn" disabled>
                    <i class="fas fa-credit-card"></i> Proceed to Payment
                </button>
            </form>
        </div>
    </div>
    
    <script>
    // Configuration
    const pricePerNight = <?php echo $property['price']; ?>;
    const depositPercent = <?php echo $depositPercent; ?>;
    const commissionPercent = <?php echo $commissionPercent; ?>;
    const listingId = <?php echo $listing_id; ?>;
    const blockedDates = <?php echo json_encode($blocked_dates); ?>;
    
    // DOM Elements
    const checkInInput = document.getElementById('check_in');
    const checkOutInput = document.getElementById('check_out');
    const bookBtn = document.getElementById('bookBtn');
    const availMsgDiv = document.getElementById('availabilityMessage');
    const availLoadingDiv = document.getElementById('availabilityLoading');
    const guestsInput = document.getElementById('guests');
    const phoneInput = document.getElementById('phone');
    const messageInput = document.getElementById('message');
    
    let isCheckingAvailability = false;
    let lastCheckedDates = { check_in: null, check_out: null };
    
    // Format money helper
    function formatMoney(amount) {
        return new Intl.NumberFormat('en-US', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        }).format(amount) + ' ETB';
    }
    
    // Calculate price breakdown
    function calculatePrice(checkIn, checkOut) {
        if (!checkIn || !checkOut) return false;
        
        const start = new Date(checkIn);
        const end = new Date(checkOut);
        const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
        
        if (nights > 0) {
            const totalRent = pricePerNight * nights;
            const deposit = totalRent * (depositPercent / 100);
            const fee = totalRent * (commissionPercent / 100);
            const total = deposit + fee;
            const remaining = totalRent - deposit;
            
            document.getElementById('nightsRow').style.display = 'flex';
            document.getElementById('nightsCount').textContent = nights;
            document.getElementById('nightsTotal').textContent = formatMoney(totalRent);
            document.getElementById('depositAmount').textContent = formatMoney(deposit);
            document.getElementById('feeAmount').textContent = formatMoney(fee);
            document.getElementById('totalAmount').textContent = formatMoney(total);
            document.getElementById('remainingAmount').textContent = formatMoney(remaining);
            return { nights, totalRent, deposit, fee, total, remaining };
        }
        return false;
    }
    
    // Check availability via AJAX
    async function checkAvailability() {
        const checkIn = checkInInput?.value;
        const checkOut = checkOutInput?.value;
        
        if (!checkIn || !checkOut) {
            if (availMsgDiv) availMsgDiv.style.display = 'none';
            if (bookBtn) bookBtn.disabled = true;
            return false;
        }
        
        // Don't re-check if same dates
        if (lastCheckedDates.check_in === checkIn && lastCheckedDates.check_out === checkOut) {
            return true;
        }
        
        isCheckingAvailability = true;
        if (availLoadingDiv) availLoadingDiv.style.display = 'flex';
        if (availMsgDiv) availMsgDiv.style.display = 'none';
        
        try {
            const response = await fetch('/broker_system/api/check_availability.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    listing_id: listingId, 
                    check_in: checkIn, 
                    check_out: checkOut 
                })
            });
            
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', text);
                throw new Error('Invalid server response');
            }
            
            lastCheckedDates = { check_in: checkIn, check_out: checkOut };
            
            if (availMsgDiv) {
                availMsgDiv.style.display = 'block';
                if (data.available) {
                    availMsgDiv.className = 'alert alert-success';
                    availMsgDiv.innerHTML = '<i class="fas fa-check-circle"></i> ✓ <strong>Available!</strong> This property is available for your selected dates.';
                    if (bookBtn) bookBtn.disabled = false;
                } else {
                    availMsgDiv.className = 'alert alert-danger';
                    availMsgDiv.innerHTML = '<i class="fas fa-times-circle"></i> ✗ <strong>Not Available</strong> ' + (data.message || 'This property is already booked for some of your selected dates.');
                    if (bookBtn) bookBtn.disabled = true;
                }
            }
            
            return data.available;
            
        } catch (error) {
            console.error('Availability check failed:', error);
            if (availMsgDiv) {
                availMsgDiv.style.display = 'block';
                availMsgDiv.className = 'alert alert-danger';
                availMsgDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ⚠️ Unable to check availability. Please try again.';
            }
            if (bookBtn) bookBtn.disabled = true;
            return false;
        } finally {
            isCheckingAvailability = false;
            if (availLoadingDiv) availLoadingDiv.style.display = 'none';
        }
    }
    
    // Validate phone number (Ethiopian format)
    function validatePhone(phone) {
        if (!phone) return true;
        const phoneRegex = /^(\+251|0)[0-9]{9}$/;
        return phoneRegex.test(phone);
    }
    
    // Form validation before submit
    function validateForm() {
        const checkIn = checkInInput?.value;
        const checkOut = checkOutInput?.value;
        const guests = guestsInput?.value;
        const phone = phoneInput?.value;
        
        if (!checkIn || !checkOut) {
            alert('Please select check-in and check-out dates');
            return false;
        }
        
        const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));
        if (nights <= 0) {
            alert('Check-out date must be after check-in date');
            return false;
        }
        
        if (nights > 365) {
            alert('Maximum booking period is 365 nights');
            return false;
        }
        
        if (!guests || guests < 1) {
            alert('Please enter number of guests');
            return false;
        }
        
        if (phone && !validatePhone(phone)) {
            alert('Please enter a valid Ethiopian phone number (format: 0912345678 or +251912345678)');
            return false;
        }
        
        return true;
    }
    
    // Initialize Flatpickr date pickers
    if (checkInInput && checkOutInput) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        const maxDate = new Date();
        maxDate.setFullYear(maxDate.getFullYear() + 1);
        
        // Configure flatpickr for check-in
        const checkInPicker = flatpickr(checkInInput, {
            dateFormat: "Y-m-d",
            minDate: today,
            maxDate: maxDate,
            disable: blockedDates,
            onChange: function(selectedDates, dateStr, instance) {
                if (dateStr) {
                    // Update check-out min date
                    const minCheckOut = new Date(dateStr);
                    minCheckOut.setDate(minCheckOut.getDate() + 1);
                    checkOutPicker.set('minDate', minCheckOut);
                    
                    // Clear check-out if invalid
                    if (checkOutInput.value && new Date(checkOutInput.value) <= minCheckOut) {
                        checkOutPicker.clear();
                    }
                    
                    // Recalculate price
                    calculatePrice(checkInInput.value, checkOutInput.value);
                    
                    // Check availability
                    checkAvailability();
                }
            }
        });
        
        // Configure flatpickr for check-out
        const checkOutPicker = flatpickr(checkOutInput, {
            dateFormat: "Y-m-d",
            minDate: today,
            maxDate: maxDate,
            disable: blockedDates,
            onChange: function(selectedDates, dateStr, instance) {
                if (dateStr) {
                    calculatePrice(checkInInput.value, checkOutInput.value);
                    checkAvailability();
                }
            }
        });
    }
    
    // Real-time price calculation when guests change
    if (guestsInput) {
        guestsInput.addEventListener('change', function() {
            calculatePrice(checkInInput?.value, checkOutInput?.value);
        });
    }
    
    // Form submit handler
    const bookingForm = document.getElementById('bookingForm');
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
            
            // Disable button to prevent double submission
            const submitBtn = document.getElementById('bookBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }
            
            return true;
        });
    }
    
    // Initial calculation and availability check if dates are pre-filled
    if (checkInInput?.value && checkOutInput?.value) {
        calculatePrice(checkInInput.value, checkOutInput.value);
        checkAvailability();
    }
    
    // Set min date for check-in
    const todayStr = new Date().toISOString().split('T')[0];
    if (checkInInput && !checkInInput.value) {
        checkInInput.setAttribute('min', todayStr);
    }
    </script>
</body>
</html>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/transaction.php

<?php
// user/transaction.php - Complete Transaction Page with Escrow and Seller Notifications

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';
require_once '../includes/transaction_workflow.php';

requireLogin();

$page_title = 'Transaction Details';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_delivered') {
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        $result = markDelivery($conn, $transaction_id, $user_id, $delivery_notes);
        if ($result['success']) {
            $message = "✓ Delivery marked successfully! Waiting for buyer confirmation.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'confirm_receipt') {
        $confirm_notes = $_POST['confirm_notes'] ?? '';
        $result = confirmReceiptAndRelease($conn, $transaction_id, $user_id, $confirm_notes);
        if ($result['success']) {
            $message = "✓ Payment released! Funds have been sent to the seller.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'raise_dispute') {
        $result = openTransactionDispute($conn, $transaction_id, $user_id, $_POST['dispute_reason'] ?? '');
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['error'];
        }
    }
}

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as listing_title, l.type as listing_type, l.cover_image,
           l.admin_deposit_percent, l.admin_commission_percent,
           u1.full_name as buyer_name, u1.email as buyer_email, u1.phone as buyer_phone,
           u2.full_name as seller_name, u2.email as seller_email, u2.phone as seller_phone
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
")->fetch_assoc();

if (!$transaction) {
    $conn->close();
    header('Location: dashboard.php');
    exit;
}

$workflow = getTransactionWorkflowView($conn, $transaction_id);
if ($workflow) {
    $transaction = array_merge($transaction, $workflow);
}

// Get escrow data
$escrow_data = $conn->query("
    SELECT ea.amount as escrow_amount, ea.status as escrow_account_status,
           eq.scheduled_release_date
    FROM escrow_accounts ea
    LEFT JOIN escrow_release_queue eq ON ea.transaction_id = eq.transaction_id AND eq.status = 'pending'
    WHERE ea.transaction_id = $transaction_id AND ea.status = 'held'
    LIMIT 1
")->fetch_assoc();

// Get payment history
$payments = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed' 
    ORDER BY created_at DESC
");

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);

// Calculate amounts
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $transaction['total_amount'] * ($depositPercent / 100);
$commissionAmount = $transaction['total_amount'] * ($commissionPercent / 100);
$buyerRequired = $depositAmount + $commissionAmount;
$sellerRequired = $depositAmount;

// Get payment totals
$buyerPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type IN ('deposit_buyer', 'commission') AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);
$sellerPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type = 'deposit_seller' AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);
$remainingPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type = 'remaining_balance' AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);

$listing_type = $transaction['listing_type'] ?? 'product';
$depositPaidDisplay = (float) ($transaction['amount_paid'] ?? 0);
$remainingBalanceDisplay = (float) ($transaction['remaining_balance'] ?? 0);
$payment_status_label = $transaction['payment_status'] ?? 'pending';
$funds_status_label = $transaction['funds_status'] ?? ($transaction['escrow_status'] ?? 'pending');
$seller_confirmed = (bool) ($transaction['seller_confirmed'] ?? 0) || ($transaction['delivery_status'] ?? '') === 'delivered';
$buyer_confirmed = (bool) ($transaction['buyer_confirmed'] ?? 0);
$is_frozen = ($transaction['admin_frozen'] == 1);
$is_completed = ($transaction['status'] == 'completed');
$is_disputed = ($transaction['status'] == 'disputed' || $funds_status_label === 'disputed');

// Store payments for template (avoid using mysqli after close)
$payments_list = [];
if ($payments && $payments->num_rows > 0) {
    while ($p = $payments->fetch_assoc()) {
        $payments_list[] = $p;
    }
}

// Determine button states
$escrow_active = in_array($funds_status_label, ['held_in_escrow', 'seller_confirmed', 'buyer_confirmed', 'ready_for_release'], true)
    || ($transaction['escrow_status'] ?? '') === 'active';
$payment_received = ($escrow_active && !$is_completed && $depositPaidDisplay > 0);
$can_mark_delivery = ($is_seller && $payment_received && !$seller_confirmed && !$is_disputed && !$is_frozen);
$can_confirm_receipt = ($is_buyer && $seller_confirmed && !$buyer_confirmed && !$is_disputed && !$is_frozen && !$is_completed);
$can_open_dispute = ($is_buyer && $payment_received && !$is_completed && !$is_disputed);
$can_pay_remaining = $is_buyer
    && $payment_status_label !== 'fully_paid'
    && $remainingBalanceDisplay > 0
    && !$is_completed
    && !$is_disputed;
?>

<style>
    .transaction-container { max-width: 1200px; margin: 0 auto; }
    
    .transaction-header {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .transaction-header h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
    .transaction-header p { opacity: 0.9; }
    
    .card {
        background: white;
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .card-header h3 { font-size: 18px; font-weight: 600; color: #0f172a; }
    
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    .status-active { background: #dbeafe; color: #1e40af; }
    .status-delivered { background: #fed7aa; color: #9a3412; }
    .status-completed { background: #d1fae5; color: #059669; }
    .status-disputed { background: #fee2e2; color: #dc2626; }
    .status-frozen { background: #f1f5f9; color: #64748b; }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .info-item { padding: 12px; background: #f8fafc; border-radius: 16px; }
    .info-label { font-size: 11px; color: #64748b; margin-bottom: 4px; }
    .info-value { font-size: 16px; font-weight: 700; color: #0f172a; }
    
    .escrow-box {
        background: linear-gradient(135deg, #667eea10, #764ba210);
        border-radius: 20px;
        padding: 20px;
        margin: 16px 0;
        border: 1px solid #667eea30;
    }
    
    /* Payment Received Card - For Seller */
    .payment-received-card {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        border: 2px solid #10b981;
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .payment-received-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        color: #065f46;
    }
    
    .payment-received-header i {
        font-size: 32px;
    }
    
    .payment-received-header h2 {
        font-size: 20px;
        font-weight: 700;
    }
    
    .buyer-details {
        background: white;
        border-radius: 16px;
        padding: 16px;
        margin: 16px 0;
    }
    
    .buyer-details p {
        margin: 8px 0;
    }
    
    .btn-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
    .btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    
    .delivery-section {
        background: #f0f9ff;
        border: 2px solid #667eea;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .delivery-title {
        font-size: 18px;
        font-weight: 700;
        color: #1e40af;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .alert-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #1e40af; }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .modal-content {
        background: white;
        border-radius: 24px;
        padding: 28px;
        width: 500px;
        max-width: 90%;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
    
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    th { font-weight: 600; color: #64748b; }
    
    @media (max-width: 768px) {
        .info-grid { grid-template-columns: 1fr; }
        .btn-group { flex-direction: column; }
        .btn { justify-content: center; }
        .buyer-details { padding: 12px; }
    }
</style>

<div class="transaction-container">
    <!-- Header -->
    <div class="transaction-header">
        <h1><i class="fas fa-receipt"></i> Transaction #<?php echo $transaction['id']; ?></h1>
        <p><?php echo htmlspecialchars($transaction['listing_title']); ?></p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($is_frozen): ?>
        <div class="alert alert-info">
            <i class="fas fa-ice-cream"></i> 
            This transaction has been frozen by admin. Reason: <?php echo htmlspecialchars($transaction['frozen_reason'] ?? 'Not specified'); ?>
        </div>
    <?php endif; ?>
    
    <!-- Status Overview -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line"></i> Status Overview</h3>
            <span class="status-badge <?php 
                if ($is_completed) echo 'status-completed';
                elseif ($is_disputed) echo 'status-disputed';
                elseif ($is_frozen) echo 'status-frozen';
                elseif ($transaction['delivery_status'] == 'delivered') echo 'status-delivered';
                elseif ($escrow_active) echo 'status-active';
                else echo 'status-active';
            ?>">
                <?php 
                if ($is_completed) echo '✓ Completed';
                elseif ($is_disputed) echo '⚠️ Disputed';
                elseif ($is_frozen) echo '❄️ Frozen';
                elseif ($transaction['delivery_status'] == 'delivered') echo '📦 Delivered - Awaiting Confirmation';
                elseif ($escrow_active) echo '💰 Escrow Active';
                else echo '📋 Pending';
                ?>
            </span>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Total Price</div>
                <div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Amount Paid</div>
                <div class="info-value"><?php echo formatMoney($depositPaidDisplay); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Remaining Balance</div>
                <div class="info-value"><?php echo formatMoney($remainingBalanceDisplay); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Payment Status</div>
                <div class="info-value"><?php echo htmlspecialchars(str_replace('_', ' ', $payment_status_label)); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Funds Status</div>
                <div class="info-value"><?php echo htmlspecialchars(str_replace('_', ' ', $funds_status_label)); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Escrow Held</div>
                <div class="info-value"><?php echo formatMoney($escrow_data['escrow_amount'] ?? $transaction['escrow_held'] ?? 0); ?></div>
            </div>
        </div>

        <?php if ($can_pay_remaining): ?>
        <div style="margin-top:16px;padding:16px;background:#ecfdf5;border:1px solid #10b981;border-radius:16px;">
            <p style="margin-bottom:12px;color:#065f46;font-size:14px;">
                <i class="fas fa-wallet"></i>
                You can pay the remaining balance of <?php echo formatMoney($remainingBalanceDisplay); ?> to complete this purchase.
            </p>
            <button type="button" class="btn btn-success pay-remaining-txn-btn" data-transaction-id="<?php echo $transaction_id; ?>">
                <i class="fas fa-credit-card"></i> Pay Remaining Balance
            </button>
            <p id="payRemainingTxnError" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></p>
        </div>
        <?php elseif ($payment_status_label === 'fully_paid'): ?>
        <p style="margin-top:12px;padding:12px;background:#d1fae5;border-radius:12px;color:#065f46;font-weight:600;">
            <i class="fas fa-check-circle"></i> Fully Paid
        </p>
        <?php endif; ?>

        <?php if ($seller_confirmed && !$buyer_confirmed && $is_buyer): ?>
        <p style="margin-top:12px;color:#1e40af;font-size:13px;"><i class="fas fa-hourglass-half"></i> Seller confirmed delivery — please confirm receipt to release funds.</p>
        <?php elseif ($buyer_confirmed && !$seller_confirmed && $is_seller): ?>
        <p style="margin-top:12px;color:#1e40af;font-size:13px;"><i class="fas fa-hourglass-half"></i> Waiting for buyer confirmation.</p>
        <?php endif; ?>
        
        <?php if (!empty($escrow_data['scheduled_release_date']) && !$is_completed): ?>
            <div class="escrow-box">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <i class="fas fa-clock"></i>
                        <strong>Auto-Release Schedule:</strong><br>
                        <small>Funds will automatically release to seller on <?php echo date('F d, Y', strtotime($escrow_data['scheduled_release_date'])); ?></small>
                    </div>
                    <div style="background: #fef3c7; padding: 8px 16px; border-radius: 40px; color: #92400e; font-weight: 600;">
                        <?php
                        $days_left = ceil((strtotime($escrow_data['scheduled_release_date']) - time()) / 86400);
                        echo max(0, (int) $days_left) . ' days remaining';
                        ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php
        $type_labels = [
            'product' => 'Product',
            'rental' => 'Rental',
            'job' => 'Job',
        ];
        ?>
        <p style="font-size: 12px; color: #64748b; margin-top: 12px;">
            <i class="fas fa-tag"></i>
            <?php echo htmlspecialchars($type_labels[$listing_type] ?? ucfirst($listing_type)); ?> transaction
        </p>
    </div>
    
    <!-- PAYMENT RECEIVED CARD - FOR SELLER (Important Notification) -->
    <?php if ($is_seller && $payment_received && !$is_completed): ?>
    <div class="payment-received-card">
        <div class="payment-received-header">
            <i class="fas fa-money-bill-wave"></i>
            <h2>💰 Payment Received - Escrow Active!</h2>
        </div>
        <p style="color: #065f46; margin-bottom: 16px;">The buyer has paid successfully. Funds are now held securely in escrow.</p>
        
        <div class="buyer-details">
            <p><strong><i class="fas fa-user"></i> Buyer Information:</strong></p>
            <p>📛 Name: <?php echo htmlspecialchars($transaction['buyer_name']); ?></p>
            <p>📧 Email: <?php echo htmlspecialchars($transaction['buyer_email']); ?></p>
            <p>📞 Phone: <?php echo htmlspecialchars($transaction['buyer_phone'] ?? 'Not provided'); ?></p>
            <hr style="margin: 12px 0;">
            <p><strong>💰 Amount in Escrow:</strong> <?php echo formatMoney($transaction['escrow_held']); ?></p>
            <p><strong>🔒 Status:</strong> Funds secured - Awaiting delivery</p>
        </div>
        
        <div class="btn-group">
            <a href="chat.php?user=<?php echo $transaction['buyer_id']; ?>" class="btn btn-primary">
                <i class="fas fa-comment"></i> Contact Buyer
            </a>
            <button onclick="openDeliveryModal()" class="btn btn-success">
                <i class="fas fa-truck"></i> Mark as Delivered
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- PAYMENT CONFIRMED CARD - FOR BUYER -->
    <?php if ($is_buyer && $payment_received && !$is_completed): ?>
    <div class="card" style="background: #dbeafe; border: 2px solid #3b82f6;">
        <div class="card-header">
            <h3><i class="fas fa-check-circle" style="color: #2563eb;"></i> Payment Confirmed!</h3>
        </div>
        <div style="text-align: center; padding: 10px;">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #2563eb;"></i>
            <p style="margin-top: 12px;">Your payment has been confirmed and is held securely in escrow.</p>
            <p>The seller has been notified and will prepare your item.</p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ESCROW ACTION BUTTONS -->
    <?php if ($escrow_active && !$is_completed && !$is_disputed && !$is_frozen): ?>
        <?php if ($can_mark_delivery): ?>
            <!-- SELLER: Mark Delivery Button -->
            <div class="delivery-section">
                <div class="delivery-title">
                    <i class="fas fa-truck"></i> Mark as Delivered
                </div>
                <p style="margin-bottom: 16px; color: #1e3a8a;">Confirm that you have delivered the item/service to the buyer.</p>
                <button onclick="openDeliveryModal()" class="btn btn-primary" style="background: #1e40af;">
                    <i class="fas fa-check-circle"></i> I Have Delivered
                </button>
            </div>
        <?php endif; ?>
        
        <?php if ($can_confirm_receipt): ?>
            <!-- BUYER: Confirm Receipt Button -->
            <div class="delivery-section" style="background: #d1fae5; border-color: #10b981;">
                <div class="delivery-title" style="color: #065f46;">
                    <i class="fas fa-check-circle"></i> Confirm Receipt
                </div>
                <p style="margin-bottom: 16px; color: #065f46;">Confirm that you have received the item/service. This will release payment to the seller.</p>
                <button onclick="openConfirmModal()" class="btn btn-success">
                    <i class="fas fa-money-bill-wave"></i> Confirm & Release Payment
                </button>
            </div>
        <?php endif; ?>
        
        <?php if (!$can_mark_delivery && !$can_confirm_receipt && $escrow_active): ?>
            <div class="card" style="text-align: center;">
                <i class="fas fa-hourglass-half" style="font-size: 48px; color: #667eea; margin-bottom: 12px; display: block;"></i>
                <p>Waiting for <?php echo $is_seller ? 'buyer confirmation' : 'seller to mark delivery'; ?>.</p>
                <?php if ($transaction['delivery_status'] == 'delivered'): ?>
                    <p class="info-text">The seller has marked this as delivered. Please confirm receipt to release payment.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Dispute Button -->
    <?php if ($can_open_dispute): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-gavel"></i> Having an Issue?</h3>
            </div>
            <p style="margin-bottom: 16px;">If you're experiencing problems with this transaction, you can raise a dispute. An admin will review your case.</p>
            <button onclick="openDisputeModal()" class="btn btn-danger">
                <i class="fas fa-flag"></i> Raise a Dispute
            </button>
        </div>
    <?php endif; ?>
    
    <!-- Party Information -->
    <div class="info-grid">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user"></i> Buyer Information</h3></div>
            <div class="info-item"><div class="info-label">Name</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_email']); ?></div></div>
            <?php if ($transaction['buyer_phone']): ?>
            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_phone']); ?></div></div>
            <?php endif; ?>
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['buyer_id']; ?>" class="btn btn-outline"><i class="fas fa-comment"></i> Message Buyer</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-store"></i> Seller Information</h3></div>
            <div class="info-item"><div class="info-label">Name</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_email']); ?></div></div>
            <?php if ($transaction['seller_phone']): ?>
            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_phone']); ?></div></div>
            <?php endif; ?>
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['seller_id']; ?>" class="btn btn-outline"><i class="fas fa-comment"></i> Message Seller</a>
            </div>
        </div>
    </div>
    
    <!-- Payment History -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-history"></i> Payment History</h3></div>
        <?php if (!empty($payments_list)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments_list as $p): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($p['created_at'])); ?></td>
                            <td><?php echo formatMoney($p['amount']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $p['type'] ?? 'payment')); ?></td>
                            <td><span class="status-badge status-completed" style="background: #d1fae5;">Confirmed</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; padding: 20px; color: #64748b;">No payments recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Delivery Modal -->
<div id="deliveryModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-truck"></i> Mark as Delivered</h3>
        <form method="POST">
            <input type="hidden" name="action" value="mark_delivered">
            <div class="form-group">
                <label>Delivery Notes (Optional)</label>
                <textarea name="delivery_notes" rows="3" placeholder="Add any delivery details or tracking information..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Confirm Delivery</button>
                <button type="button" onclick="closeDeliveryModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Receipt Modal -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-check-circle"></i> Confirm Receipt & Release Payment</h3>
        <div style="background: #fef3c7; padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <p><strong>⚠️ Important:</strong> Confirming receipt will release the payment to the seller. This action cannot be undone.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="confirm_receipt">
            <div class="form-group">
                <label>Confirmation Notes (Optional)</label>
                <textarea name="confirm_notes" rows="3" placeholder="Add any notes about the delivery..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-success">Confirm & Release Payment</button>
                <button type="button" onclick="closeConfirmModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Dispute Modal -->
<div id="disputeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-flag"></i> Raise a Dispute</h3>
        <form method="POST">
            <input type="hidden" name="action" value="raise_dispute">
            <div class="form-group">
                <label>Reason for Dispute <span style="color: red;">*</span></label>
                <textarea name="dispute_reason" rows="4" placeholder="Please explain in detail why you're raising this dispute..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-danger">Submit Dispute</button>
                <button type="button" onclick="closeDisputeModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeliveryModal() { document.getElementById('deliveryModal').style.display = 'flex'; }
function closeDeliveryModal() { document.getElementById('deliveryModal').style.display = 'none'; }
function openConfirmModal() { document.getElementById('confirmModal').style.display = 'flex'; }
function closeConfirmModal() { document.getElementById('confirmModal').style.display = 'none'; }
function openDisputeModal() { document.getElementById('disputeModal').style.display = 'flex'; }
function closeDisputeModal() { document.getElementById('disputeModal').style.display = 'none'; }

document.querySelectorAll('.pay-remaining-txn-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const tid = this.dataset.transactionId;
        if (!confirm('Pay the remaining balance now?')) return;
        const errEl = document.getElementById('payRemainingTxnError');
        this.disabled = true;
        try {
            const res = await fetch('/broker_system/user/api/transaction_workflow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'pay_remaining', transaction_id: parseInt(tid, 10) })
            });
            const data = await res.json();
            if (data.success && data.pay_url) {
                window.location.href = data.pay_url;
            } else {
                errEl.textContent = data.error || 'Could not start payment';
                errEl.style.display = 'block';
                this.disabled = false;
            }
        } catch (e) {
            errEl.textContent = 'Network error';
            errEl.style.display = 'block';
            this.disabled = false;
        }
    });
});

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$conn->close();
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/transaction_actions.php

<?php
// user/transaction_actions.php - Handle all transaction button actions

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$transaction_id = intval($input['transaction_id'] ?? 0);
$notes = $input['notes'] ?? '';

if (!$transaction_id) {
    echo json_encode(['success' => false, 'error' => 'Transaction ID required']);
    exit;
}

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title, l.type, l.seller_id, l.price,
           u1.full_name as buyer_name, u2.full_name as seller_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id
")->fetch_assoc();

if (!$transaction) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);
$is_admin = ($_SESSION['user_role'] == 'admin');

$response = ['success' => false, 'error' => 'Invalid action'];

switch($action) {
    // ==================== BUYER/TENANT ACTIONS ====================
    
    case 'pay_full_amount':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only buyer can pay'];
            break;
        }
        
        $remaining = $transaction['total_amount'] - $transaction['deposit_amount'];
        if ($remaining <= 0) {
            $response = ['success' => false, 'error' => 'No remaining amount to pay'];
            break;
        }
        
        // Generate payment code for remaining amount
        do {
            $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
        } while ($code_check->num_rows > 0);
        
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $stmt = $conn->prepare("
            INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
            VALUES (?, ?, ?, ?, 'full_payment', ?, 'pending', NOW())
        ");
        $stmt->bind_param("siids", $payment_code, $transaction_id, $remaining, $user_id, $expires_at);
        $stmt->execute();
        
        $response = [
            'success' => true, 
            'message' => 'Payment code generated',
            'payment_code' => $payment_code,
            'amount' => $remaining
        ];
        break;
        
    case 'confirm_receipt':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only buyer can confirm'];
            break;
        }
        
        if ($transaction['delivery_status'] != 'delivered') {
            $response = ['success' => false, 'error' => 'Seller has not marked delivery yet'];
            break;
        }
        
        $result = releaseEscrowPayment($conn, $transaction_id, $user_id, 'buyer', $notes);
        $response = $result;
        break;
        
    case 'cancel_transaction':
        if (!$is_buyer && !$is_seller) {
            $response = ['success' => false, 'error' => 'Unauthorized'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'cancelled', 
                cancelled_by = $user_id,
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Cancel escrow and refund
        if ($transaction['escrow_held'] > 0) {
            refundEscrowPayment($conn, $transaction_id, $user_id, "Cancelled by user");
        }
        
        $response = ['success' => true, 'message' => 'Transaction cancelled'];
        break;
        
    // ==================== SELLER/LANDLORD ACTIONS ====================
    
    case 'approve_booking':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can approve'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'approved', 
                approved_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'booking_approved', 
            "Booking approved by seller", $user_id);
        
        $response = ['success' => true, 'message' => 'Booking approved! Waiting for payment.'];
        break;
        
    case 'reject_booking':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can reject'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'rejected', 
                rejection_reason = '$notes',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        $response = ['success' => true, 'message' => 'Booking rejected'];
        break;
        
    case 'confirm_handover':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can confirm handover'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET handover_confirmed = 1,
                handover_confirmed_at = NOW(),
                delivery_status = 'handed_over',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'handover_confirmed', 
            "Property handover confirmed by seller", $user_id);
        
        $response = ['success' => true, 'message' => 'Handover confirmed! Waiting for buyer confirmation.'];
        break;
        
    case 'mark_delivered':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can mark delivery'];
            break;
        }
        
        $result = markDelivery($conn, $transaction_id, $user_id, $notes);
        $response = $result;
        break;
        
    case 'upload_delivery_proof':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only seller can upload proof'];
            break;
        }
        
        // Handle file upload
        if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/delivery_proofs/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $filename = time() . '_' . $transaction_id . '_' . basename($_FILES['proof_file']['name']);
            $target_file = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $target_file)) {
                $stmt = $conn->prepare("
                    INSERT INTO delivery_proofs (transaction_id, user_id, file_path, proof_text, uploaded_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param("iiss", $transaction_id, $user_id, $filename, $notes);
                $stmt->execute();
                
                $response = ['success' => true, 'message' => 'Delivery proof uploaded'];
            } else {
                $response = ['success' => false, 'error' => 'Failed to upload file'];
            }
        } else {
            $response = ['success' => false, 'error' => 'Please select a file to upload'];
        }
        break;
        
    // ==================== EMPLOYER ACTIONS ====================
    
    case 'hire_candidate':
        $job_id = intval($input['job_id'] ?? 0);
        $applicant_id = intval($input['applicant_id'] ?? 0);
        
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only job poster can hire'];
            break;
        }
        
        // Update job application
        $conn->query("
            UPDATE job_applications 
            SET status = 'hired', hired_at = NOW()
            WHERE job_id = $job_id AND applicant_id = $applicant_id
        ");
        
        // Create transaction if not exists
        $check = $conn->query("SELECT id FROM transactions WHERE listing_id = $job_id AND buyer_id = $applicant_id");
        if ($check->num_rows == 0) {
            $stmt = $conn->prepare("
                INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'hired', NOW())
            ");
            $stmt->bind_param("iiidddd", $job_id, $applicant_id, $user_id, $transaction['price'], 
                $transaction['price'] * 0.3, $transaction['price'] * 0.15, $transaction['price'] * 0.55);
            $stmt->execute();
            $transaction_id = $conn->insert_id;
        }
        
        addTransactionTimeline($conn, $transaction_id, 'hired', 
            "Candidate hired for job", $user_id);
        
        $response = ['success' => true, 'message' => 'Candidate hired! Please fund escrow to start.'];
        break;
        
    case 'fund_escrow':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only employer can fund escrow'];
            break;
        }
        
        // Generate payment code for escrow funding
        do {
            $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
        } while ($code_check->num_rows > 0);
        
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $stmt = $conn->prepare("
            INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
            VALUES (?, ?, ?, ?, 'escrow_fund', ?, 'pending', NOW())
        ");
        $stmt->bind_param("siids", $payment_code, $transaction_id, $transaction['total_amount'], $user_id, $expires_at);
        $stmt->execute();
        
        $response = [
            'success' => true,
            'message' => 'Escrow funding code generated',
            'payment_code' => $payment_code,
            'amount' => $transaction['total_amount']
        ];
        break;
        
    case 'approve_work':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only employer can approve work'];
            break;
        }
        
        if ($transaction['work_submitted_at'] == '0000-00-00 00:00:00' || !$transaction['work_submitted_at']) {
            $response = ['success' => false, 'error' => 'No work has been submitted yet'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET work_approved_at = NOW(),
                status = 'work_approved',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        // Release payment to worker
        $result = releaseEscrowPayment($conn, $transaction_id, $user_id, 'employer', 'Work approved');
        $response = $result;
        break;
        
    case 'reject_work':
        if (!$is_seller) {
            $response = ['success' => false, 'error' => 'Only employer can reject work'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET work_rejected_at = NOW(),
                rejection_reason = '$notes',
                status = 'work_rejected',
                updated_at = NOW()
            WHERE id = $transaction_id
        ");
        
        $response = ['success' => true, 'message' => 'Work rejected. Worker can resubmit.'];
        break;
        
    // ==================== WORKER ACTIONS ====================
    
    case 'accept_job':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only worker can accept job'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET status = 'accepted',
                accepted_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'job_accepted', 
            "Worker accepted the job", $user_id);
        
        $response = ['success' => true, 'message' => 'Job accepted! Waiting for employer to fund escrow.'];
        break;
        
    case 'submit_work':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only worker can submit work'];
            break;
        }
        
        $work_link = $input['work_link'] ?? '';
        $work_description = $input['work_description'] ?? '';
        
        $conn->query("
            UPDATE transactions 
            SET work_submitted_at = NOW(),
                work_link = '$work_link',
                work_description = '$work_description',
                status = 'work_submitted',
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'work_submitted', 
            "Work submitted for review", $user_id);
        
        $response = ['success' => true, 'message' => 'Work submitted! Waiting for employer approval.'];
        break;
        
    case 'mark_completed':
        if (!$is_buyer && !$is_seller) {
            $response = ['success' => false, 'error' => 'Unauthorized'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET worker_completed = 1,
                worker_completed_at = NOW(),
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        // Check if both parties completed
        $check = $conn->query("
            SELECT worker_completed, employer_completed 
            FROM transactions WHERE id = $transaction_id
        ")->fetch_assoc();
        
        if ($check['worker_completed'] && $check['employer_completed']) {
            $result = releaseEscrowPayment($conn, $transaction_id, $user_id, 'both', 'Both parties confirmed completion');
            $response = $result;
        } else {
            $response = ['success' => true, 'message' => 'Marked as completed. Waiting for other party.'];
        }
        break;
        
    case 'request_payment':
        if (!$is_buyer) {
            $response = ['success' => false, 'error' => 'Only worker can request payment'];
            break;
        }
        
        $conn->query("
            UPDATE transactions 
            SET payment_requested_at = NOW(),
                status = 'payment_requested',
                updated_at = NOW()
            WHERE id = $transaction_id AND buyer_id = $user_id
        ");
        
        addTransactionTimeline($conn, $transaction_id, 'payment_requested', 
            "Worker requested payment release", $user_id);
        
        $response = ['success' => true, 'message' => 'Payment request sent to employer!'];
        break;
        
    // ==================== COMMON ACTIONS ====================
    
    case 'view_timeline':
        $timeline = getTransactionTimeline($conn, $transaction_id);
        $timeline_data = [];
        while ($row = $timeline->fetch_assoc()) {
            $timeline_data[] = $row;
        }
        $response = ['success' => true, 'timeline' => $timeline_data];
        break;
        
    case 'view_escrow_status':
        $escrow_status = getTransactionEscrowStatus($conn, $transaction_id);
        $response = ['success' => true, 'escrow' => $escrow_status];
        break;
        
    default:
        $response = ['success' => false, 'error' => 'Unknown action'];
}

$conn->close();
echo json_encode($response);
?>

BRS/user/transaction_timeline.php

<?php
// user/transaction_timeline.php - Transaction Timeline Component

function displayTransactionTimeline($conn, $transaction_id) {
    $timeline = $conn->query("
        SELECT * FROM transaction_timeline 
        WHERE transaction_id = $transaction_id 
        ORDER BY created_at ASC
    ");
    
    if ($timeline->num_rows == 0) {
        return '<p class="text-muted">No timeline events yet.</p>';
    }
    
    $html = '<div class="timeline-container">';
    $step = 1;
    while ($event = $timeline->fetch_assoc()) {
        $status_class = '';
        if (strpos($event['status'], 'completed') !== false || strpos($event['status'], 'confirmed') !== false) {
            $status_class = 'completed';
        } elseif (strpos($event['status'], 'pending') !== false || strpos($event['status'], 'waiting') !== false) {
            $status_class = 'pending';
        } else {
            $status_class = 'active';
        }
        
        $icon = getStatusIcon($event['status']);
        
        $html .= '
        <div class="timeline-item">
            <div class="timeline-marker ' . $status_class . '">
                <i class="fas ' . $icon . '"></i>
            </div>
            <div class="timeline-content">
                <div class="timeline-title">' . ucwords(str_replace('_', ' ', $event['action'])) . '</div>
                <div class="timeline-description">' . htmlspecialchars($event['description']) . '</div>
                <div class="timeline-date">' . date('M d, Y H:i', strtotime($event['created_at'])) . '</div>
            </div>
        </div>';
        $step++;
    }
    $html .= '</div>';
    
    return $html;
}

function getStatusIcon($status) {
    $icons = [
        'created' => 'fa-plus-circle',
        'payment' => 'fa-credit-card',
        'escrow' => 'fa-shield-alt',
        'delivered' => 'fa-truck',
        'confirmed' => 'fa-check-circle',
        'completed' => 'fa-check-double',
        'disputed' => 'fa-gavel',
        'cancelled' => 'fa-times-circle',
        'approved' => 'fa-thumbs-up',
        'rejected' => 'fa-thumbs-down',
        'hired' => 'fa-user-check',
        'submitted' => 'fa-paper-plane'
    ];
    
    foreach ($icons as $key => $icon) {
        if (strpos($status, $key) !== false) {
            return $icon;
        }
    }
    return 'fa-circle';
}
?>

BRS/user/transactions.php

<?php
// user/transactions.php - Transactions Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Transactions';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get filter status
$status_filter = $_GET['status'] ?? '';

// Build query
$where = "t.buyer_id = $user_id OR t.seller_id = $user_id";
if ($status_filter) {
    $where .= " AND t.status = '$status_filter'";
}

$transactions = $conn->query("
    SELECT t.*, l.title as listing_title,
           CASE WHEN t.buyer_id = $user_id THEN 'bought' ELSE 'sold' END as action,
           CASE WHEN t.buyer_id = $user_id THEN u2.full_name ELSE u1.full_name END as other_party
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE $where
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<style>
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .filters {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    
    .filter-btn {
        padding: 8px 20px;
        background: white;
        border-radius: 30px;
        text-decoration: none;
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .filter-btn:hover, .filter-btn.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 14px 12px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
    }
    
    tr {
        cursor: pointer;
        transition: background 0.3s;
    }
    
    tr:hover {
        background: #f8fafc;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
        text-decoration: none;
        background: #667eea;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        color: #64748b;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
</style>

<div class="page-header">
    <h1>Transactions</h1>
    <p>View all your buying and selling activity</p>
</div>

<!-- Filters -->
<div class="filters">
    <a href="transactions.php" class="filter-btn <?php echo empty($status_filter) ? 'active' : ''; ?>">All</a>
    <a href="?status=pending_deposit" class="filter-btn <?php echo $status_filter == 'pending_deposit' ? 'active' : ''; ?>">Pending</a>
    <a href="?status=deposits_complete" class="filter-btn <?php echo $status_filter == 'deposits_complete' ? 'active' : ''; ?>">Deposits Complete</a>
    <a href="?status=completed" class="filter-btn <?php echo $status_filter == 'completed' ? 'active' : ''; ?>">Completed</a>
    <a href="?status=disputed" class="filter-btn <?php echo $status_filter == 'disputed' ? 'active' : ''; ?>">Disputed</a>
</div>

<div class="card">
    <div class="table-wrapper">
        <?php if ($transactions && $transactions->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Other Party</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($txn = $transactions->fetch_assoc()): ?>
                        <tr onclick="location.href='transaction.php?id=<?php echo $txn['id']; ?>'">
                            <td>#<?php echo $txn['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars(substr($txn['listing_title'], 0, 35)); ?></strong></td>
                            <td>
                                <span class="badge <?php echo $txn['action'] == 'bought' ? 'badge-info' : 'badge-success'; ?>" style="background: #dbeafe; color: #1e40af;">
                                    <?php echo ucfirst($txn['action']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($txn['other_party']); ?></td>
                            <td><strong><?php echo formatMoney($txn['total_amount']); ?></strong></td>
                            <td><?php echo getStatusBadge($txn['status']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($txn['created_at'])); ?></td>
                            <td><a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm" onclick="event.stopPropagation()">View</a></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-exchange-alt"></i>
                <h3>No transactions yet</h3>
                <p>Start buying or selling to see your transactions here.</p>
                <a href="browse.php" class="btn" style="display: inline-block; margin-top: 16px; background: #667eea; color: white; padding: 10px 24px; border-radius: 40px; text-decoration: none;">Browse Listings</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/wallet.php

<?php
// user/wallet.php - Complete Wallet Management with Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'My Wallet';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

// Get wallet transactions
$transactions = $conn->query("
    SELECT * FROM wallet_transactions 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 50
");

// Get pending withdrawals
$pending_withdrawals = $conn->query("
    SELECT * FROM withdrawal_requests 
    WHERE user_id = $user_id AND status = 'pending'
    ORDER BY created_at DESC
");

$conn->close();
?>

<style>
    .wallet-container { max-width: 1200px; margin: 0 auto; }
    .balance-card { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 28px; padding: 32px; color: white; margin-bottom: 28px; text-align: center; }
    .balance-label { font-size: 14px; opacity: 0.9; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; }
    .balance-amount { font-size: 56px; font-weight: 800; margin-bottom: 16px; }
    .balance-actions { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
    .action-btn { padding: 12px 28px; background: rgba(255,255,255,0.2); border-radius: 40px; text-decoration: none; color: white; font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; }
    .action-btn:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); }
    
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 24px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }
    
    .card { background: white; border-radius: 20px; padding: 24px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
    .card-header h3 { font-size: 18px; font-weight: 600; color: #0f172a; }
    
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; }
    
    .amount-positive { color: #10b981; font-weight: 600; }
    .amount-negative { color: #ef4444; font-weight: 600; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #1e40af; }
    .badge-completed { background: #d1fae5; color: #059669; }
    
    .empty-state { text-align: center; padding: 60px; color: #64748b; }
    .empty-state i { font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: 1fr; }
        .balance-amount { font-size: 36px; }
        .card-header { flex-direction: column; align-items: flex-start; gap: 12px; }
    }
</style>

<div class="wallet-container">
    <!-- Balance Card -->
    <div class="balance-card">
        <div class="balance-label">Available Balance</div>
        <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
        <div class="balance-actions">
            <a href="add_funds.php" class="action-btn"><i class="fas fa-plus-circle"></i> Add Funds</a>
            <a href="withdraw.php" class="action-btn"><i class="fas fa-money-bill-wave"></i> Withdraw</a>
            <a href="transactions.php" class="action-btn"><i class="fas fa-history"></i> History</a>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($transactions->num_rows); ?></div>
            <div class="stat-label">Total Transactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($pending_withdrawals->num_rows); ?></div>
            <div class="stat-label">Pending Withdrawals</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($balance); ?></div>
            <div class="stat-label">Current Balance</div>
        </div>
    </div>
    
    <!-- Pending Withdrawals -->
    <?php if ($pending_withdrawals && $pending_withdrawals->num_rows > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock"></i> Pending Withdrawals</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Date</th><th>Amount</th><th>Bank</th><th>Account</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php while($wd = $pending_withdrawals->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($wd['created_at'])); ?></td>
                        <td class="amount-negative">-<?php echo formatMoney($wd['amount']); ?></td>
                        <td><?php echo htmlspecialchars($wd['bank_name']); ?></td>
                        <td><?php echo htmlspecialchars(substr($wd['account_number'], -4)); ?></td>
                        <td><span class="badge badge-pending">Pending</span></td>
                        <td><a href="withdraw.php?cancel=<?php echo $wd['id']; ?>" class="btn-sm" style="background: #ef4444; color: white; padding: 4px 10px; border-radius: 6px; text-decoration: none;" onclick="return confirm('Cancel this withdrawal request?')">Cancel</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Transaction History -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Recent Transactions</h3>
            <a href="transactions.php" style="font-size: 12px; color: #667eea;">View All →</a>
        </div>
        <div class="table-wrapper">
            <?php if ($transactions && $transactions->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr><th>Date</th><th>Description</th><th>Amount</th><th>Type</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php while($txn = $transactions->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($txn['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($txn['description']); ?></td>
                            <td class="<?php echo $txn['amount'] > 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                <?php echo ($txn['amount'] > 0 ? '+' : '') . formatMoney($txn['amount']); ?>
                            </td>
                            <td><span class="badge" style="background: #e0e7ff; color: #4f46e5;"><?php echo ucfirst($txn['type']); ?></span></td>
                            <td><span class="badge badge-completed">Completed</span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No transactions yet</p>
                    <p style="font-size: 12px; margin-top: 8px;">When you make payments or receive funds, they'll appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/withdraw.php

<?php
// user/withdraw.php - Withdrawal Request with Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Withdraw Funds';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

$min_withdrawal = getSetting('min_withdrawal', 100);
$max_withdrawal = getSetting('max_withdrawal', 100000);

$error = '';
$success = '';

// Handle cancellation
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $wd_id = sanitizeInt($_GET['cancel']);
    $stmt = $conn->prepare("SELECT id, amount FROM withdrawal_requests WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->bind_param('ii', $wd_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $wd = $result->fetch_assoc();
        $conn->begin_transaction();
        try {
            $delete = $conn->prepare("DELETE FROM withdrawal_requests WHERE id = ?");
            $delete->bind_param('i', $wd_id);
            $delete->execute();

            $refund = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $refund->bind_param('di', $wd['amount'], $user_id);
            $refund->execute();

            $conn->commit();
            $success = "Withdrawal request cancelled. " . formatMoney($wd['amount']) . " returned to your balance.";

            $user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
            $balance = $user['balance'];
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to cancel withdrawal";
        }
    }
}

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = sanitizeFloat($_POST['amount'] ?? 0);
    $telebirr_phone = sanitizePhone($_POST['telebirr_phone'] ?? '');
    $errors = [];

    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount";
    } elseif ($amount < $min_withdrawal) {
        $errors[] = "Minimum withdrawal amount is " . formatMoney($min_withdrawal);
    } elseif ($amount > $max_withdrawal) {
        $errors[] = "Maximum withdrawal amount per request is " . formatMoney($max_withdrawal);
    } elseif ($amount > $balance) {
        $errors[] = "Insufficient balance. Your current balance is " . formatMoney($balance);
    }

    if (empty($telebirr_phone) || !validatePhone($telebirr_phone)) {
        $errors[] = "Please enter a valid Telebirr phone number";
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            $updateBalance = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            $updateBalance->bind_param('dii', $amount, $user_id, $amount);
            $updateBalance->execute();

            if ($conn->affected_rows > 0) {
                $stmt = $conn->prepare("INSERT INTO withdrawal_requests (user_id, amount, telebirr_phone, bank_name, account_number, account_name, status, created_at) VALUES (?, ?, ?, '', '', '', 'pending', NOW())");
                $stmt->bind_param('ids', $user_id, $amount, $telebirr_phone);
                $stmt->execute();

                $walletTx = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'withdrawal_pending', ?, NOW())");
                $description = "Withdrawal request pending approval to Telebirr " . $telebirr_phone;
                $walletTx->bind_param('ids', $user_id, $amount, $description);
                $walletTx->execute();

                $adminRow = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                if ($adminRow && $adminRow->num_rows > 0) {
                    $adminId = $adminRow->fetch_assoc()['id'];
                    $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, '💰 New Withdrawal Request', ?, NOW())");
                    $adminMessage = "User #$user_id requested a Telebirr withdrawal of " . formatMoney($amount) . ".";
                    $notif->bind_param('is', $adminId, $adminMessage);
                    $notif->execute();
                }

                $conn->commit();
                $success = "Withdrawal request submitted successfully! It will be processed within 24-48 hours.";

                $user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
                $balance = $user['balance'];
            } else {
                throw new Exception("Insufficient balance");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to process withdrawal: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Get recent withdrawal requests
$withdrawals = $conn->query("
    SELECT * FROM withdrawal_requests 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 10
");

$conn->close();
?>

<style>
    .withdraw-container { max-width: 800px; margin: 0 auto; }
    .balance-card { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 24px; padding: 28px; color: white; text-align: center; margin-bottom: 28px; }
    .balance-label { font-size: 13px; opacity: 0.9; margin-bottom: 8px; }
    .balance-amount { font-size: 42px; font-weight: 700; }
    
    .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .form-group { margin-bottom: 20px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 13px; }
    .required { color: #ef4444; }
    input, select { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; transition: all 0.3s; }
    input:focus, select:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; margin-top: 20px; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .info-text { font-size: 11px; color: #64748b; margin-top: 6px; }
    .limits { background: #f8fafc; padding: 12px; border-radius: 12px; margin-top: 16px; text-align: center; font-size: 12px; color: #64748b; }
    .table-wrapper { overflow-x: auto; margin-top: 20px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #1e40af; }
    .badge-completed { background: #d1fae5; color: #059669; }
    
    @media (max-width: 640px) {
        .form-row { grid-template-columns: 1fr; }
        .balance-amount { font-size: 32px; }
    }
</style>

<div class="withdraw-container">
    <!-- Balance Display -->
    <div class="balance-card">
        <div class="balance-label">Available Balance</div>
        <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Withdrawal Form -->
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;"><i class="fas fa-money-bill-wave"></i> Request Withdrawal</h2>
        
        <form method="POST">
            <div class="form-group">
                <label>Amount (ETB) <span class="required">*</span></label>
                <input type="number" name="amount" step="0.01" min="<?php echo $min_withdrawal; ?>" max="<?php echo min($max_withdrawal, $balance); ?>" required placeholder="0.00">
                <div class="info-text">Min: <?php echo formatMoney($min_withdrawal); ?> | Max: <?php echo formatMoney(min($max_withdrawal, $balance)); ?></div>
            </div>
            
            <div class="form-group">
                <label>Telebirr Phone Number <span class="required">*</span></label>
                <input type="text" name="telebirr_phone" required placeholder="e.g. +251912345678">
                <div class="info-text">Enter the Telebirr phone number where funds should be transferred.</div>
            </div>
            
            <div class="limits">
                <i class="fas fa-clock"></i> Withdrawals are processed within 24-48 hours
            </div>
            
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit Withdrawal Request</button>
        </form>
    </div>
    
    <!-- Recent Withdrawal Requests -->
    <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 16px;"><i class="fas fa-history"></i> Recent Withdrawal Requests</h2>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Date</th><th>Amount</th><th>Telebirr Phone</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php while($wd = $withdrawals->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($wd['created_at'])); ?></td>
                        <td class="amount-negative">-<?php echo formatMoney($wd['amount']); ?></td>
                        <td><?php echo htmlspecialchars($wd['telebirr_phone'] ?: $wd['bank_name']); ?></td>
                        <td>
                            <?php
                            $badge_class = match($wd['status']) {
                                'pending' => 'badge-pending',
                                'approved' => 'badge-approved',
                                'completed' => 'badge-completed',
                                default => ''
                            };
                            ?>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($wd['status']); ?></span>
                        </td>
                        <td>
                            <?php if ($wd['status'] == 'pending'): ?>
                                <a href="?cancel=<?php echo $wd['id']; ?>" class="btn-sm" style="background: #ef4444; color: white; padding: 4px 10px; border-radius: 6px; text-decoration: none;" onclick="return confirm('Cancel this withdrawal request?')">Cancel</a>
                            <?php else: ?>
                                <span style="color: #64748b; font-size: 11px;">Processed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

BRS/user/withdrawal_approval.php

<?php
// admin/withdrawal_approval.php - Admin approve/reject withdrawals

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';
require_once '../includes/telebirr_simulation.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Withdrawal Approvals';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $withdrawal_id = intval($_POST['withdrawal_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $admin_notes = sanitizeString($_POST['admin_notes'] ?? '');
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("SELECT user_id, amount, telebirr_phone FROM withdrawal_requests WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $withdrawal_id);
        $stmt->execute();
        $wd = $stmt->get_result()->fetch_assoc();

        if (!$wd) {
            $error = "Withdrawal request not found or already processed";
        } elseif (empty($wd['telebirr_phone'])) {
            $error = "Telebirr phone number is required to approve this withdrawal";
        } else {
            $transferError = null;
            $transfer = performTelebirrTransfer(getPlatformTelebirrPhone(), $wd['telebirr_phone'], $wd['amount'], 'Withdrawal payout', null, $transferError);

            if ($transfer === false) {
                $conn->begin_transaction();
                try {
                    $reference = generateTelebirrTransferReference();
                    $update = $conn->prepare("UPDATE withdrawal_requests SET status = 'failed', telebirr_transfer_reference = ?, telebirr_transfer_status = 'failed', telebirr_sender_phone = ?, telebirr_receiver_phone = ?, telebirr_transfer_amount = ?, telebirr_transfer_message = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                    $update->bind_param('sssdssii', $reference, getPlatformTelebirrPhone(), $wd['telebirr_phone'], $wd['amount'], $transferError, $admin_notes, $_SESSION['user_id'], $withdrawal_id);
                    $update->execute();

                    $refund = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $refund->bind_param('di', $wd['amount'], $wd['user_id']);
                    $refund->execute();

                    $walletTx = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'withdrawal_refund', ?, NOW())");
                    $walletDesc = "Refund after failed Telebirr transfer for withdrawal #" . $withdrawal_id;
                    $walletTx->bind_param('ids', $wd['user_id'], $wd['amount'], $walletDesc);
                    $walletTx->execute();

                    $conn->commit();
                    $error = "Telebirr transfer failed: " . $transferError . " — amount refunded.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to mark withdrawal failed: " . $e->getMessage();
                }
            } else {
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare("UPDATE withdrawal_requests SET status = 'approved', telebirr_transfer_reference = ?, telebirr_transfer_status = 'success', telebirr_sender_phone = ?, telebirr_receiver_phone = ?, telebirr_transfer_amount = ?, telebirr_transfer_message = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                    $update->bind_param('sssdssii', $transfer['reference'], getPlatformTelebirrPhone(), $wd['telebirr_phone'], $transfer['amount'], $transfer['description'], $admin_notes, $_SESSION['user_id'], $withdrawal_id);
                    $update->execute();

                    $walletQuery = $conn->prepare("SELECT id FROM wallet_transactions WHERE user_id = ? AND amount = ? AND type = 'withdrawal_pending' ORDER BY created_at DESC LIMIT 1");
                    $walletQuery->bind_param('id', $wd['user_id'], $wd['amount']);
                    $walletQuery->execute();
                    $walletRow = $walletQuery->get_result();

                    $walletDescription = "Withdrawal approved and sent to Telebirr " . $wd['telebirr_phone'];
                    if ($walletRow && $walletRow->num_rows > 0) {
                        $tx = $walletRow->fetch_assoc();
                        $walletUpdate = $conn->prepare("UPDATE wallet_transactions SET type = 'withdrawal_approved', description = ? WHERE id = ?");
                        $walletUpdate->bind_param('si', $walletDescription, $tx['id']);
                        $walletUpdate->execute();
                    } else {
                        $walletTx = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'withdrawal_approved', ?, NOW())");
                        $walletTx->bind_param('ids', $wd['user_id'], $wd['amount'], $walletDescription);
                        $walletTx->execute();
                    }

                    $notify = $conn->prepare("INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, '✅ Withdrawal Approved', ?, NOW())");
                    $notifyMessage = 'Your withdrawal of ' . formatMoney($wd['amount']) . ' has been approved and will be transferred to Telebirr ' . $wd['telebirr_phone'] . '.';
                    $notify->bind_param('is', $wd['user_id'], $notifyMessage);
                    $notify->execute();

                    $conn->commit();
                    $message = "Withdrawal approved and Telebirr transfer completed successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to approve withdrawal: " . $e->getMessage();
                }
            }
        }
    }
    
    if ($action === 'reject') {
        $stmt = $conn->prepare("SELECT user_id, amount FROM withdrawal_requests WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $withdrawal_id);
        $stmt->execute();
        $wd = $stmt->get_result()->fetch_assoc();

        if (!$wd) {
            $error = "Withdrawal request not found or already processed";
        } else {
            $conn->begin_transaction();
            try {
                $refund = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $refund->bind_param('di', $wd['amount'], $wd['user_id']);
                $refund->execute();

                $updateRequest = $conn->prepare("UPDATE withdrawal_requests SET status = 'rejected', admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                $updateRequest->bind_param('sii', $admin_notes, $_SESSION['user_id'], $withdrawal_id);
                $updateRequest->execute();

                $walletDescription = 'Withdrawal rejected: ' . $admin_notes;
                $walletUpdate = $conn->prepare("UPDATE wallet_transactions SET type = 'withdrawal_rejected', description = ? WHERE user_id = ? AND amount = ? AND type = 'withdrawal_pending' ORDER BY id DESC LIMIT 1");
                $walletUpdate->bind_param('sid', $walletDescription, $wd['user_id'], $wd['amount']);
                $walletUpdate->execute();

                $notify = $conn->prepare("INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, '❌ Withdrawal Rejected', ?, NOW())");
                $notifyMessage = 'Your withdrawal request was rejected. Reason: ' . $admin_notes;
                $notify->bind_param('is', $wd['user_id'], $notifyMessage);
                $notify->execute();

                $conn->commit();
                $message = "Withdrawal rejected and amount refunded";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to reject withdrawal: " . $e->getMessage();
            }
        }
    }
}

// Get pending withdrawals
$pending_withdrawals = $conn->query("
    SELECT w.*, u.full_name, u.email, u.phone
    FROM withdrawal_requests w
    JOIN users u ON w.user_id = u.id
    WHERE w.status = 'pending'
    ORDER BY w.created_at ASC
");

// Get approved/completed withdrawals
$completed_withdrawals = $conn->query("
    SELECT w.*, u.full_name, u.email
    FROM withdrawal_requests w
    JOIN users u ON w.user_id = u.id
    WHERE w.status IN ('approved', 'completed', 'rejected')
    ORDER BY w.created_at DESC
    LIMIT 20
");

$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc()['count'],
    'pending_amount' => $conn->query("SELECT SUM(amount) as total FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc()['total'] ?? 0,
    'approved_today' => $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests WHERE status = 'approved' AND DATE(processed_at) = CURDATE()")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }
    
    .withdrawal-card {
        background: white;
        border-radius: 20px;
        margin-bottom: 20px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    .card-header {
        padding: 16px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .card-body { padding: 20px; }
    
    .btn-group { display: flex; gap: 10px; margin-top: 16px; }
    .btn {
        padding: 8px 16px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        border: none;
    }
    .btn-success { background: #10b981; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .modal-content {
        background: white;
        border-radius: 20px;
        padding: 24px;
        width: 450px;
        max-width: 90%;
    }
    .form-group { margin-bottom: 16px; }
    .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
</style>

<div>
    <h1 style="margin-bottom: 20px;"><i class="fas fa-money-bill-wave"></i> Withdrawal Approvals</h1>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($stats['pending_amount']); ?></div>
            <div class="stat-label">Pending Amount</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['approved_today']; ?></div>
            <div class="stat-label">Approved Today</div>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✓ <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <h2 style="margin: 24px 0 16px;">Pending Withdrawals</h2>
    
    <?php if ($pending_withdrawals->num_rows > 0): ?>
        <?php while($wd = $pending_withdrawals->fetch_assoc()): ?>
            <div class="withdrawal-card">
                <div class="card-header">
                    <div>
                        <strong><?php echo htmlspecialchars($wd['full_name']); ?></strong><br>
                        <small><?php echo htmlspecialchars($wd['email']); ?></small>
                    </div>
                    <div class="amount" style="font-size: 20px; font-weight: 700; color: #667eea;">
                        <?php echo formatMoney($wd['amount']); ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <span>Telebirr Phone:</span>
                        <strong><?php echo htmlspecialchars($wd['telebirr_phone'] ?: $wd['bank_name']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Requested:</span>
                        <span><?php echo date('M d, Y H:i', strtotime($wd['created_at'])); ?></span>
                    </div>
                    
                    <div class="btn-group">
                        <button onclick="openActionModal('approve', <?php echo $wd['id']; ?>)" class="btn btn-success">
                            <i class="fas fa-check"></i> Approve Withdrawal
                        </button>
                        <button onclick="openActionModal('reject', <?php echo $wd['id']; ?>)" class="btn btn-danger">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; background: white; border-radius: 20px;">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 16px; display: block;"></i>
            <p>No pending withdrawal requests.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Action Modal -->
<div id="actionModal" class="modal">
    <div class="modal-content">
        <h3 id="modalTitle" style="margin-bottom: 16px;">Process Withdrawal</h3>
        <form method="POST">
            <input type="hidden" name="withdrawal_id" id="withdrawalId">
            <input type="hidden" name="action" id="actionType">
            <div class="form-group">
                <label>Admin Notes</label>
                <textarea name="admin_notes" rows="3" placeholder="Add notes about this decision..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" id="actionButton" class="btn btn-success">Confirm</button>
                <button type="button" onclick="closeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openActionModal(action, id) {
    document.getElementById('withdrawalId').value = id;
    document.getElementById('actionType').value = action;
    const modalTitle = document.getElementById('modalTitle');
    const actionButton = document.getElementById('actionButton');
    
    if (action === 'approve') {
        modalTitle.innerHTML = '<i class="fas fa-check"></i> Approve Withdrawal';
        actionButton.innerHTML = 'Approve';
        actionButton.className = 'btn btn-success';
    } else {
        modalTitle.innerHTML = '<i class="fas fa-times"></i> Reject Withdrawal';
        actionButton.innerHTML = 'Reject';
        actionButton.className = 'btn btn-danger';
    }
    document.getElementById('actionModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('actionModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>

BRS/user/withdrawal_request.php

<?php
// user/withdrawal_request.php - Request withdrawal from wallet

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Request Withdrawal';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

$min_withdrawal = getSetting('min_withdrawal', 100);
$max_withdrawal = getSetting('max_withdrawal', 100000);

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount'] ?? 0);
    $telebirr_phone = sanitizePhone($_POST['telebirr_phone'] ?? '');
    
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount";
    } elseif ($amount < $min_withdrawal) {
        $errors[] = "Minimum withdrawal amount is " . formatMoney($min_withdrawal);
    } elseif ($amount > $max_withdrawal) {
        $errors[] = "Maximum withdrawal amount is " . formatMoney($max_withdrawal);
    } elseif ($amount > $balance) {
        $errors[] = "Insufficient balance. Your balance is " . formatMoney($balance);
    }

    if (empty($telebirr_phone) || !validatePhone($telebirr_phone)) {
        $errors[] = "Please enter a valid Telebirr phone number";
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            $updateBalance = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            $updateBalance->bind_param('dii', $amount, $user_id, $amount);
            $updateBalance->execute();

            if ($conn->affected_rows > 0) {
                $stmt = $conn->prepare("INSERT INTO withdrawal_requests (user_id, amount, telebirr_phone, bank_name, account_number, account_name, status, created_at) VALUES (?, ?, ?, '', '', '', 'pending', NOW())");
                $stmt->bind_param('ids', $user_id, $amount, $telebirr_phone);
                $stmt->execute();
                
                $walletTx = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'withdrawal_pending', ?, NOW())");
                $description = "Withdrawal request pending approval to Telebirr " . $telebirr_phone;
                $walletTx->bind_param('ids', $user_id, $amount, $description);
                $walletTx->execute();

                $adminRow = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                if ($adminRow && $adminRow->num_rows > 0) {
                    $adminId = $adminRow->fetch_assoc()['id'];
                    $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, '💰 New Withdrawal Request', ?, NOW())");
                    $adminMessage = "User #$user_id requested a Telebirr withdrawal of " . formatMoney($amount) . ".";
                    $notif->bind_param('is', $adminId, $adminMessage);
                    $notif->execute();
                }

                $conn->commit();
                $success = "Withdrawal request submitted successfully! Admin will process within 24-48 hours.";
                
                // Refresh balance
                $balance = $balance - $amount;
            } else {
                throw new Exception("Insufficient balance");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to process withdrawal: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Get recent withdrawal requests
$withdrawals = $conn->query("
    SELECT * FROM withdrawal_requests 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 10
");

$conn->close();
?>

<style>
    .withdraw-container { max-width: 800px; margin: 0 auto; }
    .balance-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 24px;
        padding: 28px;
        color: white;
        text-align: center;
        margin-bottom: 28px;
    }
    .balance-amount { font-size: 48px; font-weight: 700; margin: 16px 0; }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
    .form-group input, .form-group select {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }
    
    .btn-submit {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #1e40af; }
    .badge-completed { background: #d1fae5; color: #059669; }
    
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    
    @media (max-width: 640px) {
        .balance-amount { font-size: 32px; }
        .card { padding: 20px; }
    }
</style>

<div class="withdraw-container">
    <div class="balance-card">
        <h3><i class="fas fa-wallet"></i> Available Balance</h3>
        <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
        <p>Minimum withdrawal: <?php echo formatMoney($min_withdrawal); ?></p>
    </div>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h3 style="margin-bottom: 20px;"><i class="fas fa-money-bill-wave"></i> Request Withdrawal</h3>
        <form method="POST">
            <div class="form-group">
                <label>Amount (ETB)</label>
                <input type="number" name="amount" step="100" min="<?php echo $min_withdrawal; ?>" max="<?php echo min($max_withdrawal, $balance); ?>" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Telebirr Phone Number</label>
                <input type="text" name="telebirr_phone" required placeholder="e.g. +251912345678">
                <div class="info-text">Enter the Telebirr phone number where funds should be transferred.</div>
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </form>
    </div>
    
    <?php if ($withdrawals->num_rows > 0): ?>
    <div class="card">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-history"></i> Recent Withdrawal Requests</h3>
        <table>
            <thead>
                <tr><th>Date</th><th>Amount</th><th>Telebirr Phone</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php while($wd = $withdrawals->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($wd['created_at'])); ?></td>
                    <td class="amount-negative">-<?php echo formatMoney($wd['amount']); ?></td>
                    <td><?php echo htmlspecialchars($wd['telebirr_phone'] ?: $wd['bank_name']); ?></td>
                    <td>
                        <span class="badge badge-<?php echo $wd['status']; ?>">
                            <?php echo ucfirst($wd['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>

