<?php
// user/initiate_rental.php - Create rental booking and redirect to payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_POST['listing_id']) ? intval($_POST['listing_id']) : 0;

// Get listing details
$listing = $conn->query("
    SELECT l.*, u.id as owner_id, u.full_name as owner_name, u.email as owner_email
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $listing_id AND l.type = 'rental' AND l.status = 'active'
")->fetch_assoc();

if (!$listing) {
    header('Location: browse.php');
    exit;
}

// Get form data
$check_in = isset($_POST['check_in']) ? $_POST['check_in'] : '';
$check_out = isset($_POST['check_out']) ? $_POST['check_out'] : '';
$guests = isset($_POST['guests']) ? intval($_POST['guests']) : 1;
$guest_name = isset($_POST['guest_name']) ? $_POST['guest_name'] : $_SESSION['user_name'];
$guest_phone = isset($_POST['phone']) ? $_POST['phone'] : '';
$special_requests = isset($_POST['message']) ? $_POST['message'] : '';

// Validate dates
if (empty($check_in) || empty($check_out)) {
    $_SESSION['error'] = "Please select check-in and check-out dates";
    header("Location: rental_booking.php?id=$listing_id");
    exit;
}

// Calculate nights and totals
$check_in_date = new DateTime($check_in);
$check_out_date = new DateTime($check_out);
$nights = $check_in_date->diff($check_out_date)->days;

if ($nights <= 0) {
    $_SESSION['error'] = "Check-out date must be after check-in date";
    header("Location: rental_booking.php?id=$listing_id");
    exit;
}

$depositPercent = $listing['admin_deposit_percent'] ?? 30;
$commissionPercent = $listing['admin_commission_percent'] ?? 15;
$total_rent = $listing['price'] * $nights;
$deposit_amount = $total_rent * ($depositPercent / 100);
$commission_amount = $total_rent * ($commissionPercent / 100);
$total_payment = $deposit_amount + $commission_amount;
$remaining_amount = $total_rent - $deposit_amount;

// Check if already has a pending booking for this property
$existing_booking = $conn->query("
    SELECT rb.id, rb.status, t.status as transaction_status
    FROM rental_bookings rb
    JOIN transactions t ON rb.transaction_id = t.id
    WHERE rb.property_id = $listing_id AND rb.tenant_id = $user_id 
    AND rb.status IN ('pending', 'confirmed')
");

if ($existing_booking->num_rows > 0) {
    $booking = $existing_booking->fetch_assoc();
    if ($booking['status'] == 'pending') {
        // Get the transaction
        $txn = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id AND buyer_id = $user_id")->fetch_assoc();
        if ($txn) {
            header("Location: pay_rent.php?transaction_id={$txn['id']}");
            exit;
        }
    }
}

$conn->begin_transaction();

try {
    // Create transaction
    $stmt = $conn->prepare("
        INSERT INTO transactions (
            listing_id, buyer_id, seller_id, total_amount, 
            deposit_amount, commission_amount, remaining_balance, 
            status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW())
    ");
    $stmt->bind_param("iiiiddd", $listing_id, $user_id, $listing['owner_id'], $total_rent, $deposit_amount, $commission_amount, $remaining_amount);
    $stmt->execute();
    $transaction_id = $conn->insert_id;
    
    // Create rental booking record
    $stmt2 = $conn->prepare("
        INSERT INTO rental_bookings (
            transaction_id, property_id, tenant_id, owner_id, 
            check_in_date, check_out_date, total_nights, total_amount, deposit_paid,
            guest_name, guest_phone, special_requests, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt2->bind_param("iiiiissdssss", 
        $transaction_id, $listing_id, $user_id, $listing['owner_id'],
        $check_in, $check_out, $nights, $total_rent, $deposit_amount,
        $guest_name, $guest_phone, $special_requests
    );
    $stmt2->execute();
    
    $conn->commit();
    
    // Redirect to payment
    header("Location: pay_rent.php?transaction_id=$transaction_id");
    exit;
    
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Booking failed: " . $e->getMessage();
    header("Location: rental_booking.php?id=$listing_id");
    exit;
}

$conn->close();
?>