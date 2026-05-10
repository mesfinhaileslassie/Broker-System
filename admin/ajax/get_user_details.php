<?php
// admin/ajax/get_user_details.php - Get user details for modal

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = intval($_GET['id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

$conn = getDbConnection();

$user = $conn->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM listings WHERE seller_id = u.id) as total_listings,
           (SELECT COUNT(*) FROM transactions WHERE buyer_id = u.id OR seller_id = u.id) as total_transactions
    FROM users u
    WHERE u.id = $user_id
")->fetch_assoc();

$conn->close();

if ($user) {
    echo json_encode(['success' => true, 'user' => $user]);
} else {
    echo json_encode(['success' => false, 'error' => 'User not found']);
}
?>