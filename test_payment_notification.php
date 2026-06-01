<?php
// test_payment_notification.php - Run this to test seller notification

require_once 'config/database.php';
require_once 'includes/functions.php';

$conn = getDbConnection();

// Get transaction 61 details
$transaction = $conn->query("
    SELECT t.*, l.title, u1.full_name as buyer_name, u1.email as buyer_email, u2.id as seller_id, u2.full_name as seller_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = 61
")->fetch_assoc();

if ($transaction) {
    $depositAmount = $transaction['total_amount'] * 0.3;
    $commissionAmount = $transaction['total_amount'] * 0.15;
    $totalPayment = $depositAmount + $commissionAmount;
    
    // Create notification message
    $message = "💰💰 PAYMENT RECEIVED! 💰💰\n\n";
    $message .= "Guest: {$transaction['buyer_name']}\n";
    $message .= "Property: {$transaction['title']}\n";
    $message .= "Amount Paid: " . formatMoney($totalPayment) . " (30% deposit)\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "✅ Payment is held in escrow.\n";
    $message .= "📌 You will receive the remaining balance after check-out.\n";
    $message .= "📱 Click to view booking details.";
    
    // Insert notification
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
        VALUES (?, '💰 NEW PAYMENT RECEIVED - Guest Paid 30% Deposit', ?, 'owner_bookings.php', 0, NOW())
    ");
    $stmt->bind_param("is", $transaction['seller_id'], $message);
    
    if ($stmt->execute()) {
        echo "✅ Notification sent to seller (User ID: {$transaction['seller_id']})<br>";
        echo "Seller Name: {$transaction['seller_name']}<br>";
        echo "Message: " . nl2br(htmlspecialchars($message)) . "<br>";
    } else {
        echo "❌ Failed: " . $conn->error;
    }
} else {
    echo "Transaction 61 not found!";
}

$conn->close();
?>