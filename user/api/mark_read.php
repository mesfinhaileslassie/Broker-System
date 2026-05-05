<?php
// user/api/mark_read.php - Mark messages as read

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

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

markMessagesAsRead($conn, $conversation_id, $user_id);

$conn->close();

echo json_encode(['success' => true]);
?>