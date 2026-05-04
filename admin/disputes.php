<?php
// admin/disputes.php - Dispute Resolution System

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle dispute actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_dispute'])) {
        $disputeId = intval($_POST['dispute_id']);
        $status = $_POST['status'];
        $decision = $conn->real_escape_string($_POST['admin_decision']);
        $decisionNotes = $conn->real_escape_string($_POST['decision_notes']);
        
        $stmt = $conn->prepare("UPDATE disputes SET status = ?, admin_decision = ?, decision_notes = ?, resolved_at = NOW() WHERE id = ?");
        $stmt->bind_param("sssi", $status, $decision, $decisionNotes, $disputeId);
        
        if ($stmt->execute()) {
            // Update transaction status based on decision
            $disputeInfo = $conn->query("SELECT transaction_id FROM disputes WHERE id = $disputeId")->fetch_assoc();
            if ($status == 'resolved') {
                $conn->query("UPDATE transactions SET status = 'completed' WHERE id = {$disputeInfo['transaction_id']}");
            } elseif ($status == 'rejected') {
                $conn->query("UPDATE transactions SET status = 'in_progress' WHERE id = {$disputeInfo['transaction_id']}");
            }
            $message = "Dispute updated successfully";
        } else {
            $error = "Failed to update dispute";
        }
    }
}

// Get disputes with filters
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status ? "WHERE d.status = '$status'" : "";
$sql = "SELECT d.*, t.total_amount, u.full_name as raised_by_name, u2.full_name as buyer_name, u3.full_name as seller_name
        FROM disputes d
        JOIN transactions t ON d.transaction_id = t.id
        JOIN users u ON d.raised_by = u.id
        JOIN users u2 ON t.buyer_id = u2.id
        JOIN users u3 ON t.seller_id = u3.id
        $where
        ORDER BY d.created_at DESC
        LIMIT $offset, $limit";
$disputes = $conn->query($sql);

// Get total count
$total = $conn->query("SELECT COUNT(*) as count FROM disputes $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Get single dispute for view
$viewDispute = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $viewDispute = $conn->query("
        SELECT d.*, t.*, u.full_name as raised_by_name, u2.full_name as buyer_name, u3.full_name as seller_name
        FROM disputes d
        JOIN transactions t ON d.transaction_id = t.id
        JOIN users u ON d.raised_by = u.id
        JOIN users u2 ON t.buyer_id = u2.id
        JOIN users u3 ON t.seller_id = u3.id
        WHERE d.id = $viewId
    ")->fetch_assoc();
}

$stats = [
    'open' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'open'")->fetch_assoc()['count'],
    'under_review' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'under_review'")->fetch_assoc()['count'],
    'resolved' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'resolved'")->fetch_assoc()['count'],
    'total' => $conn->query("SELECT COUNT(*) as count FROM disputes")->fetch_assoc()['count'],
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispute Resolution - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; }
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
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-success { background: #d4edda; color: #155724; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; }
        .btn-view { background: #17a2b8; color: white; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        .btn-save { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; }
        .dispute-detail { background: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 20px; }
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
                <li class="nav-item active"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Dispute Resolution</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="value"><?php echo $stats['open']; ?></div><div class="label">Open</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['under_review']; ?></div><div class="label">Under Review</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['resolved']; ?></div><div class="label">Resolved</div></div>
                <div class="stat-card"><div class="value"><?php echo $stats['total']; ?></div><div class="label">Total</div></div>
            </div>
            
            <?php if ($viewDispute): ?>
                <!-- View Single Dispute -->
                <div class="section">
                    <div class="section-title">
                        Dispute #<?php echo $viewDispute['id']; ?>
                        <button onclick="location.href='disputes.php'" class="btn-sm btn-view">← Back</button>
                    </div>
                    
                    <div class="dispute-detail">
                        <p><strong>Transaction ID:</strong> #<?php echo $viewDispute['transaction_id']; ?></p>
                        <p><strong>Amount:</strong> <?php echo formatMoney($viewDispute['total_amount']); ?></p>
                        <p><strong>Buyer:</strong> <?php echo htmlspecialchars($viewDispute['buyer_name']); ?></p>
                        <p><strong>Seller:</strong> <?php echo htmlspecialchars($viewDispute['seller_name']); ?></p>
                        <p><strong>Raised By:</strong> <?php echo htmlspecialchars($viewDispute['raised_by_name']); ?></p>
                        <p><strong>Status:</strong> <span class="badge badge-warning"><?php echo $viewDispute['status']; ?></span></p>
                        <p><strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($viewDispute['reason'])); ?></p>
                        <?php if ($viewDispute['evidence']): ?>
                            <p><strong>Evidence:</strong> <?php echo nl2br(htmlspecialchars($viewDispute['evidence'])); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="dispute_id" value="<?php echo $viewDispute['id']; ?>">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" required>
                                <option value="open" <?php echo $viewDispute['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                <option value="under_review" <?php echo $viewDispute['status'] == 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                <option value="resolved" <?php echo $viewDispute['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved - Release Payment</option>
                                <option value="rejected" <?php echo $viewDispute['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected - Refund</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Admin Decision</label>
                            <textarea name="admin_decision" rows="3" required><?php echo htmlspecialchars($viewDispute['admin_decision'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Additional Notes</label>
                            <textarea name="decision_notes" rows="2"><?php echo htmlspecialchars($viewDispute['decision_notes'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" name="update_dispute" class="btn-save"><i class="fas fa-save"></i> Update Dispute</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Disputes List -->
                <div class="section">
                    <div class="section-title">All Disputes</div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr><th>ID</th><th>Transaction</th><th>Raised By</th><th>Reason</th><th>Status</th><th>Created</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php while($row = $disputes->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td>#<?php echo $row['transaction_id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['raised_by_name']); ?></td>
                                        <td><?php echo substr(htmlspecialchars($row['reason']), 0, 50); ?>...</td>
                                        <td>
                                            <?php
                                            $badgeClass = $row['status'] == 'resolved' ? 'badge-success' : ($row['status'] == 'open' ? 'badge-danger' : 'badge-warning');
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $row['status']; ?></span>
                                         </td>
                                        <td><?php echo timeAgo($row['created_at']); ?></td>
                                        <td><a href="?view=<?php echo $row['id']; ?>" class="btn-sm btn-view">Review</a></td>
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