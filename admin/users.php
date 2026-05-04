<?php
// admin/users.php - Modern User Management

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

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
        $message = "User banned";
    }
    
    if (isset($_POST['unban_user'])) {
        $conn->query("UPDATE users SET is_suspended = 0, ban_reason = NULL, banned_at = NULL WHERE id = $userId");
        $message = "User unbanned";
    }
}

$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
if ($search) $where[] = "(full_name LIKE '%$search%' OR email LIKE '%$search%')";
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100%; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: white; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 20px; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #cbd5e1; cursor: pointer; transition: all 0.3s; margin: 4px 12px; border-radius: 12px; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 22px; }
        
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .page-title { font-size: 28px; font-weight: 700; color: #0f172a; }
        .logout-btn { padding: 8px 20px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-radius: 30px; text-decoration: none; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 28px; font-weight: 700; }
        .stat-label { color: #64748b; font-size: 13px; margin-top: 6px; }
        
        .filters { background: white; border-radius: 16px; padding: 16px 20px; margin-bottom: 24px; display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 12px; color: #64748b; font-weight: 500; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; min-width: 160px; }
        .btn-filter, .btn-reset { padding: 8px 20px; border-radius: 10px; border: none; cursor: pointer; }
        .btn-filter { background: #667eea; color: white; }
        .btn-reset { background: #94a3b8; color: white; text-decoration: none; display: inline-block; }
        
        .card { background: white; border-radius: 20px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { font-weight: 600; color: #64748b; font-size: 13px; }
        tr:hover { background: #f8fafc; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fed7aa; color: #ea580c; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-info { background: #dbeafe; color: #2563eb; }
        
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; margin: 2px; }
        .btn-verify { background: #10b981; color: white; }
        .btn-ban { background: #ef4444; color: white; }
        .btn-unban { background: #f59e0b; color: white; }
        .btn-view { background: #667eea; color: white; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 20px; padding: 24px; width: 400px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 16px; display: flex; justify-content: space-between; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
        
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; border-color: #667eea; }
        
        @media (max-width: 1024px) { .sidebar { width: 80px; } .sidebar-header h2, .nav-item span { display: none; } .nav-item { justify-content: center; } .main-content { margin-left: 80px; } .stats-grid { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .filters { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header"><h2>🏪 Brokerplace</h2></div>
        <ul class="nav-menu">
            <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></li>
            <li class="nav-item active"><i class="fas fa-users"></i><span>Users</span></li>
            <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i><span>Transactions</span></li>
            <li class="nav-item" onclick="location.href='approve_listings.php'"><i class="fas fa-check-double"></i><span>Approve</span></li>
            <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i><span>Settings</span></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <h1 class="page-title">User Management</h1>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['total']); ?></div><div class="stat-label">Total Users</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['verified']); ?></div><div class="stat-label">Verified</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['banned']); ?></div><div class="stat-label">Banned</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['companies']); ?></div><div class="stat-label">Companies</div></div>
        </div>

        <form method="GET" class="filters">
            <div class="filter-group"><label>Search</label><input type="text" name="search" placeholder="Name, email" value="<?php echo htmlspecialchars($search); ?>"></div>
            <div class="filter-group"><label>Role</label><select name="role"><option value="">All</option><option value="user">User</option><option value="company">Company</option></select></div>
            <div class="filter-group"><button type="submit" class="btn-filter">Filter</button></div>
            <div class="filter-group"><a href="users.php" class="btn-reset">Reset</a></div>
        </form>

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-users"></i> All Users</h3><span><?php echo number_format($total); ?> users</span></div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>ID</th><th>User</th><th>Email</th><th>Role</th><th>Balance</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php while($row = $users->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo $row['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><span class="badge badge-info"><?php echo ucfirst($row['role']); ?></span></td>
                            <td><?php echo formatMoney($row['balance']); ?></td>
                            <td><?php echo $row['is_verified'] ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-warning">Unverified</span>'; ?> <?php echo $row['is_suspended'] ? '<span class="badge badge-danger">Banned</span>' : ''; ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <button onclick="viewUser(<?php echo $row['id']; ?>)" class="btn-sm btn-view">View</button>
                                <?php if (!$row['is_verified']): ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="user_id" value="<?php echo $row['id']; ?>"><button type="submit" name="verify_user" class="btn-sm btn-verify">Verify</button></form>
                                <?php endif; ?>
                                <?php if ($row['is_suspended']): ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="user_id" value="<?php echo $row['id']; ?>"><button type="submit" name="unban_user" class="btn-sm btn-unban">Unban</button></form>
                                <?php else: ?>
                                <button onclick="banUser(<?php echo $row['id']; ?>)" class="btn-sm btn-ban">Ban</button>
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
    </div>

    <!-- Ban Modal -->
    <div id="banModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><span>Ban User</span><span onclick="closeBanModal()" style="cursor:pointer;">&times;</span></div>
            <form method="POST">
                <input type="hidden" name="user_id" id="banUserId">
                <div class="form-group"><label>Reason</label><textarea name="ban_reason" rows="3" required></textarea></div>
                <button type="submit" name="ban_user" class="btn-sm btn-ban">Ban User</button>
                <button type="button" onclick="closeBanModal()" class="btn-sm" style="background:#94a3b8;">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function viewUser(id) { location.href = 'users.php?view=' + id; }
        function banUser(id) { document.getElementById('banUserId').value = id; document.getElementById('banModal').style.display = 'flex'; }
        function closeBanModal() { document.getElementById('banModal').style.display = 'none'; }
        window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = 'none'; }
    </script>
</body>
</html>