<?php
// admin/chat.php - Chat Interface for Admin/Broker

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/chat_functions.php';

// Check if logged in and is admin/broker
if (!isLoggedIn() || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'broker')) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get all users (for starting new conversations)
$users = $conn->query("SELECT id, full_name, email FROM users WHERE role = 'user' ORDER BY full_name");

// Get admin's conversations
$conversations = getUserConversations($conn, $user_id);

// Get conversation messages
$messages = [];
$current_conversation = null;
if ($conversation_id > 0) {
    $current_conversation = getConversationById($conn, $conversation_id, $user_id);
    
    if ($current_conversation) {
        $messages = getMessages($conn, $conversation_id, 50, 0);
        markMessagesAsRead($conn, $conversation_id, $user_id);
    }
}

$unread_count = getUnreadMessageCount($conn, $user_id);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Admin Chat - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            height: 100vh;
            overflow: hidden;
        }

        /* Chat Container */
        .chat-container {
            display: flex;
            height: 100vh;
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
        }

        /* Sidebar - Conversations List */
        .chat-sidebar {
            width: 320px;
            background: white;
            border-right: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            background: white;
        }

        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .new-chat-btn {
            width: 100%;
            margin-top: 12px;
            padding: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .new-chat-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
        }

        /* Search */
        .search-box {
            padding: 12px 16px;
            border-bottom: 1px solid #e9ecef;
        }

        .search-box input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 13px;
            background: #f8fafc;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
        }

        /* Conversations List */
        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            display: flex;
            align-items: center;
            padding: 16px;
            gap: 12px;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 1px solid #f0f2f5;
        }

        .conversation-item:hover {
            background: #f8fafc;
        }

        .conversation-item.active {
            background: #eef2ff;
            border-left: 3px solid #667eea;
        }

        .conversation-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .conversation-name {
            font-weight: 600;
            font-size: 14px;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .conversation-last {
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-meta {
            text-align: right;
        }

        .conversation-time {
            font-size: 10px;
            color: #94a3b8;
        }

        .unread-badge {
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
            display: inline-block;
            margin-top: 4px;
        }

        /* Chat Main Area */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: #f8fafc;
        }

        /* Chat Header */
        .chat-header {
            padding: 16px 24px;
            background: white;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .back-btn {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #64748b;
        }

        .chat-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .chat-header-info h3 {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }

        .chat-header-info p {
            font-size: 12px;
            color: #64748b;
        }

        /* Messages Area */
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Message Bubbles */
        .message {
            display: flex;
            max-width: 70%;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.sent {
            align-self: flex-end;
        }

        .message.received {
            align-self: flex-start;
        }

        .message-bubble {
            padding: 10px 14px;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
        }

        .message.sent .message-bubble {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.received .message-bubble {
            background: white;
            color: #1e293b;
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .message-time {
            font-size: 9px;
            margin-top: 4px;
            opacity: 0.7;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Reactions */
        .message-reactions {
            display: flex;
            gap: 6px;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        .reaction-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 20px;
            transition: all 0.2s;
        }

        .reaction-btn:hover {
            background: rgba(0,0,0,0.05);
            transform: scale(1.1);
        }

        .reaction-count {
            font-size: 10px;
            color: #64748b;
            margin-left: 2px;
        }

        /* Typing Indicator */
        .typing-indicator {
            position: absolute;
            bottom: 80px;
            left: 20px;
            background: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            color: #64748b;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: none;
        }

        /* Input Area */
        .chat-input-area {
            padding: 16px 20px;
            background: white;
            border-top: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            font-size: 14px;
            resize: none;
            font-family: inherit;
            max-height: 100px;
        }

        .chat-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .send-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .send-btn:hover {
            transform: scale(1.05);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 24px;
            width: 400px;
            max-width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 18px;
        }

        .close-modal {
            cursor: pointer;
            font-size: 24px;
        }

        .user-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            cursor: pointer;
            border-radius: 12px;
            transition: background 0.2s;
        }

        .user-item:hover {
            background: #f1f5f9;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .chat-sidebar {
                position: fixed;
                left: -320px;
                z-index: 100;
                transition: left 0.3s;
            }
            .chat-sidebar.open {
                left: 0;
            }
            .back-btn {
                display: block;
            }
            .message {
                max-width: 85%;
            }
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <!-- Conversations Sidebar -->
        <div class="chat-sidebar" id="chatSidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-comments"></i> Messages</h2>
                <button class="new-chat-btn" onclick="openNewChatModal()">
                    <i class="fas fa-plus"></i> New Conversation
                </button>
            </div>
            <div class="search-box">
                <input type="text" id="searchConversations" placeholder="Search conversations...">
            </div>
            <div class="conversations-list" id="conversationsList">
                <?php if ($conversations && $conversations->num_rows > 0): ?>
                    <?php while($conv = $conversations->fetch_assoc()): ?>
                        <div class="conversation-item <?php echo $conversation_id == $conv['id'] ? 'active' : ''; ?>" 
                             onclick="loadConversation(<?php echo $conv['id']; ?>)"
                             data-conv-id="<?php echo $conv['id']; ?>">
                            <div class="conversation-avatar">
                                <?php echo strtoupper(substr($conv['other_user_name'], 0, 1)); ?>
                            </div>
                            <div class="conversation-info">
                                <div class="conversation-name"><?php echo htmlspecialchars($conv['other_user_name']); ?></div>
                                <div class="conversation-last"><?php echo htmlspecialchars(substr($conv['last_message'] ?? '', 0, 30)); ?></div>
                            </div>
                            <div class="conversation-meta">
                                <div class="conversation-time">
                                    <?php 
                                    if ($conv['last_message_time']) {
                                        echo date('H:i', strtotime($conv['last_message_time']));
                                    }
                                    ?>
                                </div>
                                <?php if ($conv['unread_count'] > 0): ?>
                                    <div class="unread-badge"><?php echo $conv['unread_count']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: #94a3b8;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                        No messages yet
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Main Area -->
        <div class="chat-main">
            <?php if ($current_conversation): ?>
                <div class="chat-header">
                    <button class="back-btn" onclick="toggleSidebar()"><i class="fas fa-arrow-left"></i></button>
                    <div class="chat-avatar"><?php echo strtoupper(substr($current_conversation['other_user_name'], 0, 1)); ?></div>
                    <div class="chat-header-info">
                        <h3><?php echo htmlspecialchars($current_conversation['other_user_name']); ?></h3>
                        <p id="typingStatus"></p>
                    </div>
                </div>

                <div class="messages-area" id="messagesArea">
                    <?php 
                    $messages_ordered = array_reverse($messages);
                    foreach($messages_ordered as $msg): 
                        // Calculate reaction counts safely
                        $reaction_counts = [];
                        if (isset($msg['reactions']) && is_array($msg['reactions'])) {
                            $reaction_counts = $msg['reactions'];
                        } elseif (isset($msg['reactions']) && is_string($msg['reactions'])) {
                            $reaction_counts = json_decode($msg['reactions'], true) ?: [];
                        }
                        $total_reactions = array_sum($reaction_counts);
                    ?>
                        <div class="message <?php echo $msg['sender_id'] == $user_id ? 'sent' : 'received'; ?>" data-msg-id="<?php echo $msg['id']; ?>">
                            <div class="message-bubble">
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                <div class="message-time">
                                    <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                </div>
                                <div class="message-reactions" id="reactions-<?php echo $msg['id']; ?>">
                                    <button class="reaction-btn" onclick="addReaction(<?php echo $msg['id']; ?>, 'like')">👍</button>
                                    <button class="reaction-btn" onclick="addReaction(<?php echo $msg['id']; ?>, 'dislike')">👎</button>
                                    <button class="reaction-btn" onclick="addReaction(<?php echo $msg['id']; ?>, 'love')">❤️</button>
                                    <button class="reaction-btn" onclick="addReaction(<?php echo $msg['id']; ?>, 'laugh')">😂</button>
                                    <span class="reaction-count" id="reaction-count-<?php echo $msg['id']; ?>">
                                        <?php 
                                        $total_reactions = is_array($msg['reactions']) ? array_sum($msg['reactions']) : 0;
                                        echo $total_reactions > 0 ? $total_reactions : '';
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="typing-indicator" id="typingIndicator">
                    Typing <span>.</span><span>.</span><span>.</span>
                </div>

                <div class="chat-input-area">
                    <textarea class="chat-input" id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                    <button class="send-btn" onclick="sendMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            <?php else: ?>
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; flex-direction: column; gap: 16px;">
                    <i class="fas fa-comments" style="font-size: 64px;"></i>
                    <h3>Select a conversation</h3>
                    <p>Choose a chat to start messaging</p>
                    <button class="new-chat-btn" onclick="openNewChatModal()" style="width: auto; padding: 10px 24px;">Start New Chat</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Chat Modal -->
    <div id="newChatModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Start New Conversation</h3>
                <span class="close-modal" onclick="closeNewChatModal()">&times;</span>
            </div>
            <div class="user-list">
                <?php while($user = $users->fetch_assoc()): ?>
                    <div class="user-item" onclick="startConversation(<?php echo $user['id']; ?>)">
                        <div class="conversation-avatar" style="width: 40px; height: 40px; font-size: 16px;">
                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let conversationId = <?php echo $conversation_id; ?>;
        let userId = <?php echo $user_id; ?>;
        let typingTimeout;
        let lastMessageId = 0;
        let pollInterval;

        // Scroll to bottom
        function scrollToBottom() {
            const messagesArea = document.getElementById('messagesArea');
            if (messagesArea) {
                messagesArea.scrollTop = messagesArea.scrollHeight;
            }
        }

        // Load conversation
        function loadConversation(id) {
            window.location.href = `chat.php?id=${id}`;
        }

        // Send message
        function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (!message || !conversationId) return;
            
            // Disable send button temporarily
            const sendBtn = document.querySelector('.send-btn');
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            $.ajax({
                url: '../user/api/send_message.php',
                method: 'POST',
                data: {
                    conversation_id: conversationId,
                    message: message
                },
                success: function(response) {
                    if (response.success) {
                        input.value = '';
                        input.style.height = 'auto';
                        // Reload messages to show the sent message
                        loadMessages();
                        // Update conversation list
                        updateConversationList();
                        // Scroll to bottom
                        setTimeout(scrollToBottom, 100);
                    } else {
                        alert('Failed to send message: ' + (response.error || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error sending message:', error);
                    alert('Failed to send message. Please try again.');
                },
                complete: function() {
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                }
            });
        }

        // Load messages
        
        function loadMessages() {
            if (!conversationId) return;
            
            $.ajax({
                url: 'api/get_messages.php',
                method: 'GET',
                data: { conversation_id: conversationId },
                success: function(response) {
                    if (response.success && response.messages) {
                        const messagesArea = document.getElementById('messagesArea');
                        const currentMessageIds = Array.from(messagesArea.querySelectorAll('.message')).map(el => parseInt(el.dataset.msgId));
                        const newMessages = response.messages.filter(msg => !currentMessageIds.includes(msg.id));
                        
                        if (newMessages.length > 0) {
                            // Only add new messages, don't re-render everything
                            newMessages.forEach(msg => {
                                const isSent = msg.sender_id == userId;
                                const messageDiv = document.createElement('div');
                                messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                                messageDiv.setAttribute('data-msg-id', msg.id);
                                messageDiv.innerHTML = `
                                    <div class="message-bubble">
                                        ${escapeHtml(msg.message)}
                                        <div class="message-time">
                                            ${msg.time}
                                        </div>
                                        <div class="message-reactions" id="reactions-${msg.id}">
                                            <button class="reaction-btn" onclick="addReaction(${msg.id}, 'like')">👍</button>
                                            <button class="reaction-btn" onclick="addReaction(${msg.id}, 'dislike')">👎</button>
                                            <button class="reaction-btn" onclick="addReaction(${msg.id}, 'love')">❤️</button>
                                            <button class="reaction-btn" onclick="addReaction(${msg.id}, 'laugh')">😂</button>
                                            <span class="reaction-count" id="reaction-count-${msg.id}"></span>
                                        </div>
                                    </div>
                                `;
                                messagesArea.appendChild(messageDiv);
                            });
                            
                            // Only scroll if new message was added
                            scrollToBottom();
                            
                            // Mark as read
                            $.post('api/mark_read.php', { conversation_id: conversationId });
                            updateConversationList();
                        }
                    }
                }
            });
        }

        // Append message to chat
        function appendMessage(msg) {
            const messagesArea = document.getElementById('messagesArea');
            const isSent = msg.sender_id == userId;
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            messageDiv.setAttribute('data-msg-id', msg.id);
            messageDiv.innerHTML = `
                <div class="message-bubble">
                    ${escapeHtml(msg.message)}
                    <div class="message-time">
                        ${msg.time}
                        ${isSent ? '<i class="fas fa-check-double" style="font-size: 10px;"></i>' : ''}
                    </div>
                    <div class="message-reactions" id="reactions-${msg.id}">
                        <button class="reaction-btn" onclick="addReaction(${msg.id}, 'like')">👍</button>
                        <button class="reaction-btn" onclick="addReaction(${msg.id}, 'dislike')">👎</button>
                        <button class="reaction-btn" onclick="addReaction(${msg.id}, 'love')">❤️</button>
                        <button class="reaction-btn" onclick="addReaction(${msg.id}, 'laugh')">😂</button>
                        <span class="reaction-count" id="reaction-count-${msg.id}"></span>
                    </div>
                </div>
            `;
            messagesArea.appendChild(messageDiv);
        }

        // Add reaction
        function addReaction(messageId, type) {
            $.ajax({
                url: '../user/api/add_reaction.php',
                method: 'POST',
                data: {
                    message_id: messageId,
                    reaction_type: type
                },
                success: function(response) {
                    if (response.success) {
                        // Reload to show updated reactions
                        loadMessages();
                    }
                }
            });
        }

        // Send typing indicator
        function sendTyping() {
            if (!conversationId) return;
            $.post('../user/api/typing.php', { conversation_id: conversationId, typing: true });
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                $.post('../user/api/typing.php', { conversation_id: conversationId, typing: false });
            }, 1000);
        }

        // Update conversation list
        function updateConversationList() {
            $.get('../user/api/get_conversations.php', function(response) {
                if (response.success) {
                    const list = document.getElementById('conversationsList');
                    // Update unread badges without full reload
                    if (response.conversations) {
                        response.conversations.forEach(conv => {
                            const item = document.querySelector(`.conversation-item[data-conv-id="${conv.id}"]`);
                            if (item) {
                                const badge = item.querySelector('.unread-badge');
                                if (conv.unread_count > 0) {
                                    if (badge) badge.textContent = conv.unread_count;
                                    else {
                                        const meta = item.querySelector('.conversation-meta');
                                        if (meta) meta.innerHTML += `<div class="unread-badge">${conv.unread_count}</div>`;
                                    }
                                } else if (badge) badge.remove();
                                
                                // Update last message
                                const lastMsgElem = item.querySelector('.conversation-last');
                                if (lastMsgElem && conv.last_message) {
                                    lastMsgElem.textContent = conv.last_message.substring(0, 30);
                                }
                            }
                        });
                    }
                }
            });
        }

        // Auto-resize textarea
        const textarea = document.getElementById('messageInput');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
                sendTyping();
            });
            
            textarea.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }

        // Start polling for new messages
        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(() => {
                loadMessages();
            }, 3000);
        }

        // Check typing status
        function checkTyping() {
            if (!conversationId) return;
            $.get('../user/api/typing.php', { conversation_id: conversationId }, function(response) {
                const typingStatus = document.getElementById('typingStatus');
                if (typingStatus) {
                    typingStatus.textContent = response.typing ? 'Typing...' : '';
                }
            });
        }

        // Modal functions
        function openNewChatModal() {
            document.getElementById('newChatModal').style.display = 'flex';
        }

        function closeNewChatModal() {
            document.getElementById('newChatModal').style.display = 'none';
        }

        function startConversation(userId) {
            window.location.href = `../user/api/start_conversation.php?broker_id=${userId}`;
        }

        function toggleSidebar() {
            document.getElementById('chatSidebar').classList.toggle('open');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Search conversations
        const searchInput = document.getElementById('searchConversations');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const search = this.value.toLowerCase();
                const items = document.querySelectorAll('.conversation-item');
                items.forEach(item => {
                    const name = item.querySelector('.conversation-name')?.textContent.toLowerCase();
                    if (name?.includes(search)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }

        // Initialize
        if (conversationId) {
            startPolling();
            setInterval(checkTyping, 2000);
            // Mark as read
            $.post('../user/api/mark_read.php', { conversation_id: conversationId });
            // Initial scroll to bottom
            setTimeout(scrollToBottom, 500);
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('newChatModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>