<?php
// api/confirm_booking.php - Owner confirms booking

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

// Get booking details and verify ownership
$booking = $conn->query("
    SELECT rb.*, l.title, t.status as transaction_status
    FROM rental_bookings rb
    JOIN listings l ON rb.property_id = l.id
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
    $conn->query("UPDATE rental_bookings SET status = 'confirmed' WHERE id = $booking_id");
    
    // Update transaction status if needed
    if ($booking['transaction_status'] != 'deposits_complete') {
        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$booking['transaction_id']}");
    }
    
    // Create notification for tenant
    $tenant_message = "Good news! Your booking for {$booking['title']} has been confirmed by the owner. Your dates are now secured.";
    $conn->query("
        INSERT INTO notifications (user_id, title, message, link, created_at) 
        VALUES ({$booking['tenant_id']}, 'Booking Confirmed', '$tenant_message', 'my_rentals.php', NOW())
    ");
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Booking confirmed successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>