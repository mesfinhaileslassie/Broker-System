<?php
// api/confirm_payment.php - Confirm payment by code (all payment types)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../includes/payment_confirm.php';

date_default_timezone_set('Africa/Addis_Ababa');

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$code = trim($input['payment_code'] ?? $input['code'] ?? '');
$pin = trim($input['pin'] ?? '');

if ($code === '') {
    echo json_encode(['success' => false, 'error' => 'Payment code is required']);
    exit;
}

$user_id = null;
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in']) {
    $user_id = (int) $_SESSION['user_id'];
    // Logged-in users must confirm with test PIN (Telebirr demo)
    if ($pin !== '1234') {
        echo json_encode(['success' => false, 'error' => 'Incorrect PIN. Use 1234 for testing']);
        exit;
    }
}

$result = confirmPaymentByCode($conn, $code, ['user_id' => $user_id]);

$conn->close();
echo json_encode($result);
