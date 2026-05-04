<?php
// admin/messages.php - Message Management

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle sending reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $messageId = intval($_POST['message_id']);
    $replyText = $conn->real_escape_string($_POST['reply_text']);
    $userId = intval($_POST['user_id']);
    
    $stmt = $conn->prepare("INSERT INTO messages (from_user_id, to_admin, subject, message, is_replied) VALUES (?, 0, ?, ?, 1)");
    $adminId = $_SESSION['admin_id'];
    $subject = "RE: Admin Response";
    $stmt->bind_param("iss", $adminId, $subject, $replyText);
    
    if ($stmt->execute()) {
        // Mark original as replied
        $conn->query("UPDATE messages SET is_replied = 1 WHERE id = $messageId");
        $message = "Reply sent successfully";
        
        // Create notification for user
        $notifMsg = "Admin replied to your message";
        $conn->query("INSERT INTO notifications (user_id, title, message) VALUES ($userId, 'Admin Response', '$notifMsg')");
    } else {
        $error = "Failed to send reply";
    }
}

// Get messages
$status = $_GET['status'] ?? 'unread';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "";
if ($status == 'unread') {
    $where = "AND is_replied = 0";
} elseif ($status == 'replied') {
    $where = "AND is_replied = 1";
}

$sql = "SELECT m.*, u.full_name, u.email, u.phone 
        FROM messages m
        JOIN users u ON m.from_user_id = u.id
        WHERE m.to_admin = 1 $where
        ORDER BY m.created_at DESC
        LIMIT $offset, $limit";
$messages = $conn->query($sql);

// Get total count
$total = $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1 $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Get a single message for viewing
$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewMessage = $conn->query("
        SELECT m.*, u.full_name, u.email, u.phone 
        FROM messages m
        JOIN users u ON m.from_user_id = u.id
        WHERE m.id = $viewId
    ")->fetch_assoc();
    
    // Mark as read if viewing
    if ($viewMessage && !$viewMessage['is_replied']) {
        // Don't auto-mark, let admin decide
    }
}

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1")->fetch_assoc()['count'],
    'unread' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1 AND is_replied = 0")->fetch_assoc()['count'],
    'replied' => $conn->query("SELECT COUNT(*) as count FROM messages WHERE to_admin = 1 AND is_replied = 1")->fetch_assoc()['count'],
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card .value { font-size: 28px; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 14px; }
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab { padding: 8px 16px; background: white; border-radius: 8px; text-decoration: none; color: #333; }
        .tab.active { background: #667eea; color: white; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        tr:hover { background: #f8f9fa; cursor: pointer; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-success { background: #d4edda; color: #155724; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; }
        .btn-reply { background: #28a745; color: white; }
        .btn-back { background: #6c757d; color: white; }
        .message-view { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .message-meta { color: #666; font-size: 14px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #dee2e6; }
        .message-body { font-size: 16px; line-height: 1.6; margin-bottom: 20px; }
        .reply-form textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 12px; font-family: inherit; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; }
        .unread { font-weight: 600; background: #fff3cd; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar">
            <div class="sidebar-header"><h2>🏪 Brokerplace</h2><p>Admin Dashboard</p></div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='payments.php'"><i class="fas fa-credit-card"></i> Payments</li>
                <li class="nav-item" onclick="location.href='analytics.php'"><i class="fas fa-chart-line"></i> Analytics</li>
                <li class="nav-item active"><i class="fas fa-envelope"></i> Messages</li>
                <li class="nav-item" onclick="location.href='tickets.php'"><i class="fas fa-ticket-alt"></i> Support</li>
                <li class="nav-item" onclick="location.href='withdrawals.php'"><i class="fas fa-money-bill-wave"></i> Withdrawals</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">User Messages</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success" style="background: #d4edda; padding: 12px; border-radius: 8px; margin-bottom: 20px;"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error" style="background: #f8d7da; padding: 12px; border-radius: 8px; margin-bottom: 20px;"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="value"><?php echo $stats['total']; ?></div><div class="label">Total Messages</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['unread']; ?></div><div class="label">Unread</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['replied']; ?></div><div class="label">Replied</div></div>
            </div>
            
            <div class="tabs">
                <a href="?status=all" class="tab <?php echo $status == 'all' ? 'active' : ''; ?>">All</a>
                <a href="?status=unread" class="tab <?php echo $status == 'unread' ? 'active' : ''; ?>">Unread</a>
                <a href="?status=replied" class="tab <?php echo $status == 'replied' ? 'active' : ''; ?>">Replied</a>
            </div>
            
            <?php if ($viewMessage): ?>
                <!-- View Single Message -->
                <div class="section">
                    <div class="section-title">
                        Message from <?php echo htmlspecialchars($viewMessage['full_name']); ?>
                        <button onclick="location.href='messages.php?status=<?php echo $status; ?>'" class="btn-sm btn-back"><i class="fas fa-arrow-left"></i> Back</button>
                    </div>
                    
                    <div class="message-view">
                        <div class="message-meta">
                            <p><strong>From:</strong> <?php echo htmlspecialchars($viewMessage['full_name']); ?> (<?php echo htmlspecialchars($viewMessage['email']); ?>)</p>
                            <p><strong>Subject:</strong> <?php echo htmlspecialchars($viewMessage['subject']); ?></p>
                            <p><strong>Date:</strong> <?php echo date('F d, Y H:i', strtotime($viewMessage['created_at'])); ?></p>
                        </div>
                        <div class="message-body">
                            <?php echo nl2br(htmlspecialchars($viewMessage['message'])); ?>
                        </div>
                    </div>
                    
                    <div class="reply-form">
                        <h4>Send Reply</h4>
                        <form method="POST">
                            <input type="hidden" name="message_id" value="<?php echo $viewMessage['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $viewMessage['from_user_id']; ?>">
                            <textarea name="reply_text" rows="5" placeholder="Type your reply here..." required></textarea>
                            <button type="submit" name="send_reply" class="btn-sm btn-reply"><i class="fas fa-paper-plane"></i> Send Reply</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Messages List -->
                <div class="section">
                    <div class="section-title">All Messages</div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>From</th>
                                    <th>Subject</th>
                                    <th>Message</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $messages->fetch_assoc()): ?>
                                    <tr class="<?php echo !$row['is_replied'] ? 'unread' : ''; ?>" onclick="location.href='?view=<?php echo $row['id']; ?>&status=<?php echo $status; ?>'">
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                                        <td><?php echo htmlspecialchars(substr($row['subject'], 0, 40)); ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['message'], 0, 60)); ?>...</td>
                                        <td>
                                            <?php if ($row['is_replied']): ?>
                                                <span class="badge badge-success">Replied</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Unread</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo timeAgo($row['created_at']); ?></td>
                                        <td>
                                            <a href="?view=<?php echo $row['id']; ?>&status=<?php echo $status; ?>" class="btn-sm btn-reply">Reply</a>
                                        </td>
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
        </div>
    </div>
</body>
</html>