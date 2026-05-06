<?php
// user/messages.php - User Messages Page (Redirect to Chat)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

// Redirect to the chat page since we have a full chat system
header('Location: chat.php');
exit;
?>