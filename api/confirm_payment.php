<?php
// api/confirm_payment.php - Confirm payment (no database storage)

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['payment_code'] ?? '';
$pin = $input['pin'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Missing payment code']);
    exit;
}

// Verify PIN (simple for demo)
if ($pin != '1234') {
    echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234']);
    exit;
}

// Check session for the code
if (!isset($_SESSION['temp_payment']) || $_SESSION['temp_payment']['code'] !== $code) {
    echo json_encode(['success' => false, 'error' => 'Invalid or expired payment code']);
    exit;
}

// Check expiry
if ($_SESSION['temp_payment']['expires_at'] < time()) {
    unset($_SESSION['temp_payment']);
    echo json_encode(['success' => false, 'error' => 'Payment code expired']);
    exit;
}

$amount = $_SESSION['temp_payment']['amount'];
$listing_id = $_SESSION['temp_payment']['listing_id'];

$conn = getDbConnection();

// Get user from session (assuming logged in)
$user_id = $_SESSION['user_id'] ?? 1;

// Process payment - update listing or create transaction
$conn->begin_transaction();

try {
    // Update listing to active if it's a seller deposit
    $conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id AND seller_id = $user_id");
    
    // Record payment in payments table (optional, for history)
    $stmt = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (NULL, ?, ?, 'telebirr_payment', ?, 'confirmed', NOW())");
    $stmt->bind_param("ids", $user_id, $amount, $code);
    $stmt->execute();
    
    $conn->commit();
    
    // Clear the session payment data
    unset($_SESSION['temp_payment']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment confirmed successfully',
        'amount' => $amount
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Payment failed: ' . $e->getMessage()]);
}

$conn->close();
?>