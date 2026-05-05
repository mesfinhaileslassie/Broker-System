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
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
    }
    
    .stat-card.open .stat-value { color: #ef4444; }
    .stat-card.review .stat-value { color: #f59e0b; }
    .stat-card.resolved .stat-value { color: #10b981; }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    /* Filters */
    .filters {
        margin-bottom: 24px;
    }
    
    .filter-select {
        padding: 10px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        background: white;
        min-width: 180px;
        cursor: pointer;
    }
    
    /* Card */
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .card-header h2 {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    /* Table */
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 14px 12px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
        font-size: 12px;
        text-transform: uppercase;
    }
    
    tr:hover {
        background: #f8fafc;
        cursor: pointer;
    }
    
    /* Badges */
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .badge-open { background: #fee2e2; color: #dc2626; }
    .badge-review { background: #fed7aa; color: #ea580c; }
    .badge-resolved { background: #d1fae5; color: #059669; }
    
    /* Buttons */
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        background: #667eea;
        color: white;
    }
    
    .dispute-detail {
        background: #f8fafc;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
    }
    
    .form-group select, .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-family: inherit;
    }
    
    .form-group select:focus, .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
    }
    
    .btn-save {
        background: #10b981;
        color: white;
        padding: 10px 24px;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
        padding-top: 16px;
        border-top: 1px solid #f1f5f9;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        text-decoration: none;
        color: #475569;
        font-size: 13px;
    }
    
    .pagination a:hover, .pagination .active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        th, td { padding: 10px 8px; font-size: 12px; }
        .card-header { flex-direction: column; gap: 12px; align-items: flex-start; }
    }
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card open">
        <div class="stat-value"><?php echo $stats['open']; ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-card review">
        <div class="stat-value"><?php echo $stats['under_review']; ?></div>
        <div class="stat-label">Under Review</div>
    </div>
    <div class="stat-card resolved">
        <div class="stat-value"><?php echo $stats['resolved']; ?></div>
        <div class="stat-label">Resolved</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total</div>
    </div>
</div>

<!-- Filters -->
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
            <a href="disputes.php" class="btn-sm">← Back to List</a>
        </div>
        
        <div class="dispute-detail">
            <p><strong>Transaction ID:</strong> #<?php echo $viewDispute['transaction_id']; ?></p>
            <p><strong>Amount:</strong> <?php echo formatMoney($viewDispute['total_amount']); ?></p>
            <p><strong>Buyer:</strong> <?php echo htmlspecialchars($viewDispute['buyer_name']); ?></p>
            <p><strong>Seller:</strong> <?php echo htmlspecialchars($viewDispute['seller_name']); ?></p>
            <p><strong>Raised By:</strong> <?php echo htmlspecialchars($viewDispute['raised_by_name']); ?></p>
            <p><strong>Status:</strong> <span class="badge badge-<?php echo $viewDispute['status'] == 'open' ? 'open' : ($viewDispute['status'] == 'under_review' ? 'review' : 'resolved'); ?>"><?php echo ucfirst($viewDispute['status']); ?></span></p>
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
    <!-- Disputes List -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-gavel"></i> All Disputes</h2>
            <span><?php echo number_format($total); ?> disputes</span>
        </div>
        <div class="table-wrapper">
            <?php if ($disputes && $disputes->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Transaction</th>
                            <th>Raised By</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $disputes->fetch_assoc()): ?>
                            <tr onclick="location.href='?view=<?php echo $row['id']; ?>'">
                                <td>#<?php echo $row['id']; ?></td>
                                <td>#<?php echo $row['transaction_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['raised_by_name']); ?></td>
                                <td><?php echo substr(htmlspecialchars($row['reason']), 0, 50); ?>...</td>
                                <td>
                                    <span class="badge badge-<?php echo $row['status'] == 'open' ? 'open' : ($row['status'] == 'under_review' ? 'review' : 'resolved'); ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo timeAgo($row['created_at']); ?></td>
                                <td><a href="?view=<?php echo $row['id']; ?>" class="btn-sm" onclick="event.stopPropagation()">Review</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state" style="text-align: center; padding: 60px; color: #94a3b8;">
                    <i class="fas fa-gavel" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                    <p>No disputes found</p>
                </div>
            <?php endif; ?>
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