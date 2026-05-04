<?php
// admin/dashboard.php - Modern Admin Dashboard

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();

// Get statistics
$stats = [
    'total_users' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'")->fetch_assoc()['count'],
    'total_companies' => $conn->query("SELECT COUNT(*) as count FROM companies")->fetch_assoc()['count'],
    'total_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'],
    'pending_transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_revenue' => $conn->query("SELECT SUM(commission_amount) as total FROM transactions WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0,
    'pending_approvals' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE approval_status = 'pending'")->fetch_assoc()['count'],
    'active_disputes' => $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status IN ('open', 'under_review')")->fetch_assoc()['count'],
];

// Get recent transactions
$recentTransactions = $conn->query("
    SELECT t.*, u1.full_name as buyer_name, u2.full_name as seller_name 
    FROM transactions t
    LEFT JOIN users u1 ON t.buyer_id = u1.id
    LEFT JOIN users u2 ON t.seller_id = u2.id
    ORDER BY t.created_at DESC 
    LIMIT 8
");

// Get pending listings
$pendingListings = $conn->query("
    SELECT l.*, u.full_name as seller_name 
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.approval_status = 'pending'
    ORDER BY l.created_at DESC
    LIMIT 5
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            transition: all 0.3s;
            z-index: 100;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 24px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header h2 {
            font-size: 20px;
            font-weight: 700;
        }

        .sidebar-header p {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }

        .nav-menu {
            list-style: none;
            padding: 20px 0;
        }

        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s;
            margin: 4px 12px;
            border-radius: 12px;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .nav-item.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .nav-item i {
            width: 22px;
            font-size: 18px;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 24px;
        }

        /* Header */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 20px;
            background: white;
            padding: 8px 20px;
            border-radius: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .admin-info span {
            color: #334155;
            font-weight: 500;
        }

        .logout-btn {
            padding: 8px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
        }

        .stat-icon {
            font-size: 32px;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-label {
            color: #64748b;
            font-size: 13px;
            margin-top: 6px;
        }

        .stat-trend {
            font-size: 11px;
            margin-top: 8px;
            color: #10b981;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 28px;
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

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
        }

        .card-header a {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }

        th {
            font-weight: 600;
            color: #64748b;
            font-size: 13px;
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
        }

        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; transform: translateY(-1px); }

        /* Alert */
        .alert {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
        }

        .alert-content i {
            font-size: 20px;
        }

        .alert-btn {
            background: #f59e0b;
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
            }
            .sidebar-header h2, .sidebar-header p, .nav-item span {
                display: none;
            }
            .nav-item {
                justify-content: center;
                padding: 12px;
            }
            .main-content {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🏪 Brokerplace</h2>
            <p>Admin Portal</p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item active" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></li>
            <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i><span>Users</span></li>
            <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i><span>Transactions</span></li>
            <li class="nav-item" onclick="location.href='approve_listings.php'"><i class="fas fa-check-double"></i><span>Approve Listings</span></li>
            <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i><span>Disputes</span></li>
            <li class="nav-item" onclick="location.href='withdrawals.php'"><i class="fas fa-money-bill-wave"></i><span>Withdrawals</span></li>
            <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i><span>Settings</span></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <h1 class="page-title">Dashboard</h1>
            <div class="admin-info">
                <span><i class="fas fa-user-shield"></i> <?php echo $_SESSION['admin_name']; ?></span>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <?php if ($stats['pending_approvals'] > 0): ?>
        <div class="alert">
            <div class="alert-content">
                <i class="fas fa-clock"></i>
                <span><strong><?php echo $stats['pending_approvals']; ?> listing(s)</strong> pending approval</span>
            </div>
            <a href="approve_listings.php" class="alert-btn">Review Now →</a>
        </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏢</div>
                <div class="stat-value"><?php echo number_format($stats['total_companies']); ?></div>
                <div class="stat-label">Companies</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                <div class="stat-label">Transactions</div>
                <div class="stat-trend"><?php echo $stats['pending_transactions']; ?> pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💵</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_revenue']); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-value"><?php echo $stats['pending_approvals']; ?></div>
                <div class="stat-label">Pending Approvals</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚖️</div>
                <div class="stat-value"><?php echo $stats['active_disputes']; ?></div>
                <div class="stat-label">Active Disputes</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Transactions</h3>
                <a href="transactions.php">View All →</a>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Status</th><th>Date</th><th></th>
                    </thead>
                    <tbody>
                        <?php if ($recentTransactions->num_rows > 0): ?>
                            <?php while($row = $recentTransactions->fetch_assoc()): ?>
                            <tr>
                                <td>#<?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars(substr($row['buyer_name'] ?? 'N/A', 0, 20)); ?></td>
                                <td><?php echo htmlspecialchars(substr($row['seller_name'] ?? 'N/A', 0, 20)); ?></td>
                                <td><?php echo formatMoney($row['total_amount']); ?></td>
                                <td><?php echo getStatusBadge($row['status']); ?></td>
                                <td><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                                <td><a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary">View</a></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center;">No transactions found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Pending Approvals</h3>
                <a href="approve_listings.php">View All →</a>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>Title</th><th>Seller</th><th>Price</th><th>Type</th><th>Posted</th><th></th>
                    </thead>
                    <tbody>
                        <?php if ($pendingListings->num_rows > 0): ?>
                            <?php while($row = $pendingListings->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($row['title'], 0, 30)); ?></td>
                                <td><?php echo htmlspecialchars($row['seller_name']); ?></td>
                                <td><?php echo formatMoney($row['price']); ?></td>
                                <td><span class="badge badge-info"><?php echo ucfirst($row['type']); ?></span></td>
                                <td><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                                <td><a href="approve_listings.php" class="btn-sm btn-primary">Review</a></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center;">No pending approvals</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>