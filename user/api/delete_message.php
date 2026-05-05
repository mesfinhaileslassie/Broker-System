<?php
// user/api/delete_message.php - Delete message

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
$message_id = $input['message_id'] ?? $_POST['message_id'] ?? 0;

if (!$message_id) {
    echo json_encode(['success' => false, 'error' => 'Missing message ID']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$result = deleteMessage($conn, $message_id, $user_id);

$conn->close();

echo json_encode($result);
?>