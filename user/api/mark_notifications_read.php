<?php
// user/api/mark_notifications_read.php - Mark all notifications as read

session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

$conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");

$conn->close();

echo json_encode(['success' => true]);
?>