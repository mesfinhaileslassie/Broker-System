<?php
// user/chat.php - Complete Chat Interface with Fixed Typing Indicator

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/chat_functions.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get broker/admin users for new conversations
$brokers = $conn->query("SELECT id, full_name, email FROM users WHERE role IN ('admin', 'broker') ORDER BY full_name");

// Get user's conversations
$conversations = getUserConversations($conn, $user_id);

// Get conversation messages
$messages = [];
$current_conversation = null;
if ($conversation_id > 0) {
    $current_conversation = getConversationById($conn, $conversation_id, $user_id);
    
    if ($current_conversation) {
        $messages = getMessagesWithDeleteFilter($conn, $conversation_id, $user_id, 100, 0);
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
    <title>Messages - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; height: 100vh; overflow: hidden; }
        
        .chat-container { display: flex; height: 100vh; max-width: 1400px; margin: 0 auto; background: white; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        
        /* Sidebar */
        .chat-sidebar { width: 320px; background: white; border-right: 1px solid #e9ecef; display: flex; flex-direction: column; height: 100vh; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #e9ecef; }
        .sidebar-header h2 { font-size: 18px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .new-chat-btn { width: 100%; margin-top: 12px; padding: 10px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; }
        .new-chat-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
        
        .search-box { padding: 12px 16px; border-bottom: 1px solid #e9ecef; }
        .search-box input { width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 13px; background: #f8fafc; }
        .search-box input:focus { outline: none; border-color: #667eea; }
        
        .conversations-list { flex: 1; overflow-y: auto; }
        .conversation-item { display: flex; align-items: center; padding: 16px; gap: 12px; cursor: pointer; transition: all 0.2s; border-bottom: 1px solid #f0f2f5; }
        .conversation-item:hover { background: #f8fafc; }
        .conversation-item.active { background: #eef2ff; border-left: 3px solid #667eea; }
        .conversation-avatar { width: 48px; height: 48px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 18px; flex-shrink: 0; }
        .conversation-info { flex: 1; min-width: 0; }
        .conversation-name { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
        .conversation-last { font-size: 12px; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conversation-meta { text-align: right; }
        .conversation-time { font-size: 10px; color: #94a3b8; }
        .unread-badge { background: #ef4444; color: white; font-size: 10px; padding: 2px 6px; border-radius: 20px; min-width: 18px; text-align: center; display: inline-block; margin-top: 4px; }
        
        /* Typing Indicator Animation */
        .typing-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e2e8f0;
            padding: 8px 12px;
            border-radius: 18px;
            font-size: 12px;
            color: #64748b;
        }
        .typing-dot {
            width: 6px;
            height: 6px;
            background: #64748b;
            border-radius: 50%;
            animation: typingAnimation 1.4s infinite ease-in-out;
        }
        .typing-dot:nth-child(1) { animation-delay: 0s; }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingAnimation {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-6px); opacity: 1; }
        }
        
        /* Chat Main */
        .chat-main { flex: 1; display: flex; flex-direction: column; height: 100vh; background: #f8fafc; }
        .chat-header { padding: 16px 24px; background: white; border-bottom: 1px solid #e9ecef; display: flex; align-items: center; justify-content: space-between; }
        .chat-header-left { display: flex; align-items: center; gap: 12px; }
        .chat-header-right { display: flex; align-items: center; gap: 12px; }
        .back-btn { display: none; background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; }
        .chat-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; }
        .chat-header-info h3 { font-size: 16px; font-weight: 600; }
        .chat-header-info p { font-size: 12px; color: #64748b; }
        .clear-history-btn { background: none; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 20px; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.3s; }
        .clear-history-btn:hover { background: #fee2e2; border-color: #ef4444; color: #ef4444; }
        
        .messages-area { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
        .message { display: flex; max-width: 70%; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .message.sent { align-self: flex-end; }
        .message.received { align-self: flex-start; }
        .message-bubble { padding: 10px 14px; border-radius: 18px; position: relative; word-wrap: break-word; max-width: 100%; }
        .message.sent .message-bubble { background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-bottom-right-radius: 4px; }
        .message.received .message-bubble { background: white; color: #1e293b; border-bottom-left-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .message-time { font-size: 9px; margin-top: 4px; opacity: 0.7; display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
        .delete-msg-btn { background: none; border: none; color: rgba(255,255,255,0.6); cursor: pointer; font-size: 10px; opacity: 0; transition: opacity 0.3s; }
        .message.received .delete-msg-btn { color: #94a3b8; }
        .message-bubble:hover .delete-msg-btn { opacity: 1; }
        .delete-msg-btn:hover { color: #ef4444 !important; }
        
        .message-reactions { display: flex; gap: 4px; margin-top: 6px; flex-wrap: wrap; }
        .reaction-btn { background: rgba(0,0,0,0.05); border: none; cursor: pointer; font-size: 11px; padding: 2px 6px; border-radius: 20px; transition: all 0.2s; }
        .message.sent .reaction-btn { background: rgba(255,255,255,0.2); color: white; }
        .message.received .reaction-btn { background: #f1f5f9; color: #334155; }
        .reaction-btn:hover { transform: scale(1.1); }
        .reaction-btn.active { background: #667eea; color: white; }
        .message.sent .reaction-btn.active { background: white; color: #667eea; }
        
        .chat-input-area { padding: 16px 20px; background: white; border-top: 1px solid #e9ecef; display: flex; align-items: center; gap: 12px; }
        .chat-input { flex: 1; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 24px; font-size: 14px; resize: none; font-family: inherit; max-height: 100px; }
        .chat-input:focus { outline: none; border-color: #667eea; }
        .send-btn { background: linear-gradient(135deg, #667eea, #764ba2); border: none; width: 42px; height: 42px; border-radius: 50%; color: white; cursor: pointer; transition: all 0.3s; }
        .send-btn:hover { transform: scale(1.05); }
        
        .typing-status {
            font-size: 11px;
            color: #667eea;
            margin-top: 4px;
            min-height: 20px;
        }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 20px; padding: 24px; width: 400px; max-width: 90%; }
        .modal-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .close-modal { cursor: pointer; font-size: 24px; }
        .broker-list { max-height: 300px; overflow-y: auto; }
        .broker-item { display: flex; align-items: center; gap: 12px; padding: 12px; cursor: pointer; border-radius: 12px; transition: background 0.2s; }
        .broker-item:hover { background: #f1f5f9; }
        
        @media (max-width: 768px) {
            .chat-sidebar { position: fixed; left: -320px; z-index: 100; transition: left 0.3s; }
            .chat-sidebar.open { left: 0; }
            .back-btn { display: block; }
            .message { max-width: 85%; }
            .chat-header-right { gap: 8px; }
            .clear-history-btn { font-size: 10px; padding: 4px 8px; }
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
                    <div class="chat-header-left">
                        <button class="back-btn" onclick="toggleSidebar()"><i class="fas fa-arrow-left"></i></button>
                        <div class="chat-avatar"><?php echo strtoupper(substr($current_conversation['other_user_name'], 0, 1)); ?></div>
                        <div class="chat-header-info">
                            <h3><?php echo htmlspecialchars($current_conversation['other_user_name']); ?></h3>
                            <div class="typing-status" id="typingStatus"></div>
                        </div>
                    </div>
                    <div class="chat-header-right">
                        <button class="clear-history-btn" onclick="clearChatHistory()">
                            <i class="fas fa-trash-alt"></i> Clear History
                        </button>
                    </div>
                </div>

                <div class="messages-area" id="messagesArea">
                    <?php foreach($messages as $msg): ?>
                        <?php
                        $isSent = ($msg['sender_id'] == $user_id);
                        $reactionTypes = ['like' => '👍', 'dislike' => '👎', 'love' => '❤️', 'laugh' => '😂'];
                        ?>
                        <div class="message <?php echo $isSent ? 'sent' : 'received'; ?>" data-msg-id="<?php echo $msg['id']; ?>">
                            <div class="message-bubble">
                                <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                <div class="message-time">
                                    <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                    <button class="delete-msg-btn" onclick="deleteMessage(<?php echo $msg['id']; ?>)" title="Delete message">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                                <div class="message-reactions">
                                    <?php foreach($reactionTypes as $type => $emoji): ?>
                                        <?php $count = $msg['reactions'][$type] ?? 0; ?>
                                        <button class="reaction-btn <?php echo ($msg['my_reaction'] == $type) ? 'active' : ''; ?>" onclick="addReaction(<?php echo $msg['id']; ?>, '<?php echo $type; ?>')">
                                            <?php echo $emoji; ?> <?php echo $count > 0 ? $count : ''; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
            <div class="broker-list">
                <?php while($broker = $brokers->fetch_assoc()): ?>
                    <div class="broker-item" onclick="startConversation(<?php echo $broker['id']; ?>)">
                        <div class="conversation-avatar" style="width: 40px; height: 40px; font-size: 16px;">
                            <?php echo strtoupper(substr($broker['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($broker['full_name']); ?></div>
                            <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($broker['email']); ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <!-- Clear History Confirmation Modal -->
    <div id="clearHistoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Clear Chat History</h3>
                <span class="close-modal" onclick="closeClearHistoryModal()">&times;</span>
            </div>
            <p style="margin-bottom: 20px;">Are you sure you want to clear all messages in this conversation? This action cannot be undone.</p>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button onclick="closeClearHistoryModal()" class="btn-secondary" style="padding: 8px 16px; background: #94a3b8; color: white; border: none; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button onclick="confirmClearHistory()" class="btn-danger" style="padding: 8px 16px; background: #ef4444; color: white; border: none; border-radius: 8px; cursor: pointer;">Clear All</button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let conversationId = <?php echo $conversation_id; ?>;
        let userId = <?php echo $user_id; ?>;
        let pollInterval;
        let typingTimeout;
        let typingCheckInterval;

        function scrollToBottom() {
            const messagesArea = document.getElementById('messagesArea');
            if (messagesArea) {
                messagesArea.scrollTop = messagesArea.scrollHeight;
            }
        }

        function loadConversation(id) {
            window.location.href = `chat.php?id=${id}`;
        }

        function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (!message || !conversationId) return;
            
            const sendBtn = document.querySelector('.send-btn');
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            $.ajax({
                url: 'api/send_message.php',
                method: 'POST',
                data: {
                    conversation_id: conversationId,
                    message: message
                },
                success: function(response) {
                    if (response.success) {
                        input.value = '';
                        input.style.height = 'auto';
                        loadMessages();
                        updateConversationList();
                        setTimeout(scrollToBottom, 100);
                    } else {
                        alert('Failed to send message');
                    }
                },
                complete: function() {
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                }
            });
        }

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
                            newMessages.forEach(msg => {
                                appendMessage(msg);
                            });
                            scrollToBottom();
                            $.post('api/mark_read.php', { conversation_id: conversationId });
                            updateConversationList();
                        } else if (currentMessageIds.length !== response.messages.length) {
                            messagesArea.innerHTML = '';
                            response.messages.forEach(msg => {
                                appendMessage(msg);
                            });
                            scrollToBottom();
                        }
                    }
                }
            });
        }

        function appendMessage(msg) {
            const messagesArea = document.getElementById('messagesArea');
            const isSent = msg.sender_id == userId;
            const reactionTypes = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            messageDiv.setAttribute('data-msg-id', msg.id);
            
            let reactionsHtml = '<div class="message-reactions">';
            for (const [type, emoji] of Object.entries(reactionTypes)) {
                const count = msg.reactions[type] || 0;
                const isActive = (msg.my_reaction === type);
                reactionsHtml += `<button class="reaction-btn ${isActive ? 'active' : ''}" onclick="addReaction(${msg.id}, '${type}')">${emoji} ${count > 0 ? count : ''}</button>`;
            }
            reactionsHtml += '</div>';
            
            messageDiv.innerHTML = `
                <div class="message-bubble">
                    <div class="message-text">${escapeHtml(msg.message)}</div>
                    <div class="message-time">
                        ${msg.time}
                        <button class="delete-msg-btn" onclick="deleteMessage(${msg.id})" title="Delete message">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                    ${reactionsHtml}
                </div>
            `;
            messagesArea.appendChild(messageDiv);
        }

        function getEmojiForType(type) {
            const emojis = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
            return emojis[type] || '👍';
        }

        function addReaction(messageId, type) {
            const messageDiv = $(`.message[data-msg-id="${messageId}"]`);
            const emoji = getEmojiForType(type);
            const reactionBtn = messageDiv.find('.reaction-btn').filter(function() {
                return $(this).text().trim().startsWith(emoji);
            });
            
            const btnText = reactionBtn.text().trim();
            const currentCount = parseInt(btnText.match(/\d+/)?.[0] || 0);
            const isCurrentlyActive = reactionBtn.hasClass('active');
            
            if (isCurrentlyActive) {
                const newCount = currentCount - 1;
                reactionBtn.text(`${emoji} ${newCount > 0 ? newCount : ''}`);
                reactionBtn.removeClass('active');
            } else {
                const newCount = currentCount + 1;
                reactionBtn.text(`${emoji} ${newCount > 0 ? newCount : ''}`);
                reactionBtn.addClass('active');
                
                messageDiv.find('.reaction-btn').not(reactionBtn).each(function() {
                    const otherBtn = $(this);
                    const otherText = otherBtn.text().trim();
                    const otherCount = parseInt(otherText.match(/\d+/)?.[0] || 0);
                    const otherEmoji = otherText.charAt(0);
                    const newOtherCount = otherCount - 1;
                    otherBtn.text(`${otherEmoji} ${newOtherCount > 0 ? newOtherCount : ''}`);
                    otherBtn.removeClass('active');
                });
            }
            
            $.ajax({
                url: 'api/add_reaction.php',
                method: 'POST',
                data: { message_id: messageId, reaction_type: type },
                success: function(response) {
                    if (response.success && response.reactions) {
                        syncReactions(messageId, response.reactions);
                    }
                },
                error: function() {
                    loadMessages();
                }
            });
        }

        function syncReactions(messageId, reactions) {
            const messageDiv = $(`.message[data-msg-id="${messageId}"]`);
            const reactionTypes = ['like', 'dislike', 'love', 'laugh'];
            
            reactionTypes.forEach(type => {
                const count = reactions[type] || 0;
                const emoji = getEmojiForType(type);
                const btn = messageDiv.find('.reaction-btn').filter(function() {
                    return $(this).text().trim().startsWith(emoji);
                });
                btn.text(`${emoji} ${count > 0 ? count : ''}`);
            });
        }

        function deleteMessage(messageId) {
            if (confirm('Delete this message? It will be removed from your chat history.')) {
                $.ajax({
                    url: 'api/delete_message.php',
                    method: 'POST',
                    data: { message_id: messageId },
                    success: function(response) {
                        if (response.success) {
                            $(`.message[data-msg-id="${messageId}"]`).remove();
                        } else {
                            alert('Failed to delete message');
                        }
                    }
                });
            }
        }

        function clearChatHistory() {
            if (!conversationId) return;
            document.getElementById('clearHistoryModal').style.display = 'flex';
        }

        function closeClearHistoryModal() {
            document.getElementById('clearHistoryModal').style.display = 'none';
        }

        function confirmClearHistory() {
            if (!conversationId) return;
            
            $.ajax({
                url: 'api/clear_history.php',
                method: 'POST',
                data: { conversation_id: conversationId },
                success: function(response) {
                    if (response.success) {
                        document.getElementById('messagesArea').innerHTML = '';
                        updateConversationList();
                        closeClearHistoryModal();
                        alert('Chat history cleared successfully');
                    } else {
                        alert('Failed to clear history: ' + (response.error || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to clear history');
                }
            });
        }

        // FIXED: Send typing indicator - only sends when user is typing
        function sendTyping() {
            if (!conversationId) return;
            
            // Clear previous timeout
            if (typingTimeout) {
                clearTimeout(typingTimeout);
            }
            
            // Send typing start
            $.post('api/typing.php', { 
                conversation_id: conversationId, 
                typing: true 
            });
            
            // Set timeout to stop typing after 2 seconds of no typing
            typingTimeout = setTimeout(() => {
                $.post('api/typing.php', { 
                    conversation_id: conversationId, 
                    typing: false 
                });
            }, 2000);
        }

        // FIXED: Check other user's typing status - only shows when OTHER user is typing
        function checkOtherUserTyping() {
            if (!conversationId) return;
            
            $.get('api/typing.php', { 
                conversation_id: conversationId 
            }, function(response) {
                const typingStatus = document.getElementById('typingStatus');
                if (typingStatus) {
                    // Only show typing indicator if the OTHER user is typing
                    if (response.typing && response.typing_user_id && response.typing_user_id != userId) {
                        typingStatus.innerHTML = '<span class="typing-indicator"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span> typing...</span>';
                    } else {
                        typingStatus.innerHTML = '';
                    }
                }
            });
        }

        function updateConversationList() {
            $.get('api/get_conversations.php', function(response) {
                if (response.success && response.conversations) {
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
                            
                            const lastMsgElem = item.querySelector('.conversation-last');
                            if (lastMsgElem && conv.last_message) {
                                lastMsgElem.textContent = conv.last_message.substring(0, 30);
                            }
                        }
                    });
                }
            });
        }

        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(() => { loadMessages(); }, 3000);
        }

        function startTypingCheck() {
            if (typingCheckInterval) clearInterval(typingCheckInterval);
            typingCheckInterval = setInterval(() => { checkOtherUserTyping(); }, 2000);
        }

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

        function openNewChatModal() { 
            document.getElementById('newChatModal').style.display = 'flex'; 
        }
        
        function closeNewChatModal() { 
            document.getElementById('newChatModal').style.display = 'none'; 
        }
        
        function startConversation(brokerId) { 
            window.location.href = `api/start_conversation.php?broker_id=${brokerId}`; 
        }
        
        function toggleSidebar() { 
            document.getElementById('chatSidebar').classList.toggle('open'); 
        }
        
        function escapeHtml(text) { 
            const div = document.createElement('div'); 
            div.textContent = text; 
            return div.innerHTML; 
        }

        // Initialize chat if conversation exists
        if (conversationId) {
            startPolling();
            startTypingCheck();
            loadMessages();
            $.post('api/mark_read.php', { conversation_id: conversationId });
            setTimeout(scrollToBottom, 500);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('newChatModal');
            const clearModal = document.getElementById('clearHistoryModal');
            if (event.target === modal) modal.style.display = 'none';
            if (event.target === clearModal) clearModal.style.display = 'none';
        }
    </script>
</body>
</html>