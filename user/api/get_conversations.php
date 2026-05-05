<?php
// user/api/get_conversations.php - Get conversations for sidebar

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$conversations = getUserConversations($conn, $user_id);

$result = [];
while ($conv = $conversations->fetch_assoc()) {
    $result[] = [
        'id' => $conv['id'],
        'other_user_name' => $conv['other_user_name'],
        'last_message' => $conv['last_message'],
        'last_message_time' => $conv['last_message_time'],
        'unread_count' => $conv['unread_count']
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'conversations' => $result
]);
?>