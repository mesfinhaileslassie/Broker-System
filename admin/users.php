<?php
// admin/users.php - Users Management with Clean Table Design

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
        $conn->query("DELETE FROM users WHERE id = $userId AND role != 'admin'");
        $message = "User deleted successfully";
    }
}

$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
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
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        background: #f5f7fb;
        font-family: 'Inter', sans-serif;
    }
    
    /* Stats Grid */
    .stats-container {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
        margin-bottom: 1.5rem;
    }
    
    .stat-box {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }
    
    .stat-box:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    
    .stat-number {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e293b;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: #64748b;
        margin-top: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    /* Filters */
    .filters-container {
        background: white;
        border-radius: 0.75rem;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        border: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .filters-group {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .filter-input {
        padding: 0.5rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        background: white;
    }
    
    .filter-input:focus {
        outline: none;
        border-color: #4f46e5;
    }
    
    .filter-select {
        padding: 0.5rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        background: white;
        cursor: pointer;
    }
    
    .btn-apply {
        background: #4f46e5;
        color: white;
        border: none;
        padding: 0.5rem 1.25rem;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .btn-apply:hover {
        background: #4338ca;
    }
    
    .btn-reset {
        background: #64748b;
        color: white;
        border: none;
        padding: 0.5rem 1.25rem;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-reset:hover {
        background: #475569;
    }
    
    /* Table Card */
    .table-container {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    
    .table-header {
        padding: 1rem 1.25rem;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .table-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e293b;
    }
    
    .table-header span {
        font-size: 0.75rem;
        color: #64748b;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    
    th {
        text-align: left;
        padding: 0.875rem 1rem;
        background: #fafcff;
        font-weight: 600;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
    }
    
    td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    
    tr:hover {
        background: #f8fafc;
    }
    
    /* User Cell */
    .user-cell {
        display: flex;
        flex-direction: column;
    }
    
    .user-name {
        font-weight: 600;
        color: #1e293b;
    }
    
    .user-id {
        font-size: 0.7rem;
        color: #94a3b8;
        margin-top: 0.125rem;
    }
    
    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.625rem;
        border-radius: 1.5rem;
        font-size: 0.7rem;
        font-weight: 500;
    }
    
    .badge-active {
        background: #d1fae5;
        color: #065f46;
    }
    
    .badge-banned {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .badge-admin {
        background: #e0e7ff;
        color: #4338ca;
    }
    
    .badge-company {
        background: #dbeafe;
        color: #1e40af;
    }
    
    .badge-user {
        background: #f1f5f9;
        color: #475569;
    }
    
    /* Role Badge Special */
    .role-admin {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: white;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .action-btn {
        padding: 0.375rem 0.75rem;
        border-radius: 0.5rem;
        font-size: 0.7rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    .action-view {
        background: #3b82f6;
        color: white;
    }
    
    .action-view:hover {
        background: #2563eb;
    }
    
    .action-ban {
        background: #ef4444;
        color: white;
    }
    
    .action-ban:hover {
        background: #dc2626;
    }
    
    .action-unban {
        background: #10b981;
        color: white;
    }
    
    .action-unban:hover {
        background: #059669;
    }
    
    .action-delete {
        background: #dc2626;
        color: white;
    }
    
    .action-delete:hover {
        background: #b91c1c;
    }
    
    /* Pagination */
    .pagination {
        padding: 1rem 1.25rem;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .page-link {
        padding: 0.375rem 0.75rem;
        border-radius: 0.5rem;
        text-decoration: none;
        color: #475569;
        font-size: 0.8rem;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }
    
    .page-link:hover, .page-link.active {
        background: #4f46e5;
        color: white;
        border-color: #4f46e5;
    }
    
    /* Alert */
    .alert {
        padding: 0.75rem 1rem;
        border-radius: 0.75rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border-left: 3px solid #10b981;
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
        border-radius: 1rem;
        padding: 1.5rem;
        width: 420px;
        max-width: 90%;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    
    .modal-header h3 {
        font-size: 1.125rem;
        font-weight: 700;
    }
    
    .close-modal {
        cursor: pointer;
        font-size: 1.25rem;
        color: #94a3b8;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 0.375rem;
        font-weight: 500;
        font-size: 0.8rem;
    }
    
    .form-group textarea {
        width: 100%;
        padding: 0.625rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        resize: vertical;
    }
    
    /* View Modal */
    .view-modal {
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
    
    .view-modal-content {
        background: white;
        border-radius: 1rem;
        width: 520px;
        max-width: 90%;
        max-height: 85vh;
        overflow-y: auto;
    }
    
    .view-modal-header {
        padding: 1rem 1.25rem;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: white;
        border-radius: 1rem 1rem 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .view-modal-body {
        padding: 1.25rem;
    }
    
    .profile-row {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.25rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .profile-avatar {
        width: 64px;
        height: 64px;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        color: white;
    }
    
    .info-row {
        display: flex;
        padding: 0.625rem 0;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .info-label {
        width: 110px;
        font-size: 0.7rem;
        font-weight: 600;
        color: #64748b;
    }
    
    .info-value {
        flex: 1;
        font-size: 0.8rem;
        color: #1e293b;
        font-weight: 500;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #94a3b8;
    }
    
    @media (max-width: 768px) {
        .stats-container {
            grid-template-columns: repeat(2, 1fr);
        }
        .filters-container {
            flex-direction: column;
            align-items: stretch;
        }
        .filters-group {
            justify-content: center;
        }
        .action-buttons {
            flex-direction: column;
        }
        .action-btn {
            justify-content: center;
        }
        th, td {
            padding: 0.625rem;
        }
    }
</style>

<!-- Stats -->
<div class="stats-container">
    <div class="stat-box">
        <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label"><i class="fas fa-users"></i> Total Users</div>
    </div>
    <div class="stat-box">
        <div class="stat-number"><?php echo number_format($stats['active']); ?></div>
        <div class="stat-label"><i class="fas fa-check-circle"></i> Active</div>
    </div>
    <div class="stat-box">
        <div class="stat-number"><?php echo number_format($stats['banned']); ?></div>
        <div class="stat-label"><i class="fas fa-ban"></i> Banned</div>
    </div>
    <div class="stat-box">
        <div class="stat-number"><?php echo number_format($stats['companies']); ?></div>
        <div class="stat-label"><i class="fas fa-building"></i> Companies</div>
    </div>
</div>

<!-- Filters -->
<div class="filters-container">
    <form method="GET" class="filters-group">
        <input type="text" name="search" class="filter-input" placeholder="Search name, email..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="role" class="filter-select">
            <option value="">All Roles</option>
            <option value="user" <?php echo $role == 'user' ? 'selected' : ''; ?>>User</option>
            <option value="company" <?php echo $role == 'company' ? 'selected' : ''; ?>>Company</option>
            <option value="admin" <?php echo $role == 'admin' ? 'selected' : ''; ?>>Admin</option>
        </select>
        <select name="status" class="filter-select">
            <option value="">All Status</option>
            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="banned" <?php echo $status == 'banned' ? 'selected' : ''; ?>>Banned</option>
        </select>
        <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Apply</button>
    </form>
    <div>
        <a href="users.php" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
    </div>
</div>

<!-- Users Table -->
<div class="table-container">
    <div class="table-header">
        <h3><i class="fas fa-users"></i> All Users</h3>
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
                            <div class="user-cell">
                                <span class="user-name"><?php echo htmlspecialchars($row['full_name']); ?></span>
                                <span class="user-id">ID: #<?php echo $row['id']; ?></span>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($row['email']); ?></td>
                        <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                        <td>
                            <?php if($row['role'] == 'admin'): ?>
                                <span class="badge role-admin"><i class="fas fa-shield-alt"></i> Admin</span>
                            <?php elseif($row['role'] == 'company'): ?>
                                <span class="badge badge-company"><i class="fas fa-building"></i> Company</span>
                            <?php else: ?>
                                <span class="badge badge-user"><i class="fas fa-user"></i> User</span>
                            <?php endif; ?>
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
                            <div class="action-buttons">
                                <button onclick="viewUser(<?php echo $row['id']; ?>)" class="action-btn action-view" title="View Details">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                
                                <?php if ($row['role'] != 'admin'): ?>
                                    <?php if ($row['is_suspended']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="unban_user" class="action-btn action-unban" onclick="return confirm('Unban this user?')" title="Unban">
                                                <i class="fas fa-check-circle"></i> Unban
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button onclick="banUser(<?php echo $row['id']; ?>)" class="action-btn action-ban" title="Ban">
                                            <i class="fas fa-ban"></i> Ban
                                        </button>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user? This action cannot be undone.')">
                                        <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="delete_user" class="action-btn action-delete" title="Delete">
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
                <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; display: block;"></i>
                <p>No users found</p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" 
               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- View User Modal -->
<div id="viewUserModal" class="view-modal">
    <div class="view-modal-content">
        <div class="view-modal-header">
            <h3><i class="fas fa-user-circle"></i> User Profile</h3>
            <span class="close-modal" onclick="closeViewModal()">&times;</span>
        </div>
        <div class="view-modal-body" id="viewUserContent">
            <div style="text-align: center; padding: 2rem;">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>
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
                <textarea name="ban_reason" rows="3" required placeholder="Enter reason for banning this user..."></textarea>
            </div>
            <button type="submit" name="ban_user" class="action-btn action-ban" style="width: 100%; padding: 0.625rem;">
                <i class="fas fa-ban"></i> Ban User
            </button>
        </form>
    </div>
</div>

<script>
function viewUser(userId) {
    const modal = document.getElementById('viewUserModal');
    const content = document.getElementById('viewUserContent');
    
    modal.style.display = 'flex';
    content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    fetch(`ajax/get_user_details.php?id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const user = data.user;
                const isBanned = user.is_suspended == 1;
                
                content.innerHTML = `
                    <div class="profile-row">
                        <div class="profile-avatar">${user.full_name.charAt(0).toUpperCase()}</div>
                        <div>
                            <h3 style="margin-bottom: 0.25rem;">${escapeHtml(user.full_name)}</h3>
                            <p style="font-size: 0.7rem; color: #64748b;"><i class="fas fa-calendar-alt"></i> Member since ${new Date(user.created_at).toLocaleDateString()}</p>
                            <span class="badge ${isBanned ? 'badge-banned' : 'badge-active'}">
                                ${isBanned ? '<i class="fas fa-ban"></i> Banned' : '<i class="fas fa-check-circle"></i> Active'}
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                        <div class="info-value">${escapeHtml(user.email)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                        <div class="info-value">${user.phone || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-tag"></i> Role</div>
                        <div class="info-value">${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                        <div class="info-value">${user.address || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-city"></i> City</div>
                        <div class="info-value">${user.city || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                    </div>
                    ${user.bio ? `
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-align-left"></i> Bio</div>
                        <div class="info-value">${escapeHtml(user.bio)}</div>
                    </div>
                    ` : ''}
                    ${user.ban_reason ? `
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-exclamation-triangle"></i> Ban Reason</div>
                        <div class="info-value" style="color: #dc2626;">${escapeHtml(user.ban_reason)}</div>
                    </div>
                    ` : ''}
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-wallet"></i> Balance</div>
                        <div class="info-value">${formatMoney(user.balance)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-box"></i> Listings</div>
                        <div class="info-value">${user.total_listings || 0}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-exchange-alt"></i> Transactions</div>
                        <div class="info-value">${user.total_transactions || 0}</div>
                    </div>
                    
                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                        ${!isBanned && user.role != 'admin' ? `
                            <button onclick="banUser(${user.id})" class="action-btn action-ban">
                                <i class="fas fa-ban"></i> Ban User
                            </button>
                        ` : isBanned ? `
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="user_id" value="${user.id}">
                                <button type="submit" name="unban_user" class="action-btn action-unban">
                                    <i class="fas fa-check-circle"></i> Unban User
                                </button>
                            </form>
                        ` : ''}
                        ${user.role != 'admin' ? `
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user?')">
                                <input type="hidden" name="user_id" value="${user.id}">
                                <button type="submit" name="delete_user" class="action-btn action-delete">
                                    <i class="fas fa-trash"></i> Delete User
                                </button>
                            </form>
                        ` : ''}
                        <a href="chat.php?user=${user.id}" class="action-btn action-view">
                            <i class="fas fa-comment"></i> Send Message
                        </a>
                        <button onclick="closeViewModal()" class="action-btn" style="background: #64748b; color: white;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                `;
            } else {
                content.innerHTML = `<div style="text-align: center; padding: 2rem; color: #dc2626;">Failed to load user details</div>`;
            }
        })
        .catch(error => {
            content.innerHTML = `<div style="text-align: center; padding: 2rem; color: #dc2626;">Error loading user details</div>`;
        });
}

function closeViewModal() {
    document.getElementById('viewUserModal').style.display = 'none';
}

function banUser(id) { 
    document.getElementById('banUserId').value = id; 
    document.getElementById('banModal').style.display = 'flex'; 
    closeViewModal();
}

function closeBanModal() { 
    document.getElementById('banModal').style.display = 'none'; 
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(amount) + ' ETB';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.onclick = function(event) { 
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none'; 
    }
    if (event.target.classList.contains('view-modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>