<?php
// admin/users.php - Premium Responsive Users Management

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
    
    if (isset($_POST['ban_user'])) {
        $reason = $conn->real_escape_string($_POST['ban_reason']);
        $conn->query("UPDATE users SET is_suspended = 1, ban_reason = '$reason', banned_at = NOW() WHERE id = $userId");
        $message = "User banned successfully";
    }
    
    if (isset($_POST['unban_user'])) {
        $conn->query("UPDATE users SET is_suspended = 0, ban_reason = NULL, banned_at = NULL WHERE id = $userId");
        $message = "User unbanned successfully";
    }
    
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

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'active' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_suspended = 0")->fetch_assoc()['count'],
    'banned' => $conn->query("SELECT COUNT(*) as count FROM users WHERE is_suspended = 1")->fetch_assoc()['count'],
    'companies' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'company'")->fetch_assoc()['count'],
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <style>
        /* ============================================
           CSS VARIABLES & RESET
        ============================================ */
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
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f5f7fb;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        /* ============================================
           STATS CARDS - FULLY RESPONSIVE
        ============================================ */
        .stats-container {
            display: grid;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* Desktop: 4 columns */
        @media (min-width: 1200px) {
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 1.25rem;
            }
        }
        
        /* Laptop: 4 columns */
        @media (min-width: 992px) and (max-width: 1199px) {
            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }
        }
        
        /* Tablet: 2 columns */
        @media (min-width: 576px) and (max-width: 991px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
        }
        
        /* Mobile: 1 column */
        @media (max-width: 575px) {
            .stats-container {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
        }
        
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            border: 1px solid var(--border);
            transition: var(--transition);
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
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .stat-icon {
            width: 42px;
            height: 42px;
            background: var(--primary-light);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--primary);
        }
        
        .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.02em;
        }
        
        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* ============================================
           FILTERS SECTION - FULLY RESPONSIVE
        ============================================ */
        .filters-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
        }
        
        .filters-form {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 140px;
        }
        
        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .filter-input, .filter-select {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.8rem;
            background: white;
            transition: var(--transition);
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        .btn-primary, .btn-secondary {
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: var(--gray);
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            transform: translateY(-1px);
        }
        
        /* Responsive Filters */
        @media (max-width: 992px) {
            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .filter-actions {
                flex-direction: row;
                margin-top: 0.5rem;
            }
            
            .btn-primary, .btn-secondary {
                flex: 1;
                justify-content: center;
            }
        }
        
        /* ============================================
           USERS TABLE - RESPONSIVE
        ============================================ */
        .table-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .table-header {
            padding: 1rem 1.25rem;
            background: var(--gray-light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .table-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .table-header span {
            font-size: 0.75rem;
            color: var(--gray);
            background: white;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            border: 1px solid var(--border);
        }
        
        /* Desktop Table */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            min-width: 900px;
        }
        
        .users-table th {
            text-align: left;
            padding: 1rem 1rem;
            background: #fafcff;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .users-table td {
            padding: 1rem 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        
        .users-table tr {
            transition: var(--transition);
        }
        
        .users-table tr:hover {
            background: var(--gray-light);
        }
        
        /* User Info Cell */
        .user-info-cell {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 0.85rem;
        }
        
        .user-id {
            font-size: 0.65rem;
            color: #94a3b8;
            margin-top: 0.125rem;
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-active { background: var(--success-light); color: #065f46; }
        .badge-banned { background: var(--danger-light); color: #991b1b; }
        .badge-admin { background: linear-gradient(135deg, var(--primary), #7c3aed); color: white; }
        .badge-company { background: var(--info-light); color: #1e40af; }
        .badge-user { background: #f1f5f9; color: #475569; }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 0.375rem 0.875rem;
            border-radius: 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            white-space: nowrap;
        }
        
        .action-view { background: var(--info); color: white; }
        .action-view:hover { background: #2563eb; transform: translateY(-1px); }
        .action-ban { background: var(--danger); color: white; }
        .action-ban:hover { background: #dc2626; transform: translateY(-1px); }
        .action-unban { background: var(--success); color: white; }
        .action-unban:hover { background: #059669; transform: translateY(-1px); }
        .action-delete { background: #dc2626; color: white; }
        .action-delete:hover { background: #b91c1c; transform: translateY(-1px); }
        
        /* Mobile Actions - Compact */
        @media (max-width: 576px) {
            .action-btn span {
                display: none;
            }
            
            .action-btn {
                padding: 0.375rem 0.625rem;
            }
            
            .action-btn i {
                margin: 0;
                font-size: 0.8rem;
            }
        }
        
        /* ============================================
           PAGINATION - RESPONSIVE
        ============================================ */
        .pagination {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .page-link {
            padding: 0.375rem 0.875rem;
            border-radius: 0.5rem;
            text-decoration: none;
            color: var(--gray);
            font-size: 0.8rem;
            border: 1px solid var(--border);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .page-link:hover, .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        @media (max-width: 576px) {
            .page-link span {
                display: none;
            }
            
            .page-link i {
                margin: 0;
            }
        }
        
        /* ============================================
           MODALS - FULLY RESPONSIVE
        ============================================ */
        .modal, .view-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content, .view-modal-content {
            background: white;
            border-radius: 1.25rem;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            animation: modalIn 0.2s ease;
        }
        
        .view-modal-content {
            max-width: 550px;
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .modal-header, .view-modal-header {
            padding: 1.25rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: white;
            border-radius: 1.25rem 1.25rem 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .modal-header h3, .view-modal-header h3 {
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .close-modal {
            cursor: pointer;
            font-size: 1.25rem;
            transition: opacity 0.2s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
        }
        
        .close-modal:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .view-modal-body {
            padding: 1.5rem;
        }
        
        /* Profile Section in Modal */
        .profile-section {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: 700;
            color: white;
        }
        
        .profile-details h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .profile-details p {
            font-size: 0.7rem;
            color: var(--gray);
        }
        
        /* Info Grid in Modal */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            background: var(--gray-light);
            border-radius: 0.75rem;
            padding: 0.875rem;
        }
        
        .info-label {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .info-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark);
            word-break: break-word;
        }
        
        /* Stats Row in Modal */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-item {
            background: var(--primary-light);
            border-radius: 0.75rem;
            padding: 0.875rem;
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
        }
        
        .stat-text {
            font-size: 0.65rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }
        
        /* Modal Action Buttons */
        .modal-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--dark);
        }
        
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            resize: vertical;
            font-family: inherit;
            font-size: 0.85rem;
        }
        
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        /* Responsive Modal */
        @media (max-width: 576px) {
            .modal-content, .view-modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .profile-section {
                flex-direction: column;
                text-align: center;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-actions .action-btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Alert */
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-size: 0.8rem;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border-left: 3px solid var(--success);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
            display: block;
        }
    </style>
</head>

<div>
    <!-- Alert Message -->
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Cards - Fully Responsive Grid -->
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['active']); ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['banned']); ?></div>
            <div class="stat-label">Banned</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-building"></i></div>
            </div>
            <div class="stat-number"><?php echo number_format($stats['companies']); ?></div>
            <div class="stat-label">Companies</div>
        </div>
    </div>
    
    <!-- Filters Section - Fully Responsive -->
    <div class="filters-card">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" class="filter-input" placeholder="Name, email or phone..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Role</label>
                <select name="role" class="filter-select">
                    <option value="">All Roles</option>
                    <option value="user" <?php echo $role == 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="company" <?php echo $role == 'company' ? 'selected' : ''; ?>>Company</option>
                    <option value="admin" <?php echo $role == 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-flag"></i> Status</label>
                <select name="status" class="filter-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="banned" <?php echo $status == 'banned' ? 'selected' : ''; ?>>Banned</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="users.php" class="btn-secondary"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Users Table -->
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-users"></i> All Users</h3>
            <span><i class="fas fa-database"></i> <?php echo number_format($total); ?> users</span>
        </div>
        <div class="table-wrapper">
            <?php if ($users && $users->num_rows > 0): ?>
                <table class="users-table">
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
                                    <div class="user-info-cell">
                                        <span class="user-name"><?php echo htmlspecialchars($row['full_name']); ?></span>
                                        <span class="user-id"><i class="fas fa-hashtag"></i> ID: <?php echo $row['id']; ?></span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars(substr($row['email'], 0, 25)); ?><?php echo strlen($row['email']) > 25 ? '...' : ''; ?></td>
                                <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                                <td>
                                    <?php if($row['role'] == 'admin'): ?>
                                        <span class="badge badge-admin"><i class="fas fa-shield-alt"></i> Admin</span>
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
                                            <i class="fas fa-eye"></i> <span>View</span>
                                        </button>
                                        
                                        <?php if ($row['role'] != 'admin'): ?>
                                            <?php if ($row['is_suspended']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="unban_user" class="action-btn action-unban" onclick="return confirm('Unban this user?')" title="Unban">
                                                        <i class="fas fa-check-circle"></i> <span>Unban</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button onclick="banUser(<?php echo $row['id']; ?>)" class="action-btn action-ban" title="Ban">
                                                    <i class="fas fa-ban"></i> <span>Ban</span>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user? This action cannot be undone.')">
                                                <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="delete_user" class="action-btn action-delete" title="Delete">
                                                    <i class="fas fa-trash"></i> <span>Delete</span>
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
                    <i class="fas fa-users"></i>
                    <p>No users found</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> <span>Prev</span>
                    </a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" 
                       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role); ?>&status=<?php echo urlencode($status); ?>" class="page-link">
                        <span>Next</span> <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
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
        <div style="padding: 1.5rem;">
            <form method="POST">
                <input type="hidden" name="user_id" id="banUserId">
                <div class="form-group">
                    <label>Reason for Banning</label>
                    <textarea name="ban_reason" rows="3" required placeholder="Enter reason for banning this user..."></textarea>
                </div>
                <button type="submit" name="ban_user" class="action-btn action-ban" style="width: 100%; padding: 0.75rem; justify-content: center;">
                    <i class="fas fa-ban"></i> Ban User
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function viewUser(userId) {
    const modal = document.getElementById('viewUserModal');
    const content = document.getElementById('viewUserContent');
    
    modal.style.display = 'flex';
    content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading user details...</div>';
    
    fetch(`ajax/get_user_details.php?id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const user = data.user;
                const isBanned = user.is_suspended == 1;
                
                content.innerHTML = `
                    <div class="profile-section">
                        <div class="profile-avatar">${user.full_name.charAt(0).toUpperCase()}</div>
                        <div class="profile-details">
                            <h3>${escapeHtml(user.full_name)}</h3>
                            <p><i class="fas fa-calendar-alt"></i> Member since ${new Date(user.created_at).toLocaleDateString()}</p>
                            <span class="badge ${isBanned ? 'badge-banned' : 'badge-active'}" style="margin-top: 0.5rem;">
                                ${isBanned ? '<i class="fas fa-ban"></i> Banned' : '<i class="fas fa-check-circle"></i> Active'}
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                            <div class="info-value">${escapeHtml(user.email)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                            <div class="info-value">${user.phone || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-tag"></i> Role</div>
                            <div class="info-value">${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                            <div class="info-value">${user.address || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-city"></i> City</div>
                            <div class="info-value">${user.city || '<span style="color: #94a3b8;">Not provided</span>'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-wallet"></i> Balance</div>
                            <div class="info-value">${formatMoney(user.balance)}</div>
                        </div>
                        ${user.bio ? `
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-align-left"></i> Bio</div>
                            <div class="info-value">${escapeHtml(user.bio)}</div>
                        </div>
                        ` : ''}
                        ${user.ban_reason ? `
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-exclamation-triangle"></i> Ban Reason</div>
                            <div class="info-value" style="color: #dc2626;">${escapeHtml(user.ban_reason)}</div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="stat-number">${user.total_listings || 0}</div>
                            <div class="stat-text">Listings</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${user.total_transactions || 0}</div>
                            <div class="stat-text">Transactions</div>
                        </div>
                        <div const="stat-item">
                            <div class="stat-number">${formatMoney(user.balance)}</div>
                            <div class="stat-text">Balance</div>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        ${!isBanned && user.role != 'admin' ? `
                            <button onclick="banUser(${user.id})" class="action-btn action-ban" style="flex: 1; justify-content: center;">
                                <i class="fas fa-ban"></i> Ban User
                            </button>
                        ` : isBanned ? `
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="user_id" value="${user.id}">
                                <button type="submit" name="unban_user" class="action-btn action-unban" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-check-circle"></i> Unban User
                                </button>
                            </form>
                        ` : ''}
                        ${user.role != 'admin' ? `
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('Delete this user?')">
                                <input type="hidden" name="user_id" value="${user.id}">
                                <button type="submit" name="delete_user" class="action-btn action-delete" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-trash"></i> Delete User
                                </button>
                            </form>
                        ` : ''}
                        <a href="chat.php?user=${user.id}" class="action-btn action-view" style="flex: 1; justify-content: center; text-decoration: none;">
                            <i class="fas fa-comment"></i> Send Message
                        </a>
                        <button onclick="closeViewModal()" class="action-btn" style="flex: 1; justify-content: center; background: #64748b; color: white;">
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