<?php
// api/reject_booking.php - Owner rejects booking

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$booking_id = isset($input['booking_id']) ? intval($input['booking_id']) : 0;
$user_id = $_SESSION['user_id'];

if (!$booking_id) {
    echo json_encode(['success' => false, 'error' => 'Booking ID required']);
    exit;
}

$conn = getDbConnection();

// Verify ownership
$booking = $conn->query("
    SELECT rb.*, t.id as transaction_id
    FROM rental_bookings rb
    JOIN transactions t ON rb.transaction_id = t.id
    WHERE rb.id = $booking_id AND rb.owner_id = $user_id
")->fetch_assoc();

if (!$booking) {
    echo json_encode(['success' => false, 'error' => 'Booking not found']);
    exit;
}

if ($booking['status'] != 'pending') {
    echo json_encode(['success' => false, 'error' => 'Booking already ' . $booking['status']]);
    exit;
}

$conn->begin_transaction();

try {
    // Update booking status
    $conn->query("UPDATE rental_bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = $user_id WHERE id = $booking_id");
    
    // Update transaction status
    $conn->query("UPDATE transactions SET status = 'cancelled' WHERE id = {$booking['transaction_id']}");
    
    // Notify tenant
    $message = "Your booking request was declined by the property owner.";
    $conn->query("
        INSERT INTO notifications (user_id, title, message, link, created_at) 
        VALUES ({$booking['tenant_id']}, 'Booking Declined', '$message', 'my_rentals.php', NOW())
    ");
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Booking declined successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>