<?php
// admin/withdrawals.php - Withdrawal Requests

$page_title = 'Withdrawal Requests';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $withdrawalId = intval($_POST['withdrawal_id'] ?? 0);
    $adminId = $_SESSION['admin_id'] ?? 1;
    $adminNotes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
    
    if (isset($_POST['approve'])) {
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'approved', admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("sii", $adminNotes, $adminId, $withdrawalId);
        $stmt->execute();
        $message = "Withdrawal approved";
    }
    
    if (isset($_POST['reject'])) {
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'rejected', admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("sii", $adminNotes, $adminId, $withdrawalId);
        $stmt->execute();
        
        $withdrawal = $conn->query("SELECT user_id, amount FROM withdrawal_requests WHERE id = $withdrawalId")->fetch_assoc();
        $conn->query("UPDATE users SET balance = balance + {$withdrawal['amount']} WHERE id = {$withdrawal['user_id']}");
        $message = "Withdrawal rejected and amount refunded";
    }
    
    if (isset($_POST['complete'])) {
        $conn->query("UPDATE withdrawal_requests SET status = 'completed', processed_by = $adminId, processed_at = NOW() WHERE id = $withdrawalId");
        $message = "Withdrawal marked as completed";
    }
}

$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = $status ? "WHERE w.status = '$status'" : "";
$sql = "SELECT w.*, u.full_name, u.email, u.phone, u.balance
        FROM withdrawal_requests w
        JOIN users u ON w.user_id = u.id
        $where
        ORDER BY FIELD(w.status, 'pending', 'approved', 'completed', 'rejected'), w.created_at DESC
        LIMIT $offset, $limit";
$withdrawals = $conn->query($sql);

$total = $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests w $where")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$stats = [
    'pending' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc(),
    'approved' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'approved'")->fetch_assoc(),
    'completed' => $conn->query("SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawal_requests WHERE status = 'completed'")->fetch_assoc(),
    'total_processed' => $conn->query("SELECT SUM(amount) as total FROM withdrawal_requests WHERE status IN ('approved', 'completed')")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; }
    .filters { margin-bottom: 20px; }
    .filter-select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
    .pagination .active { background: #667eea; color: white; }
    .btn-approve { background: #10b981; color: white; }
    .btn-reject { background: #ef4444; color: white; }
    .btn-complete { background: #667eea; color: white; }
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
    .modal-content { background: white; border-radius: 20px; padding: 24px; width: 400px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
    .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['pending']['total'] ?? 0); ?></div><div class="stat-label">Pending Amount</div><small><?php echo $stats['pending']['count'] ?? 0; ?> requests</small></div>
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['approved']['total'] ?? 0); ?></div><div class="stat-label">Approved Amount</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['completed']['total'] ?? 0); ?></div><div class="stat-label">Completed Amount</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['total_processed']); ?></div><div class="stat-label">Total Processed</div></div>
</div>

<div class="filters">
    <select class="filter-select" onchange="location.href='?status='+this.value">
        <option value="">All Status</option>
        <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
        <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
        <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
        <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
    </select>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-money-bill-wave"></i> Withdrawal Requests</h2></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>ID</th><th>User</th><th>Amount</th><th>Bank Details</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while($row = $withdrawals->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br><small><?php echo htmlspecialchars($row['email']); ?></small></td>
                    <td><strong><?php echo formatMoney($row['amount']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['bank_name'] ?? 'N/A'); ?><br><small><?php echo htmlspecialchars($row['account_number'] ?? 'N/A'); ?></small></td>
                    <td><?php $badge = $row['status'] == 'pending' ? 'badge-warning' : ($row['status'] == 'approved' ? 'badge-info' : ($row['status'] == 'completed' ? 'badge-success' : 'badge-danger')); echo '<span class="badge ' . $badge . '">' . ucfirst($row['status']) . '</span>'; ?></td>
                    <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                    <td>
                        <?php if ($row['status'] == 'pending'): ?>
                        <button onclick="openActionModal('approve', <?php echo $row['id']; ?>)" class="btn-sm btn-approve">Approve</button>
                        <button onclick="openActionModal('reject', <?php echo $row['id']; ?>)" class="btn-sm btn-reject">Reject</button>
                        <?php elseif ($row['status'] == 'approved'): ?>
                        <button onclick="completeWithdrawal(<?php echo $row['id']; ?>)" class="btn-sm btn-complete">Complete</button>
                        <?php endif; ?>
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

<div id="actionModal" class="modal">
    <div class="modal-content">
        <div class="card-header"><h2 id="modalTitle">Process Withdrawal</h2><span onclick="closeModal()" style="cursor:pointer;">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="withdrawal_id" id="withdrawalId">
            <div class="form-group"><label>Admin Notes</label><textarea name="admin_notes" rows="3" placeholder="Add notes..."></textarea></div>
            <button type="submit" id="actionButton" class="btn-sm">Confirm</button>
            <button type="button" onclick="closeModal()" class="btn-sm">Cancel</button>
        </form>
    </div>
</div>

<script>
let currentAction = '';
function openActionModal(action, id) {
    currentAction = action;
    document.getElementById('withdrawalId').value = id;
    const modalTitle = document.getElementById('modalTitle');
    const actionButton = document.getElementById('actionButton');
    if (action === 'approve') {
        modalTitle.innerText = 'Approve Withdrawal';
        actionButton.innerText = 'Approve';
        actionButton.name = 'approve';
        actionButton.className = 'btn-sm btn-approve';
    } else {
        modalTitle.innerText = 'Reject Withdrawal';
        actionButton.innerText = 'Reject';
        actionButton.name = 'reject';
        actionButton.className = 'btn-sm btn-reject';
    }
    document.getElementById('actionModal').style.display = 'flex';
}
function completeWithdrawal(id) {
    if (confirm('Mark this withdrawal as completed?')) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="withdrawal_id" value="' + id + '"><input type="hidden" name="complete" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}
function closeModal() { document.getElementById('actionModal').style.display = 'none'; }
window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = 'none'; }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>