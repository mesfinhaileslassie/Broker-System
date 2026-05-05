<?php
// user/notifications.php - Notifications Management Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Notifications';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Handle mark as read
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $notif_id = intval($_GET['id']);
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $notif_id AND user_id = $user_id");
    header('Location: notifications.php');
    exit;
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    header('Location: notifications.php');
    exit;
}

// Handle delete notification
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $notif_id = intval($_GET['id']);
    $conn->query("DELETE FROM notifications WHERE id = $notif_id AND user_id = $user_id");
    header('Location: notifications.php');
    exit;
}

// Get filter
$filter = $_GET['filter'] ?? 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query
$where = "user_id = $user_id";
if ($filter == 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter == 'read') {
    $where .= " AND is_read = 1";
}

// Get total count
$total_result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE $where");
$total = $total_result->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Get notifications
$notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE $where 
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
");

// Get counts for stats
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id")->fetch_assoc()['count'],
    'unread' => $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count'],
    'read' => $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 1")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .page-header p {
        color: #64748b;
        font-size: 14px;
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    /* Filters */
    .filters {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .filter-btn {
        padding: 8px 20px;
        background: white;
        border-radius: 30px;
        text-decoration: none;
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .filter-btn:hover, .filter-btn.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .mark-all-btn {
        margin-left: auto;
        padding: 8px 20px;
        background: #10b981;
        color: white;
        border-radius: 30px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .mark-all-btn:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    /* Notifications List */
    .notifications-list {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .notification-item {
        display: flex;
        align-items: flex-start;
        padding: 20px;
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.3s;
        position: relative;
    }
    
    .notification-item:hover {
        background: #f8fafc;
    }
    
    .notification-item.unread {
        background: #eef2ff;
    }
    
    .notification-item.unread:hover {
        background: #e0e7ff;
    }
    
    .notification-icon {
        width: 48px;
        height: 48px;
        background: #f1f5f9;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 16px;
        flex-shrink: 0;
    }
    
    .notification-icon i {
        font-size: 20px;
        color: #667eea;
    }
    
    .notification-content {
        flex: 1;
    }
    
    .notification-title {
        font-size: 15px;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 4px;
    }
    
    .notification-message {
        font-size: 13px;
        color: #475569;
        margin-bottom: 6px;
        line-height: 1.5;
    }
    
    .notification-time {
        font-size: 11px;
        color: #94a3b8;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .notification-actions {
        display: flex;
        gap: 12px;
        margin-top: 8px;
    }
    
    .action-link {
        font-size: 12px;
        text-decoration: none;
        padding: 4px 12px;
        border-radius: 20px;
        transition: all 0.3s;
    }
    
    .action-link.mark-read {
        color: #667eea;
        background: #e0e7ff;
    }
    
    .action-link.mark-read:hover {
        background: #667eea;
        color: white;
    }
    
    .action-link.delete {
        color: #dc2626;
        background: #fee2e2;
    }
    
    .action-link.delete:hover {
        background: #dc2626;
        color: white;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
        display: block;
    }
    
    .empty-state h3 {
        font-size: 20px;
        color: #334155;
        margin-bottom: 8px;
    }
    
    .empty-state p {
        color: #64748b;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border-radius: 10px;
        text-decoration: none;
        color: #334155;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .pagination a:hover, .pagination .active {
        background: #667eea;
        color: white;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .filters {
            flex-direction: column;
            align-items: stretch;
        }
        
        .mark-all-btn {
            margin-left: 0;
            text-align: center;
        }
        
        .notification-item {
            flex-direction: column;
        }
        
        .notification-icon {
            margin-bottom: 12px;
        }
        
        .notification-actions {
            flex-wrap: wrap;
        }
    }
</style>

<div class="page-header">
    <h1><i class="fas fa-bell"></i> Notifications</h1>
    <p>Stay updated with your account activity</p>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Notifications</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['unread']); ?></div>
        <div class="stat-label">Unread</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['read']); ?></div>
        <div class="stat-label">Read</div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">All</a>
    <a href="?filter=unread" class="filter-btn <?php echo $filter == 'unread' ? 'active' : ''; ?>">Unread</a>
    <a href="?filter=read" class="filter-btn <?php echo $filter == 'read' ? 'active' : ''; ?>">Read</a>
    <?php if ($stats['unread'] > 0): ?>
        <a href="?mark_all_read=1" class="mark-all-btn" onclick="return confirm('Mark all notifications as read?')">
            <i class="fas fa-check-double"></i> Mark All as Read
        </a>
    <?php endif; ?>
</div>

<!-- Notifications List -->
<?php if ($notifications && $notifications->num_rows > 0): ?>
    <div class="notifications-list">
        <?php while($notif = $notifications->fetch_assoc()): 
            // Determine icon based on notification type
            $icon = 'bell';
            $icon_color = '#667eea';
            $bg_color = '#e0e7ff';
            
            if (strpos($notif['title'], 'Approved') !== false) {
                $icon = 'check-circle';
                $icon_color = '#10b981';
                $bg_color = '#d1fae5';
            } elseif (strpos($notif['title'], 'Rejected') !== false) {
                $icon = 'times-circle';
                $icon_color = '#ef4444';
                $bg_color = '#fee2e2';
            } elseif (strpos($notif['title'], 'Payment') !== false) {
                $icon = 'credit-card';
                $icon_color = '#f59e0b';
                $bg_color = '#fed7aa';
            } elseif (strpos($notif['title'], 'Legal') !== false) {
                $icon = 'gavel';
                $icon_color = '#8b5cf6';
                $bg_color = '#ede9fe';
            } else {
                $icon = 'bell';
                $icon_color = '#667eea';
                $bg_color = '#e0e7ff';
            }
        ?>
            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                <div class="notification-icon" style="background: <?php echo $bg_color; ?>;">
                    <i class="fas fa-<?php echo $icon; ?>" style="color: <?php echo $icon_color; ?>;"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                    <div class="notification-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                    <div class="notification-time">
                        <span><i class="far fa-clock"></i> <?php echo timeAgo($notif['created_at']); ?></span>
                        <div class="notification-actions">
                            <?php if (!$notif['is_read']): ?>
                                <a href="?mark_read=1&id=<?php echo $notif['id']; ?>" class="action-link mark-read">
                                    <i class="fas fa-check"></i> Mark as read
                                </a>
                            <?php endif; ?>
                            <a href="?delete=1&id=<?php echo $notif['id']; ?>" class="action-link delete" onclick="return confirm('Delete this notification?')">
                                <i class="fas fa-trash-alt"></i> Delete
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-bell-slash"></i>
        <h3>No notifications</h3>
        <p>You don't have any notifications at the moment.</p>
        <a href="dashboard.php" class="btn" style="display: inline-block; margin-top: 16px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 10px 24px; border-radius: 40px; text-decoration: none;">
            <i class="fas fa-home"></i> Go to Dashboard
        </a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>