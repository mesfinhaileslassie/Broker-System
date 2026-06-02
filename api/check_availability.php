<?php
// ============================================
// FILE: api/check_availability.php
// Description: Check if listing is available for dates
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../config/database.php';
require_once '../includes/AvailabilityManager.php';

$input = json_decode(file_get_contents('php://input'), true);

$listing_id = isset($input['listing_id']) ? intval($input['listing_id']) : 0;
$check_in = isset($input['check_in']) ? $input['check_in'] : '';
$check_out = isset($input['check_out']) ? $input['check_out'] : '';

if (!$listing_id || !$check_in || !$check_out) {
    echo json_encode(['available' => false, 'message' => 'Missing required parameters']);
    exit;
}

$conn = getDbConnection();
$availabilityManager = new AvailabilityManager($conn);

$available = $availabilityManager->isAvailable($listing_id, $check_in, $check_out);

echo json_encode([
    'available' => $available,
    'message' => $available ? 'Available for selected dates' : 'Not available for selected dates'
]);

$conn->close();
?>