<?php
// user/api/typing.php - Typing indicator

session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['typing' => false]);
    exit;
}

$conversation_id = $_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0;
$is_typing = $_POST['typing'] ?? false;

if ($conversation_id && $is_typing !== false) {
    // In a real WebSocket implementation, this would be broadcast
    // For polling, we'll use session or cache
    $_SESSION['typing_' . $conversation_id] = $is_typing ? time() : 0;
}

$typing = false;
if ($conversation_id && isset($_SESSION['typing_' . $conversation_id])) {
    $typing = (time() - $_SESSION['typing_' . $conversation_id]) < 3;
}

echo json_encode(['typing' => $typing]);
?>