<?php
// admin/users.php

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_user'])) {
        $userId = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $message = "User verified successfully";
        } else {
            $error = "Failed to verify user";
        }
    }
    
    if (isset($_POST['suspend_user'])) {
        $userId = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET is_suspended = 1 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $message = "User suspended";
        } else {
            $error = "Failed to suspend user";
        }
    }
    
    if (isset($_POST['activate_user'])) {
        $userId = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET is_suspended = 0 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $message = "User activated";
        } else {
            $error = "Failed to activate user";
        }
    }
    
    if (isset($_POST['adjust_balance'])) {
        $userId = intval($_POST['user_id']);
        $amount = floatval($_POST['amount']);
        $operation = $_POST['operation'];
        
        if ($operation === 'add') {
            $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            $stmt->bind_param("dii", $amount, $userId, $amount);
        }
        
        if ($operation === 'add') {
            $stmt->bind_param("di", $amount, $userId);
        }
        
        if ($stmt->execute()) {
            $message = "Balance updated successfully";
            // Log the adjustment
            $adminId = $_SESSION['admin_id'];
            $desc = "Admin balance adjustment: " . ($operation === 'add' ? '+' : '-') . $amount;
            $stmt2 = $conn->prepare("INSERT INTO balance_adjustments (user_id, admin_id, amount, operation, description) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iidss", $userId, $adminId, $amount, $operation, $desc);
            $stmt2->execute();
        } else {
            $error = "Failed to update balance";
        }
    }
}

// Get search/filter parameters
$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$verification = $_GET['verification'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query
$where = [];
$params = [];
$types = "";

if ($search) {
    $where[] = "(full_name LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if ($role) {
    $where[] = "role = ?";
    $params[] = $role;
    $types .= "s";
}

if ($verification === 'verified') {
    $where[] = "is_verified = 1";
} elseif ($verification === 'unverified') {
    $where[] = "is_verified = 0";
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$countSql = "SELECT COUNT(*) as total FROM users $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalUsers = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalUsers / $limit);

// Get users
$sql = "SELECT * FROM users $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        
        .admin-wrapper { display: flex; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        
        .main-content { margin-left: 260px; flex: 1; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        
        .filters { background: white; border-radius: 12px; padding: 16px; margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 12px; color: #666; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        .btn-filter { padding: 8px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .btn-reset { padding: 8px 20px; background: #aaa; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; }
        
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; margin: 2px; }
        .btn-verify { background: #27ae60; color: white; }
        .btn-suspend { background: #e74c3c; color: white; }
        .btn-activate { background: #3498db; color: white; }
        .btn-balance { background: #f39c12; color: white; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 12px; padding: 24px; width: 400px; max-width: 90%; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 16px; }
        .modal-body { margin-bottom: 20px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 12px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; }
            .filters { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🏪 Brokerplace</h2>
                <p>Admin Dashboard</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'">📊 Dashboard</li>
                <li class="nav-item active" onclick="location.href='users.php'">👥 Users</li>
                <li class="nav-item" onclick="location.href='companies.php'">🏢 Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'">💰 Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'">⚖️ Disputes</li>
                <li class="nav-item" onclick="location.href='settings.php'">⚙️ Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Users Management</h1>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <!-- Filters -->
            <form method="GET" class="filters">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Name, phone, email" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label>Role</label>
                    <select name="role">
                        <option value="">All</option>
                        <option value="user" <?php echo $role === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="company" <?php echo $role === 'company' ? 'selected' : ''; ?>>Company</option>
                        <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Verification</label>
                    <select name="verification">
                        <option value="">All</option>
                        <option value="verified" <?php echo $verification === 'verified' ? 'selected' : ''; ?>>Verified</option>
                        <option value="unverified" <?php echo $verification === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn-filter">Filter</button>
                </div>
                <div class="filter-group">
                    <a href="users.php" class="btn-reset">Reset</a>
                </div>
            </form>
            
            <!-- Users Table -->
            <div class="section">
                <div class="section-title">All Users (<?php echo number_format($totalUsers); ?>)</div>
                <table>
                    <thead>
                        <tr><th>ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Role</th><th>Balance</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($users->num_rows > 0): ?>
                            <?php while($row = $users->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email'] ?? '-'); ?></td>
                                    <td><?php echo getUserRoleBadge($row['role']); ?></td>
                                    <td><?php echo formatMoney($row['balance']); ?></td>
                                    <td>
                                        <?php echo getVerificationBadge($row['is_verified']); ?>
                                        <?php if (isset($row['is_suspended']) && $row['is_suspended']): ?>
                                            <span class="badge badge-danger">Suspended</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo timeAgo($row['created_at']); ?></td>
                                    <td>
                                        <?php if (!$row['is_verified']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="verify_user" class="btn-sm btn-verify">Verify</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($row['is_suspended']) && $row['is_suspended']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="activate_user" class="btn-sm btn-activate">Activate</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="suspend_user" class="btn-sm btn-suspend" onclick="return confirm('Suspend this user?')">Suspend</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <button class="btn-sm btn-balance" onclick="openBalanceModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>', <?php echo $row['balance']; ?>)">Adjust Balance</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align: center;">No users found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&verification=<?php echo urlencode($verification); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Balance Adjustment Modal -->
    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Adjust Balance</div>
            <form method="POST" id="balanceForm">
                <input type="hidden" name="user_id" id="balanceUserId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>User</label>
                        <input type="text" id="userName" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label>Current Balance</label>
                        <input type="text" id="currentBalance" readonly style="background: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label>Operation</label>
                        <select name="operation" id="operation" required>
                            <option value="add">Add (+)</option>
                            <option value="subtract">Subtract (-)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (ETB)</label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="Enter amount">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-sm" onclick="closeBalanceModal()" style="background: #aaa;">Cancel</button>
                    <button type="submit" name="adjust_balance" class="btn-sm" style="background: #667eea; color: white;">Update Balance</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openBalanceModal(userId, userName, currentBalance) {
            document.getElementById('balanceUserId').value = userId;
            document.getElementById('userName').value = userName;
            document.getElementById('currentBalance').value = currentBalance.toFixed(2) + ' ETB';
            document.getElementById('balanceModal').style.display = 'flex';
        }
        
        function closeBalanceModal() {
            document.getElementById('balanceModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            let modal = document.getElementById('balanceModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>