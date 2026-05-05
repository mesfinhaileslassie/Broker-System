<?php
// user/api/check_payment_status.php - Check if payment is confirmed

session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['confirmed' => false]);
    exit;
}

$code = $_GET['code'] ?? '';

if (!$code) {
    echo json_encode(['confirmed' => false]);
    exit;
}

$conn = getDbConnection();

// Check if payment exists for this code
$check = $conn->query("
    SELECT p.*, t.status as transaction_status 
    FROM payments p
    JOIN transactions t ON p.transaction_id = t.id
    WHERE p.telebirr_code_5digit = '$code' AND p.status = 'confirmed'
");

$confirmed = $check->num_rows > 0;

$conn->close();

echo json_encode(['confirmed' => $confirmed]);
?>