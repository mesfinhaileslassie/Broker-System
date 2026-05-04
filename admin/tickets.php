<?php
// admin/tickets.php - Support Ticket Management

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Generate ticket number function
function generateTicketNumber() {
    return 'TKT-' . strtoupper(uniqid());
}

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_ticket'])) {
        $ticketId = intval($_POST['ticket_id']);
        $status = $_POST['status'];
        $priority = $_POST['priority'];
        $assignedTo = intval($_POST['assigned_to']);
        
        $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, priority = ?, assigned_to = ? WHERE id = ?");
        $stmt->bind_param("ssii", $status, $priority, $assignedTo, $ticketId);
        if ($stmt->execute()) {
            $message = "Ticket updated successfully";
        }
    }
    
    if (isset($_POST['add_reply'])) {
        $ticketId = intval($_POST['ticket_id']);
        $reply = $conn->real_escape_string($_POST['reply']);
        $adminId = $_SESSION['admin_id'];
        
        $stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, 1)");
        $stmt->bind_param("iis", $ticketId, $adminId, $reply);
        if ($stmt->execute()) {
            // Update ticket status to in_progress if it was open
            $conn->query("UPDATE support_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = $ticketId");
            $message = "Reply added successfully";
        }
    }
    
    if (isset($_POST['resolve_ticket'])) {
        $ticketId = intval($_POST['ticket_id']);
        $conn->query("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW() WHERE id = $ticketId");
        $message = "Ticket marked as resolved";
    }
}

// Get tickets with filters
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
if ($status) $where[] = "t.status = '$status'";
if ($priority) $where[] = "t.priority = '$priority'";
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$sql = "SELECT t.*, u.full_name, u.email, u.phone 
        FROM support_tickets t
        JOIN users u ON t.user_id = u.id
        $whereClause
        ORDER BY 
            FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'),
            t.created_at DESC
        LIMIT $offset, $limit";
$tickets = $conn->query($sql);

// Get total count
$total = $conn->query("SELECT COUNT(*) as count FROM support_tickets t $whereClause")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Get single ticket for view
$viewTicket = null;
$replies = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewTicket = $conn->query("
        SELECT t.*, u.full_name, u.email, u.phone 
        FROM support_tickets t
        JOIN users u ON t.user_id = u.id
        WHERE t.id = $viewId
    ")->fetch_assoc();
    
    if ($viewTicket) {
        $replies = $conn->query("
            SELECT r.*, u.full_name, u.role
            FROM ticket_replies r
            JOIN users u ON r.user_id = u.id
            WHERE r.ticket_id = $viewId
            ORDER BY r.created_at ASC
        ");
    }
}

// Get admin users for assignment
$admins = $conn->query("SELECT id, full_name FROM users WHERE role = 'admin'");

$stats = [
    'open' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'open'")->fetch_assoc()['count'],
    'in_progress' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'in_progress'")->fetch_assoc()['count'],
    'resolved' => $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'resolved'")->fetch_assoc()['count'],
    'total' => $conn->query("SELECT COUNT(*) as count FROM support_tickets")->fetch_assoc()['count'],
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - Admin Panel</title>
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
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card .value { font-size: 28px; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 14px; }
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .filters { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        tr:hover { background: #f8f9fa; cursor: pointer; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-success { background: #d4edda; color: #155724; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; }
        .btn-view { background: #17a2b8; color: white; }
        .btn-reply { background: #28a745; color: white; }
        .btn-resolve { background: #fd7e14; color: white; }
        .ticket-view { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .reply-item { background: white; border-radius: 8px; padding: 15px; margin-bottom: 15px; border-left: 3px solid #667eea; }
        .reply-admin { border-left-color: #28a745; background: #f0fff4; }
        .reply-form textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 12px; font-family: inherit; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; }
        .priority-high { border-left: 3px solid #dc3545; }
        .priority-medium { border-left: 3px solid #fd7e14; }
        .priority-low { border-left: 3px solid #28a745; }
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
                <li class="nav-item" onclick="location.href='messages.php'"><i class="fas fa-envelope"></i> Messages</li>
                <li class="nav-item active"><i class="fas fa-ticket-alt"></i> Support</li>
                <li class="nav-item" onclick="location.href='withdrawals.php'"><i class="fas fa-money-bill-wave"></i> Withdrawals</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Support Tickets</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success" style="background: #d4edda; padding: 12px; border-radius: 8px; margin-bottom: 20px;"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="value"><?php echo $stats['open']; ?></div><div class="label">Open</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['in_progress']; ?></div><div class="label">In Progress</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['resolved']; ?></div><div class="label">Resolved</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['total']; ?></div><div class="label">Total</div></div>
            </div>
            
            <?php if ($viewTicket): ?>
                <!-- View Single Ticket -->
                <div class="section">
                    <div class="section-title">
                        Ticket #<?php echo $viewTicket['ticket_number']; ?>
                        <button onclick="location.href='tickets.php'" class="btn-sm btn-view"><i class="fas fa-arrow-left"></i> Back</button>
                    </div>
                    
                    <div class="ticket-view">
                        <p><strong>From:</strong> <?php echo htmlspecialchars($viewTicket['full_name']); ?> (<?php echo htmlspecialchars($viewTicket['email']); ?>)</p>
                        <p><strong>Subject:</strong> <?php echo htmlspecialchars($viewTicket['subject']); ?></p>
                        <p><strong>Priority:</strong> 
                            <span class="badge <?php echo $viewTicket['priority'] == 'urgent' ? 'badge-danger' : ($viewTicket['priority'] == 'high' ? 'badge-warning' : 'badge-info'); ?>">
                                <?php echo ucfirst($viewTicket['priority']); ?>
                            </span>
                        </p>
                        <p><strong>Status:</strong> 
                            <span class="badge <?php echo $viewTicket['status'] == 'resolved' ? 'badge-success' : ($viewTicket['status'] == 'closed' ? 'badge-secondary' : 'badge-warning'); ?>">
                                <?php echo ucfirst($viewTicket['status']); ?>
                            </span>
                        </p>
                        <p><strong>Created:</strong> <?php echo date('F d, Y H:i', strtotime($viewTicket['created_at'])); ?></p>
                        <div style="margin-top: 20px; padding: 15px; background: white; border-radius: 8px;">
                            <strong>Message:</strong>
                            <p style="margin-top: 10px;"><?php echo nl2br(htmlspecialchars($viewTicket['message'])); ?></p>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <h4>Conversation History</h4>
                        <?php if ($replies && $replies->num_rows > 0): ?>
                            <?php while($reply = $replies->fetch_assoc()): ?>
                                <div class="reply-item <?php echo $reply['is_admin'] ? 'reply-admin' : ''; ?>">
                                    <p><strong><?php echo $reply['is_admin'] ? '👨‍💼 Admin' : htmlspecialchars($reply['full_name']); ?></strong> 
                                    <small><?php echo date('M d, H:i', strtotime($reply['created_at'])); ?></small></p>
                                    <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($reply['message'])); ?></p>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p>No replies yet.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="reply-form" style="margin-top: 20px;">
                        <h4>Add Reply</h4>
                        <form method="POST">
                            <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                            <textarea name="reply" rows="4" placeholder="Type your reply here..." required></textarea>
                            <button type="submit" name="add_reply" class="btn-sm btn-reply"><i class="fas fa-paper-plane"></i> Send Reply</button>
                            <?php if ($viewTicket['status'] != 'resolved' && $viewTicket['status'] != 'closed'): ?>
                                <button type="submit" name="resolve_ticket" class="btn-sm btn-resolve" style="margin-left: 10px;"><i class="fas fa-check"></i> Mark as Resolved</button>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <form method="POST">
                            <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                            <div style="display: flex; gap: 10px; align-items: flex-end;">
                                <div class="form-group" style="flex: 1;">
                                    <label>Update Status</label>
                                    <select name="status" class="filter-select" style="width: 100%;">
                                        <option value="open" <?php echo $viewTicket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo $viewTicket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="resolved" <?php echo $viewTicket['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="closed" <?php echo $viewTicket['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label>Priority</label>
                                    <select name="priority" class="filter-select" style="width: 100%;">
                                        <option value="low" <?php echo $viewTicket['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo $viewTicket['priority'] == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo $viewTicket['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="urgent" <?php echo $viewTicket['priority'] == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label>Assign To</label>
                                    <select name="assigned_to" class="filter-select" style="width: 100%;">
                                        <option value="0">Unassigned</option>
                                        <?php while($admin = $admins->fetch_assoc()): ?>
                                            <option value="<?php echo $admin['id']; ?>" <?php echo $viewTicket['assigned_to'] == $admin['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($admin['full_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <button type="submit" name="update_ticket" class="btn-sm btn-view">Update</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Tickets List -->
                <div class="filters">
                    <select class="filter-select" onchange="location.href='?status='+this.value+'&priority=<?php echo urlencode($priority); ?>'">
                        <option value="">All Status</option>
                        <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="resolved" <?php echo $status == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                    <select class="filter-select" onchange="location.href='?priority='+this.value+'&status=<?php echo urlencode($status); ?>'">
                        <option value="">All Priority</option>
                        <option value="low" <?php echo $priority == 'low' ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo $priority == 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo $priority == 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="urgent" <?php echo $priority == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                    </select>
                </div>
                
                <div class="section">
                    <div class="section-title">All Support Tickets</div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr><th>Ticket #</th><th>User</th><th>Subject</th><th>Priority</th><th>Status</th><th>Created</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php while($row = $tickets->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $row['ticket_number']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['full_name']); ?><br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                                        <td><?php echo htmlspecialchars(substr($row['subject'], 0, 50)); ?>...</td>
                                        <td>
                                            <span class="badge <?php echo $row['priority'] == 'urgent' ? 'badge-danger' : ($row['priority'] == 'high' ? 'badge-warning' : 'badge-info'); ?>">
                                                <?php echo ucfirst($row['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $row['status'] == 'resolved' ? 'badge-success' : ($row['status'] == 'closed' ? 'badge-secondary' : 'badge-warning'); ?>">
                                                <?php echo ucfirst($row['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo timeAgo($row['created_at']); ?></td>
                                        <td><a href="?view=<?php echo $row['id']; ?>" class="btn-sm btn-view">View</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>&priority=<?php echo urlencode($priority); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>