<?php
// api/confirm_payment.php

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Ethiopia timezone
date_default_timezone_set('Africa/Addis_Ababa');

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$input = json_decode(file_get_contents('php://input'), true);

$code = trim($input['payment_code'] ?? '');
$pin = trim($input['pin'] ?? '');

if (empty($code)) {
    echo json_encode([
        'success' => false,
        'error' => 'Payment code is required'
    ]);
    exit;
}

// Demo PIN
if ($pin !== '1234') {
    echo json_encode([
        'success' => false,
        'error' => 'Incorrect PIN. Use 1234'
    ]);
    exit;
}

// ==========================================
// Find payment code from database
// ==========================================
$stmt = $conn->prepare("
    SELECT 
        pc.*,
        t.id as transaction_id,
        t.listing_id,
        t.seller_id
    FROM payment_codes pc
    JOIN transactions t 
        ON pc.transaction_id = t.id
    WHERE pc.code = ?
    AND pc.type = 'deposit_seller'
    LIMIT 1
");

$stmt->bind_param("s", $code);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid payment code'
    ]);
    exit;
}

$payment_code = $result->fetch_assoc();

// ==========================================
// Check if expired
// ==========================================
$expiry_check = $conn->query("
    SELECT TIMESTAMPDIFF(SECOND, NOW(), '{$payment_code['expires_at']}') as seconds_left
")->fetch_assoc();

if ($expiry_check['seconds_left'] <= 0) {

    $conn->query("
        UPDATE payment_codes
        SET status = 'expired'
        WHERE id = {$payment_code['id']}
    ");

    echo json_encode([
        'success' => false,
        'error' => 'Payment code expired'
    ]);
    exit;
}

// ==========================================
// Already used?
// ==========================================
if ($payment_code['status'] === 'used') {
    echo json_encode([
        'success' => false,
        'error' => 'Payment already completed'
    ]);
    exit;
}

$conn->begin_transaction();

try {

    // ======================================
    // Create payment record
    // IMPORTANT: type = deposit_seller
    // ======================================
    $stmt = $conn->prepare("
        INSERT INTO payments (
            transaction_id,
            user_id,
            amount,
            type,
            telebirr_code_5digit,
            status,
            confirmed_at,
            created_at
        )
        VALUES (
            ?, ?, ?, 
            'deposit_seller',
            ?, 
            'confirmed',
            NOW(),
            NOW()
        )
    ");

    $stmt->bind_param(
        "iids",
        $payment_code['transaction_id'],
        $payment_code['user_id'],
        $payment_code['amount'],
        $code
    );

    $stmt->execute();

    // ======================================
    // Mark code as used
    // ======================================
    $conn->query("
        UPDATE payment_codes
        SET status = 'used'
        WHERE id = {$payment_code['id']}
    ");

    // ======================================
    // Update transaction
    // ======================================
    $conn->query("
        UPDATE transactions
        SET
            escrow_held = escrow_held + {$payment_code['amount']},
            status = 'deposits_complete'
        WHERE id = {$payment_code['transaction_id']}
    ");

    // ======================================
    // Activate listing
    // ======================================
    $conn->query("
        UPDATE listings
        SET status = 'active'
        WHERE id = {$payment_code['listing_id']}
    ");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment confirmed successfully'
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();