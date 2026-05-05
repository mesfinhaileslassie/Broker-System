<?php
// user/api/start_conversation.php - Start new conversation

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/chat_functions.php';

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$broker_id = $_GET['broker_id'] ?? 0;

if (!$broker_id) {
    header('Location: /broker_system/user/chat.php');
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$conversation_id = getOrCreateConversation($conn, $user_id, $broker_id);

$conn->close();

header("Location: /broker_system/user/chat.php?id=$conversation_id");
?>