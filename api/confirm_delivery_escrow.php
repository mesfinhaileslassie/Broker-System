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