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