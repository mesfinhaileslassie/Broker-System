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
