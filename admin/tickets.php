<?php
// admin/tickets.php - Support Tickets

$page_title = 'Support Tickets';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_ticket'])) {
        $ticketId = intval($_POST['ticket_id']);
        $status = $_POST['status'];
        $conn->query("UPDATE support_tickets SET status = '$status', updated_at = NOW() WHERE id = $ticketId");
        $message = "Ticket updated";
    }
    if (isset($_POST['add_reply'])) {
        $ticketId = intval($_POST['ticket_id']);
        $reply = $conn->real_escape_string($_POST['reply']);
        $adminId = $_SESSION['admin_id'] ?? 1;
        $conn->query("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES ($ticketId, $adminId, '$reply', 1)");
        $conn->query("UPDATE support_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = $ticketId");
        $message = "Reply added";
    }
    if (isset($_POST['resolve_ticket'])) {
        $ticketId = intval($_POST['ticket_id']);
        $conn->query("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW() WHERE id = $ticketId");
        $message = "Ticket resolved";
    }
}

$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status ? "WHERE t.status = '$status'" : "";
$sql = "SELECT t.*, u.full_name, u.email 
        FROM support_tickets t
        JOIN users u ON t.user_id = u.id
        $where
        ORDER BY FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), t.created_at DESC
        LIMIT $offset, $limit";
$tickets = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM support_tickets t $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$viewTicket = null;
$replies = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewTicket = $conn->query("SELECT t.*, u.full_name, u.email FROM support_tickets t JOIN users u ON t.user_id = u.id WHERE t.id = $viewId")->fetch_assoc();
    if ($viewTicket) {
        $replies = $conn->query("SELECT r.*, u.full_name, u.role FROM ticket_replies r JOIN users u ON r.user_id = u.id WHERE r.ticket_id = $viewId ORDER BY r.created_at ASC");
    }
}

$stats = [
    'open' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'open'")->fetch_assoc()['count'],
    'in_progress' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'in_progress'")->fetch_assoc()['count'],
    'resolved' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'resolved'")->fetch_assoc()['count'],
    'total' => $conn->query("SELECT COUNT(*) as count FROM support_tickets")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; }
    .filters { display: flex; gap: 12px; margin-bottom: 20px; }
    .filter-select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .ticket-view { background: #f8fafc; padding: 20px; border-radius: 16px; margin-bottom: 20px; }
    .reply-item { background: white; padding: 16px; border-radius: 12px; margin-bottom: 12px; border-left: 3px solid #667eea; }
    .reply-admin { border-left-color: #10b981; background: #f0fdf4; }
    .reply-form textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; }
    .btn-reply { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 40px; cursor: pointer; }
    .btn-resolve { background: #10b981; color: white; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
    .pagination .active { background: #667eea; color: white; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['open']; ?></div><div class="stat-label">Open</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['in_progress']; ?></div><div class="stat-label">In Progress</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['resolved']; ?></div><div class="stat-label">Resolved</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total</div></div>
</div>

<div class="filters">
    <select class="filter-select" onchange="location.href='?status='+this.value">
        <option value="">All</option>
        <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
        <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
        <option value="resolved" <?php echo $status == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
        <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
    </select>
</div>

<?php if ($viewTicket): ?>
    <div class="card">
        <div class="card-header">
            <h2>Ticket #<?php echo $viewTicket['ticket_number']; ?></h2>
            <a href="tickets.php" class="btn-sm btn-primary">← Back</a>
        </div>
        <div class="ticket-view">
            <p><strong>From:</strong> <?php echo htmlspecialchars($viewTicket['full_name']); ?> (<?php echo htmlspecialchars($viewTicket['email']); ?>)</p>
            <p><strong>Subject:</strong> <?php echo htmlspecialchars($viewTicket['subject']); ?></p>
            <p><strong>Priority:</strong> <span class="badge badge-warning"><?php echo ucfirst($viewTicket['priority']); ?></span></p>
            <p><strong>Status:</strong> <span class="badge badge-info"><?php echo ucfirst($viewTicket['status']); ?></span></p>
            <p><strong>Created:</strong> <?php echo date('F d, Y H:i', strtotime($viewTicket['created_at'])); ?></p>
            <div style="margin-top: 16px; padding: 16px; background: white; border-radius: 12px;">
                <strong>Message:</strong>
                <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($viewTicket['message'])); ?></p>
            </div>
        </div>
        
        <h3>Conversation</h3>
        <?php if ($replies && $replies->num_rows > 0): ?>
            <?php while($reply = $replies->fetch_assoc()): ?>
            <div class="reply-item <?php echo $reply['is_admin'] ? 'reply-admin' : ''; ?>">
                <p><strong><?php echo $reply['is_admin'] ? '👨‍💼 Admin' : htmlspecialchars($reply['full_name']); ?></strong> <small><?php echo date('M d, H:i', strtotime($reply['created_at'])); ?></small></p>
                <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($reply['message'])); ?></p>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No replies yet.</p>
        <?php endif; ?>
        
        <div class="reply-form" style="margin-top: 20px;">
            <h3>Add Reply</h3>
            <form method="POST">
                <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                <textarea name="reply" rows="4" placeholder="Type your reply..." required></textarea>
                <button type="submit" name="add_reply" class="btn-reply">Send Reply</button>
                <?php if ($viewTicket['status'] != 'resolved' && $viewTicket['status'] != 'closed'): ?>
                <button type="submit" name="resolve_ticket" class="btn-reply btn-resolve" style="margin-left: 10px;">Mark Resolved</button>
                <?php endif; ?>
            </form>
        </div>
        
        <div style="margin-top: 20px;">
            <form method="POST">
                <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                <label>Update Status:</label>
                <select name="status" class="filter-select" style="margin-left: 10px;">
                    <option value="open" <?php echo $viewTicket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="in_progress" <?php echo $viewTicket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="resolved" <?php echo $viewTicket['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="closed" <?php echo $viewTicket['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <button type="submit" name="update_ticket" class="btn-sm btn-primary">Update</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-ticket-alt"></i> Support Tickets</h2></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Ticket #</th><th>User</th><th>Subject</th><th>Priority</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                    <?php while($row = $tickets->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                        <td><?php echo htmlspecialchars($row['full_name']); ?> <br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                        <td><?php echo htmlspecialchars(substr($row['subject'], 0, 40)); ?>...</td>
                        <td><span class="badge badge-warning"><?php echo ucfirst($row['priority']); ?></span></td>
                        <td><span class="badge badge-info"><?php echo ucfirst($row['status']); ?></span></td>
                        <td><?php echo timeAgo($row['created_at']); ?></td>
                        <td><a href="?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary">View</a></td>
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