<?php
// user/notifications.php - Complete Working Notifications Page

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
    $notif_id = intval($_GET['mark_read']);
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
    $notif_id = intval($_GET['delete']);
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
$total = $total_result ? $total_result->fetch_assoc()['count'] : 0;
$totalPages = $total > 0 ? ceil($total / $limit) : 1;

// Get notifications
$notifications = $conn->query("
    SELECT * FROM notifications 
    WHERE $where 
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
");

// Get counts for stats
$stats = [
    'total' => ($conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id")->fetch_assoc()['count']) ?? 0,
    'unread' => ($conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0")->fetch_assoc()['count']) ?? 0,
    'read' => ($conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 1")->fetch_assoc()['count']) ?? 0,
];

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .notifications-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    /* Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .page-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .page-header h1 {
        position: relative;
        z-index: 1;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .page-header p {
        position: relative;
        z-index: 1;
        font-size: 14px;
        opacity: 0.9;
    }
    
    .dashboard-link {
        position: relative;
        z-index: 1;
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 10px 20px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .dashboard-link:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
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
        border: 1px solid var(--border);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 13px;
        color: var(--gray);
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
        color: var(--gray);
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border);
    }
    
    .filter-btn:hover, .filter-btn.active {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border-color: transparent;
    }
    
    .mark-all-btn {
        margin-left: auto;
        padding: 8px 20px;
        background: var(--success);
        color: white;
        border-radius: 30px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
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
        border: 1px solid var(--border);
    }
    
    .notification-item {
        display: flex;
        align-items: flex-start;
        padding: 20px;
        border-bottom: 1px solid var(--border);
        transition: all 0.3s;
        position: relative;
    }
    
    .notification-item:last-child {
        border-bottom: none;
    }
    
    .notification-item:hover {
        background: var(--light);
    }
    
    .notification-item.unread {
        background: #eef2ff;
        border-left: 4px solid var(--primary);
    }
    
    .notification-item.unread:hover {
        background: #e0e7ff;
    }
    
    .notification-icon {
        width: 48px;
        height: 48px;
        background: var(--light);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 16px;
        flex-shrink: 0;
    }
    
    .notification-icon i {
        font-size: 20px;
        color: var(--primary);
    }
    
    .notification-content {
        flex: 1;
    }
    
    .notification-title {
        font-size: 15px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .notification-message {
        font-size: 13px;
        color: var(--gray);
        margin-bottom: 6px;
        line-height: 1.5;
    }
    
    .notification-time {
        font-size: 11px;
        color: var(--gray);
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
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .action-link.mark-read {
        color: var(--primary);
        background: #e0e7ff;
    }
    
    .action-link.mark-read:hover {
        background: var(--primary);
        color: white;
    }
    
    .action-link.delete {
        color: var(--danger);
        background: #fee2e2;
    }
    
    .action-link.delete:hover {
        background: var(--danger);
        color: white;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
        border: 1px solid var(--border);
    }
    
    .empty-state-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 36px;
        color: white;
    }
    
    .empty-state h3 {
        font-size: 20px;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .empty-state p {
        color: var(--gray);
        margin-bottom: 20px;
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
        color: var(--dark);
        font-size: 14px;
        transition: all 0.3s;
        border: 1px solid var(--border);
    }
    
    .pagination a:hover, .pagination .active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
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
            justify-content: center;
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
        .page-header {
            flex-direction: column;
            text-align: center;
        }
    }
</style>

<div class="notifications-container">
    <!-- Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-bell"></i> Notifications</h1>
            <p>Stay updated with your account activity</p>
        </div>
        <a href="dashboard.php" class="dashboard-link">
            <i class="fas fa-home"></i> Back to Dashboard
        </a>
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
                } elseif (strpos($notif['message'], 'message') !== false) {
                    $icon = 'comment';
                    $icon_color = '#3b82f6';
                    $bg_color = '#dbeafe';
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
                                    <a href="?mark_read=1&id=<?php echo $notif['id']; ?>" class="action-link mark-read" onclick="event.stopPropagation()">
                                        <i class="fas fa-check"></i> Mark as read
                                    </a>
                                <?php endif; ?>
                                <a href="?delete=1&id=<?php echo $notif['id']; ?>" class="action-link delete" onclick="event.stopPropagation(); return confirm('Delete this notification?')">
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
            <div class="empty-state-icon">
                <i class="fas fa-bell-slash"></i>
            </div>
            <h3>No notifications</h3>
            <p>You don't have any notifications at the moment.</p>
            <a href="dashboard.php" class="dashboard-link" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); display: inline-block;">
                <i class="fas fa-home"></i> Go to Dashboard
            </a>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>