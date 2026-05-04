<?php
// admin/users.php - Users Management

$page_title = 'Users Management';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = intval($_POST['user_id'] ?? 0);
    
    if (isset($_POST['verify_user'])) {
        $conn->query("UPDATE users SET is_verified = 1 WHERE id = $userId");
        $message = "User verified successfully";
    }
    
    if (isset($_POST['ban_user'])) {
        $reason = $conn->real_escape_string($_POST['ban_reason']);
        $conn->query("UPDATE users SET is_suspended = 1, ban_reason = '$reason', banned_at = NOW() WHERE id = $userId");
        $message = "User banned successfully";
    }
    
    if (isset($_POST['unban_user'])) {
        $conn->query("UPDATE users SET is_suspended = 0, ban_reason = NULL, banned_at = NULL WHERE id = $userId");
        $message = "User unbanned successfully";
    }
}

$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
if ($search) $where[] = "(full_name LIKE '%$search%' OR email LIKE '%$search%' OR phone LIKE '%$search%')";
if ($role) $where[] = "role = '$role'";
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$users = $conn->query("SELECT * FROM users $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$total = $conn->query("SELECT COUNT(*) as count FROM users $whereClause")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'verified' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_verified = 1")->fetch_assoc()['count'],
    'banned' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_suspended = 1")->fetch_assoc()['count'],
    'companies' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'company'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
    .filters {
        background: white;
        border-radius: 16px;
        padding: 16px 20px;
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .filter-group label {
        font-size: 12px;
        color: #64748b;
        font-weight: 500;
    }
    .filter-group input, .filter-group select {
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 14px;
        min-width: 160px;
    }
    .btn-filter, .btn-reset {
        padding: 8px 20px;
        border-radius: 10px;
        border: none;
        cursor: pointer;
    }
    .btn-filter { background: #667eea; color: white; }
    .btn-reset { background: #94a3b8; color: white; text-decoration: none; display: inline-block; }
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
    }
    .pagination a, .pagination span {
        padding: 8px 12px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        text-decoration: none;
        color: #333;
    }
    .pagination .active { background: #667eea; color: white; border-color: #667eea; }
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .modal-content {
        background: white;
        border-radius: 20px;
        padding: 24px;
        width: 400px;
    }
    .form-group {
        margin-bottom: 16px;
    }
    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 500;
    }
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
    }
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters { flex-direction: column; }
    }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['total']); ?></div><div class="stat-label">Total Users</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['verified']); ?></div><div class="stat-label">Verified</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['banned']); ?></div><div class="stat-label">Banned</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['companies']); ?></div><div class="stat-label">Companies</div></div>
</div>

<form method="GET" class="filters">
    <div class="filter-group"><label>Search</label><input type="text" name="search" placeholder="Name, email, phone" value="<?php echo htmlspecialchars($search); ?>"></div>
    <div class="filter-group"><label>Role</label><select name="role"><option value="">All</option><option value="user" <?php echo $role == 'user' ? 'selected' : ''; ?>>User</option><option value="company" <?php echo $role == 'company' ? 'selected' : ''; ?>>Company</option><option value="admin" <?php echo $role == 'admin' ? 'selected' : ''; ?>>Admin</option></select></div>
    <div class="filter-group"><button type="submit" class="btn-filter">Filter</button></div>
    <div class="filter-group"><a href="users.php" class="btn-reset">Reset</a></div>
</form>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-users"></i> All Users</h2><span><?php echo number_format($total); ?> users</span></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>ID</th><th>User</th><th>Email</th><th>Phone</th><th>Role</th><th>Balance</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while($row = $users->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                    <td><span class="badge badge-info"><?php echo ucfirst($row['role']); ?></span></td>
                    <td><?php echo formatMoney($row['balance']); ?></td>
                    <td><?php echo $row['is_verified'] ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-warning">Unverified</span>'; ?> <?php echo $row['is_suspended'] ? '<span class="badge badge-danger">Banned</span>' : ''; ?></td>
                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                    <td>
                        <button onclick="viewUser(<?php echo $row['id']; ?>)" class="btn-sm btn-primary">View</button>
                        <?php if (!$row['is_verified']): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="user_id" value="<?php echo $row['id']; ?>"><button type="submit" name="verify_user" class="btn-sm btn-success">Verify</button></form>
                        <?php endif; ?>
                        <?php if ($row['is_suspended']): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="user_id" value="<?php echo $row['id']; ?>"><button type="submit" name="unban_user" class="btn-sm btn-success">Unban</button></form>
                        <?php else: ?>
                        <button onclick="banUser(<?php echo $row['id']; ?>)" class="btn-sm btn-danger">Ban</button>
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
        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<div id="banModal" class="modal">
    <div class="modal-content">
        <div class="card-header"><h2>Ban User</h2><span onclick="closeBanModal()" style="cursor:pointer;">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="user_id" id="banUserId">
            <div class="form-group"><label>Reason</label><textarea name="ban_reason" rows="3" required placeholder="Enter reason for banning..."></textarea></div>
            <button type="submit" name="ban_user" class="btn-sm btn-danger">Ban User</button>
            <button type="button" onclick="closeBanModal()" class="btn-sm">Cancel</button>
        </form>
    </div>
</div>

<script>
function viewUser(id) { window.location.href = 'users.php?view=' + id; }
function banUser(id) { document.getElementById('banUserId').value = id; document.getElementById('banModal').style.display = 'flex'; }
function closeBanModal() { document.getElementById('banModal').style.display = 'none'; }
window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = 'none'; }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>