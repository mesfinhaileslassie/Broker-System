<?php
// user/accept_terms.php - Accept negotiation terms

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$negotiation_id = intval($_GET['negotiation_id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Verify ownership
$neg = $conn->query("SELECT * FROM listing_negotiations WHERE id = $negotiation_id AND seller_id = $user_id")->fetch_assoc();

if ($neg) {
    $conn->query("
        UPDATE listing_negotiations 
        SET status = 'agreement_accepted', accepted_at = NOW() 
        WHERE id = $negotiation_id
    ");
    
    // Add notification for admin
    $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
    $notif_stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, created_at) 
        VALUES (?, 'Terms Accepted', 'Seller has accepted the terms for listing. Awaiting deposit payment.', NOW())
    ");
    $notif_stmt->bind_param("i", $admin['id']);
    $notif_stmt->execute();
    
    $_SESSION['success'] = "Terms accepted! Please pay the deposit to publish your listing.";
} else {
    $_SESSION['error'] = "Invalid negotiation.";
}

$conn->close();
header('Location: listings.php');
exit;
?>