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