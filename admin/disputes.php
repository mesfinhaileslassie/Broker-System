<?php
// admin/disputes.php - Dispute Resolution

$page_title = 'Dispute Resolution';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

// Handle dispute actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dispute'])) {
    $disputeId = intval($_POST['dispute_id']);
    $status = $_POST['status'];
    $decision = $conn->real_escape_string($_POST['admin_decision']);
    $decisionNotes = $conn->real_escape_string($_POST['decision_notes']);
    
    $stmt = $conn->prepare("UPDATE disputes SET status = ?, admin_decision = ?, decision_notes = ?, resolved_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssi", $status, $decision, $decisionNotes, $disputeId);
    
    if ($stmt->execute()) {
        $disputeInfo = $conn->query("SELECT transaction_id FROM disputes WHERE id = $disputeId")->fetch_assoc();
        if ($status == 'resolved') {
            $conn->query("UPDATE transactions SET status = 'completed' WHERE id = {$disputeInfo['transaction_id']}");
        } elseif ($status == 'rejected') {
            $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = {$disputeInfo['transaction_id']}");
        }
        $message = "Dispute updated successfully";
    } else {
        $error = "Failed to update dispute";
    }
}

// Get disputes
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

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 32px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    .filters { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .filter-select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .btn-filter { padding: 8px 20px; background: #667eea; color: white; border: none; border-radius: 10px; cursor: pointer; }
    .dispute-card { background: white; border-radius: 20px; padding: 24px; margin-bottom: 20px; }
    .dispute-header { display: flex; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; }
    .dispute-title { font-size: 18px; font-weight: 600; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-success { background: #d1fae5; color: #059669; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
    .pagination .active { background: #667eea; color: white; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
    .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .btn-save { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 10px; cursor: pointer; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['open']; ?></div><div class="stat-label">Open</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['under_review']; ?></div><div class="stat-label">Under Review</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['resolved']; ?></div><div class="stat-label">Resolved</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total</div></div>
</div>

<div class="filters">
    <select class="filter-select" onchange="location.href='?status='+this.value">
        <option value="">All Status</option>
        <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
        <option value="under_review" <?php echo $status == 'under_review' ? 'selected' : ''; ?>>Under Review</option>
        <option value="resolved" <?php echo $status == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
    </select>
</div>

<?php if ($viewDispute): ?>
    <!-- View Single Dispute -->
    <div class="card">
        <div class="card-header">
            <h2>Dispute #<?php echo $viewDispute['id']; ?></h2>
            <a href="disputes.php" class="btn-sm btn-primary">← Back</a>
        </div>
        <div class="dispute-card">
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
            <button type="submit" name="update_dispute" class="btn-save">Update Dispute</button>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-gavel"></i> All Disputes</h2></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>ID</th><th>Transaction</th><th>Raised By</th><th>Reason</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                    <?php while($row = $disputes->fetch_assoc()): ?>
                    <tr>
                        <td>#<?php echo $row['id']; ?></td>
                        <td>#<?php echo $row['transaction_id']; ?></td>
                        <td><?php echo htmlspecialchars($row['raised_by_name']); ?></td>
                        <td><?php echo substr(htmlspecialchars($row['reason']), 0, 50); ?>...</td>
                        <td><span class="badge <?php echo $row['status'] == 'resolved' ? 'badge-success' : ($row['status'] == 'open' ? 'badge-danger' : 'badge-warning'); ?>"><?php echo $row['status']; ?></span></td>
                        <td><?php echo timeAgo($row['created_at']); ?></td>
                        <td><a href="?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary">Review</a></td>
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