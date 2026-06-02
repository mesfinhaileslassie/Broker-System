BRS/admin/ajax/get_negotiation_messages.php

<?php
// ============================================
// FILE: broker_system/admin/ajax/get_negotiation_messages.php
// ============================================

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$negotiation_id = intval($_GET['id'] ?? 0);
if (!$negotiation_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid negotiation ID']);
    exit;
}

$conn = getDbConnection();

$result = $conn->query("
    SELECT nm.*, 
           CASE 
               WHEN nm.sender_type = 'admin' THEN 'admin'
               WHEN nm.sender_type = 'seller' THEN 'seller'
               ELSE 'system'
           END as sender_type
    FROM negotiation_messages nm
    WHERE nm.negotiation_id = $negotiation_id
    ORDER BY nm.created_at ASC
");

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'sender_type' => $row['sender_type'],
        'message' => $row['message'],
        'time' => date('M d, H:i', strtotime($row['created_at']))
    ];
}

$conn->close();
echo json_encode(['success' => true, 'messages' => $messages]);
?>

BRS/admin/ajax/get_user_details.php

<?php
// admin/ajax/get_user_details.php - Get user details for modal

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = intval($_GET['id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

$conn = getDbConnection();

$user = $conn->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM listings WHERE seller_id = u.id) as total_listings,
           (SELECT COUNT(*) FROM transactions WHERE buyer_id = u.id OR seller_id = u.id) as total_transactions
    FROM users u
    WHERE u.id = $user_id
")->fetch_assoc();

$conn->close();

if ($user) {
    echo json_encode(['success' => true, 'user' => $user]);
} else {
    echo json_encode(['success' => false, 'error' => 'User not found']);
}
?>

BRS/ajax/search_users.php

<?php
// ajax/search_users.php - AJAX endpoint for real-time user search

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = getDbConnection();

// Get search parameters
$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$verification = $_GET['verification'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where = [];
$params = [];
$types = "";

// Search across multiple fields
if (!empty($search)) {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($role)) {
    $where[] = "role = ?";
    $params[] = $role;
    $types .= "s";
}

if ($status === 'active') {
    $where[] = "is_suspended = 0";
} elseif ($status === 'banned') {
    $where[] = "is_suspended = 1";
}

if ($verification === 'verified') {
    $where[] = "is_verified = 1";
} elseif ($verification === 'unverified') {
    $where[] = "is_verified = 0";
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$countSql = "SELECT COUNT(*) as total FROM users $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Get users
$sql = "SELECT id, full_name, email, phone, city, role, balance, is_verified, is_suspended, created_at 
        FROM users $whereClause 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        'id' => $row['id'],
        'full_name' => $row['full_name'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'city' => $row['city'],
        'role' => $row['role'],
        'balance' => $row['balance'],
        'is_verified' => (bool)$row['is_verified'],
        'is_suspended' => (bool)$row['is_suspended'],
        'created_at' => $row['created_at']
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'users' => $users,
    'total' => $total,
    'total_pages' => $totalPages,
    'current_page' => $page,
    'search_term' => $search
]);
?>

BRS/api/check_availability.php

<?php
// ============================================
// FILE: api/check_availability.php
// Description: Check if listing is available for dates
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../config/database.php';
require_once '../includes/AvailabilityManager.php';

$input = json_decode(file_get_contents('php://input'), true);

$listing_id = isset($input['listing_id']) ? intval($input['listing_id']) : 0;
$check_in = isset($input['check_in']) ? $input['check_in'] : '';
$check_out = isset($input['check_out']) ? $input['check_out'] : '';

if (!$listing_id || !$check_in || !$check_out) {
    echo json_encode(['available' => false, 'message' => 'Missing required parameters']);
    exit;
}

$conn = getDbConnection();
$availabilityManager = new AvailabilityManager($conn);

$available = $availabilityManager->isAvailable($listing_id, $check_in, $check_out);

echo json_encode([
    'available' => $available,
    'message' => $available ? 'Available for selected dates' : 'Not available for selected dates'
]);

$conn->close();
?>

BRS/api/check_payment_fast.php

<?php
// api/check_payment_fast.php - ULTRA FAST payment check (no JOINs)

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

// Validate request
if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['confirmed' => false, 'error' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['confirmed' => false, 'error' => 'invalid_code']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// STEP 1: Check payment_codes table (lightweight, indexed)
$code_check = $conn->query("
    SELECT 
        pc.id,
        pc.status,
        pc.transaction_id,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($code_check->num_rows === 0) {
    echo json_encode(['confirmed' => false, 'error' => 'code_not_found']);
    $conn->close();
    exit;
}

$code_data = $code_check->fetch_assoc();

// Check if expired
if ($code_data['seconds_remaining'] <= 0) {
    echo json_encode(['confirmed' => false, 'expired' => true]);
    $conn->close();
    exit;
}

// STEP 2: Check if code is already marked as used
if ($code_data['status'] === 'used') {
    echo json_encode(['confirmed' => true, 'code_used' => true]);
    $conn->close();
    exit;
}

// STEP 3: Check payments table - FAST lookup by telebirr_code
$payment_check = $conn->query("
    SELECT id, status 
    FROM payments 
    WHERE telebirr_code_5digit = '$code' 
    AND user_id = $user_id
    AND type = 'deposit_seller'
    AND status = 'confirmed'
    LIMIT 1
");

if ($payment_check->num_rows > 0) {
    // Payment confirmed! Update everything atomically
    $payment_data = $payment_check->fetch_assoc();
    
    // Get transaction and listing info
    $txn_info = $conn->query("
        SELECT t.listing_id, t.id as transaction_id
        FROM transactions t
        WHERE t.id = {$code_data['transaction_id']}
        LIMIT 1
    ")->fetch_assoc();
    
    if ($txn_info) {
        // Atomic update - all in one transaction
        $conn->begin_transaction();
        
        try {
            // Update payment_codes
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$code_data['id']}");
            
            // Activate listing
            $conn->query("UPDATE listings SET status = 'active' WHERE id = {$txn_info['listing_id']}");
            
            // Update transaction if needed
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$txn_info['transaction_id']}");
            
            $conn->commit();
            
            echo json_encode([
                'confirmed' => true, 
                'listing_activated' => true,
                'seconds_remaining' => $code_data['seconds_remaining']
            ]);
            $conn->close();
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['confirmed' => false, 'error' => 'update_failed']);
            $conn->close();
            exit;
        }
    }
}

// Not confirmed yet
echo json_encode([
    'confirmed' => false, 
    'seconds_remaining' => $code_data['seconds_remaining'],
    'code_status' => $code_data['status']
]);

$conn->close();
?>

BRS/api/check_payment_safe.php

<?php
// api/check_payment_safe.php - Server-authoritative payment check

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['confirmed' => false, 'error' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['confirmed' => false, 'error' => 'invalid_code']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// STEP 1: Get payment code and check expiration using MySQL (SERVER AUTHORITY)
$code_check = $conn->query("
    SELECT 
        pc.id,
        pc.status,
        pc.transaction_id,
        pc.user_id,
        pc.amount,
        UNIX_TIMESTAMP(pc.expires_at) * 1000 as expires_at_ms,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining,
        CASE 
            WHEN pc.expires_at > NOW() THEN 1 
            ELSE 0 
        END as is_valid
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($code_check->num_rows === 0) {
    echo json_encode([
        'confirmed' => false, 
        'expired' => false,
        'code_exists' => false,
        'error' => 'code_not_found'
    ]);
    $conn->close();
    exit;
}

$code_data = $code_check->fetch_assoc();

// STEP 2: Check if code is expired (USING MYSQL - SINGLE SOURCE OF TRUTH)
$is_expired = ($code_data['is_valid'] == 0);

if ($is_expired) {
    echo json_encode([
        'confirmed' => false,
        'expired' => true,
        'expires_at' => $code_data['expires_at_ms'],
        'seconds_remaining' => 0,
        'message' => 'Code has expired'
    ]);
    $conn->close();
    exit;
}

// STEP 3: Check if code is already used
if ($code_data['status'] === 'used') {
    echo json_encode([
        'confirmed' => true,
        'already_used' => true,
        'seconds_remaining' => $code_data['seconds_remaining']
    ]);
    $conn->close();
    exit;
}

// STEP 4: Check for confirmed payment (FAST lookup)
$payment_check = $conn->query("
    SELECT p.id, p.status, p.confirmed_at
    FROM payments p
    WHERE p.telebirr_code_5digit = '$code' 
    AND p.user_id = $user_id
    AND p.type = 'deposit_seller'
    AND p.status = 'confirmed'
    LIMIT 1
");

if ($payment_check->num_rows > 0) {
    // Payment confirmed! Activate listing
    $payment_data = $payment_check->fetch_assoc();
    
    // Get transaction and listing info
    $txn_info = $conn->query("
        SELECT t.listing_id, t.id as transaction_id
        FROM transactions t
        WHERE t.id = {$code_data['transaction_id']}
        LIMIT 1
    ")->fetch_assoc();
    
    if ($txn_info) {
        $conn->begin_transaction();
        
        try {
            $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$code_data['id']}");
            $conn->query("UPDATE listings SET status = 'active' WHERE id = {$txn_info['listing_id']}");
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$txn_info['transaction_id']}");
            $conn->commit();
            
            echo json_encode([
                'confirmed' => true,
                'seconds_remaining' => $code_data['seconds_remaining'],
                'expires_at' => $code_data['expires_at_ms']
            ]);
            $conn->close();
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['confirmed' => false, 'error' => 'activation_failed']);
            $conn->close();
            exit;
        }
    }
}

// Not confirmed yet - return server time for sync
echo json_encode([
    'confirmed' => false,
    'seconds_remaining' => max(0, $code_data['seconds_remaining']),
    'expires_at' => $code_data['expires_at_ms'],
    'is_valid' => true,
    'server_time' => time() * 1000
]);

$conn->close();
?>

BRS/api/check_payment_status.php

<?php
// api/check_payment_status.php - SINGLE SOURCE OF TRUTH

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['status' => 'error', 'message' => 'invalid_code']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// SINGLE QUERY - Gets ALL status information
$result = $conn->query("
    SELECT 
        pc.id,
        pc.code,
        pc.status as code_status,
        pc.user_id,
        pc.transaction_id,
        pc.amount,
        UNIX_TIMESTAMP(pc.expires_at) * 1000 as expires_at_ms,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining,
        CASE 
            WHEN pc.status = 'used' THEN 'used'
            WHEN pc.expires_at <= NOW() THEN 'expired'
            WHEN pc.expires_at > NOW() THEN 'active'
            ELSE 'unknown'
        END as calculated_status,
        -- Check if payment is confirmed
        EXISTS(
            SELECT 1 FROM payments p 
            WHERE p.telebirr_code_5digit = pc.code 
            AND p.user_id = pc.user_id 
            AND p.type = 'deposit_seller'
            AND p.status = 'confirmed'
        ) as is_paid,
        -- Check if listing is active
        EXISTS(
            SELECT 1 FROM transactions t
            JOIN listings l ON t.listing_id = l.id
            WHERE t.id = pc.transaction_id 
            AND l.status = 'active'
        ) as listing_active
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($result->num_rows === 0) {
    echo json_encode([
        'status' => 'not_found',
        'valid' => false,
        'message' => 'Payment code not found'
    ]);
    $conn->close();
    exit;
}

$data = $result->fetch_assoc();

// Build response based on backend authority ONLY
$response = [
    'code' => $data['code'],
    'status' => $data['calculated_status'],
    'valid' => ($data['calculated_status'] === 'active'),
    'is_paid' => (bool)$data['is_paid'],
    'listing_active' => (bool)$data['listing_active'],
    'seconds_remaining' => max(0, intval($data['seconds_remaining'])),
    'expires_at' => intval($data['expires_at_ms']),
    'server_time' => time() * 1000
];

// If payment is confirmed, trigger activation
if ($data['is_paid'] && !$data['listing_active']) {
    // Get transaction and listing info for activation
    $txn_info = $conn->query("
        SELECT t.listing_id, t.id as transaction_id
        FROM transactions t
        WHERE t.id = {$data['transaction_id']}
    ")->fetch_assoc();
    
    if ($txn_info) {
        $conn->query("UPDATE listings SET status = 'active' WHERE id = {$txn_info['listing_id']}");
        $conn->query("UPDATE payment_codes SET status = 'used' WHERE id = {$data['id']}");
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$txn_info['transaction_id']}");
        $response['listing_activated'] = true;
    }
}

$conn->close();
echo json_encode($response);
?>

BRS/api/check_transaction_status.php

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

BRS/api/confirm_delivery.php

<?php
// api/confirm_delivery.php - Buyer confirms delivery

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
    SELECT t.*, l.seller_id 
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.id = $transaction_id AND t.buyer_id = $user_id
")->fetch_assoc();

if (!$txn) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

if ($txn['status'] != 'deposits_complete') {
    echo json_encode(['success' => false, 'error' => 'Cannot confirm delivery at this stage']);
    exit;
}

$conn->begin_transaction();

try {
    // Set buyer confirmation
    $conn->query("UPDATE transactions SET buyer_confirmed = 1 WHERE id = $transaction_id");
    
    // Check if seller also confirmed
    $check = $conn->query("SELECT buyer_confirmed, seller_confirmed FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    
    if ($check['buyer_confirmed'] && $check['seller_confirmed']) {
        // Both confirmed - release payment to seller
        $release_amount = $txn['total_amount'] - $txn['commission_amount'];
        
        // Release from admin escrow to seller
        $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$txn['seller_id']}");
        $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
        $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW(), escrow_released = 1 WHERE id = $transaction_id");
        
        echo json_encode(['success' => true, 'message' => 'Both parties confirmed! Payment released to seller.']);
    } else {
        $conn->query("UPDATE transactions SET status = 'in_progress' WHERE id = $transaction_id");
        echo json_encode(['success' => true, 'message' => 'Delivery confirmed. Waiting for seller confirmation.']);
    }
    
    $conn->commit();
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>

BRS/api/confirm_delivery_escrow.php

<?php
// api/confirm_delivery_escrow.php - Handle delivery confirmation

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/escrow_functions.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = intval($input['transaction_id'] ?? 0);
$action = $input['action'] ?? '';
$user_id = $_SESSION['user_id'];
$notes = $input['notes'] ?? '';

if (!$transaction_id) {
    echo json_encode(['success' => false, 'error' => 'Transaction ID required']);
    exit;
}

$conn = getDbConnection();

if ($action === 'deliver') {
    // Seller marks as delivered
    $result = markDelivery($conn, $transaction_id, $user_id, $notes);
    
    if ($result['success']) {
        // Get buyer info for notification
        $transaction = $conn->query("SELECT buyer_id, title FROM transactions t JOIN listings l ON t.listing_id = l.id WHERE t.id = $transaction_id")->fetch_assoc();
        
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, '📦 Item Delivered', 'The seller has marked your item as delivered. Please confirm receipt to release payment.', NOW())
        ");
        $notif_stmt->bind_param("i", $transaction['buyer_id']);
        $notif_stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Delivery confirmed. Waiting for buyer confirmation.']);
    } else {
        echo json_encode($result);
    }
    
} elseif ($action === 'confirm') {
    // Buyer confirms receipt
    $result = confirmReceiptAndRelease($conn, $transaction_id, $user_id, $notes);
    echo json_encode($result);
    
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

$conn->close();
?>

BRS/api/confirm_escrow_payment.php

<?php
// admin/escrow_management.php - Complete Admin Escrow Dashboard

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Escrow Management';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'release') {
        $result = adminReleasePayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['release_notes'] ?? '');
        if ($result['success']) {
            $message = "✓ Payment released successfully!";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'freeze') {
        adminFreezeTransaction($conn, $transaction_id, $_SESSION['user_id'], $_POST['freeze_reason'] ?? '');
        $message = "❄️ Transaction frozen successfully.";
    }
    
    if ($action === 'unfreeze') {
        adminUnfreezeTransaction($conn, $transaction_id, $_SESSION['user_id']);
        $message = "🔥 Transaction unfrozen successfully.";
    }
    
    if ($action === 'refund') {
        $result = refundEscrowPayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['refund_notes'] ?? '');
        if ($result['success']) {
            $message = "💰 Refund processed successfully.";
        } else {
            $error = $result['error'];
        }
    }
}

// Process auto-release queue
$auto_released = processAutoReleaseQueue($conn);

// Get escrow summary
$summary = [
    'total_held' => $conn->query("SELECT SUM(amount) as total FROM escrow_accounts WHERE status = 'held'")->fetch_assoc()['total'] ?? 0,
    'total_released' => $conn->query("SELECT SUM(amount) as total FROM escrow_accounts WHERE status = 'released'")->fetch_assoc()['total'] ?? 0,
    'active_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE escrow_status = 'active'")->fetch_assoc()['count'],
    'pending_release' => $conn->query("SELECT COUNT(*) as count FROM escrow_release_queue WHERE status = 'pending' AND scheduled_release_date <= NOW()")->fetch_assoc()['count']
];

// Get all escrow transactions with complete details
$escrow_transactions = $conn->query("
    SELECT t.*, 
           l.title, 
           l.type, 
           u1.full_name as buyer_name, 
           u2.full_name as seller_name,
           ea.id as escrow_id,
           ea.amount as escrow_amount,
           ea.status as escrow_account_status,
           ea.created_at as escrow_created_at,
           eq.scheduled_release_date,
           (SELECT COUNT(*) FROM transaction_timeline tt WHERE tt.transaction_id = t.id) as timeline_count,
           (SELECT SUM(amount) FROM payments WHERE transaction_id = t.id AND status = 'confirmed') as total_paid
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    LEFT JOIN escrow_accounts ea ON t.id = ea.transaction_id
    LEFT JOIN escrow_release_queue eq ON t.id = eq.transaction_id AND eq.status = 'pending'
    WHERE t.escrow_held > 0 OR t.escrow_status = 'active' OR ea.id IS NOT NULL
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Escrow Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        
        .admin-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 28px;
            color: white;
        }
        .header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }
        
        .escrow-card {
            background: white;
            border-radius: 24px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .escrow-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .escrow-body { padding: 20px 24px; }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-active { background: #dbeafe; color: #1e40af; }
        .badge-frozen { background: #fee2e2; color: #dc2626; }
        .badge-completed { background: #d1fae5; color: #059669; }
        .badge-pending { background: #fed7aa; color: #ea580c; }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-row:last-child { border-bottom: none; }
        
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
        
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
        
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #059669; }
        .alert-error { background: #fee2e2; color: #dc2626; }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 20px;
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .escrow-header { flex-direction: column; align-items: flex-start; }
            .btn-group { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="header">
        <h1><i class="fas fa-shield-alt"></i> Escrow Management Dashboard</h1>
        <p>Monitor and manage all escrow transactions</p>
    </div>
    
    <?php if ($auto_released > 0): ?>
        <div class="alert alert-success">✓ <?php echo $auto_released; ?> payment(s) auto-released.</div>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✓ <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_held']); ?></div>
            <div class="stat-label">Total Escrow Held</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_released']); ?></div>
            <div class="stat-label">Total Released</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['active_transactions']; ?></div>
            <div class="stat-label">Active Escrow</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['pending_release']; ?></div>
            <div class="stat-label">Pending Release</div>
        </div>
    </div>
    
    <h2 style="margin-bottom: 20px;">Escrow Transactions</h2>
    
    <?php if ($escrow_transactions && $escrow_transactions->num_rows > 0): ?>
        <?php while($txn = $escrow_transactions->fetch_assoc()): ?>
            <div class="escrow-card">
                <div class="escrow-header">
                    <div>
                        <strong>#<?php echo $txn['id']; ?></strong> - <?php echo htmlspecialchars($txn['title']); ?>
                        <span class="badge <?php 
                            if ($txn['admin_frozen']) echo 'badge-frozen';
                            elseif ($txn['status'] == 'completed') echo 'badge-completed';
                            elseif ($txn['escrow_status'] == 'active') echo 'badge-active';
                            else echo 'badge-pending';
                        ?>" style="margin-left: 10px;">
                            <?php 
                            if ($txn['admin_frozen']) echo '❄️ Frozen';
                            elseif ($txn['status'] == 'completed') echo '✓ Completed';
                            elseif ($txn['escrow_status'] == 'active') echo '💰 Escrow Active';
                            else echo '⏳ Pending';
                            ?>
                        </span>
                    </div>
                    <div><strong><?php echo formatMoney($txn['total_amount']); ?></strong></div>
                </div>
                <div class="escrow-body">
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;">
                        <div><small>Buyer:</small><br><strong><?php echo htmlspecialchars($txn['buyer_name']); ?></strong></div>
                        <div><small>Seller:</small><br><strong><?php echo htmlspecialchars($txn['seller_name']); ?></strong></div>
                        <div><small>Escrow Amount:</small><br><strong><?php echo formatMoney($txn['escrow_amount'] ?? 0); ?></strong></div>
                    </div>
                    
                    <div class="info-row">
                        <span>Escrow Status:</span>
                        <span><?php echo ucfirst($txn['escrow_status'] ?? 'pending'); ?></span>
                    </div>
                    <div class="info-row">
                        <span>Total Paid:</span>
                        <span><?php echo formatMoney($txn['total_paid'] ?? 0); ?></span>
                    </div>
                    <div class="info-row">
                        <span>Created:</span>
                        <span><?php echo date('M d, Y H:i', strtotime($txn['created_at'])); ?></span>
                    </div>
                    <?php if ($txn['scheduled_release_date']): ?>
                    <div class="info-row">
                        <span>Auto-Release:</span>
                        <span><?php echo date('M d, Y', strtotime($txn['scheduled_release_date'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($txn['escrow_created_at']): ?>
                    <div class="info-row">
                        <span>Escrow Created:</span>
                        <span><?php echo date('M d, Y H:i', strtotime($txn['escrow_created_at'])); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="btn-group">
                        <?php if (($txn['escrow_status'] == 'active' || $txn['escrow_held'] > 0) && !$txn['admin_frozen']): ?>
                            <button onclick="openReleaseModal(<?php echo $txn['id']; ?>)" class="btn btn-success">
                                <i class="fas fa-money-bill-wave"></i> Release Payment
                            </button>
                            <button onclick="openFreezeModal(<?php echo $txn['id']; ?>)" class="btn btn-warning">
                                <i class="fas fa-ice-cream"></i> Freeze
                            </button>
                            <button onclick="openRefundModal(<?php echo $txn['id']; ?>)" class="btn btn-danger">
                                <i class="fas fa-undo"></i> Refund Buyer
                            </button>
                        <?php elseif ($txn['admin_frozen']): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="transaction_id" value="<?php echo $txn['id']; ?>">
                                <input type="hidden" name="action" value="unfreeze">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-fire"></i> Unfreeze
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <a href="/broker_system/user/transaction.php?id=<?php echo $txn['id']; ?>" target="_blank" class="btn btn-outline">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-shield-alt" style="font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
            <h3>No Escrow Transactions</h3>
            <p>No escrow transactions found in the system.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-money-bill-wave"></i> Release Payment</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="releaseTransactionId">
            <input type="hidden" name="action" value="release">
            <div class="form-group">
                <label>Release Notes</label>
                <textarea name="release_notes" rows="3" placeholder="Reason for manual release..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-success">Confirm Release</button>
                <button type="button" onclick="closeReleaseModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Freeze Modal -->
<div id="freezeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-ice-cream"></i> Freeze Transaction</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="freezeTransactionId">
            <input type="hidden" name="action" value="freeze">
            <div class="form-group">
                <label>Reason for Freezing</label>
                <textarea name="freeze_reason" rows="3" placeholder="Enter reason for freezing this transaction..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-warning">Confirm Freeze</button>
                <button type="button" onclick="closeFreezeModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Refund Modal -->
<div id="refundModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-undo"></i> Refund Buyer</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="refundTransactionId">
            <input type="hidden" name="action" value="refund">
            <div class="form-group">
                <label>Refund Notes</label>
                <textarea name="refund_notes" rows="3" placeholder="Reason for refund..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-danger">Confirm Refund</button>
                <button type="button" onclick="closeRefundModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReleaseModal(id) {
    document.getElementById('releaseTransactionId').value = id;
    document.getElementById('releaseModal').style.display = 'flex';
}
function closeReleaseModal() { document.getElementById('releaseModal').style.display = 'none'; }

function openFreezeModal(id) {
    document.getElementById('freezeTransactionId').value = id;
    document.getElementById('freezeModal').style.display = 'flex';
}
function closeFreezeModal() { document.getElementById('freezeModal').style.display = 'none'; }

function openRefundModal(id) {
    document.getElementById('refundTransactionId').value = id;
    document.getElementById('refundModal').style.display = 'flex';
}
function closeRefundModal() { document.getElementById('refundModal').style.display = 'none'; }

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

BRS/api/confirm_payment.php

<?php
// api/confirm_payment.php - Confirm payment by code (all payment types)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../includes/payment_confirm.php';

date_default_timezone_set('Africa/Addis_Ababa');

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$code = trim($input['payment_code'] ?? $input['code'] ?? '');
$pin = trim($input['pin'] ?? '');

if ($code === '') {
    echo json_encode(['success' => false, 'error' => 'Payment code is required']);
    exit;
}

$user_id = null;
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in']) {
    $user_id = (int) $_SESSION['user_id'];
    // Logged-in users must confirm with test PIN (Telebirr demo)
    if ($pin !== '1234') {
        echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234 for testing']);
        exit;
    }
}

$result = confirmPaymentByCode($conn, $code, ['user_id' => $user_id]);

$conn->close();
echo json_encode($result);


BRS/api/confirm_payment_session.php

<?php
// api/confirm_payment_session.php - Confirm payment

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Missing payment code']);
    exit;
}

// Check session
if (!isset($_SESSION['payment_code']) || $_SESSION['payment_code'] !== $code) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

// Check expiry
if ($_SESSION['payment_expires'] < time()) {
    unset($_SESSION['payment_code']);
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

// Verify PIN
if ($pin != '1234') {
    echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234']);
    exit;
}

$amount = $_SESSION['payment_amount'];

// Clear session after successful payment
unset($_SESSION['payment_code']);
unset($_SESSION['payment_amount']);
unset($_SESSION['payment_expires']);

echo json_encode([
    'success' => true,
    'message' => 'Payment confirmed successfully',
    'amount' => $amount
]);
?>

BRS/api/confirm_seller.php

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

BRS/api/confirm_simple.php

<?php
// api/confirm_simple.php - Confirm payment using DATABASE

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'No code provided']);
    exit;
}

if ($pin != '1234') {
    echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234']);
    exit;
}

$conn = getDbConnection();

// Get the payment code
$stmt = $conn->prepare("
    SELECT pc.*, t.listing_id, l.seller_id
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

$payment = $result->fetch_assoc();

// Check expiry
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

$amount = $payment['amount'];
$listing_id = $payment['listing_id'];

// Process payment - update listing to active
$conn->begin_transaction();

try {
    // Update listing status to active
    $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
    
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment in payments table
    $stmt2 = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, 'telebirr_payment', ?, 'confirmed', NOW())");
    $stmt2->bind_param("iids", $payment['transaction_id'], $payment['user_id'], $amount, $code);
    $stmt2->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'message' => 'Payment confirmed! Your listing is now active.'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>

BRS/api/create_dispute.php

<?php
// api/create_dispute.php - User raises dispute

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../includes/auth.php';

$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = $input['transaction_id'] ?? 0;
$reason = $input['reason'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

if (!$transaction_id || !$reason) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();

// Verify user is part of transaction
$check = $conn->query("
    SELECT id FROM transactions 
    WHERE id = $transaction_id AND (buyer_id = $user_id OR seller_id = $user_id)
")->fetch_assoc();

if (!$check) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

// Create dispute
$stmt = $conn->prepare("
    INSERT INTO disputes (transaction_id, raised_by, reason, status) 
    VALUES (?, ?, ?, 'open')
");
$stmt->bind_param("iis", $transaction_id, $user_id, $reason);
$stmt->execute();

// Update transaction status
$conn->query("UPDATE transactions SET status = 'disputed' WHERE id = $transaction_id");

echo json_encode(['success' => true, 'message' => 'Dispute raised. Admin will review your case.']);

$conn->close();
?>

BRS/api/debug_payment.php

<?php
// api/debug_payment.php - Debug payment codes

header('Content-Type: application/json');
require_once '../config/database.php';

$conn = getDbConnection();

// Get all pending payment codes
$result = $conn->query("
    SELECT pc.*, u.email, u.phone, l.title 
    FROM payment_codes pc
    LEFT JOIN users u ON pc.user_id = u.id
    LEFT JOIN transactions t ON pc.transaction_id = t.id
    LEFT JOIN listings l ON t.listing_id = l.id
    WHERE pc.status = 'pending'
    ORDER BY pc.created_at DESC
");

$codes = [];
while ($row = $result->fetch_assoc()) {
    $codes[] = $row;
}

echo json_encode([
    'total_pending_codes' => count($codes),
    'codes' => $codes,
    'message' => 'Run this query in phpMyAdmin to see all payment codes: SELECT * FROM payment_codes;'
]);

$conn->close();
?>

BRS/api/escrow_management.php

<?php
// ============================================
// FILE: admin/escrow_management.php
// ============================================
// Admin Escrow Management Dashboard

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Escrow Management';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'release' && isset($_POST['release_payment'])) {
        $result = adminReleasePayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['release_notes'] ?? '');
        if ($result['success']) {
            $message = "Payment released successfully!";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'freeze') {
        adminFreezeTransaction($conn, $transaction_id, $_SESSION['user_id'], $_POST['freeze_reason'] ?? '');
        $message = "Transaction frozen successfully.";
    }
    
    if ($action === 'unfreeze') {
        adminUnfreezeTransaction($conn, $transaction_id, $_SESSION['user_id']);
        $message = "Transaction unfrozen successfully.";
    }
}

// Process auto-release queue
$auto_released = processAutoReleaseQueue($conn);

// Get escrow summary
$summary = getEscrowSummary($conn);

// Get active escrow transactions
$active_escrow = $conn->query("
    SELECT t.*, l.title, l.type, u1.full_name as buyer_name, u2.full_name as seller_name,
           ea.amount as escrow_amount,
           eq.scheduled_release_date
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    LEFT JOIN escrow_accounts ea ON t.id = ea.transaction_id AND ea.status = 'held'
    LEFT JOIN escrow_release_queue eq ON t.id = eq.transaction_id AND eq.status = 'pending'
    WHERE t.escrow_status = 'active' OR t.status = 'escrow_active'
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
    
    .escrow-card {
        background: white;
        border-radius: 20px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    .escrow-header {
        padding: 16px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .escrow-body { padding: 20px; }
    .timeline { margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .timeline-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 0;
        font-size: 13px;
    }
    .timeline-dot {
        width: 8px;
        height: 8px;
        background: #667eea;
        border-radius: 50%;
    }
    .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
    .btn { padding: 8px 16px; border-radius: 8px; font-weight: 500; cursor: pointer; border: none; }
    .btn-primary { background: #667eea; color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    .badge-active { background: #dbeafe; color: #1e40af; }
    .badge-frozen { background: #fee2e2; color: #dc2626; }
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
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .alert-success { background: #d1fae5; color: #059669; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
    .alert-error { background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
</style>

<div>
    <h1 style="margin-bottom: 20px;"><i class="fas fa-shield-alt"></i> Escrow Management</h1>
    
    <?php if ($auto_released > 0): ?>
        <div class="alert-success">✓ <?php echo $auto_released; ?> payment(s) auto-released.</div>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <div class="alert-success">✓ <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert-error">⚠️ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_held']); ?></div>
            <div class="stat-label">Total Escrow Held</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_released']); ?></div>
            <div class="stat-label">Total Released</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['active_transactions']; ?></div>
            <div class="stat-label">Active Escrow</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['pending_release']; ?></div>
            <div class="stat-label">Pending Release</div>
        </div>
    </div>
    
    <h2 style="margin-bottom: 20px;">Active Escrow Transactions</h2>
    
    <?php while($txn = $active_escrow->fetch_assoc()): ?>
        <div class="escrow-card">
            <div class="escrow-header">
                <div>
                    <strong>#<?php echo $txn['id']; ?></strong> - <?php echo htmlspecialchars($txn['title']); ?>
                    <span class="badge <?php echo $txn['admin_frozen'] ? 'badge-frozen' : 'badge-active'; ?>" style="margin-left: 10px;">
                        <?php echo $txn['admin_frozen'] ? '❄️ Frozen' : '🟢 Active'; ?>
                    </span>
                </div>
                <div class="price"><?php echo formatMoney($txn['total_amount']); ?></div>
            </div>
            <div class="escrow-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;">
                    <div><small>Buyer:</small><br><strong><?php echo htmlspecialchars($txn['buyer_name']); ?></strong></div>
                    <div><small>Seller:</small><br><strong><?php echo htmlspecialchars($txn['seller_name']); ?></strong></div>
                    <div><small>Escrow Amount:</small><br><strong><?php echo formatMoney($txn['escrow_amount'] ?? 0); ?></strong></div>
                </div>
                
                <?php if ($txn['scheduled_release_date']): ?>
                    <div style="background: #fef3c7; padding: 10px; border-radius: 8px; margin-bottom: 16px;">
                        ⏰ Auto-release scheduled: <?php echo date('M d, Y H:i', strtotime($txn['scheduled_release_date'])); ?>
                    </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="transaction_id" value="<?php echo $txn['id']; ?>">
                        <input type="hidden" name="action" value="release">
                        <button type="button" name="release_payment" class="btn btn-success" onclick="openReleaseModal(<?php echo $txn['id']; ?>, '<?php echo addslashes($txn['title']); ?>')">
                            💰 Release Payment
                        </button>
                    </form>
                    
                    <?php if (!$txn['admin_frozen']): ?>
                        <button onclick="openFreezeModal(<?php echo $txn['id']; ?>, '<?php echo addslashes($txn['title']); ?>')" class="btn btn-warning">
                            ❄️ Freeze Transaction
                        </button>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="transaction_id" value="<?php echo $txn['id']; ?>">
                            <input type="hidden" name="action" value="unfreeze">
                            <button type="submit" class="btn btn-primary">🔥 Unfreeze</button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="transaction_details.php?id=<?php echo $txn['id']; ?>" class="btn btn-primary">📋 View Details</a>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
    
    <?php if ($active_escrow->num_rows == 0): ?>
        <div style="text-align: center; padding: 60px; background: white; border-radius: 20px;">
            <i class="fas fa-shield-alt" style="font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
            <h3>No Active Escrow Transactions</h3>
            <p>All escrow funds have been released or no payments are pending.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;">Release Payment</h3>
        <form method="POST" id="releaseForm">
            <input type="hidden" name="transaction_id" id="releaseTransactionId">
            <input type="hidden" name="action" value="release">
            <div class="form-group">
                <label>Release Notes</label>
                <textarea name="release_notes" rows="3" placeholder="Reason for manual release..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" name="release_payment" class="btn btn-success">Confirm Release</button>
                <button type="button" onclick="closeReleaseModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Freeze Modal -->
<div id="freezeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;">Freeze Transaction</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="freezeTransactionId">
            <input type="hidden" name="action" value="freeze">
            <div class="form-group">
                <label>Reason for Freezing</label>
                <textarea name="freeze_reason" rows="3" placeholder="Enter reason for freezing this transaction..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-warning">Confirm Freeze</button>
                <button type="button" onclick="closeFreezeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReleaseModal(transactionId, title) {
    document.getElementById('releaseTransactionId').value = transactionId;
    document.getElementById('releaseModal').style.display = 'flex';
}

function closeReleaseModal() {
    document.getElementById('releaseModal').style.display = 'none';
}

function openFreezeModal(transactionId, title) {
    document.getElementById('freezeTransactionId').value = transactionId;
    document.getElementById('freezeModal').style.display = 'flex';
}

function closeFreezeModal() {
    document.getElementById('freezeModal').style.display = 'none';
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

BRS/api/generate_code.php

<?php
// api/generate_code.php - Generate code in session (no database)

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$amount = floatval($input['amount'] ?? 0);
$payment_type = $input['payment_type'] ?? 'deposit_buyer';
$listing_id = intval($input['listing_id'] ?? 0);

if (!$amount || !$listing_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Generate 5-digit code
$code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

// Store in session instead of database
$_SESSION['temp_payment'] = [
    'code' => $code,
    'amount' => $amount,
    'listing_id' => $listing_id,
    'payment_type' => $payment_type,
    'created_at' => time(),
    'expires_at' => time() + 600 // 10 minutes
];

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => $amount,
    'amount_display' => number_format($amount, 2) . ' ETB',
    'expires_in' => 600
]);
?>

BRS/api/generate_code_session.php

<?php
// api/generate_code_session.php - Generate code and store in session

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

// Generate 5-digit code
$code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

// Store in session
$_SESSION['payment_code'] = $code;
$_SESSION['payment_amount'] = 500.00;
$_SESSION['payment_expires'] = time() + 600; // 10 minutes

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => 500.00,
    'amount_display' => '500.00 ETB',
    'expires_in' => 600
]);
?>

BRS/api/generate_payment_code.php

<?php
// api/generate_payment_code.php - Generate Telebirr payment code (10-minute expiry)

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_code.php';

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Please login to continue']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$transaction_id = isset($input['transaction_id']) ? (int) $input['transaction_id'] : 0;
$amount = isset($input['amount']) ? (float) $input['amount'] : 0;
$payment_type = isset($input['payment_type']) ? preg_replace('/[^a-z_]/', '', (string) $input['payment_type']) : 'deposit_buyer';
$user_id = (int) $_SESSION['user_id'];

if ($transaction_id <= 0 || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction details']);
    exit;
}

$allowed_types = ['deposit_buyer', 'deposit_seller', 'remaining_balance', 'full_payment'];
if (!in_array($payment_type, $allowed_types, true)) {
    $payment_type = 'deposit_buyer';
}

$conn = getDbConnection();
ensurePaymentCodeTimezone($conn);

$check = $conn->query("
    SELECT t.*, l.title, l.type
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
");

if (!$check || $check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    $conn->close();
    exit;
}

$transaction = $check->fetch_assoc();
$code_row = getOrCreatePaymentCode($conn, $transaction_id, $user_id, $amount, $payment_type);
$conn->close();

echo json_encode([
    'success' => true,
    'payment_code' => $code_row['code'],
    'amount' => $amount,
    'amount_formatted' => formatMoney($amount),
    'item_name' => $transaction['title'],
    'seconds_remaining' => $code_row['seconds_remaining'],
    'expires_in' => $code_row['seconds_remaining'],
    'expiry_minutes' => PAYMENT_CODE_EXPIRY_MINUTES,
]);


BRS/api/generate_test_code.php

<?php
// api/generate_test_code.php - Generate a test payment code

require_once '../config/database.php';

$conn = getDbConnection();

// Get or create a transaction
$transaction = $conn->query("SELECT id FROM transactions LIMIT 1");
if ($transaction->num_rows == 0) {
    $conn->query("INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status) VALUES (1, 1, 1, 1000.00, 300.00, 150.00, 550.00, 'pending_deposit')");
    $transaction_id = $conn->insert_id;
} else {
    $transaction_id = $transaction->fetch_assoc()['id'];
}

// Generate a 5-digit code
$code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

// Insert the code
$stmt = $conn->prepare("INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) VALUES (?, ?, 500.00, 1, 'deposit_buyer', DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending')");
$stmt->bind_param("si", $code, $transaction_id);
$stmt->execute();

echo "<h1>Test Payment Code Generated</h1>";
echo "<p>Code: <strong style='font-size:24px;'>$code</strong></p>";
echo "<p>Use this code in Telebirr app to test payment.</p>";
echo "<p><a href='/broker_system/api/verify_code.php' onclick='return false;'>Test API</a></p>";

$conn->close();
?>

BRS/api/generate_test_code_session.php

<?php
// api/generate_test_code_session.php - Generate test code in session

session_start();
header('Content-Type: application/json');

// Generate a 5-digit code
$code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);

// Store in session
$_SESSION['temp_payment'] = [
    'code' => $code,
    'amount' => 500.00,
    'listing_id' => 1,
    'payment_type' => 'test',
    'created_at' => time(),
    'expires_at' => time() + 600
];

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => 500.00,
    'message' => 'Test code generated. Use this code in Telebirr app.'
]);
?>

BRS/api/get_transaction.php

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

BRS/api/payment_status.php

<?php
// api/payment_status.php - FIXED VERSION

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

// CRITICAL: Set PHP timezone FIRST
date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$user_id = $_SESSION['user_id'];

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'no_code']);
    exit;
}

$conn = getDbConnection();

// CRITICAL: Set MySQL timezone to match PHP
$conn->query("SET time_zone = '+03:00'");

// SINGLE QUERY - Get ALL status from database (SOURCE OF TRUTH)
$result = $conn->query("
    SELECT 
        pc.code,
        pc.status as code_status,
        pc.user_id,
        pc.transaction_id,
        pc.amount,
        pc.expires_at,
        UNIX_TIMESTAMP(pc.expires_at) as expires_timestamp,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) as seconds_remaining,
        
        pc.type as payment_code_type,
        -- Check if payment exists and is confirmed (any type for this code)
        EXISTS(SELECT 1 FROM payments p 
               WHERE p.telebirr_code_5digit = pc.code 
               AND p.status = 'confirmed') as is_paid,
        
        -- Check if listing is active
        EXISTS(SELECT 1 FROM transactions t
               JOIN listings l ON t.listing_id = l.id
               WHERE t.id = pc.transaction_id 
               AND l.status = 'active') as listing_active,
        
        -- Get listing status directly
        (SELECT l.status FROM transactions t 
         JOIN listings l ON t.listing_id = l.id 
         WHERE t.id = pc.transaction_id LIMIT 1) as listing_status
        
    FROM payment_codes pc
    WHERE pc.code = '$code' 
    AND pc.user_id = $user_id
    LIMIT 1
");

if ($result->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'status' => 'not_found',
        'error' => 'Payment code not found'
    ]);
    $conn->close();
    exit;
}

$data = $result->fetch_assoc();

// Determine expiration from database ONLY
$is_expired = ($data['seconds_remaining'] <= 0);
$is_paid = (bool)$data['is_paid'];
$is_active = (bool)$data['listing_active'];

// Build response
$response = [
    'success' => true,
    'code' => $data['code'],
    'code_status' => $data['code_status'],
    'is_paid' => $is_paid,
    'is_expired' => $is_expired,
    'listing_active' => $is_active,
    'listing_status' => $data['listing_status'],
    'seconds_remaining' => max(0, intval($data['seconds_remaining'])),
    'expires_timestamp' => intval($data['expires_timestamp']),
    'server_time' => time(),
    'display_time' => date('Y-m-d H:i:s')
];

// Determine payment status
$code_type = $data['payment_code_type'] ?? 'deposit_seller';

if ($is_paid && $code_type === 'remaining_balance') {
    $response['payment_status'] = 'fully_paid';
    $response['message'] = 'Remaining balance payment confirmed';
} elseif ($is_paid && $is_active) {
    $response['payment_status'] = 'confirmed_activated';
    $response['message'] = 'Payment confirmed and listing activated';
} elseif ($is_paid && !$is_active) {
    // Payment confirmed but listing not active - trigger activation
    $response['payment_status'] = 'confirmed_pending_activation';
    $response['message'] = 'Payment confirmed, activating listing...';
    
    // Trigger activation
    require_once 'process_payment_callback.php';
    $activation = activateListingByPaymentCode($conn, $code);
    if ($activation['success']) {
        $response['listing_activated'] = true;
        $response['payment_status'] = 'confirmed_and_activated';
        $response['listing_active'] = true;
    }
} elseif (!$is_paid && !$is_expired) {
    $response['payment_status'] = 'pending';
    $response['message'] = 'Waiting for payment';
} elseif (!$is_paid && $is_expired) {
    $response['payment_status'] = 'expired';
    $response['message'] = 'Payment code expired';
} elseif ($is_paid && $is_expired) {
    $response['payment_status'] = 'paid_but_expired';
    $response['message'] = 'Payment received';
}

$conn->close();
echo json_encode($response);
?>

BRS/api/payment_status_remaining.php

<?php
// api/payment_status_remaining.php - Poll remaining balance payment status

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';
require_once '../includes/seller_listing_payment.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$code = isset($_GET['code']) ? preg_replace('/[^0-9]/', '', $_GET['code']) : '';
$listing_id = isset($_GET['listing_id']) ? (int) $_GET['listing_id'] : 0;
$user_id = (int) $_SESSION['user_id'];

if (empty($code) && $listing_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'code or listing_id required']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$where = $code ? "pc.code = '$code' AND pc.user_id = $user_id" : "pc.transaction_id IN (
    SELECT id FROM transactions WHERE listing_id = $listing_id AND seller_id = $user_id LIMIT 1
) AND pc.user_id = $user_id AND pc.type = 'remaining_balance'";

$result = $conn->query("
    SELECT
        pc.code,
        pc.status AS code_status,
        pc.amount,
        pc.transaction_id,
        TIMESTAMPDIFF(SECOND, NOW(), pc.expires_at) AS seconds_remaining,
        EXISTS(
            SELECT 1 FROM payments p
            WHERE p.telebirr_code_5digit = pc.code
              AND p.type = 'remaining_balance'
              AND p.status = 'confirmed'
        ) AS is_paid,
        t.listing_id
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    WHERE $where
      AND pc.type = 'remaining_balance'
    ORDER BY pc.id DESC
    LIMIT 1
");

if (!$result || $result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Payment code not found']);
    $conn->close();
    exit;
}

$data = $result->fetch_assoc();
$listing_id = (int) $data['listing_id'];
$info = getSellerListingPaymentInfo($conn, $listing_id, $user_id);

$is_paid = (bool) $data['is_paid'];
$is_expired = ((int) $data['seconds_remaining']) <= 0 && !$is_paid;

$response = [
    'success' => true,
    'code' => $data['code'],
    'is_paid' => $is_paid,
    'is_expired' => $is_expired,
    'seconds_remaining' => max(0, (int) $data['seconds_remaining']),
    'amount' => (float) $data['amount'],
    'summary' => $info ? [
        'total_price' => $info['total_price'],
        'total_price_formatted' => number_format($info['total_price'], 2) . ' ETB',
        'deposit_paid' => $info['deposit_paid'],
        'deposit_paid_formatted' => number_format($info['deposit_paid'], 2) . ' ETB',
        'remaining_balance' => $info['remaining_balance'],
        'remaining_balance_formatted' => number_format($info['remaining_balance'], 2) . ' ETB',
        'payment_status' => $info['payment_status'],
        'is_fully_paid' => $info['payment_status'] === 'fully_paid',
    ] : null,
];

if ($is_paid) {
    $response['payment_status'] = 'fully_paid';
    $response['message'] = 'Remaining balance paid in full';
} elseif ($is_expired) {
    $response['payment_status'] = 'expired';
    $response['message'] = 'Payment code expired';
} else {
    $response['payment_status'] = 'pending';
    $response['message'] = 'Waiting for payment';
}

$conn->close();
echo json_encode($response);


BRS/api/process_payment.php

<?php
// api/process_payment.php - Process payment and activate listing

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$user_phone = $input['user_phone'] ?? '';
$payment_type = $input['payment_type'] ?? 'deposit';

if (empty($code) || empty($user_phone)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();

// Get payment code details
$stmt = $conn->prepare("
    SELECT pc.*, t.id as transaction_id, t.buyer_id, t.seller_id, t.total_amount, 
           t.deposit_amount, t.commission_amount, t.remaining_balance,
           l.id as listing_id, l.title, l.seller_id as listing_seller_id
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid or expired payment code']);
    exit;
}

$payment = $result->fetch_assoc();

// Check expiry
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Payment code expired']);
    exit;
}

// Get user by phone
$user_stmt = $conn->prepare("SELECT id, full_name FROM users WHERE phone = ?");
$user_stmt->bind_param("s", $user_phone);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

$amount = $payment['amount'];
$transaction_id = $payment['transaction_id'];
$listing_id = $payment['listing_id'];
$is_seller = ($user['id'] == $payment['listing_seller_id']);

$conn->begin_transaction();

try {
    // Mark payment code as used
    $conn->query("UPDATE payment_codes SET status = 'used' WHERE code = '$code'");
    
    // Record payment
    $payment_type_record = ($is_seller) ? 'deposit_seller' : 'deposit_buyer';
    $stmt2 = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
    $stmt2->bind_param("iidss", $transaction_id, $user['id'], $amount, $payment_type_record, $code);
    $stmt2->execute();
    
    // Update escrow in transaction
    $conn->query("UPDATE transactions SET escrow_held = escrow_held + $amount WHERE id = $transaction_id");
    
    // Get updated transaction data
    $txn_check = $conn->query("SELECT escrow_held, deposit_amount, commission_amount FROM transactions WHERE id = $transaction_id")->fetch_assoc();
    $required = $txn_check['deposit_amount'] * 2 + $txn_check['commission_amount'];
    
    // Update transaction status
    if ($txn_check['escrow_held'] >= $required) {
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
        
        // CRITICAL: Update listing status to ACTIVE when seller pays
        // This is the key fix - activate the listing
        $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'transaction_id' => $transaction_id,
        'item_name' => $payment['title'],
        'status' => 'success',
        'message' => 'Payment successful!'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>

BRS/api/process_payment_callback.php

<?php
// api/process_payment_callback.php - ATOMIC PAYMENT ACTIVATION

header('Content-Type: application/json');
require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

function activateListingByPaymentCode($conn, $code, $amount = null) {
    // First, verify the code exists and get all related data
    $result = $conn->query("
        SELECT 
            pc.id as code_id,
            pc.transaction_id,
            pc.user_id,
            pc.amount as expected_amount,
            pc.status as code_status,
            t.listing_id,
            t.status as transaction_status,
            l.status as listing_status,
            l.seller_id,
            l.price
        FROM payment_codes pc
        JOIN transactions t ON pc.transaction_id = t.id
        JOIN listings l ON t.listing_id = l.id
        WHERE pc.code = '$code'
        LIMIT 1
    ");
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'error' => 'Code not found'];
    }
    
    $data = $result->fetch_assoc();
    
    // If already active, return success (idempotent)
    if ($data['listing_status'] === 'active') {
        return ['success' => true, 'already_active' => true];
    }
    
    // BEGIN ATOMIC TRANSACTION
    $conn->begin_transaction();
    
    try {
        // 1. Insert or update payment record
        $payment_check = $conn->query("
            SELECT id FROM payments 
            WHERE telebirr_code_5digit = '$code' 
            AND type = 'deposit_seller'
        ");
        
        if ($payment_check->num_rows === 0) {
            $stmt = $conn->prepare("
                INSERT INTO payments (
                    transaction_id, user_id, amount, type, 
                    telebirr_code_5digit, status, confirmed_at, created_at
                ) VALUES (?, ?, ?, 'deposit_seller', ?, 'confirmed', NOW(), NOW())
            ");
            $stmt->bind_param("iids", 
                $data['transaction_id'], 
                $data['user_id'], 
                $data['expected_amount'], 
                $code
            );
            $stmt->execute();
        }
        
        // 2. Mark payment code as used
        $conn->query("
            UPDATE payment_codes 
            SET status = 'used', updated_at = NOW() 
            WHERE id = {$data['code_id']}
        ");
        
        // 3. Update transaction status
        $conn->query("
            UPDATE transactions 
            SET status = 'deposits_complete', 
                escrow_held = escrow_held + {$data['expected_amount']},
                updated_at = NOW() 
            WHERE id = {$data['transaction_id']}
        ");
        
        // 4. ACTIVATE LISTING - CRITICAL STEP
        $conn->query("
            UPDATE listings 
            SET status = 'active', 
                updated_at = NOW() 
            WHERE id = {$data['listing_id']}
        ");
        
        $conn->commit();
        
        return [
            'success' => true,
            'listing_id' => $data['listing_id'],
            'transaction_id' => $data['transaction_id']
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$code = isset($input['code']) ? preg_replace('/[^0-9]/', '', $input['code']) : '';
$amount = isset($input['amount']) ? floatval($input['amount']) : null;

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'No payment code provided']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$result = activateListingByPaymentCode($conn, $code, $amount);

$conn->close();
echo json_encode($result);
?>

BRS/api/reject_booking.php

<?php
// api/reject_booking.php - Owner rejects booking

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

// Verify ownership
$booking = $conn->query("
    SELECT rb.*, t.id as transaction_id
    FROM rental_bookings rb
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
    $conn->query("UPDATE rental_bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = $user_id WHERE id = $booking_id");
    
    // Update transaction status
    $conn->query("UPDATE transactions SET status = 'cancelled' WHERE id = {$booking['transaction_id']}");
    
    // Notify tenant
    $message = "Your booking request was declined by the property owner.";
    $conn->query("
        INSERT INTO notifications (user_id, title, message, link, created_at) 
        VALUES ({$booking['tenant_id']}, 'Booking Declined', '$message', 'my_rentals.php', NOW())
    ");
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Booking declined successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>

BRS/api/resolve_dispute.php

<?php
// admin/resolve_dispute.php - Admin resolves dispute

require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdminLogin();

$conn = getDbConnection();
$dispute_id = intval($_GET['id'] ?? 0);
$dispute = $conn->query("
    SELECT d.*, t.buyer_id, t.seller_id, t.total_amount, t.commission_amount, t.escrow_held
    FROM disputes d
    JOIN transactions t ON d.transaction_id = t.id
    WHERE d.id = $dispute_id
")->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'];
    $refund_to = $_POST['refund_to']; // 'buyer', 'seller', 'both'
    $admin_notes = $_POST['admin_notes'];
    $admin_id = $_SESSION['admin_id'];
    
    $conn->begin_transaction();
    
    try {
        // Update dispute
        $conn->query("
            UPDATE disputes 
            SET status = 'resolved', admin_decision = '$decision', decision_notes = '$admin_notes', resolved_at = NOW() 
            WHERE id = $dispute_id
        ");
        
        if ($decision == 'refund') {
            // Calculate refund amount (minus commission)
            $commission = $dispute['commission_amount'];
            $refund_amount = $dispute['total_amount'] - $commission;
            
            if ($refund_to == 'buyer') {
                $conn->query("UPDATE users SET balance = balance + $refund_amount WHERE id = {$dispute['buyer_id']}");
                $conn->query("UPDATE users SET admin_balance = admin_balance - $refund_amount WHERE role = 'admin'");
            } elseif ($refund_to == 'seller') {
                $conn->query("UPDATE users SET balance = balance + $refund_amount WHERE id = {$dispute['seller_id']}");
                $conn->query("UPDATE users SET admin_balance = admin_balance - $refund_amount WHERE role = 'admin'");
            } elseif ($refund_to == 'both') {
                $half = $refund_amount / 2;
                $conn->query("UPDATE users SET balance = balance + $half WHERE id = {$dispute['buyer_id']}");
                $conn->query("UPDATE users SET balance = balance + $half WHERE id = {$dispute['seller_id']}");
                $conn->query("UPDATE users SET admin_balance = admin_balance - $refund_amount WHERE role = 'admin'");
            }
            
            $conn->query("UPDATE transactions SET status = 'cancelled', escrow_released = 1 WHERE id = {$dispute['transaction_id']}");
        } elseif ($decision == 'release') {
            // Release payment to seller
            $release_amount = $dispute['total_amount'] - $dispute['commission_amount'];
            $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$dispute['seller_id']}");
            $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
            $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW(), escrow_released = 1 WHERE id = {$dispute['transaction_id']}");
        }
        
        $conn->commit();
        $message = "Dispute resolved successfully";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to resolve dispute: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Resolve Dispute</title>
    <style>
        body { font-family: Arial; padding: 20px; max-width: 600px; margin: 0 auto; }
        .card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Resolve Dispute #<?php echo $dispute_id; ?></h1>
        
        <div class="info">
            <p><strong>Transaction ID:</strong> #<?php echo $dispute['transaction_id']; ?></p>
            <p><strong>Amount:</strong> <?php echo number_format($dispute['total_amount'], 2); ?> ETB</p>
            <p><strong>Commission:</strong> <?php echo number_format($dispute['commission_amount'], 2); ?> ETB</p>
            <p><strong>Reason:</strong> <?php echo htmlspecialchars($dispute['reason']); ?></p>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label>Decision</label>
                <select name="decision" required onchange="toggleRefundTo(this.value)">
                    <option value="release">Release Payment to Seller</option>
                    <option value="refund">Refund to User(s) (minus commission)</option>
                </select>
            </div>
            
            <div class="form-group" id="refundToGroup" style="display: none;">
                <label>Refund To</label>
                <select name="refund_to">
                    <option value="buyer">Refund to Buyer Only</option>
                    <option value="seller">Refund to Seller Only</option>
                    <option value="both">Refund to Both (50/50)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Admin Notes</label>
                <textarea name="admin_notes" rows="3" required></textarea>
            </div>
            
            <button type="submit">Resolve Dispute</button>
        </form>
    </div>
    
    <script>
        function toggleRefundTo(value) {
            const group = document.getElementById('refundToGroup');
            group.style.display = value === 'refund' ? 'block' : 'none';
        }
    </script>
</body>
</html>

BRS/api/server_time.php

<?php
// api/server_time.php - Single source of truth for time

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config/database.php';

date_default_timezone_set('Africa/Addis_Ababa');

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

// Get MySQL time as the ultimate source
$mysql_time_result = $conn->query("SELECT NOW() as mysql_time, UNIX_TIMESTAMP() as mysql_timestamp");
$mysql_time = $mysql_time_result->fetch_assoc();

$response = [
    'success' => true,
    'mysql_timestamp' => intval($mysql_time['mysql_timestamp']) * 1000, // Convert to milliseconds
    'mysql_time' => $mysql_time['mysql_time'],
    'php_timestamp' => time() * 1000,
    'timezone' => 'Africa/Addis_Ababa',
    'utc_offset' => 10800000 // 3 hours in milliseconds
];

$conn->close();
echo json_encode($response);
?>

BRS/api/simple_generator.php

<?php
// api/simple_generator.php - Generate code and save to file

// Generate 5-digit code
$code = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
$amount = 500;
$expires = time() + 600; // 10 minutes

// Save to a simple JSON file
$data = [
    'code' => $code,
    'amount' => $amount,
    'expires' => $expires,
    'created' => time()
];

file_put_contents('payment_data.json', json_encode($data));

?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Code Generator</title>
    <style>
        body { text-align: center; padding: 50px; font-family: Arial; background: #f5f6fa; }
        .code { font-size: 64px; font-weight: bold; letter-spacing: 10px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; border-radius: 10px; display: inline-block; margin: 20px; }
        .info { margin: 20px; color: #666; }
        button { padding: 10px 20px; font-size: 16px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>🏪 Ethio Brokerplace</h1>
    <h2>Your Payment Code</h2>
    <div class="code"><?php echo $code; ?></div>
    <div class="info">
        <p>Amount: <strong>500.00 ETB</strong></p>
        <p>Code expires in 10 minutes</p>
    </div>
    <button onclick="location.reload()">Generate New Code</button>
    <hr>
    <h3>Instructions:</h3>
    <ol style="text-align: left; max-width: 300px; margin: 0 auto;">
        <li>Copy this code: <strong><?php echo $code; ?></strong></li>
        <li>Open Telebirr app</li>
        <li>Go to Marketplace</li>
        <li>Enter the code: <strong><?php echo $code; ?></strong></li>
        <li>Enter PIN: <strong>1234</strong></li>
    </ol>
</body>
</html>

BRS/api/telebirr_callback.php

<?php
// api/telebirr_callback.php - Telebirr payment callback

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once '../config/database.php';
require_once '../includes/payment_confirm.php';

date_default_timezone_set('Africa/Addis_Ababa');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$payment_code = isset($input['code']) ? preg_replace('/[^0-9]/', '', $input['code']) : '';
$amount = isset($input['amount']) ? (float) $input['amount'] : 0;
$status = $input['status'] ?? '';

if ($payment_code === '' || $status !== 'success') {
    echo json_encode(['success' => false, 'error' => 'invalid_callback']);
    exit;
}

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$check = $conn->query("
    SELECT amount FROM payment_codes
    WHERE code = '$payment_code' AND status = 'pending'
    LIMIT 1
")->fetch_assoc();

if ($check && $amount > 0 && abs((float) $check['amount'] - $amount) > 0.01) {
    echo json_encode(['success' => false, 'error' => 'Amount mismatch']);
    $conn->close();
    exit;
}

$result = confirmPaymentByCode($conn, $payment_code, [
    'amount' => $amount > 0 ? $amount : null,
]);

$conn->close();
echo json_encode($result);


BRS/api/test_api.php

<?php
// api/test_api.php - Test if API is working

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'API is working!',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION
]);
?>

BRS/api/test_confirm.php

<?php
// api/test_confirm.php - Test confirm payment directly

header('Content-Type: application/json');

require_once '../config/database.php';

$conn = getDbConnection();

// Test with code 12345
$code = '12345';

echo "<h1>Testing Confirm Payment for Code: $code</h1>";

// Check if code exists
$check = $conn->prepare("SELECT * FROM payment_codes WHERE code = ?");
$check->bind_param("s", $code);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    $payment = $result->fetch_assoc();
    echo "<pre>";
    print_r($payment);
    echo "</pre>";
    
    // Try to update
    $update = $conn->prepare("UPDATE payment_codes SET status = 'used' WHERE code = ?");
    $update->bind_param("s", $code);
    if ($update->execute()) {
        echo "<p style='color:green'>✓ Payment code marked as used</p>";
    } else {
        echo "<p style='color:red'>✗ Failed to update: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:red'>✗ Code not found</p>";
}

$conn->close();
?>

BRS/api/test_confirm_fixed.html

<!DOCTYPE html>
<html>
<head>
    <title>Test Payment API</title>
</head>
<body>
    <h1>Test Payment Confirmation</h1>
    <button onclick="testConfirm()">Test Confirm Payment for Code 12345</button>
    <pre id="result"></pre>
    
    <script>
        function testConfirm() {
            fetch('/broker_system/api/confirm_payment.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    payment_code: '12345',
                    user_phone: '+251992116527',
                    pin: '1234'
                })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('result').innerHTML = JSON.stringify(data, null, 2);
                console.log('Response:', data);
            })
            .catch(error => {
                document.getElementById('result').innerHTML = 'Error: ' + error;
            });
        }
    </script>
</body>
</html>

BRS/api/test_payment.html

<!DOCTYPE html>
<html>
<head>
    <title>Test Payment API</title>
</head>
<body>
    <h1>Test Payment Confirmation</h1>
    <button onclick="testConfirm()">Test Confirm Payment for Code 12345</button>
    <pre id="result"></pre>
    
    <script>
        function testConfirm() {
            fetch('/broker_system/api/confirm_payment.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    payment_code: '12345',
                    user_phone: '+251992116527',
                    pin: '1234'
                })
            })
            .then(response => response.text())
            .then(text => {
                document.getElementById('result').innerHTML = text;
                console.log('Response:', text);
            })
            .catch(error => {
                document.getElementById('result').innerHTML = 'Error: ' + error;
            });
        }
    </script>
</body>
</html>

BRS/api/test_verify_direct.php

<?php
// api/test_verify_direct.php - Direct test of verify function

require_once '../config/database.php';

$code = $_GET['code'] ?? '12345';

echo "<h1>Testing Verify Code: $code</h1>";

$conn = getDbConnection();

// Check payment code
$stmt = $conn->prepare("
    SELECT pc.*, t.total_amount, l.title as item_name 
    FROM payment_codes pc
    LEFT JOIN transactions t ON pc.transaction_id = t.id
    LEFT JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $payment = $result->fetch_assoc();
    echo "<pre>";
    print_r($payment);
    echo "</pre>";
    echo "<h2 style='color:green'>✓ Code found!</h2>";
    echo "Amount: " . $payment['amount'] . " ETB<br>";
    echo "Item: " . $payment['item_name'] . "<br>";
} else {
    echo "<h2 style='color:red'>✗ Code not found or expired</h2>";
    
    // Show all pending codes
    $all = $conn->query("SELECT * FROM payment_codes WHERE status = 'pending'");
    if ($all->num_rows > 0) {
        echo "<h3>Pending codes in database:</h3>";
        while($row = $all->fetch_assoc()) {
            echo "- Code: {$row['code']}, Amount: {$row['amount']}, Expires: {$row['expires_at']}<br>";
        }
    } else {
        echo "No pending codes found in database.";
    }
}

$conn->close();
?>

BRS/api/verify_code.php

<?php
// api/verify_code.php - Verify code from session (no database)

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['success' => false, 'error' => 'Invalid code format']);
    exit;
}

// Check session for the code
if (!isset($_SESSION['temp_payment']) || $_SESSION['temp_payment']['code'] !== $code) {
    echo json_encode(['success' => false, 'error' => 'Invalid or expired payment code']);
    exit;
}

// Check if expired
if ($_SESSION['temp_payment']['expires_at'] < time()) {
    unset($_SESSION['temp_payment']);
    echo json_encode(['success' => false, 'error' => 'Payment code expired']);
    exit;
}

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => $_SESSION['temp_payment']['amount'],
    'amount_display' => number_format($_SESSION['temp_payment']['amount'], 2) . ' ETB',
    'listing_id' => $_SESSION['temp_payment']['listing_id']
]);
?>

BRS/api/verify_code_session.php

<?php
// api/verify_code_session.php - Verify code from session

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';

if (empty($code) || strlen($code) != 5) {
    echo json_encode(['success' => false, 'error' => 'Invalid code format']);
    exit;
}

// Check if code exists in session
if (!isset($_SESSION['payment_code']) || $_SESSION['payment_code'] !== $code) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment code']);
    exit;
}

// Check if expired
if (isset($_SESSION['payment_expires']) && $_SESSION['payment_expires'] < time()) {
    unset($_SESSION['payment_code']);
    echo json_encode(['success' => false, 'error' => 'Code expired']);
    exit;
}

echo json_encode([
    'success' => true,
    'payment_code' => $code,
    'amount' => $_SESSION['payment_amount'],
    'amount_display' => number_format($_SESSION['payment_amount'], 2) . ' ETB',
    'merchant' => 'Ethio Brokerplace'
]);
?>

BRS/api/verify_simple.php

<?php
// api/verify_simple.php - Read from DATABASE (REAL SYSTEM)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';

if (empty($code) || strlen($code) != 5 || !ctype_digit($code)) {
    echo json_encode(['success' => false, 'error' => 'Invalid code format. Must be 5 digits.']);
    exit;
}

$conn = getDbConnection();

// Check in payment_codes table
$stmt = $conn->prepare("
    SELECT pc.*, l.title as item_name 
    FROM payment_codes pc
    JOIN transactions t ON pc.transaction_id = t.id
    JOIN listings l ON t.listing_id = l.id
    WHERE pc.code = ? AND pc.status = 'pending'
");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // Check if code exists but not pending
    $check = $conn->prepare("SELECT status FROM payment_codes WHERE code = ?");
    $check->bind_param("s", $code);
    $check->execute();
    $status_result = $check->get_result();
    
    if ($status_result->num_rows > 0) {
        $status_data = $status_result->fetch_assoc();
        echo json_encode(['success' => false, 'error' => 'Code already ' . $status_data['status']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid payment code. Please generate a code from your listing page.']);
    }
    exit;
}

$payment = $result->fetch_assoc();

// Check if expired
if (strtotime($payment['expires_at']) < time()) {
    $conn->query("UPDATE payment_codes SET status = 'expired' WHERE code = '$code'");
    echo json_encode(['success' => false, 'error' => 'Code expired. Please generate a new code.']);
    exit;
}

echo json_encode([
    'success' => true,
    'amount' => floatval($payment['amount']),
    'amount_display' => number_format($payment['amount'], 2) . ' ETB',
    'item_name' => $payment['item_name']
]);

$conn->close();
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

