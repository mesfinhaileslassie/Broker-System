<?php
// user/api/clear_history.php - Clear all messages in conversation

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$conversation_id = $input['conversation_id'] ?? $_POST['conversation_id'] ?? 0;

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

// Delete all messages in this conversation for this user
$conn->query("UPDATE messages SET deleted_by_sender = 1, deleted_at = NOW() WHERE conversation_id = $conversation_id AND sender_id = $user_id");
$conn->query("UPDATE messages SET deleted_by_receiver = 1, deleted_at = NOW() WHERE conversation_id = $conversation_id AND receiver_id = $user_id");

// Check for messages that are deleted by both
$both_deleted = $conn->query("
    SELECT id FROM messages 
    WHERE conversation_id = $conversation_id 
    AND deleted_by_sender = 1 AND deleted_by_receiver = 1
");

while ($msg = $both_deleted->fetch_assoc()) {
    $conn->query("DELETE FROM messages WHERE id = {$msg['id']}");
    $conn->query("DELETE FROM message_reactions WHERE message_id = {$msg['id']}");
}

// Update conversation last message
$conn->query("UPDATE conversations SET last_message = NULL, last_message_time = NULL, updated_at = NOW() WHERE id = $conversation_id");

$conn->close();

echo json_encode(['success' => true]);
?>