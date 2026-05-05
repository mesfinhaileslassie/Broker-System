<?php
// admin/users.php - Users Management with proper styling

$page_title = 'User Management';
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
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    /* Filters */
    .filters {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
        padding: 10px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        min-width: 160px;
        background: #f8fafc;
        transition: all 0.3s;
    }
    
    .filter-group input:focus, .filter-group select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .btn-filter, .btn-reset {
        padding: 10px 24px;
        border-radius: 12px;
        border: none;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .btn-filter {
        background: #667eea;
        color: white;
    }
    
    .btn-filter:hover {
        background: #5a67d8;
        transform: translateY(-2px);
    }
    
    .btn-reset {
        background: #94a3b8;
        color: white;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-reset:hover {
        background: #64748b;
        transform: translateY(-2px);
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
    
    .card-header span {
        font-size: 13px;
        color: #64748b;
        background: #f1f5f9;
        padding: 4px 12px;
        border-radius: 20px;
    }
    
    /* Table Styles */
    .table-wrapper {
        overflow-x: auto;
        border-radius: 12px;
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
        letter-spacing: 0.5px;
    }
    
    tr:hover {
        background: #f8fafc;
    }
    
    /* Badges */
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-info { background: #dbeafe; color: #2563eb; }
    .badge-primary { background: #e0e7ff; color: #4f46e5; }
    
    /* Buttons */
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        transition: all 0.3s;
        margin: 2px;
    }
    
    .btn-sm:hover {
        transform: translateY(-1px);
    }
    
    .btn-primary { background: #667eea; color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    
    /* Pagination */
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
        transition: all 0.3s;
    }
    
    .pagination a:hover {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    .pagination .active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    /* Modal */
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
        padding: 28px;
        width: 450px;
        max-width: 90%;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header h3 {
        font-size: 20px;
        font-weight: 600;
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 28px;
        color: #94a3b8;
        transition: color 0.3s;
    }
    
    .close-modal:hover {
        color: #ef4444;
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
    
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-family: inherit;
        resize: vertical;
    }
    
    .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
    }
    
    @media (max-width: 1024px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters { flex-direction: column; align-items: stretch; }
        .filter-group input, .filter-group select { min-width: auto; }
    }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: 1fr; }
        .card { padding: 16px; }
        th, td { padding: 10px 8px; font-size: 12px; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
    }
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['verified']); ?></div>
        <div class="stat-label">Verified</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['banned']); ?></div>
        <div class="stat-label">Banned</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['companies']); ?></div>
        <div class="stat-label">Companies</div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filters">
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="search" placeholder="Name, email, phone" value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="filter-group">
        <label>Role</label>
        <select name="role">
            <option value="">All Roles</option>
            <option value="user" <?php echo $role == 'user' ? 'selected' : ''; ?>>User</option>
            <option value="company" <?php echo $role == 'company' ? 'selected' : ''; ?>>Company</option>
            <option value="admin" <?php echo $role == 'admin' ? 'selected' : ''; ?>>Admin</option>
        </select>
    </div>
    <div class="filter-group">
        <button type="submit" class="btn-filter">Apply Filter</button>
    </div>
    <div class="filter-group">
        <a href="users.php" class="btn-reset">Reset</a>
    </div>
</form>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-users"></i> All Users</h2>
        <span><?php echo number_format($total); ?> users</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $users->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                    <td><span class="badge badge-info"><?php echo ucfirst($row['role']); ?></span></td>
                    <td><strong><?php echo formatMoney($row['balance']); ?></strong></td>
                    <td>
                        <?php if($row['is_verified']): ?>
                            <span class="badge badge-success">Verified</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Unverified</span>
                        <?php endif; ?>
                        <?php if($row['is_suspended']): ?>
                            <span class="badge badge-danger">Banned</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                    <td>
                        <button onclick="viewUser(<?php echo $row['id']; ?>)" class="btn-sm btn-primary">View</button>
                        <?php if (!$row['is_verified'] && $row['role'] != 'admin'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="verify_user" class="btn-sm btn-success">Verify</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($row['is_suspended']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="unban_user" class="btn-sm btn-warning">Unban</button>
                            </form>
                        <?php elseif ($row['role'] != 'admin'): ?>
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

<!-- Ban Modal -->
<div id="banModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Ban User</h3>
            <span class="close-modal" onclick="closeBanModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="user_id" id="banUserId">
            <div class="form-group">
                <label>Reason for banning</label>
                <textarea name="ban_reason" rows="3" required placeholder="Enter reason for banning this user..."></textarea>
            </div>
            <button type="submit" name="ban_user" class="btn-sm btn-danger" style="padding: 10px 20px;">Ban User</button>
            <button type="button" onclick="closeBanModal()" class="btn-sm" style="background: #94a3b8; color: white; padding: 10px 20px; margin-left: 10px;">Cancel</button>
        </form>
    </div>
</div>

<script>
function viewUser(id) { 
    window.location.href = 'users.php?view=' + id; 
}

function banUser(id) { 
    document.getElementById('banUserId').value = id; 
    document.getElementById('banModal').style.display = 'flex'; 
}

function closeBanModal() { 
    document.getElementById('banModal').style.display = 'none'; 
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