<?php
// api/force_activate.php - Force activate listing (for testing only)

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$listing_id = isset($input['listing_id']) ? intval($input['listing_id']) : 0;
$user_id = $_SESSION['user_id'];

if (!$listing_id) {
    echo json_encode(['success' => false, 'error' => 'Listing ID required']);
    exit;
}

$conn = getDbConnection();

// Verify ownership
$check = $conn->query("SELECT id FROM listings WHERE id = $listing_id AND seller_id = $user_id");
if ($check->num_rows == 0) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Force activate
$conn->query("UPDATE listings SET status = 'active' WHERE id = $listing_id");

echo json_encode(['success' => true, 'message' => 'Listing activated']);

$conn->close();
?>