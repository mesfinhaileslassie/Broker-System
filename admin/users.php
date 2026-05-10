<?php
// admin/users.php - Users Management with Unban Button

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
    
    // Ban user
    if (isset($_POST['ban_user'])) {
        $reason = $conn->real_escape_string($_POST['ban_reason']);
        $conn->query("UPDATE users SET is_suspended = 1, ban_reason = '$reason', banned_at = NOW() WHERE id = $userId");
        $message = "User banned successfully";
    }
    
    // Unban user
    if (isset($_POST['unban_user'])) {
        $conn->query("UPDATE users SET is_suspended = 0, ban_reason = NULL, banned_at = NULL WHERE id = $userId");
        $message = "User unbanned successfully";
    }
    
    // Delete user
    if (isset($_POST['delete_user'])) {
        $conn->query("DELETE FROM users WHERE id = $userId AND role = 'user'");
        $message = "User deleted successfully";
    }
}

$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
if ($search) $where[] = "(full_name LIKE '%$search%' OR email LIKE '%$search%' OR phone LIKE '%$search%')";
if ($role) $where[] = "role = '$role'";
if ($status === 'active') $where[] = "is_suspended = 0";
if ($status === 'banned') $where[] = "is_suspended = 1";

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$users = $conn->query("SELECT * FROM users $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$total = $conn->query("SELECT COUNT(*) as count FROM users $whereClause")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

// Statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_suspended = 0")->fetch_assoc()['count'],
    'banned' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_suspended = 1")->fetch_assoc()['count'],
    'companies' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'company'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    :root {
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --primary-light: #eef2ff;
        --success: #10b981;
        --success-light: #d1fae5;
        --warning: #f59e0b;
        --warning-light: #fef3c7;
        --danger: #ef4444;
        --danger-light: #fee2e2;
        --info: #3b82f6;
        --info-light: #dbeafe;
        --dark: #1e293b;
        --gray: #64748b;
        --gray-light: #f8fafc;
        --border: #e2e8f0;
        --card-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.03);
        --card-shadow-hover: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.02);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .stat-card {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        transition: all 0.3s ease;
        border: 1px solid var(--border);
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(135deg, var(--primary), #7c3aed);
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--card-shadow-hover);
    }
    
    .stat-value {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--dark);
    }
    
    .stat-label {
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--gray);
        margin-top: 0.25rem;
    }
    
    /* Filters Bar */
    .filters-bar {
        background: white;
        border-radius: 1rem;
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
        border: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .filter-group {
        display: flex;
        gap: 0.75rem;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .filter-group input, .filter-group select {
        padding: 0.5rem 1rem;
        border: 1px solid var(--border);
        border-radius: 2rem;
        font-size: 0.8rem;
        background: white;
    }
    
    .filter-group input:focus, .filter-group select:focus {
        outline: none;
        border-color: var(--primary);
    }
    
    .btn-filter {
        padding: 0.5rem 1.25rem;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 2rem;
        cursor: pointer;
        font-weight: 500;
        font-size: 0.8rem;
    }
    
    .btn-reset {
        padding: 0.5rem 1.25rem;
        background: var(--gray);
        color: white;
        border: none;
        border-radius: 2rem;
        text-decoration: none;
        font-size: 0.8rem;
    }
    
    /* User Table */
    .table-card {
        background: white;
        border-radius: 1rem;
        overflow: hidden;
        border: 1px solid var(--border);
    }
    
    .table-header {
        padding: 1rem 1.5rem;
        background: var(--gray-light);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .table-header h2 {
        font-size: 1rem;
        font-weight: 700;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 1rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border);
        font-size: 0.8rem;
    }
    
    th {
        font-weight: 600;
        color: var(--gray);
        background: var(--gray-light);
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    tr:hover {
        background: var(--gray-light);
    }
    
    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.75rem;
        border-radius: 2rem;
        font-size: 0.7rem;
        font-weight: 600;
    }
    
    .badge-active { background: var(--success-light); color: #065f46; }
    .badge-banned { background: var(--danger-light); color: #991b1b; }
    .badge-company { background: var(--info-light); color: #1e40af; }
    .badge-user { background: #f1f5f9; color: #475569; }
    .badge-admin { background: linear-gradient(135deg, var(--primary), #7c3aed); color: white; }
    
    /* Buttons */
    .btn-sm {
        padding: 0.35rem 0.85rem;
        font-size: 0.7rem;
        border-radius: 2rem;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-weight: 500;
    }
    
    .btn-view { background: var(--info); color: white; }
    .btn-ban { background: var(--danger); color: white; }
    .btn-unban { background: var(--success); color: white; }
    .btn-delete { background: #dc2626; color: white; }
    .btn-edit { background: var(--warning); color: white; }
    
    .btn-sm:hover {
        transform: translateY(-1px);
        filter: brightness(0.95);
    }
    
    /* Action Group */
    .action-group {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    /* Pagination */
    .pagination {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .pagination a, .pagination span {
        padding: 0.4rem 0.8rem;
        border-radius: 0.5rem;
        text-decoration: none;
        color: var(--dark);
        font-size: 0.8rem;
        border: 1px solid var(--border);
    }
    
    .pagination a:hover, .pagination .active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
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
        backdrop-filter: blur(4px);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-content {
        background: white;
        border-radius: 1.5rem;
        padding: 1.75rem;
        width: 450px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.95); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
    }
    
    .modal-header h3 {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--dark);
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 1.5rem;
        color: var(--gray);
    }
    
    .close-modal:hover {
        color: var(--danger);
    }
    
    .form-group {
        margin-bottom: 1.25rem;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        font-size: 0.8rem;
    }
    
    .form-group textarea {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border);
        border-radius: 0.75rem;
        resize: vertical;
    }
    
    .alert {
        padding: 0.75rem 1rem;
        border-radius: 0.75rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .alert-success {
        background: var(--success-light);
        color: #065f46;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .filters-bar {
            flex-direction: column;
            align-items: stretch;
        }
        .filter-group {
            justify-content: center;
        }
        .action-group {
            flex-direction: column;
            align-items: center;
        }
        .btn-sm {
            width: 100%;
            justify-content: center;
        }
        th, td {
            padding: 0.75rem;
        }
    }
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
        <div class="stat-label">Active</div>
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

<!-- Filters Bar -->
<div class="filters-bar">
    <form method="GET" class="filter-group">
        <input type="text" name="search" placeholder="Search by name, email..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="role">
            <option value="">All Roles</option>
            <option value="user" <?php echo $role == 'user' ? 'selected' : ''; ?>>User</option>
            <option value="company" <?php echo $role == 'company' ? 'selected' : ''; ?>>Company</option>
            <option value="admin" <?php echo $role == 'admin' ? 'selected' : ''; ?>>Admin</option>
        </select>
        <select name="status">
            <option value="">All Status</option>
            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="banned" <?php echo $status == 'banned' ? 'selected' : ''; ?>>Banned</option>
        </select>
        <button type="submit" class="btn-filter">Apply Filter</button>
    </form>
    <div class="filter-group">
        <a href="users.php" class="btn-reset">Reset All</a>
    </div>
</div>

<!-- Users Table -->
<div class="table-card">
    <div class="table-header">
        <h2><i class="fas fa-users"></i> All Users</h2>
        <span><?php echo number_format($total); ?> users found</span>
    </div>
    <div class="table-wrapper">
        <?php if ($users && $users->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
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
                        <td>
                            <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                            <small>ID: #<?php echo $row['id']; ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($row['email']); ?></td>
                        <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                        <td>
                            <span class="badge <?php 
                                echo $row['role'] == 'admin' ? 'badge-admin' : ($row['role'] == 'company' ? 'badge-company' : 'badge-user'); 
                            ?>">
                                <?php echo ucfirst($row['role']); ?>
                            </span>
                        </td>
                        <td><strong><?php echo formatMoney($row['balance']); ?></strong></td>
                        <td>
                            <?php if($row['is_suspended']): ?>
                                <span class="badge badge-banned"><i class="fas fa-ban"></i> Banned</span>
                            <?php else: ?>
                                <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                        <td>
                            <div class="action-group">
                                <a href="users.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-view" title="View Details">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                
                                <?php if ($row['role'] != 'admin'): ?>
                                    <?php if ($row['is_suspended']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="unban_user" class="btn-sm btn-unban" onclick="return confirm('Unban this user?')" title="Unban User">
                                                <i class="fas fa-check-circle"></i> Unban
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button onclick="banUser(<?php echo $row['id']; ?>)" class="btn-sm btn-ban" title="Ban User">
                                            <i class="fas fa-ban"></i> Ban
                                        </button>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user? This action cannot be undone.')">
                                        <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="delete_user" class="btn-sm btn-delete" title="Delete User">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; display: block;"></i>
                <p style="color: var(--gray);">No users found</p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" 
               class="<?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Ban Modal -->
<div id="banModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-ban"></i> Ban User</h3>
            <span class="close-modal" onclick="closeBanModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="user_id" id="banUserId">
            <div class="form-group">
                <label>Reason for Banning</label>
                <textarea name="ban_reason" rows="4" required placeholder="Enter reason for banning this user..."></textarea>
            </div>
            <button type="submit" name="ban_user" class="btn-sm btn-ban" style="width: 100%; padding: 0.75rem;">
                <i class="fas fa-ban"></i> Ban User
            </button>
        </form>
    </div>
</div>

<script>
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