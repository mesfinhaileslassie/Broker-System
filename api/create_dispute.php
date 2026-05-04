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