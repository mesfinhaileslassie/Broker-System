<?php
// includes/chat_functions.php - Chat system helper functions (COMPLETELY FIXED)

require_once __DIR__ . '/../config/database.php';

function getOrCreateConversation($conn, $user_id, $broker_id) {
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

function getMessages($conn, $conversation_id, $limit = 50, $offset = 0) {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    
    // First, verify the conversation exists
    $check = $conn->prepare("SELECT id FROM conversations WHERE id = ?");
    $check->bind_param("i", $conversation_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        return [];
    }
    
    $stmt = $conn->prepare("
        SELECT m.*, u.full_name as sender_name, u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $conversation_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        // Get reactions for this message
        $reactions = [];
        $reaction_query = $conn->prepare("SELECT reaction_type, COUNT(*) as count FROM message_reactions WHERE message_id = ? GROUP BY reaction_type");
        $reaction_query->bind_param("i", $row['id']);
        $reaction_query->execute();
        $reaction_result = $reaction_query->get_result();
        while ($react = $reaction_result->fetch_assoc()) {
            $reactions[$react['reaction_type']] = $react['count'];
        }
        
        // Get my reaction if any
        if ($user_id > 0) {
            $my_reaction_query = $conn->prepare("SELECT reaction_type FROM message_reactions WHERE message_id = ? AND user_id = ?");
            $my_reaction_query->bind_param("ii", $row['id'], $user_id);
            $my_reaction_query->execute();
            $my_reaction_result = $my_reaction_query->get_result();
            $row['my_reaction'] = $my_reaction_result->num_rows > 0 ? $my_reaction_result->fetch_assoc()['reaction_type'] : null;
        } else {
            $row['my_reaction'] = null;
        }
        
        $row['reactions'] = $reactions;
        $messages[] = $row;
    }
    
    return $messages;
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

function addReaction($conn, $message_id, $user_id, $reaction_type) {
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
            ORDER BY c.updated_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    } else {
        $sql = "
            SELECT c.*, 
                   u.id as other_user_id, u.full_name as other_user_name, u.email as other_user_email,
                   CASE WHEN c.user_unread_count > 0 THEN c.user_unread_count ELSE 0 END as unread_count
            FROM conversations c
            JOIN users u ON c.broker_id = u.id
            WHERE c.user_id = ? AND c.status = 'active'
            ORDER BY c.updated_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    }
    
    $stmt->execute();
    return $stmt->get_result();
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
    $stmt = $conn->prepare("
        SELECT c.*, 
               CASE WHEN c.broker_id = ? THEN u.full_name ELSE u2.full_name END as other_user_name,
               CASE WHEN c.broker_id = ? THEN u.id ELSE u2.id END as other_user_id
        FROM conversations c
        JOIN users u ON c.user_id = u.id
        JOIN users u2 ON c.broker_id = u2.id
        WHERE c.id = ? AND (c.user_id = ? OR c.broker_id = ?)
    ");
    $stmt->bind_param("iiiii", $user_id, $user_id, $conversation_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}
?>