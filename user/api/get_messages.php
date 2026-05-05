<?php
// user/api/get_messages.php - Get messages with reactions

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

// Get messages with delete filter
$messages = getMessagesWithDeleteFilter($conn, $conversation_id, $user_id, 100, 0);

$result = [];
foreach ($messages as $msg) {
    $result[] = [
        'id' => $msg['id'],
        'sender_id' => $msg['sender_id'],
        'receiver_id' => $msg['receiver_id'],
        'message' => $msg['message'],
        'time' => date('H:i', strtotime($msg['created_at'])),
        'date' => date('Y-m-d H:i:s', strtotime($msg['created_at'])),
        'reactions' => $msg['reactions'],
        'my_reaction' => $msg['my_reaction'],
        'can_delete' => ($msg['sender_id'] == $user_id || $msg['receiver_id'] == $user_id)
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'messages' => $result
]);
?>