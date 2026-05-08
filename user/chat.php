<?php
// user/chat.php - Modern Redesigned Chat Interface

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/chat_functions.php';

requireLogin();

$page_title = 'Messages';
ob_start();

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

<style>
    :root {
        --primary: #667eea;
        --primary-dark: #5a67d8;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    /* Chat Container - Uses full width but with sidebar integration */
    .chat-full-container {
        display: flex;
        gap: 0;
        background: white;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        min-height: calc(100vh - 180px);
    }
    
    /* Sidebar Styles */
    .chat-sidebar-modern {
        width: 320px;
        background: white;
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
    }
    
    .sidebar-header-modern {
        padding: 24px;
        border-bottom: 1px solid var(--border);
    }
    
    .sidebar-header-modern h2 {
        font-size: 20px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .new-chat-btn-modern {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.3s;
    }
    
    .new-chat-btn-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .search-box-modern {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
    }
    
    .search-box-modern input {
        width: 100%;
        padding: 10px 16px;
        border: 1px solid var(--border);
        border-radius: 40px;
        font-size: 13px;
        background: var(--light);
        transition: all 0.3s;
    }
    
    .search-box-modern input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .conversations-list-modern {
        flex: 1;
        overflow-y: auto;
    }
    
    .conversation-item-modern {
        display: flex;
        align-items: center;
        padding: 16px 20px;
        gap: 14px;
        cursor: pointer;
        transition: all 0.2s;
        border-bottom: 1px solid var(--border);
        position: relative;
    }
    
    .conversation-item-modern:hover {
        background: var(--light);
    }
    
    .conversation-item-modern.active {
        background: linear-gradient(135deg, rgba(102,126,234,0.08), rgba(118,75,162,0.08));
        border-left: 3px solid var(--primary);
    }
    
    .conversation-avatar-modern {
        width: 52px;
        height: 52px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 18px;
        flex-shrink: 0;
    }
    
    .conversation-info-modern {
        flex: 1;
        min-width: 0;
    }
    
    .conversation-name-modern {
        font-weight: 600;
        font-size: 15px;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .conversation-last-modern {
        font-size: 12px;
        color: var(--gray);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .conversation-meta-modern {
        text-align: right;
        flex-shrink: 0;
    }
    
    .conversation-time-modern {
        font-size: 10px;
        color: var(--gray);
    }
    
    .unread-badge-modern {
        background: var(--danger);
        color: white;
        font-size: 10px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 20px;
        min-width: 20px;
        text-align: center;
        display: inline-block;
        margin-top: 6px;
    }
    
    /* Main Chat Area */
    .chat-main-modern {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: var(--light);
    }
    
    .chat-header-modern {
        padding: 20px 24px;
        background: white;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .chat-header-left-modern {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    
    .back-btn-modern {
        display: none;
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .back-btn-modern:hover {
        color: var(--primary);
    }
    
    .chat-avatar-modern {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 20px;
    }
    
    .chat-header-info-modern h3 {
        font-size: 18px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .typing-status-modern {
        font-size: 11px;
        color: var(--primary);
        min-height: 18px;
    }
    
    .typing-indicator-modern {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 0;
    }
    
    .typing-dot-modern {
        width: 6px;
        height: 6px;
        background: var(--primary);
        border-radius: 50%;
        animation: typingAnimation 1.4s infinite ease-in-out;
    }
    
    @keyframes typingAnimation {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
        30% { transform: translateY(-6px); opacity: 1; }
    }
    
    .chat-actions-modern {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .dashboard-link-modern {
        background: var(--success);
        color: white;
        padding: 8px 16px;
        border-radius: 40px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.3s;
    }
    
    .dashboard-link-modern:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .clear-history-btn-modern {
        background: none;
        border: 1px solid var(--border);
        padding: 8px 14px;
        border-radius: 40px;
        font-size: 12px;
        color: var(--gray);
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .clear-history-btn-modern:hover {
        background: #fee2e2;
        border-color: var(--danger);
        color: var(--danger);
    }
    
    /* Messages Area */
    .messages-area-modern {
        flex: 1;
        overflow-y: auto;
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    
    .message-modern {
        display: flex;
        max-width: 70%;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .message-modern.sent {
        align-self: flex-end;
    }
    
    .message-modern.received {
        align-self: flex-start;
    }
    
    .message-bubble-modern {
        padding: 12px 16px;
        border-radius: 20px;
        position: relative;
        word-wrap: break-word;
        max-width: 100%;
    }
    
    .message-modern.sent .message-bubble-modern {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border-bottom-right-radius: 4px;
    }
    
    .message-modern.received .message-bubble-modern {
        background: white;
        color: var(--dark);
        border-bottom-left-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .message-text-modern {
        font-size: 14px;
        line-height: 1.5;
    }
    
    .message-time-modern {
        font-size: 9px;
        margin-top: 6px;
        opacity: 0.7;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .delete-msg-btn-modern {
        background: none;
        border: none;
        color: rgba(255,255,255,0.6);
        cursor: pointer;
        font-size: 10px;
        opacity: 0;
        transition: opacity 0.3s;
    }
    
    .message-modern.received .delete-msg-btn-modern {
        color: var(--gray);
    }
    
    .message-bubble-modern:hover .delete-msg-btn-modern {
        opacity: 1;
    }
    
    .delete-msg-btn-modern:hover {
        color: var(--danger) !important;
    }
    
    /* Reactions */
    .message-reactions-modern {
        display: flex;
        gap: 6px;
        margin-top: 8px;
        flex-wrap: wrap;
    }
    
    .reaction-btn-modern {
        background: rgba(0,0,0,0.05);
        border: none;
        cursor: pointer;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 20px;
        transition: all 0.2s;
    }
    
    .message-modern.sent .reaction-btn-modern {
        background: rgba(255,255,255,0.2);
        color: white;
    }
    
    .message-modern.received .reaction-btn-modern {
        background: #f1f5f9;
        color: var(--dark);
    }
    
    .reaction-btn-modern:hover {
        transform: scale(1.1);
    }
    
    .reaction-btn-modern.active {
        background: var(--primary);
        color: white;
    }
    
    .message-modern.sent .reaction-btn-modern.active {
        background: white;
        color: var(--primary);
    }
    
    /* Input Area */
    .chat-input-area-modern {
        padding: 20px 24px;
        background: white;
        border-top: 1px solid var(--border);
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }
    
    .chat-input-modern {
        flex: 1;
        padding: 12px 18px;
        border: 1px solid var(--border);
        border-radius: 24px;
        font-size: 14px;
        resize: none;
        font-family: inherit;
        max-height: 120px;
        transition: all 0.3s;
    }
    
    .chat-input-modern:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .send-btn-modern {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border: none;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        color: white;
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .send-btn-modern:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    /* Empty State */
    .empty-state-modern {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: var(--gray);
        flex-direction: column;
        gap: 20px;
        text-align: center;
        padding: 40px;
    }
    
    .empty-state-modern i {
        font-size: 64px;
        color: #cbd5e1;
    }
    
    .empty-state-modern h3 {
        font-size: 20px;
        color: var(--dark);
    }
    
    /* Modal */
    .modal-modern {
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
    
    .modal-content-modern {
        background: white;
        border-radius: 28px;
        padding: 28px;
        width: 450px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header-modern {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header-modern h3 {
        font-size: 20px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .close-modal-modern {
        cursor: pointer;
        font-size: 28px;
        color: var(--gray);
        transition: color 0.3s;
    }
    
    .close-modal-modern:hover {
        color: var(--danger);
    }
    
    .broker-list-modern {
        max-height: 400px;
        overflow-y: auto;
    }
    
    .broker-item-modern {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px;
        cursor: pointer;
        border-radius: 16px;
        transition: all 0.2s;
    }
    
    .broker-item-modern:hover {
        background: var(--light);
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .chat-full-container {
            flex-direction: column;
            min-height: calc(100vh - 140px);
        }
        
        .chat-sidebar-modern {
            width: 100%;
            display: none;
        }
        
        .chat-sidebar-modern.open {
            display: flex;
        }
        
        .back-btn-modern {
            display: block;
        }
        
        .message-modern {
            max-width: 85%;
        }
        
        .chat-header-modern {
            padding: 16px 20px;
        }
        
        .dashboard-link-modern span {
            display: none;
        }
        
        .dashboard-link-modern i {
            margin: 0;
        }
    }
</style>

<!-- Main Content -->
<div class="chat-full-container">
    <!-- Sidebar -->
    <div class="chat-sidebar-modern" id="chatSidebar">
        <div class="sidebar-header-modern">
            <h2><i class="fas fa-comments"></i> Messages</h2>
            <button class="new-chat-btn-modern" onclick="openNewChatModal()">
                <i class="fas fa-plus"></i> New Conversation
            </button>
        </div>
        <div class="search-box-modern">
            <input type="text" id="searchConversations" placeholder="Search conversations..." onkeyup="filterConversations(this.value)">
        </div>
        <div class="conversations-list-modern" id="conversationsList">
            <?php if ($conversations && $conversations->num_rows > 0): ?>
                <?php while($conv = $conversations->fetch_assoc()): ?>
                    <div class="conversation-item-modern <?php echo $conversation_id == $conv['id'] ? 'active' : ''; ?>" 
                         onclick="loadConversation(<?php echo $conv['id']; ?>)"
                         data-conv-id="<?php echo $conv['id']; ?>"
                         data-conv-name="<?php echo strtolower($conv['other_user_name']); ?>">
                        <div class="conversation-avatar-modern">
                            <?php echo strtoupper(substr($conv['other_user_name'], 0, 1)); ?>
                        </div>
                        <div class="conversation-info-modern">
                            <div class="conversation-name-modern"><?php echo htmlspecialchars($conv['other_user_name']); ?></div>
                            <div class="conversation-last-modern"><?php echo htmlspecialchars(substr($conv['last_message'] ?? '', 0, 35)); ?></div>
                        </div>
                        <div class="conversation-meta-modern">
                            <div class="conversation-time-modern">
                                <?php 
                                if ($conv['last_message_time']) {
                                    echo date('H:i', strtotime($conv['last_message_time']));
                                }
                                ?>
                            </div>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <div class="unread-badge-modern"><?php echo $conv['unread_count']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 60px 20px; text-align: center; color: var(--gray);">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                    <p>No messages yet</p>
                    <p style="font-size: 12px; margin-top: 8px;">Start a conversation with support</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Chat Area -->
    <div class="chat-main-modern">
        <?php if ($current_conversation): ?>
            <div class="chat-header-modern">
                <div class="chat-header-left-modern">
                    <button class="back-btn-modern" onclick="toggleSidebar()"><i class="fas fa-arrow-left"></i></button>
                    <div class="chat-avatar-modern">
                        <?php echo strtoupper(substr($current_conversation['other_user_name'], 0, 1)); ?>
                    </div>
                    <div class="chat-header-info-modern">
                        <h3><?php echo htmlspecialchars($current_conversation['other_user_name']); ?></h3>
                        <div class="typing-status-modern" id="typingStatus"></div>
                    </div>
                </div>
                <div class="chat-actions-modern">
                    <a href="dashboard.php" class="dashboard-link-modern">
                        <i class="fas fa-home"></i> <span>Dashboard</span>
                    </a>
                    <button class="clear-history-btn-modern" onclick="clearChatHistory()">
                        <i class="fas fa-trash-alt"></i> Clear
                    </button>
                </div>
            </div>

            <div class="messages-area-modern" id="messagesArea">
                <?php foreach($messages as $msg): ?>
                    <?php
                    $isSent = ($msg['sender_id'] == $user_id);
                    $reactionTypes = ['like' => '👍', 'dislike' => '👎', 'love' => '❤️', 'laugh' => '😂'];
                    ?>
                    <div class="message-modern <?php echo $isSent ? 'sent' : 'received'; ?>" data-msg-id="<?php echo $msg['id']; ?>">
                        <div class="message-bubble-modern">
                            <div class="message-text-modern"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                            <div class="message-time-modern">
                                <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                <button class="delete-msg-btn-modern" onclick="deleteMessage(<?php echo $msg['id']; ?>)" title="Delete message">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                            <div class="message-reactions-modern">
                                <?php foreach($reactionTypes as $type => $emoji): ?>
                                    <?php $count = $msg['reactions'][$type] ?? 0; ?>
                                    <button class="reaction-btn-modern <?php echo ($msg['my_reaction'] == $type) ? 'active' : ''; ?>" onclick="addReaction(<?php echo $msg['id']; ?>, '<?php echo $type; ?>')">
                                        <?php echo $emoji; ?> <?php echo $count > 0 ? $count : ''; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="chat-input-area-modern">
                <textarea class="chat-input-modern" id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                <button class="send-btn-modern" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-comments"></i>
                <h3>Select a conversation</h3>
                <p>Choose a chat to start messaging or start a new conversation</p>
                <div style="display: flex; gap: 12px; margin-top: 8px;">
                    <a href="dashboard.php" class="dashboard-link-modern" style="background: var(--success);">
                        <i class="fas fa-home"></i> Go to Dashboard
                    </a>
                    <button class="new-chat-btn-modern" onclick="openNewChatModal()" style="width: auto; padding: 10px 24px;">
                        <i class="fas fa-plus"></i> New Chat
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Chat Modal -->
<div id="newChatModal" class="modal-modern">
    <div class="modal-content-modern">
        <div class="modal-header-modern">
            <h3><i class="fas fa-plus-circle"></i> Start New Conversation</h3>
            <span class="close-modal-modern" onclick="closeNewChatModal()">&times;</span>
        </div>
        <div class="broker-list-modern">
            <?php while($broker = $brokers->fetch_assoc()): ?>
                <div class="broker-item-modern" onclick="startConversation(<?php echo $broker['id']; ?>)">
                    <div class="conversation-avatar-modern" style="width: 44px; height: 44px; font-size: 18px;">
                        <?php echo strtoupper(substr($broker['full_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($broker['full_name']); ?></div>
                        <div style="font-size: 12px; color: var(--gray);"><?php echo htmlspecialchars($broker['email']); ?></div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<!-- Clear History Modal -->
<div id="clearHistoryModal" class="modal-modern">
    <div class="modal-content-modern">
        <div class="modal-header-modern">
            <h3><i class="fas fa-trash-alt"></i> Clear Chat History</h3>
            <span class="close-modal-modern" onclick="closeClearHistoryModal()">&times;</span>
        </div>
        <p style="margin-bottom: 20px; color: var(--gray);">Are you sure you want to clear all messages in this conversation? This action cannot be undone.</p>
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button onclick="closeClearHistoryModal()" style="padding: 10px 20px; background: var(--gray); color: white; border: none; border-radius: 40px; cursor: pointer;">Cancel</button>
            <button onclick="confirmClearHistory()" style="padding: 10px 20px; background: var(--danger); color: white; border: none; border-radius: 40px; cursor: pointer;">Clear All</button>
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

    function filterConversations(searchTerm) {
        const items = document.querySelectorAll('.conversation-item-modern');
        const term = searchTerm.toLowerCase();
        
        items.forEach(item => {
            const name = item.getAttribute('data-conv-name') || '';
            if (name.includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function sendMessage() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();
        
        if (!message || !conversationId) return;
        
        const sendBtn = document.querySelector('.send-btn-modern');
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
                    const currentMessageIds = Array.from(messagesArea.querySelectorAll('.message-modern')).map(el => parseInt(el.dataset.msgId));
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
        messageDiv.className = `message-modern ${isSent ? 'sent' : 'received'}`;
        messageDiv.setAttribute('data-msg-id', msg.id);
        
        let reactionsHtml = '<div class="message-reactions-modern">';
        for (const [type, emoji] of Object.entries(reactionTypes)) {
            const count = msg.reactions[type] || 0;
            const isActive = (msg.my_reaction === type);
            reactionsHtml += `<button class="reaction-btn-modern ${isActive ? 'active' : ''}" onclick="addReaction(${msg.id}, '${type}')">${emoji} ${count > 0 ? count : ''}</button>`;
        }
        reactionsHtml += '</div>';
        
        messageDiv.innerHTML = `
            <div class="message-bubble-modern">
                <div class="message-text-modern">${escapeHtml(msg.message)}</div>
                <div class="message-time-modern">
                    ${msg.time}
                    <button class="delete-msg-btn-modern" onclick="deleteMessage(${msg.id})" title="Delete message">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
                ${reactionsHtml}
            </div>
        `;
        messagesArea.appendChild(messageDiv);
    }

    function addReaction(messageId, type) {
        const messageDiv = $(`.message-modern[data-msg-id="${messageId}"]`);
        const emojis = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
        const emoji = emojis[type];
        
        const reactionBtn = messageDiv.find('.reaction-btn-modern').filter(function() {
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
            
            messageDiv.find('.reaction-btn-modern').not(reactionBtn).each(function() {
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
        const messageDiv = $(`.message-modern[data-msg-id="${messageId}"]`);
        const reactionTypes = ['like', 'dislike', 'love', 'laugh'];
        const emojis = { 'like': '👍', 'dislike': '👎', 'love': '❤️', 'laugh': '😂' };
        
        reactionTypes.forEach(type => {
            const count = reactions[type] || 0;
            const emoji = emojis[type];
            const btn = messageDiv.find('.reaction-btn-modern').filter(function() {
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
                        $(`.message-modern[data-msg-id="${messageId}"]`).remove();
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
                    alert('Failed to clear history');
                }
            },
            error: function() {
                alert('Failed to clear history');
            }
        });
    }

    function sendTyping() {
        if (!conversationId) return;
        
        if (typingTimeout) clearTimeout(typingTimeout);
        
        $.post('api/typing.php', { conversation_id: conversationId, typing: true });
        
        typingTimeout = setTimeout(() => {
            $.post('api/typing.php', { conversation_id: conversationId, typing: false });
        }, 2000);
    }

    function checkOtherUserTyping() {
        if (!conversationId) return;
        
        $.get('api/typing.php', { conversation_id: conversationId }, function(response) {
            const typingStatus = document.getElementById('typingStatus');
            if (typingStatus) {
                if (response.typing && response.typing_user_id && response.typing_user_id != userId) {
                    typingStatus.innerHTML = '<div class="typing-indicator-modern"><span class="typing-dot-modern"></span><span class="typing-dot-modern"></span><span class="typing-dot-modern"></span> typing...</div>';
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
                    const item = document.querySelector(`.conversation-item-modern[data-conv-id="${conv.id}"]`);
                    if (item) {
                        const badge = item.querySelector('.unread-badge-modern');
                        if (conv.unread_count > 0) {
                            if (badge) badge.textContent = conv.unread_count;
                            else {
                                const meta = item.querySelector('.conversation-meta-modern');
                                if (meta) meta.innerHTML += `<div class="unread-badge-modern">${conv.unread_count}</div>`;
                            }
                        } else if (badge) badge.remove();
                        
                        const lastMsgElem = item.querySelector('.conversation-last-modern');
                        if (lastMsgElem && conv.last_message) {
                            lastMsgElem.textContent = conv.last_message.substring(0, 35);
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

    if (conversationId) {
        startPolling();
        startTypingCheck();
        loadMessages();
        $.post('api/mark_read.php', { conversation_id: conversationId });
        setTimeout(scrollToBottom, 500);
    }

    window.onclick = function(event) {
        const modal = document.getElementById('newChatModal');
        const clearModal = document.getElementById('clearHistoryModal');
        if (event.target === modal) modal.style.display = 'none';
        if (event.target === clearModal) clearModal.style.display = 'none';
    }
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>