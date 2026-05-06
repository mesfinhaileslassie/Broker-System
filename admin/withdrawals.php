<?php
// admin/withdrawals.php - Admin Withdrawal Management

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireAdminLogin();

$page_title = 'Withdrawal Management';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle withdrawal actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $withdrawal_id = sanitizeInt($_POST['withdrawal_id'] ?? 0);
    $action = sanitizeString($_POST['action'] ?? '');
    $admin_notes = sanitizeString($_POST['admin_notes'] ?? '');
    $admin_id = $_SESSION['user_id'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("
            UPDATE withdrawal_requests 
            SET status = 'approved', admin_notes = ?, processed_by = ?, processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("sii", $admin_notes, $admin_id, $withdrawal_id);
        
        if ($stmt->execute()) {
            $message = "Withdrawal approved successfully";
        } else {
            $error = "Failed to approve withdrawal";
        }
    } elseif ($action === 'complete') {
        $stmt = $conn->prepare("
            UPDATE withdrawal_requests 
            SET status = 'completed', admin_notes = ?, processed_by = ?, processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("sii", $admin_notes, $admin_id, $withdrawal_id);
        
        if ($stmt->execute()) {
            $message = "Withdrawal marked as completed";
        } else {
            $error = "Failed to complete withdrawal";
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("
            UPDATE withdrawal_requests 
            SET status = 'rejected', admin_notes = ?, processed_by = ?, processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("sii", $admin_notes, $admin_id, $withdrawal_id);
        
        if ($stmt->execute()) {
            // Refund the amount back to user
            $wd = $conn->query("SELECT user_id, amount FROM withdrawal_requests WHERE id = $withdrawal_id")->fetch_assoc();
            if ($wd) {
                $conn->query("UPDATE users SET balance = balance + {$wd['amount']} WHERE id = {$wd['user_id']}");
                $conn->query("INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                    VALUES ({$wd['user_id']}, {$wd['amount']}, 'deposit', 'Withdrawal rejection refund', NOW())");
            }
            $message = "Withdrawal rejected and amount refunded";
        } else {
            $error = "Failed to reject withdrawal";
        }
    }
}

// Get filter
$status_filter = sanitizeString($_GET['status'] ?? '');
$page = sanitizeInt($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status_filter ? "WHERE w.status = '$status_filter'" : "";
$sql = "SELECT w.*, u.full_name, u.email, u.phone, u.balance
        FROM withdrawal_requests w
        JOIN users u ON w.user_id = u.id
        $where
        ORDER BY FIELD(w.status, 'pending', 'approved', 'completed', 'rejected'), w.created_at DESC
        LIMIT $offset, $limit";
$withdrawals = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests w $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Statistics
$stats = [
    'pending' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc(),
    'approved' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'approved'")->fetch_assoc(),
    'completed' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'completed'")->fetch_assoc(),
    'total_processed' => $conn->query("SELECT SUM(amount) as total FROM withdrawal_requests WHERE status IN ('approved', 'completed')")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: white; border-radius: 20px; padding: 24px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    .stat-small { font-size: 11px; color: #94a3b8; margin-top: 4px; }
    
    .filters { margin-bottom: 24px; }
    .filter-select { padding: 10px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; background: white; min-width: 180px; }
    
    .card { background: white; border-radius: 20px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
    
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; }
    
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #2563eb; }
    .badge-completed { background: #d1fae5; color: #059669; }
    .badge-rejected { background: #fee2e2; color: #dc2626; }
    
    .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; margin: 2px; }
    .btn-approve { background: #10b981; color: white; }
    .btn-reject { background: #ef4444; color: white; }
    .btn-complete { background: #667eea; color: white; }
    
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
    .modal-content { background: white; border-radius: 20px; padding: 28px; width: 450px; max-width: 90%; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .close-modal { cursor: pointer; font-size: 28px; color: #94a3b8; }
    .form-group { margin-bottom: 20px; }
    .form-group textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; font-family: inherit; resize: vertical; }
    
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 24px; }
    .pagination a, .pagination span { padding: 8px 14px; background: white; border: 1px solid #e2e8f0; border-radius: 10px; text-decoration: none; color: #475569; font-size: 13px; }
    .pagination a:hover, .pagination .active { background: #667eea; color: white; border-color: #667eea; }
    
    .empty-state { text-align: center; padding: 60px; color: #94a3b8; }
    .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; }
    
    @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
</style>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['pending']['total'] ?? 0); ?></div>
        <div class="stat-label">Pending Amount</div>
        <div class="stat-small"><?php echo number_format($stats['pending']['count'] ?? 0); ?> requests</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['approved']['total'] ?? 0); ?></div>
        <div class="stat-label">Approved Amount</div>
        <div class="stat-small"><?php echo number_format($stats['approved']['count'] ?? 0); ?> requests</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['completed']['total'] ?? 0); ?></div>
        <div class="stat-label">Completed Amount</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['total_processed']); ?></div>
        <div class="stat-label">Total Processed</div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <select class="filter-select" onchange="location.href='?status='+this.value">
        <option value="">All Status</option>
        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
    </select>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="background:#d1fae5; color:#059669; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" style="background:#fee2e2; color:#dc2626; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Withdrawals Table -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-money-bill-wave"></i> Withdrawal Requests</h2>
        <span><?php echo number_format($total); ?> requests</span>
    </div>
    <div class="table-wrapper">
        <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
            <table>
                <thead>
                    <tr><th>ID</th><th>User</th><th>Amount</th><th>Bank Details</th><th>Status</th><th>Requested</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php while($row = $withdrawals->fetch_assoc()): ?>
                    <tr>
                        <td>#<?php echo $row['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                        <td><strong><?php echo formatMoney($row['amount']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['bank_name']); ?><br><small><?php echo htmlspecialchars($row['account_number']); ?></small></td>
                        <td>
                            <?php
                            $badgeClass = match($row['status']) {
                                'pending' => 'badge-pending',
                                'approved' => 'badge-approved',
                                'completed' => 'badge-completed',
                                'rejected' => 'badge-rejected',
                                default => ''
                            };
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($row['status']); ?></span>
                        </td>
                        <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                        <td>
                            <?php if ($row['status'] == 'pending'): ?>
                                <button onclick="openActionModal('approve', <?php echo $row['id']; ?>)" class="btn-sm btn-approve">Approve</button>
                                <button onclick="openActionModal('reject', <?php echo $row['id']; ?>)" class="btn-sm btn-reject">Reject</button>
                            <?php elseif ($row['status'] == 'approved'): ?>
                                <button onclick="openActionModal('complete', <?php echo $row['id']; ?>)" class="btn-sm btn-complete">Mark Complete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No withdrawal requests found</p></div>
        <?php endif; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Action Modal -->
<div id="actionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Process Withdrawal</h3>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="withdrawal_id" id="withdrawalId">
            <input type="hidden" name="action" id="actionType">
            <div class="form-group">
                <label>Admin Notes</label>
                <textarea name="admin_notes" rows="3" placeholder="Add notes about this withdrawal..."></textarea>
            </div>
            <button type="submit" id="actionButton" class="btn-sm btn-approve" style="padding: 10px 20px;">Confirm</button>
            <button type="button" onclick="closeModal()" class="btn-sm" style="background: #94a3b8; color: white; padding: 10px 20px; margin-left: 10px;">Cancel</button>
        </form>
    </div>
</div>

<script>
function openActionModal(action, id) {
    document.getElementById('withdrawalId').value = id;
    document.getElementById('actionType').value = action;
    const modalTitle = document.getElementById('modalTitle');
    const actionButton = document.getElementById('actionButton');
    
    if (action === 'approve') {
        modalTitle.innerText = 'Approve Withdrawal';
        actionButton.innerText = 'Approve';
        actionButton.className = 'btn-sm btn-approve';
        actionButton.style.padding = '10px 20px';
    } else if (action === 'reject') {
        modalTitle.innerText = 'Reject Withdrawal';
        actionButton.innerText = 'Reject';
        actionButton.className = 'btn-sm btn-reject';
        actionButton.style.padding = '10px 20px';
    } else if (action === 'complete') {
        modalTitle.innerText = 'Mark as Completed';
        actionButton.innerText = 'Complete';
        actionButton.className = 'btn-sm btn-complete';
        actionButton.style.padding = '10px 20px';
    }
    document.getElementById('actionModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('actionModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>