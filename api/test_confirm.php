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