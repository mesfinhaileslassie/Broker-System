<?php
// user/initiate_rental.php - Create transaction and redirect to payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$listing_id = isset($_POST['listing_id']) ? intval($_POST['listing_id']) : 0;

// Get listing details
$listing = $conn->query("
    SELECT l.*, u.id as seller_id 
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $listing_id AND l.status = 'active' AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$listing) {
    header('Location: browse.php');
    exit;
}

// Calculate amounts
$depositPercent = $listing['admin_deposit_percent'] ?? 30;
$commissionPercent = $listing['admin_commission_percent'] ?? 15;
$depositAmount = $listing['price'] * ($depositPercent / 100);
$commissionAmount = $listing['price'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;
$remainingAmount = $listing['price'] - $depositAmount;

// Check if transaction already exists
$existing = $conn->query("
    SELECT id FROM transactions 
    WHERE listing_id = $listing_id AND buyer_id = $user_id
");

if ($existing->num_rows > 0) {
    $txn = $existing->fetch_assoc();
    header("Location: pay_rent.php?transaction_id={$txn['id']}");
    exit;
}

// Create transaction
$stmt = $conn->prepare("
    INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW())
");
$stmt->bind_param("iiiiddd", $listing_id, $user_id, $listing['seller_id'], $listing['price'], $depositAmount, $commissionAmount, $remainingAmount);
$stmt->execute();
$transaction_id = $conn->insert_id;

$conn->close();

// Redirect to payment page
header("Location: pay_rent.php?transaction_id=$transaction_id");
exit;
?>