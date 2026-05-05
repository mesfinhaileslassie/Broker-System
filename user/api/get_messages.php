<?php
// user/api/get_messages.php - Get messages API

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conversation_id = $_GET['conversation_id'] ?? 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Verify user has access to this conversation
$conv = $conn->query("SELECT user_id, broker_id FROM conversations WHERE id = $conversation_id")->fetch_assoc();

if (!$conv || ($conv['user_id'] != $user_id && $conv['broker_id'] != $user_id)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get messages
$stmt = $conn->prepare("
    SELECT m.*, u.full_name as sender_name 
    FROM messages m 
    JOIN users u ON m.sender_id = u.id 
    WHERE m.conversation_id = ? 
    ORDER BY m.created_at ASC 
    LIMIT 100
");
$stmt->bind_param("i", $conversation_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($msg = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $msg['id'],
        'sender_id' => $msg['sender_id'],
        'message' => $msg['message'],
        'time' => date('H:i', strtotime($msg['created_at'])),
        'date' => date('Y-m-d H:i:s', strtotime($msg['created_at']))
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'messages' => $messages
]);
?>