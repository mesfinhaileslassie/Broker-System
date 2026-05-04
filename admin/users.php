<?php
// admin/users.php - With Real-Time AJAX Search

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle POST actions (same as before)
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
            
            $stmt2 = $conn->prepare("INSERT INTO balance_adjustments (user_id, admin_id, amount, operation, description) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iidss", $userId, $admin_id, $amount, $operation, $reason);
            $stmt2->execute();
        } else {
            $error = "Failed to update balance";
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

// Get filter parameters (for initial load)
$role = $_GET['role'] ?? '';
$verification = $_GET['verification'] ?? '';
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build where clause for initial load
$where = [];
$params = [];
$types = "";

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

// Get total count for initial load
$countSql = "SELECT COUNT(*) as total FROM users $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalUsers = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalUsers / $limit);

// Get users for initial load
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
    <title>User Management - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 20px; }
        .sidebar-header p { font-size: 12px; color: #888; margin-top: 8px; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card .value { font-size: 28px; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 14px; margin-top: 5px; }
        .filters { background: white; border-radius: 12px; padding: 16px; margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 12px; color: #666; font-weight: 500; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; min-width: 150px; }
        .search-group { flex: 2; min-width: 250px; }
        .search-group input { width: 100%; }
        .search-results-info { font-size: 13px; color: #666; margin: 10px 0; padding: 8px 12px; background: #e3f2fd; border-radius: 6px; display: none; }
        .btn-filter, .btn-reset { padding: 8px 20px; border: none; border-radius: 6px; cursor: pointer; }
        .btn-filter { background: #667eea; color: white; }
        .btn-reset { background: #aaa; color: white; text-decoration: none; display: inline-block; }
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        tr:hover { background: #f8f9fa; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; margin: 2px; }
        .btn-view { background: #17a2b8; color: white; }
        .btn-verify { background: #28a745; color: white; }
        .btn-ban { background: #dc3545; color: white; }
        .btn-unban { background: #ffc107; color: #333; }
        .btn-balance { background: #fd7e14; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90%; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        .profile-header { display: flex; gap: 24px; margin-bottom: 24px; }
        .profile-avatar { width: 80px; height: 80px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px; color: white; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .info-card { background: #f8f9fa; padding: 16px; border-radius: 8px; }
        .info-card label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; }
        .info-card .value { font-size: 16px; font-weight: 500; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; }
        .loading { display: inline-block; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #667eea; border-radius: 50%; animation: spin 1s linear infinite; margin-left: 10px; display: none; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .no-results { text-align: center; padding: 40px; color: #666; }
        .highlight { background-color: #fff3cd; }
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
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item active"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
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
            
            <div class="stats-grid">
                <div class="stat-card"><div class="value"><?php echo number_format($stats['total']); ?></div><div class="label">Total Users</div></div>
                <div class="stat-card"><div class="value"><?php echo number_format($stats['verified']); ?></div><div class="label">Verified</div></div>
                <div class="stat-card"><div class="value"><?php echo number_format($stats['banned']); ?></div><div class="label">Banned</div></div>
                <div class="stat-card"><div class="value"><?php echo number_format($stats['companies']); ?></div><div class="label">Companies</div></div>
            </div>
            
            <!-- Search and Filters -->
            <div class="filters">
                <div class="filter-group search-group">
                    <label><i class="fas fa-search"></i> Real-Time Search</label>
                    <input type="text" id="liveSearch" placeholder="Type name, email, or phone..." autocomplete="off">
                </div>
                <div class="filter-group">
                    <label>Role</label>
                    <select id="roleFilter">
                        <option value="">All</option>
                        <option value="user">User</option>
                        <option value="company">Company</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select id="statusFilter">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="banned">Banned</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Verification</label>
                    <select id="verificationFilter">
                        <option value="">All</option>
                        <option value="verified">Verified</option>
                        <option value="unverified">Unverified</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button class="btn-reset" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </div>
            
            <div class="search-results-info" id="searchResultsInfo"></div>
            
            <?php if ($viewUser): ?>
                <!-- View User Profile -->
                <div class="section">
                    <div class="section-title">
                        User Profile
                        <button onclick="location.href='users.php'" class="btn-sm btn-view">← Back to List</button>
                    </div>
                    
                    <div class="profile-header">
                        <div class="profile-avatar"><?php echo strtoupper(substr($viewUser['full_name'], 0, 1)); ?></div>
                        <div>
                            <h3><?php echo htmlspecialchars($viewUser['full_name']); ?></h3>
                            <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($viewUser['email']); ?></p>
                            <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($viewUser['phone'] ?? 'Not provided'); ?></p>
                            <?php echo getUserRoleBadge($viewUser['role']); ?>
                            <?php echo getVerificationBadge($viewUser['is_verified']); ?>
                        </div>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-card"><label>Balance</label><div class="value"><?php echo formatMoney($viewUser['balance']); ?></div></div>
                        <div class="info-card"><label>Escrow Held</label><div class="value"><?php echo formatMoney($viewUser['escrow_held']); ?></div></div>
                        <div class="info-card"><label>Address</label><div class="value"><?php echo htmlspecialchars($viewUser['address'] ?? 'N/A'); ?></div></div>
                        <div class="info-card"><label>City</label><div class="value"><?php echo htmlspecialchars($viewUser['city'] ?? 'N/A'); ?></div></div>
                        <div class="info-card"><label>Joined</label><div class="value"><?php echo date('M d, Y', strtotime($viewUser['created_at'])); ?></div></div>
                        <div class="info-card"><label>Last Login</label><div class="value"><?php echo $viewUser['last_login'] ? date('M d, Y', strtotime($viewUser['last_login'])) : 'Never'; ?></div></div>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <?php if (!$viewUser['is_verified']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $viewUser['id']; ?>">
                            <button type="submit" name="verify_user" class="btn-sm btn-verify">Verify User</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($viewUser['is_suspended']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $viewUser['id']; ?>">
                            <button type="submit" name="unban_user" class="btn-sm btn-unban">Unban User</button>
                        </form>
                        <?php else: ?>
                        <button onclick="openBanModal(<?php echo $viewUser['id']; ?>)" class="btn-sm btn-ban">Ban User</button>
                        <?php endif; ?>
                        <button onclick="openBalanceModal(<?php echo $viewUser['id']; ?>, '<?php echo addslashes($viewUser['full_name']); ?>', <?php echo $viewUser['balance']; ?>)" class="btn-sm btn-balance">Adjust Balance</button>
                    </div>
                </div>
            <?php else: ?>
                <!-- Users Table - Results will be loaded here -->
                <div class="section">
                    <div class="section-title">
                        <span><i class="fas fa-users"></i> Users List</span>
                        <span id="resultCount"></span>
                    </div>
                    <div id="usersTableContainer">
                        <div style="text-align: center; padding: 40px;">
                            <div class="loading" style="display: inline-block;"></div>
                            <p>Loading users...</p>
                        </div>
                    </div>
                    <div id="paginationContainer" class="pagination"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Ban Modal -->
    <div id="banModal" class="modal">
        <div class="modal-content">
            <h3>Ban User</h3>
            <form method="POST">
                <input type="hidden" name="user_id" id="banUserId">
                <div class="form-group">
                    <label>Ban Reason</label>
                    <textarea name="ban_reason" rows="3" required placeholder="Enter reason for banning..."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeBanModal()" class="btn-sm" style="background:#aaa;">Cancel</button>
                    <button type="submit" name="ban_user" class="btn-sm btn-ban">Ban User</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Balance Modal -->
    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <h3>Adjust Balance</h3>
            <form method="POST">
                <input type="hidden" name="user_id" id="balanceUserId">
                <div class="form-group">
                    <label>User: <span id="balanceUserName"></span></label>
                </div>
                <div class="form-group">
                    <label>Current Balance: <span id="balanceCurrent"></span></label>
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
                    <input type="number" name="amount" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <textarea name="reason" rows="2" placeholder="Reason for adjustment"></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeBalanceModal()" class="btn-sm" style="background:#aaa;">Cancel</button>
                    <button type="submit" name="adjust_balance" class="btn-sm btn-balance">Update Balance</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let searchTimeout;
        let currentPage = 1;
        
        // Get filter values
        function getFilters() {
            return {
                search: document.getElementById('liveSearch').value,
                role: document.getElementById('roleFilter').value,
                status: document.getElementById('statusFilter').value,
                verification: document.getElementById('verificationFilter').value,
                page: currentPage
            };
        }
        
        // Load users with AJAX
        function loadUsers() {
            const filters = getFilters();
            const searchTerm = filters.search.trim();
            
            // Show loading
            document.getElementById('usersTableContainer').innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading" style="display: inline-block;"></div><p>Searching...</p></div>';
            
            // Build URL with parameters
            let url = '../ajax/search_users.php?';
            url += 'search=' + encodeURIComponent(searchTerm);
            url += '&role=' + encodeURIComponent(filters.role);
            url += '&status=' + encodeURIComponent(filters.status);
            url += '&verification=' + encodeURIComponent(filters.verification);
            url += '&page=' + filters.page;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayUsers(data.users);
                        displayPagination(data.total_pages, data.current_page, data.total);
                        updateResultsInfo(data.total, searchTerm);
                    } else {
                        document.getElementById('usersTableContainer').innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i> Error loading users</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('usersTableContainer').innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i> Error loading users</div>';
                });
        }
        
        // Display users in table
        function displayUsers(users) {
            if (!users || users.length === 0) {
                document.getElementById('usersTableContainer').innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No users found matching your criteria</div>';
                return;
            }
            
            let html = `
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
            `;
            
            users.forEach(user => {
                const verifiedBadge = user.is_verified ? '<span class="badge badge-success">✓ Verified</span>' : '<span class="badge badge-warning">⚠ Pending</span>';
                const bannedBadge = user.is_suspended ? '<span class="badge badge-danger">Banned</span>' : '';
                let roleBadge = '';
                if (user.role === 'admin') roleBadge = '<span class="badge badge-danger">Admin</span>';
                else if (user.role === 'company') roleBadge = '<span class="badge badge-info">Company</span>';
                else roleBadge = '<span class="badge badge-primary">User</span>';
                
                html += `
                    <tr>
                        <td>#${user.id}</td>
                        <td><strong>${escapeHtml(user.full_name)}</strong><br><small>${escapeHtml(user.email)}</small></td>
                        <td>${escapeHtml(user.phone || '-')}<br><small>${escapeHtml(user.city || '-')}</small></td>
                        <td>${roleBadge}</td>
                        <td>${formatMoney(user.balance)}</td>
                        <td>${verifiedBadge} ${bannedBadge}</td>
                        <td>${timeAgo(user.created_at)}</td>
                        <td><button onclick="location.href='?view=${user.id}'" class="btn-sm btn-view"><i class="fas fa-eye"></i> View</button></td>
                    </tr>
                `;
            });
            
            html += `</tbody></table></div>`;
            document.getElementById('usersTableContainer').innerHTML = html;
        }
        
        // Display pagination
        function displayPagination(totalPages, currentPage, total) {
            if (totalPages <= 1) {
                document.getElementById('paginationContainer').innerHTML = '';
                return;
            }
            
            let html = '';
            for (let i = 1; i <= totalPages; i++) {
                if (i === currentPage) {
                    html += `<span class="active">${i}</span>`;
                } else {
                    html += `<a href="#" onclick="goToPage(${i}); return false;">${i}</a>`;
                }
            }
            document.getElementById('paginationContainer').innerHTML = html;
            document.getElementById('resultCount').innerHTML = `<small>(${total} total users)</small>`;
        }
        
        // Update search results info
        function updateResultsInfo(total, searchTerm) {
            const infoDiv = document.getElementById('searchResultsInfo');
            if (searchTerm && searchTerm.length > 0) {
                infoDiv.innerHTML = `<i class="fas fa-search"></i> Found ${total} result(s) for "<strong>${escapeHtml(searchTerm)}</strong>"`;
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
        }
        
        // Go to page
        function goToPage(page) {
            currentPage = page;
            loadUsers();
        }
        
        // Reset all filters
        function resetFilters() {
            document.getElementById('liveSearch').value = '';
            document.getElementById('roleFilter').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('verificationFilter').value = '';
            currentPage = 1;
            loadUsers();
        }
        
        // Real-time search with debounce
        document.getElementById('liveSearch').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            currentPage = 1;
            searchTimeout = setTimeout(() => {
                loadUsers();
            }, 300);
        });
        
        // Filter change listeners
        document.getElementById('roleFilter').addEventListener('change', function() {
            currentPage = 1;
            loadUsers();
        });
        document.getElementById('statusFilter').addEventListener('change', function() {
            currentPage = 1;
            loadUsers();
        });
        document.getElementById('verificationFilter').addEventListener('change', function() {
            currentPage = 1;
            loadUsers();
        });
        
        // Helper functions
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        function formatMoney(amount) {
            return parseFloat(amount).toFixed(2) + ' ETB';
        }
        
        function timeAgo(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);
            if (diff < 60) return diff + ' seconds ago';
            if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
            if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
            if (diff < 2592000) return Math.floor(diff / 86400) + ' days ago';
            return date.toLocaleDateString();
        }
        
        function openBanModal(userId) {
            document.getElementById('banUserId').value = userId;
            document.getElementById('banModal').style.display = 'flex';
        }
        
        function closeBanModal() {
            document.getElementById('banModal').style.display = 'none';
        }
        
        function openBalanceModal(userId, userName, currentBalance) {
            document.getElementById('balanceUserId').value = userId;
            document.getElementById('balanceUserName').innerText = userName;
            document.getElementById('balanceCurrent').innerText = formatMoney(currentBalance);
            document.getElementById('balanceModal').style.display = 'flex';
        }
        
        function closeBalanceModal() {
            document.getElementById('balanceModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Initial load
        loadUsers();
    </script>
</body>
</html>