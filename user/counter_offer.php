<?php
// user/counter_offer.php - Send counter offer

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDbConnection();
    $negotiation_id = intval($_POST['negotiation_id'] ?? 0);
    $counter_commission = floatval($_POST['counter_commission'] ?? 0);
    $counter_deposit = floatval($_POST['counter_deposit'] ?? 0);
    $counter_message = $conn->real_escape_string($_POST['counter_message'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    $neg = $conn->query("SELECT id FROM listing_negotiations WHERE id = $negotiation_id AND seller_id = $user_id")->fetch_assoc();
    
    if ($neg) {
        $stmt = $conn->prepare("
            UPDATE listing_negotiations 
            SET counter_commission = ?, counter_deposit = ?, counter_message = ?, status = 'counter_offer_sent' 
            WHERE id = ?
        ");
        $stmt->bind_param("ddsi", $counter_commission, $counter_deposit, $counter_message, $negotiation_id);
        $stmt->execute();
        
        // Notify admin
        $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, created_at) 
            VALUES (?, 'Counter Offer Received', 'A seller has sent a counter offer. Please review.', NOW())
        ");
        $notif_stmt->bind_param("i", $admin['id']);
        $notif_stmt->execute();
        
        $_SESSION['success'] = "Counter offer sent! Admin will review your proposal.";
    }
    
    $conn->close();
}

header('Location: listings.php');
exit;
?>