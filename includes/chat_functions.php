<?php
// includes/chat_functions.php - Complete Fixed Version

require_once __DIR__ . '/../config/database.php';

function getOrCreateConversation($conn, $user_id, $broker_id) {
    // Prevent creating conversation with self
    if ($user_id == $broker_id) {
        return false;
    }
    
    // Check if conversation exists
    $stmt = $conn->prepare("SELECT id FROM conversations WHERE (user_id = ? AND broker_id = ?) OR (user_id = ? AND broker_id = ?)");
    $stmt->bind_param("iiii", $user_id, $broker_id, $broker_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['id'];
    }
    
    // Create new conversation
    $user_role = getUserRole($conn, $user_id);
    $broker_role = getUserRole($conn, $broker_id);
    
    // Ensure one is user and one is broker/admin
    $actual_user_id = ($user_role == 'user') ? $user_id : $broker_id;
    $actual_broker_id = ($broker_role == 'admin' || $broker_role == 'broker') ? $broker_id : $user_id;
    
    // Don't create if both are same
    if ($actual_user_id == $actual_broker_id) {
        return false;
    }
    
    $stmt = $conn->prepare("INSERT INTO conversations (user_id, broker_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
    $stmt->bind_param("ii", $actual_user_id, $actual_broker_id);
    $stmt->execute();
    
    return $conn->insert_id;
}

function getUserRole($conn, $user_id) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['role'];
    }
    return 'user';
}

function sendMessage($conn, $conversation_id, $sender_id, $receiver_id, $message) {
    // Don't send if sender and receiver are same
    if ($sender_id == $receiver_id) {
        return false;
    }
    
    $message = trim($message);
    if (empty($message)) return false;
    
    $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, message, status, created_at) VALUES (?, ?, ?, ?, 'sent', NOW())");
    $stmt->bind_param("iiis", $conversation_id, $sender_id, $receiver_id, $message);
    if (!$stmt->execute()) {
        return false;
    }
    $message_id = $conn->insert_id;
    
    // Update conversation last message
    $stmt2 = $conn->prepare("UPDATE conversations SET last_message = ?, last_message_time = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt2->bind_param("si", $message, $conversation_id);
    $stmt2->execute();
    
    // Update unread count for receiver
    $receiver_role = getUserRole($conn, $receiver_id);
    if ($receiver_role == 'admin' || $receiver_role == 'broker') {
        $conn->query("UPDATE conversations SET broker_unread_count = broker_unread_count + 1 WHERE id = $conversation_id");
    } else {
        $conn->query("UPDATE conversations SET user_unread_count = user_unread_count + 1 WHERE id = $conversation_id");
    }
    
    return $message_id;
}

function getMessagesWithDeleteFilter($conn, $conversation_id, $user_id, $limit = 50, $offset = 0) {
    $stmt = $conn->prepare("
        SELECT m.*, u.full_name as sender_name, u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ? 
        AND NOT (m.deleted_by_sender = 1 AND m.sender_id = ?)
        AND NOT (m.deleted_by_receiver = 1 AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiiii", $conversation_id, $user_id, $user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        // Get reactions for this message
        $reactions = getMessageReactions($conn, $row['id']);
        
        // Get user's own reaction
        $my_reaction = null;
        $reaction_check = $conn->prepare("SELECT reaction_type FROM message_reactions WHERE message_id = ? AND user_id = ?");
        $reaction_check->bind_param("ii", $row['id'], $user_id);
        $reaction_check->execute();
        $reaction_result = $reaction_check->get_result();
        if ($reaction_result->num_rows > 0) {
            $my_reaction = $reaction_result->fetch_assoc()['reaction_type'];
        }
        
        $row['reactions'] = $reactions;
        $row['my_reaction'] = $my_reaction;
        $messages[] = $row;
    }
    
    return $messages;
}

function getMessageReactions($conn, $message_id) {
    $reactions = $conn->prepare("
        SELECT reaction_type, COUNT(*) as count
        FROM message_reactions 
        WHERE message_id = ? 
        GROUP BY reaction_type
    ");
    $reactions->bind_param("i", $message_id);
    $reactions->execute();
    $result = $reactions->get_result();
    
    $reaction_data = [];
    while($row = $result->fetch_assoc()) {
        $reaction_data[$row['reaction_type']] = $row['count'];
    }
    return $reaction_data;
}

function addReaction($conn, $message_id, $user_id, $reaction_type) {
    // First, get the message to verify user has access
    $msg_check = $conn->prepare("
        SELECT m.*, c.user_id, c.broker_id 
        FROM messages m 
        JOIN conversations c ON m.conversation_id = c.id 
        WHERE m.id = ?
    ");
    $msg_check->bind_param("i", $message_id);
    $msg_check->execute();
    $message = $msg_check->get_result()->fetch_assoc();
    
    if (!$message || ($message['user_id'] != $user_id && $message['broker_id'] != $user_id)) {
        return false;
    }
    
    // Check if reaction exists
    $check = $conn->prepare("SELECT id FROM message_reactions WHERE message_id = ? AND user_id = ?");
    $check->bind_param("ii", $message_id, $user_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing reaction
        $update = $conn->prepare("UPDATE message_reactions SET reaction_type = ? WHERE message_id = ? AND user_id = ?");
        $update->bind_param("sii", $reaction_type, $message_id, $user_id);
        $update->execute();
    } else {
        // Add new reaction
        $insert = $conn->prepare("INSERT INTO message_reactions (message_id, user_id, reaction_type) VALUES (?, ?, ?)");
        $insert->bind_param("iis", $message_id, $user_id, $reaction_type);
        $insert->execute();
    }
    
    return true;
}

function deleteMessage($conn, $message_id, $user_id) {
    // Get message details
    $msg = $conn->prepare("
        SELECT m.*, c.user_id, c.broker_id 
        FROM messages m 
        JOIN conversations c ON m.conversation_id = c.id 
        WHERE m.id = ?
    ");
    $msg->bind_param("i", $message_id);
    $msg->execute();
    $message = $msg->get_result()->fetch_assoc();
    
    if (!$message) {
        return ['success' => false, 'error' => 'Message not found'];
    }
    
    // Determine if user is sender or receiver
    $is_sender = ($message['sender_id'] == $user_id);
    $is_receiver = ($message['receiver_id'] == $user_id);
    
    if (!$is_sender && !$is_receiver) {
        return ['success' => false, 'error' => 'Unauthorized'];
    }
    
    if ($is_sender) {
        // Delete for sender only
        $conn->query("UPDATE messages SET deleted_by_sender = 1, deleted_at = NOW() WHERE id = $message_id");
    } else {
        // Delete for receiver only
        $conn->query("UPDATE messages SET deleted_by_receiver = 1, deleted_at = NOW() WHERE id = $message_id");
    }
    
    // Check if both have deleted, then hard delete
    $check = $conn->query("SELECT deleted_by_sender, deleted_by_receiver FROM messages WHERE id = $message_id")->fetch_assoc();
    if ($check['deleted_by_sender'] && $check['deleted_by_receiver']) {
        $conn->query("DELETE FROM messages WHERE id = $message_id");
        $conn->query("DELETE FROM message_reactions WHERE message_id = $message_id");
    }
    
    return ['success' => true];
}

function markMessagesAsRead($conn, $conversation_id, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $conn->query("UPDATE conversations SET broker_unread_count = 0 WHERE id = $conversation_id");
    } else {
        $conn->query("UPDATE conversations SET user_unread_count = 0 WHERE id = $conversation_id");
    }
    
    $conn->query("UPDATE messages SET is_read = 1, read_at = NOW() WHERE conversation_id = $conversation_id AND receiver_id = $user_id AND is_read = 0");
}

function getUserConversations($conn, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $sql = "
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.broker_unread_count > 0 THEN c.broker_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.user_id = u.id
            WHERE c.broker_id = ? AND c.status = 'active'
              AND u.role = 'user'
              AND u.id != ?
            ORDER BY c.updated_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $user_id);
    } else {
        $sql = "
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.user_unread_count > 0 THEN c.user_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.broker_id = u.id
            WHERE c.user_id = ? AND c.status = 'active'
              AND u.role IN ('admin', 'broker')
              AND u.id != ?
            ORDER BY c.updated_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result;
}

function getUnreadMessageCount($conn, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $result = $conn->query("SELECT SUM(broker_unread_count) as total FROM conversations WHERE broker_id = $user_id");
    } else {
        $result = $conn->query("SELECT SUM(user_unread_count) as total FROM conversations WHERE user_id = $user_id");
    }
    
    $row = $result->fetch_assoc();
    return $row['total'] ?? 0;
}

function getConversationById($conn, $conversation_id, $user_id) {
    $user_role = getUserRole($conn, $user_id);
    
    if ($user_role == 'admin' || $user_role == 'broker') {
        $stmt = $conn->prepare("
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.broker_unread_count > 0 THEN c.broker_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ? AND c.broker_id = ? AND c.status = 'active'
              AND u.role = 'user'
        ");
        $stmt->bind_param("ii", $conversation_id, $user_id);
    } else {
        $stmt = $conn->prepare("
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.user_unread_count > 0 THEN c.user_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.broker_id = u.id
            WHERE c.id = ? AND c.user_id = ? AND c.status = 'active'
              AND u.role IN ('admin', 'broker')
        ");
        $stmt->bind_param("ii", $conversation_id, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $conversation = $result->fetch_assoc();
        // Don't return conversation if it's with self
        if ($conversation['other_user_id'] == $user_id) {
            return null;
        }
        return $conversation;
    }
    return null;
}
?>