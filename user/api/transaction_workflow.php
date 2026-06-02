<?php
// user/api/transaction_workflow.php - Transaction actions API
// FIXED: Added proper pay_remaining action with 5-digit code generation

session_start();
header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/transaction_workflow.php';
require_once '../../includes/payment_confirm.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create debug log
$debug_log = __DIR__ . '/transaction_workflow_debug.log';
function debug_log_api($message, $data = null) {
    global $debug_log;
    $log_entry = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log_entry .= " - " . print_r($data, true);
    }
    file_put_contents($debug_log, $log_entry . PHP_EOL, FILE_APPEND);
}

debug_log_api("========== API CALLED ==========");
debug_log_api("Action: " . ($_REQUEST['action'] ?? 'not set'));
debug_log_api("POST data: " . print_r($_POST, true));
debug_log_api("GET data: " . print_r($_GET, true));

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}

$conn = getDbConnection();
$user_id = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

debug_log_api("Decoded input: " . print_r($input, true));

$action = $input['action'] ?? ($_GET['action'] ?? $_POST['action'] ?? '');
$transaction_id = (int) ($input['transaction_id'] ?? $_GET['transaction_id'] ?? $_POST['transaction_id'] ?? 0);

debug_log_api("Action: $action, Transaction ID: $transaction_id, User ID: $user_id");

if ($transaction_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction ID']);
    $conn->close();
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
        
        // Get remaining balance
        $payments = $conn->query("
            SELECT COALESCE(SUM(CASE WHEN type = 'deposit_buyer' THEN amount ELSE 0 END), 0) as deposit_paid,
                   COALESCE(SUM(CASE WHEN type = 'remaining_balance' THEN amount ELSE 0 END), 0) as remaining_paid
            FROM payments 
            WHERE transaction_id = $transaction_id AND status = 'confirmed'
        ")->fetch_assoc();
        
        $deposit_paid = floatval($payments['deposit_paid']);
        $remaining_paid = floatval($payments['remaining_paid']);
        $total_amount = floatval($view['total_amount']);
        $remaining_balance = max(0, $total_amount - $deposit_paid - $remaining_paid);
        
        echo json_encode([
            'success' => true,
            'transaction' => [
                'id' => (int) $view['id'],
                'total_amount' => (float) $view['total_amount'],
                'amount_paid' => (float) ($view['amount_paid'] ?? 0),
                'remaining_balance' => $remaining_balance,
                'payment_status' => $view['payment_status'] ?? 'pending',
                'status' => $view['status'],
                'seller_confirmed' => (bool) ($view['seller_delivery_confirmed'] ?? 0),
                'buyer_confirmed' => (bool) ($view['buyer_delivery_confirmed'] ?? 0),
            ],
            'can_pay_remaining' => $is_buyer && $remaining_balance > 0 && 
                                   ($view['seller_delivery_confirmed'] ?? 0) == 1 && 
                                   ($view['buyer_delivery_confirmed'] ?? 0) == 1,
        ]);
        break;

    case 'pay_remaining':
        debug_log_api("=== PAY REMAINING ACTION STARTED ===");
        
        // Verify transaction exists and user is the buyer
        $check = $conn->query("
            SELECT t.*, 
                   t.seller_delivery_confirmed, 
                   t.buyer_delivery_confirmed, 
                   t.total_amount
            FROM transactions t
            WHERE t.id = $transaction_id AND t.buyer_id = $user_id
        ");
        
        if (!$check) {
            debug_log_api("Query failed: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database error']);
            break;
        }
        
        $transaction = $check->fetch_assoc();
        
        if (!$transaction) {
            debug_log_api("Transaction not found for ID: $transaction_id and user: $user_id");
            echo json_encode(['success' => false, 'error' => 'Transaction not found or unauthorized']);
            break;
        }
        
        debug_log_api("Transaction found: " . print_r($transaction, true));
        
        // Check delivery confirmation
        if (!$transaction['seller_delivery_confirmed'] || !$transaction['buyer_delivery_confirmed']) {
            debug_log_api("Delivery not confirmed by both parties - Seller: {$transaction['seller_delivery_confirmed']}, Buyer: {$transaction['buyer_delivery_confirmed']}");
            echo json_encode(['success' => false, 'error' => 'Both buyer and seller must confirm delivery before paying remaining balance']);
            break;
        }
        
        // Get remaining balance
        $payments = $conn->query("
            SELECT COALESCE(SUM(CASE WHEN type = 'deposit_buyer' THEN amount ELSE 0 END), 0) as deposit_paid,
                   COALESCE(SUM(CASE WHEN type = 'remaining_balance' THEN amount ELSE 0 END), 0) as remaining_paid
            FROM payments 
            WHERE transaction_id = $transaction_id AND status = 'confirmed'
        ")->fetch_assoc();
        
        $deposit_paid = floatval($payments['deposit_paid']);
        $remaining_paid = floatval($payments['remaining_paid']);
        $total_amount = floatval($transaction['total_amount']);
        $remaining_balance = max(0, $total_amount - $deposit_paid - $remaining_paid);
        
        debug_log_api("Total: $total_amount, Deposit Paid: $deposit_paid, Remaining Paid: $remaining_paid, Balance Due: $remaining_balance");
        
        if ($remaining_balance <= 0) {
            debug_log_api("No remaining balance to pay");
            echo json_encode(['success' => false, 'error' => 'No remaining balance to pay']);
            break;
        }
        
        // Check for existing pending payment code
        $existing_code = $conn->query("
            SELECT code, expires_at 
            FROM payment_codes 
            WHERE transaction_id = $transaction_id 
            AND user_id = $user_id 
            AND type = 'remaining_balance' 
            AND status = 'pending'
            AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        
        if ($existing_code && $existing_code->num_rows > 0) {
            $code_data = $existing_code->fetch_assoc();
            debug_log_api("Using existing payment code: " . $code_data['code']);
            echo json_encode([
                'success' => true,
                'message' => 'Existing payment code found',
                'payment_code' => $code_data['code'],
                'amount' => $remaining_balance,
                'amount_formatted' => formatMoney($remaining_balance),
                'pay_url' => '/broker_system/user/pay_rent.php?transaction_id=' . $transaction_id . '&pay=remaining'
            ]);
            break;
        }
        
        // Generate new 5-digit payment code
        do {
            $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
        } while ($code_check->num_rows > 0);
        
        debug_log_api("Generated new payment code: $payment_code");
        
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        $stmt = $conn->prepare("
            INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at) 
            VALUES (?, ?, ?, ?, 'remaining_balance', ?, 'pending', NOW())
        ");
        $stmt->bind_param("siidss", $payment_code, $transaction_id, $remaining_balance, $user_id, $expires_at);
        
        if ($stmt->execute()) {
            debug_log_api("Payment code inserted successfully");
            echo json_encode([
                'success' => true,
                'message' => 'Payment code generated successfully',
                'payment_code' => $payment_code,
                'amount' => $remaining_balance,
                'amount_formatted' => formatMoney($remaining_balance),
                'expires_at' => $expires_at,
                'pay_url' => '/broker_system/user/pay_rent.php?transaction_id=' . $transaction_id . '&pay=remaining'
            ]);
        } else {
            debug_log_api("Failed to insert payment code: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Failed to generate payment code: ' . $stmt->error]);
        }
        $stmt->close();
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
        debug_log_api("Invalid action: $action");
        echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
}

$conn->close();
?>