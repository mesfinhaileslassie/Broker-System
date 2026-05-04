<?php
// admin/messages.php - User Messages

$page_title = 'User Messages';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

// Handle sending reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $messageId = intval($_POST['message_id']);
    $replyText = $conn->real_escape_string($_POST['reply_text']);
    $userId = intval($_POST['user_id']);
    
    $stmt = $conn->prepare("INSERT INTO messages (from_user_id, to_admin, subject, message, is_replied) VALUES (?, 0, ?, ?, 1)");
    $adminId = $_SESSION['admin_id'] ?? 1;
    $subject = "RE: Admin Response";
    $stmt->bind_param("iss", $adminId, $subject, $replyText);
    
    if ($stmt->execute()) {
        $conn->query("UPDATE messages SET is_replied = 1 WHERE id = $messageId");
        $conn->query("INSERT INTO notifications (user_id, title, message) VALUES ($userId, 'Admin Response', 'Admin replied to your message')");
        $message = "Reply sent successfully";
    }
}

$status = $_GET['status'] ?? 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "m.to_admin = 1";
if ($status == 'unread') $where .= " AND m.is_replied = 0";
if ($status == 'replied') $where .= " AND m.is_replied = 1";

$sql = "SELECT m.*, u.full_name, u.email, u.phone 
        FROM messages m
        JOIN users u ON m.from_user_id = u.id
        WHERE $where
        ORDER BY m.created_at DESC
        LIMIT $offset, $limit";
$messages = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM messages WHERE $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewMessage = $conn->query("
        SELECT m.*, u.full_name, u.email, u.phone, u.id as user_id
        FROM messages m
        JOIN users u ON m.from_user_id = u.id
        WHERE m.id = $viewId
    ")->fetch_assoc();
}

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1")->fetch_assoc()['count'],
    'unread' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1 AND is_replied = 0")->fetch_assoc()['count'],
    'replied' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1 AND is_replied = 1")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; }
    .tabs { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .tab { padding: 8px 20px; background: white; border-radius: 30px; text-decoration: none; color: #64748b; }
    .tab.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .message-view { background: #f8fafc; padding: 20px; border-radius: 16px; margin-bottom: 20px; }
    .reply-form textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; font-family: inherit; }
    .btn-send { background: #667eea; color: white; padding: 10px 24px; border: none; border-radius: 40px; cursor: pointer; }
    .unread-row { font-weight: 600; background: #fef3c7; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
    .pagination .active { background: #667eea; color: white; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Messages</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['unread']; ?></div><div class="stat-label">Unread</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['replied']; ?></div><div class="stat-label">Replied</div></div>
</div>

<div class="tabs">
    <a href="?status=all" class="tab <?php echo $status == 'all' ? 'active' : ''; ?>">All</a>
    <a href="?status=unread" class="tab <?php echo $status == 'unread' ? 'active' : ''; ?>">Unread</a>
    <a href="?status=replied" class="tab <?php echo $status == 'replied' ? 'active' : ''; ?>">Replied</a>
</div>

<?php if ($viewMessage): ?>
    <div class="card">
        <div class="card-header">
            <h2>Message from <?php echo htmlspecialchars($viewMessage['full_name']); ?></h2>
            <a href="messages.php?status=<?php echo $status; ?>" class="btn-sm btn-primary">← Back</a>
        </div>
        <div class="message-view">
            <p><strong>From:</strong> <?php echo htmlspecialchars($viewMessage['full_name']); ?> (<?php echo htmlspecialchars($viewMessage['email']); ?>)</p>
            <p><strong>Subject:</strong> <?php echo htmlspecialchars($viewMessage['subject']); ?></p>
            <p><strong>Date:</strong> <?php echo date('F d, Y H:i', strtotime($viewMessage['created_at'])); ?></p>
            <div style="margin-top: 16px; padding: 16px; background: white; border-radius: 12px;">
                <?php echo nl2br(htmlspecialchars($viewMessage['message'])); ?>
            </div>
        </div>
        <div class="reply-form">
            <h3>Send Reply</h3>
            <form method="POST">
                <input type="hidden" name="message_id" value="<?php echo $viewMessage['id']; ?>">
                <input type="hidden" name="user_id" value="<?php echo $viewMessage['user_id']; ?>">
                <textarea name="reply_text" rows="4" placeholder="Type your reply here..." required></textarea>
                <button type="submit" name="send_reply" class="btn-send"><i class="fas fa-paper-plane"></i> Send Reply</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-envelope"></i> All Messages</h2></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>From</th><th>Subject</th><th>Message</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php while($row = $messages->fetch_assoc()): ?>
                    <tr class="<?php echo !$row['is_replied'] ? 'unread-row' : ''; ?>" onclick="location.href='?view=<?php echo $row['id']; ?>&status=<?php echo $status; ?>'" style="cursor:pointer;">
                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                        <td><?php echo htmlspecialchars(substr($row['subject'], 0, 40)); ?></td>
                        <td><?php echo htmlspecialchars(substr($row['message'], 0, 60)); ?>...</td>
                        <td><?php echo $row['is_replied'] ? '<span class="badge badge-success">Replied</span>' : '<span class="badge badge-warning">Unread</span>'; ?></td>
                        <td><?php echo timeAgo($row['created_at']); ?></td>
                        <td><a href="?view=<?php echo $row['id']; ?>&status=<?php echo $status; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">Reply</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include 'layout.php';
?>