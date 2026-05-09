<?php
// ============================================
// FILE: broker_system/admin/ajax/get_negotiation_messages.php
// ============================================

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$negotiation_id = intval($_GET['id'] ?? 0);
if (!$negotiation_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid negotiation ID']);
    exit;
}

$conn = getDbConnection();

// Check if negotiation_messages table exists
$table_check = $conn->query("SHOW TABLES LIKE 'negotiation_messages'");
if ($table_check->num_rows == 0) {
    echo json_encode(['success' => true, 'messages' => []]);
    $conn->close();
    exit;
}

// Get messages
$result = $conn->query("
    SELECT nm.*, 
           CASE 
               WHEN nm.sender_type = 'admin' THEN 'Admin'
               WHEN nm.sender_type = 'seller' THEN 'Seller'
               ELSE 'System'
           END as sender_name
    FROM negotiation_messages nm
    WHERE nm.negotiation_id = $negotiation_id
    ORDER BY nm.created_at ASC
");

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'sender_type' => $row['sender_type'],
        'sender_name' => $row['sender_name'],
        'message' => $row['message'],
        'time' => date('M d, H:i', strtotime($row['created_at']))
    ];
}

$conn->close();
echo json_encode(['success' => true, 'messages' => $messages]);
?>