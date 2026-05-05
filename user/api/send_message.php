<?php
// user/api/send_message.php - Send message API

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conversation_id = $_POST['conversation_id'] ?? 0;
$message = $_POST['message'] ?? '';

if (!$conversation_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get conversation details
$conv = $conn->query("SELECT user_id, broker_id FROM conversations WHERE id = $conversation_id")->fetch_assoc();

if (!$conv) {
    echo json_encode(['success' => false, 'error' => 'Conversation not found']);
    exit;
}

$receiver_id = ($conv['user_id'] == $user_id) ? $conv['broker_id'] : $conv['user_id'];

$message_id = sendMessage($conn, $conversation_id, $user_id, $receiver_id, $message);

$conn->close();

echo json_encode([
    'success' => true,
    'message_id' => $message_id
]);
?>