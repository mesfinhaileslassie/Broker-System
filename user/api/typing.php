<?php
// user/api/typing.php - Typing indicator with proper user tracking

session_start();
require_once '../../config/database.php';
require_once '../../includes/chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
    echo json_encode(['typing' => false, 'typing_user_id' => null]);
    exit;
}

$conversation_id = $_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0;
$is_typing = $_POST['typing'] ?? false;
$user_id = $_SESSION['user_id'];

if (!$conversation_id) {
    echo json_encode(['typing' => false, 'typing_user_id' => null]);
    exit;
}

$conn = getDbConnection();

if ($is_typing !== false) {
    // Store typing status in database
    $table_exists = $conn->query("SHOW TABLES LIKE 'conversation_typing'");
    if ($table_exists->num_rows == 0) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS conversation_typing (
                conversation_id INT PRIMARY KEY,
                user_id INT,
                typing_until DATETIME,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                INDEX idx_typing (typing_until)
            )
        ");
    }
    
    $typing_until = date('Y-m-d H:i:s', time() + 3); // Typing indicator lasts 3 seconds
    
    if ($is_typing == 'true' || $is_typing === true) {
        $conn->query("
            INSERT INTO conversation_typing (conversation_id, user_id, typing_until) 
            VALUES ($conversation_id, $user_id, '$typing_until')
            ON DUPLICATE KEY UPDATE user_id = $user_id, typing_until = '$typing_until'
        ");
    } else {
        $conn->query("DELETE FROM conversation_typing WHERE conversation_id = $conversation_id AND user_id = $user_id");
    }
}

// Check if someone is typing in this conversation (other than current user)
$typing_data = $conn->query("
    SELECT user_id, typing_until 
    FROM conversation_typing 
    WHERE conversation_id = $conversation_id 
    AND typing_until > NOW()
    AND user_id != $user_id
    LIMIT 1
");

$is_other_typing = $typing_data && $typing_data->num_rows > 0;
$typing_user_id = null;

if ($is_other_typing) {
    $row = $typing_data->fetch_assoc();
    $typing_user_id = $row['user_id'];
}

$conn->close();

echo json_encode([
    'typing' => $is_other_typing,
    'typing_user_id' => $typing_user_id
]);
?>