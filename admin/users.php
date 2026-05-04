<?php
// admin/users.php - Complete user management with view profile, ban, edit, etc.

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Handle different actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_id = $_SESSION['admin_id'];
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // Verify User
    if (isset($_POST['verify_user'])) {
        $userId = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $message = "User verified successfully";
            logAdminAction($conn, $admin_id, 'verify_user', 'user', $userId, "Verified user ID: $userId", $ip);
        } else {
            $error = "Failed to verify user";
        }
    }
    
    // Ban User
    if (isset($_POST['ban_user'])) {
        $userId = intval($_POST['user_id']);
        $banReason = trim($_POST['ban_reason']);
        $stmt = $conn->prepare("UPDATE users SET is_suspended = 1, ban_reason = ?, banned_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $banReason, $userId);
        if ($stmt->execute()) {
            $message = "User has been banned";
            logAdminAction($conn, $admin_id, 'ban_user', 'user', $userId, "Banned user ID: $userId. Reason: $banReason", $ip);
        } else {
            $error = "Failed to ban user";
        }
    }
    
    // Unban User
    if (isset($_POST['unban_user'])) {
        $userId = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET is_suspended = 0, ban_reason = NULL, banned_at = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $message = "User has been unbanned";
            logAdminAction($conn, $admin_id, 'unban_user', 'user', $userId, "Unbanned user ID: $userId", $ip);
        } else {
            $error = "Failed to unban user";
        }
    }
    
    // Adjust Balance
    if (isset($_POST['adjust_balance'])) {
        $userId = intval($_POST['user_id']);
        $amount = floatval($_POST['amount']);
        $operation = $_POST['operation'];
        $reason = trim($_POST['reason']);
        
        if ($operation === 'add') {
            $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->bind_param("di", $amount, $userId);
        } else {
            $stmt = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            $stmt->bind_param("dii", $amount, $userId, $amount);
        }
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Balance updated successfully";
            logAdminAction($conn, $admin_id, 'adjust_balance', 'user', $userId, "$operation $amount ETB. Reason: $reason", $ip);
            
            // Record adjustment
            $stmt2 = $conn->prepare("INSERT INTO balance_adjustments (user_id, admin_id, amount, operation, description) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iidss", $userId, $admin_id, $amount, $operation, $reason);
            $stmt2->execute();
        } else {
            $error = "Failed to update balance. Insufficient funds?";
        }
    }
    
    // Delete User
    if (isset($_POST['delete_user'])) {
        $userId = intval($_POST['user_id']);
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $message = "User deleted successfully";
            logAdminAction($conn, $admin_id, 'delete_user', 'user', $userId, "Deleted user ID: $userId", $ip);
        } else {
            $error = "Failed to delete user";
        }
    }
    
    // Update User Profile
    if (isset($_POST['update_user'])) {
        $userId = intval($_POST['user_id']);
        $full_name = $conn->real_escape_string($_POST['full_name']);
        $phone = $conn->real_escape_string($_POST['phone']);
        $address = $conn->real_escape_string($_POST['address']);
        $city = $conn->real_escape_string($_POST['city']);
        
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, address = ?, city = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $full_name, $phone, $address, $city, $userId);
        if ($stmt->execute()) {
            $message = "User profile updated successfully";
            logAdminAction($conn, $admin_id, 'update_user', 'user', $userId, "Updated profile for user ID: $userId", $ip);
        } else {
            $error = "Failed to update user";
        }
    }
}

// Get user details for view
$viewUser = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $viewId);
    $stmt->execute();
    $viewUser = $stmt->get_result()->fetch_assoc();
}

// Get search/filter parameters
$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';
$verification = $_GET['verification'] ?? '';
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query
$where = [];
$params = [];
$types = "";

if ($search) {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
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

if ($status === 'active') {
    $where[] = "is_suspended = 0";
} elseif ($status === 'banned') {
    $where[] = "is_suspended = 1";
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

// Get statistics
$stats = [
    'total' => $totalUsers,
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
    <title>User Management - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        
        /* Sidebar */
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 20px; }
        .sidebar-header p { font-size: 12px; color: #888; margin-top: 8px; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        
        /* Main Content */
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        
        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card .icon { font-size: 32px; margin-bottom: 10px; }
        .stat-card .value { font-size: 28px; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 14px; margin-top: 5px; }
        
        /* Filters */
        .filters { background: white; border-radius: 12px; padding: 16px; margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 12px; color: #666; font-weight: 500; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; min-width: 150px; }
        .btn-filter, .btn-reset { padding: 8px 20px; border: none; border-radius: 6px; cursor: pointer; }
        .btn-filter { background: #667eea; color: white; }
        .btn-reset { background: #aaa; color: white; text-decoration: none; display: inline-block; }
        
        /* Messages */
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* Tables */
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        tr:hover { background: #f8f9fa; }
        
        /* Badges */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        
        /* Buttons */
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; margin: 2px; }
        .btn-view { background: #17a2b8; color: white; }
        .btn-verify { background: #28a745; color: white; }
        .btn-ban { background: #dc3545; color: white; }
        .btn-unban { background: #ffc107; color: #333; }
        .btn-edit { background: #007bff; color: white; }
        .btn-balance { background: #fd7e14; color: white; }
        .btn-delete { background: #6c757d; color: white; }
        
        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90%; max-height: 80vh; overflow-y: auto; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 16px; display: flex; justify-content: space-between; }
        .close-modal { cursor: pointer; font-size: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        
        /* Profile View */
        .profile-header { display: flex; gap: 24px; margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #eee; }
        .profile-avatar { width: 100px; height: 100px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; }
        .profile-info h3 { font-size: 24px; margin-bottom: 8px; }
        .profile-info p { color: #666; margin: 4px 0; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .info-card { background: #f8f9fa; padding: 16px; border-radius: 8px; }
        .info-card label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; }
        .info-card .value { font-size: 16px; font-weight: 500; }
        
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; border-color: #667eea; }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🏪 Brokerplace</h2>
                <p>Admin Dashboard</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item active"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='payments.php'"><i class="fas fa-credit-card"></i> Payments</li>
                <li class="nav-item" onclick="location.href='withdrawals.php'"><i class="fas fa-money-bill-wave"></i> Withdrawals</li>
                <li class="nav-item" onclick="location.href='tickets.php'"><i class="fas fa-ticket-alt"></i> Support Tickets</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">User Management</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon">👥</div>
                    <div class="value"><?php echo number_format($stats['total']); ?></div>
                    <div class="label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="icon">✓</div>
                    <div class="value"><?php echo number_format($stats['verified']); ?></div>
                    <div class="label">Verified Users</div>
                </div>
                <div class="stat-card">
                    <div class="icon">🚫</div>
                    <div class="value"><?php echo number_format($stats['banned']); ?></div>
                    <div class="label">Banned Users</div>
                </div>
                <div class="stat-card">
                    <div class="icon">🏢</div>
                    <div class="value"><?php echo number_format($stats['companies']); ?></div>
                    <div class="label">Companies</div>
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
                        <option value="">All</option>
                        <option value="user" <?php echo $role === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="company" <?php echo $role === 'company' ? 'selected' : ''; ?>>Company</option>
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
                    <label>Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="banned" <?php echo $status === 'banned' ? 'selected' : ''; ?>>Banned</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filter</button>
                </div>
                <div class="filter-group">
                    <a href="users.php" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
            
            <!-- User List or Profile View -->
            <?php if ($viewUser): ?>
                <!-- View User Profile -->
                <div class="section">
                    <div class="section-title">
                        User Profile
                        <button onclick="location.href='users.php'" class="btn-sm btn-view">← Back to List</button>
                    </div>
                    
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($viewUser['full_name'], 0, 1)); ?>
                        </div>
                        <div class="profile-info">
                            <h3><?php echo htmlspecialchars($viewUser['full_name']); ?></h3>
                            <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($viewUser['email']); ?></p>
                            <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($viewUser['phone'] ?? 'Not provided'); ?></p>
                            <p>
                                <?php echo getUserRoleBadge($viewUser['role']); ?>
                                <?php echo getVerificationBadge($viewUser['is_verified']); ?>
                                <?php if ($viewUser['is_suspended']): ?>
                                    <span class="badge badge-danger"><i class="fas fa-ban"></i> Banned</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-card">
                            <label>Account Balance</label>
                            <div class="value"><?php echo formatMoney($viewUser['balance']); ?></div>
                        </div>
                        <div class="info-card">
                            <label>Escrow Held</label>
                            <div class="value"><?php echo formatMoney($viewUser['escrow_held']); ?></div>
                        </div>
                        <div class="info-card">
                            <label>Total Transactions</label>
                            <div class="value"><?php echo number_format($viewUser['total_transactions'] ?? 0); ?></div>
                        </div>
                        <div class="info-card">
                            <label>Total Spent</label>
                            <div class="value"><?php echo formatMoney($viewUser['total_spent'] ?? 0); ?></div>
                        </div>
                        <div class="info-card">
                            <label>Address</label>
                            <div class="value"><?php echo htmlspecialchars($viewUser['address'] ?? 'Not provided'); ?></div>
                        </div>
                        <div class="info-card">
                            <label>City</label>
                            <div class="value"><?php echo htmlspecialchars($viewUser['city'] ?? 'Not provided'); ?></div>
                        </div>
                        <div class="info-card">
                            <label>Joined Date</label>
                            <div class="value"><?php echo date('F d, Y', strtotime($viewUser['created_at'])); ?></div>
                        </div>
                        <div class="info-card">
                            <label>Last Login</label>
                            <div class="value"><?php echo $viewUser['last_login'] ? date('F d, Y H:i', strtotime($viewUser['last_login'])) : 'Never'; ?></div>
                        </div>
                    </div>
                    
                    <?php if ($viewUser['is_suspended'] && $viewUser['ban_reason']): ?>
                    <div class="info-card" style="margin-top: 16px; background: #f8d7da;">
                        <label>Ban Reason</label>
                        <div class="value"><?php echo htmlspecialchars($viewUser['ban_reason']); ?></div>
                        <label>Banned At</label>
                        <div class="value"><?php echo date('F d, Y H:i', strtotime($viewUser['banned_at'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Actions -->
                <div class="section">
                    <div class="section-title">Quick Actions</div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if (!$viewUser['is_verified']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $viewUser['id']; ?>">
                            <button type="submit" name="verify_user" class="btn-sm btn-verify"><i class="fas fa-check"></i> Verify User</button>
                        </form>
                        <?php endif; ?>
                        
                        <?php if ($viewUser['is_suspended']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $viewUser['id']; ?>">
                            <button type="submit" name="unban_user" class="btn-sm btn-unban"><i class="fas fa-user-check"></i> Unban User</button>
                        </form>
                        <?php else: ?>
                        <button onclick="openBanModal(<?php echo $viewUser['id']; ?>)" class="btn-sm btn-ban"><i class="fas fa-ban"></i> Ban User</button>
                        <?php endif; ?>
                        
                        <button onclick="openBalanceModal(<?php echo $viewUser['id']; ?>, '<?php echo htmlspecialchars($viewUser['full_name']); ?>', <?php echo $viewUser['balance']; ?>)" class="btn-sm btn-balance"><i class="fas fa-coins"></i> Adjust Balance</button>
                        
                        <button onclick="openEditModal(<?php echo $viewUser['id']; ?>, '<?php echo htmlspecialchars($viewUser['full_name']); ?>', '<?php echo htmlspecialchars($viewUser['phone']); ?>', '<?php echo htmlspecialchars($viewUser['address']); ?>', '<?php echo htmlspecialchars($viewUser['city']); ?>')" class="btn-sm btn-edit"><i class="fas fa-edit"></i> Edit Profile</button>
                        
                        <?php if ($viewUser['role'] !== 'admin'): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user permanently? This cannot be undone!')">
                            <input type="hidden" name="user_id" value="<?php echo $viewUser['id']; ?>">
                            <button type="submit" name="delete_user" class="btn-sm btn-delete"><i class="fas fa-trash"></i> Delete User</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- User List Table -->
                <div class="section">
                    <div class="section-title">
                        All Users (<?php echo number_format($totalUsers); ?>)
                        <button onclick="location.href='users.php?action=export'" class="btn-sm btn-view"><i class="fas fa-download"></i> Export</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Contact</th>
                                    <th>Role</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users->num_rows > 0): ?>
                                    <?php while($row = $users->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo $row['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                                <small style="color:#888;"><?php echo htmlspecialchars($row['email']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($row['phone'] ?? '-'); ?><br>
                                                <small><?php echo htmlspecialchars($row['city'] ?? '-'); ?></small>
                                            </td>
                                            <td><?php echo getUserRoleBadge($row['role']); ?></td>
                                            <td><?php echo formatMoney($row['balance']); ?></td>
                                            <td>
                                                <?php echo getVerificationBadge($row['is_verified']); ?>
                                                <?php if ($row['is_suspended']): ?>
                                                    <span class="badge badge-danger">Banned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo timeAgo($row['created_at']); ?></td>
                                            <td>
                                                <button onclick="location.href='?view=<?php echo $row['id']; ?>'" class="btn-sm btn-view"><i class="fas fa-eye"></i> View</button>
                                                <button onclick="openBalanceModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>', <?php echo $row['balance']; ?>)" class="btn-sm btn-balance"><i class="fas fa-coins"></i></button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" style="text-align: center;">No users found</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&verification=<?php echo urlencode($verification); ?>&status=<?php echo urlencode($status); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Ban Modal -->
    <div id="banModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Ban User</span>
                <span class="close-modal" onclick="closeBanModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="user_id" id="banUserId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Ban Reason</label>
                        <textarea name="ban_reason" rows="3" required placeholder="Enter reason for banning this user..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="closeBanModal()" class="btn-sm" style="background: #aaa;">Cancel</button>
                    <button type="submit" name="ban_user" class="btn-sm btn-ban">Ban User</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Balance Modal -->
    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Adjust Balance</span>
                <span class="close-modal" onclick="closeBalanceModal()">&times;</span>
            </div>
            <form method="POST">
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
                        <select name="operation" required>
                            <option value="add">Add (+)</option>
                            <option value="subtract">Subtract (-)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (ETB)</label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="Enter amount">
                    </div>
                    <div class="form-group">
                        <label>Reason</label>
                        <textarea name="reason" rows="2" placeholder="Reason for balance adjustment"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="closeBalanceModal()" class="btn-sm" style="background: #aaa;">Cancel</button>
                    <button type="submit" name="adjust_balance" class="btn-sm btn-balance">Update Balance</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Edit User Profile</span>
                <span class="close-modal" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" id="editFullName" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="editPhone">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" id="editAddress" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" id="editCity">
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="closeEditModal()" class="btn-sm" style="background: #aaa;">Cancel</button>
                    <button type="submit" name="update_user" class="btn-sm btn-edit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openBanModal(userId) {
            document.getElementById('banUserId').value = userId;
            document.getElementById('banModal').style.display = 'flex';
        }
        
        function closeBanModal() {
            document.getElementById('banModal').style.display = 'none';
        }
        
        function openBalanceModal(userId, userName, currentBalance) {
            document.getElementById('balanceUserId').value = userId;
            document.getElementById('userName').value = userName;
            document.getElementById('currentBalance').value = currentBalance.toFixed(2) + ' ETB';
            document.getElementById('balanceModal').style.display = 'flex';
        }
        
        function closeBalanceModal() {
            document.getElementById('balanceModal').style.display = 'none';
        }
        
        function openEditModal(userId, fullName, phone, address, city) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('editFullName').value = fullName;
            document.getElementById('editPhone').value = phone || '';
            document.getElementById('editAddress').value = address || '';
            document.getElementById('editCity').value = city || '';
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>