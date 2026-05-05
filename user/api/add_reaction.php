<?php
// user/api/add_reaction.php - Add reaction to message

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
$reaction_type = $input['reaction_type'] ?? $_POST['reaction_type'] ?? '';

if (!$message_id || !$reaction_type) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Verify message belongs to user's conversation
$msg = $conn->query("
    SELECT m.*, c.user_id, c.broker_id 
    FROM messages m 
    JOIN conversations c ON m.conversation_id = c.id 
    WHERE m.id = $message_id
")->fetch_assoc();

if (!$msg || ($msg['user_id'] != $user_id && $msg['broker_id'] != $user_id)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

addReaction($conn, $message_id, $user_id, $reaction_type);

// Get updated reactions
$reactions = $conn->query("SELECT reaction_type, COUNT(*) as count FROM message_reactions WHERE message_id = $message_id GROUP BY reaction_type");
$reactions_json = [];
while($row = $reactions->fetch_assoc()) {
    $reactions_json[$row['reaction_type']] = $row['count'];
}

$conn->close();

echo json_encode([
    'success' => true,
    'reactions' => $reactions_json
]);
?>