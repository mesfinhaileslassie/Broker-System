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