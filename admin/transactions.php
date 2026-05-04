<?php
// admin/transactions.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle transaction actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['release_payment'])) {
        $transactionId = intval($_POST['transaction_id']);
        // Release payment logic here
        $message = "Payment released successfully";
    }
    
    if (isset($_POST['cancel_transaction'])) {
        $transactionId = intval($_POST['transaction_id']);
        $stmt = $conn->prepare("UPDATE transactions SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param("i", $transactionId);
        if ($stmt->execute()) {
            $message = "Transaction cancelled";
        }
    }
}

// Get transactions with filters
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types = "";

if ($status) {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($search) {
    $where[] = "(u1.full_name LIKE ? OR u2.full_name LIKE ? OR t.payment_code_5digit LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get transactions
$sql = "SELECT t.*, u1.full_name as buyer_name, u2.full_name as seller_name 
        FROM transactions t
        LEFT JOIN users u1 ON t.buyer_id = u1.id
        LEFT JOIN users u2 ON t.seller_id = u2.id
        $whereClause
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();

// Get total count
$countSql = "SELECT COUNT(*) as total FROM transactions t $whereClause";
$stmt = $conn->prepare($countSql);
$paramsCount = array_slice($params, 0, -2);
$typesCount = substr($types, 0, -2);
if ($paramsCount) {
    $stmt->bind_param($typesCount, ...$paramsCount);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'],
    'completed' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'completed'")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_volume' => $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
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
        .filters { background: white; border-radius: 12px; padding: 16px; margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; }
        .filter-group select, .filter-group input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        .btn-filter { padding: 8px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .section { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; margin: 2px; }
        .btn-release { background: #28a745; color: white; }
        .btn-cancel { background: #dc3545; color: white; }
        .btn-view { background: #17a2b8; color: white; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; }
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
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item active"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Transaction Management</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="value"><?php echo number_format($stats['total']); ?></div><div class="label">Total Transactions</div></div>
                <div class="stat-card"><div class="value"><?php echo number_format($stats['completed']); ?></div><div class="label">Completed</div></div>
                <div class="stat-card"><div class="value"><?php echo number_format($stats['pending']); ?></div><div class="label">Pending</div></div>
                <div class="stat-card"><div class="value"><?php echo formatMoney($stats['total_volume']); ?></div><div class="label">Total Volume</div></div>
            </div>
            
            <form method="GET" class="filters">
                <div class="filter-group">
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="pending_deposit" <?php echo $status == 'pending_deposit' ? 'selected' : ''; ?>>Pending Deposit</option>
                        <option value="awaiting_buyer_deposit" <?php echo $status == 'awaiting_buyer_deposit' ? 'selected' : ''; ?>>Awaiting Buyer</option>
                        <option value="awaiting_seller_deposit" <?php echo $status == 'awaiting_seller_deposit' ? 'selected' : ''; ?>>Awaiting Seller</option>
                        <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="disputed" <?php echo $status == 'disputed' ? 'selected' : ''; ?>>Disputed</option>
                        <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <input type="text" name="search" placeholder="Search by buyer/seller/code" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filter</button>
            </form>
            
            <div class="section">
                <div class="section-title">All Transactions</div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Commission</th><th>Status</th><th>Payment Code</th><th>Created</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php while($row = $transactions->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['buyer_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['seller_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo formatMoney($row['total_amount']); ?></td>
                                    <td><?php echo formatMoney($row['commission_amount']); ?></td>
                                    <td><?php echo getStatusBadge($row['status']); ?></td>
                                    <td><code><?php echo $row['payment_code_5digit'] ?? '-'; ?></code></td>
                                    <td><?php echo timeAgo($row['created_at']); ?></td>
                                    <td>
                                        <button onclick="viewTransaction(<?php echo $row['id']; ?>)" class="btn-sm btn-view">View</button>
                                        <?php if ($row['status'] == 'in_progress'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="transaction_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="release_payment" class="btn-sm btn-release">Release</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!in_array($row['status'], ['completed', 'cancelled'])): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Cancel this transaction?')">
                                                <input type="hidden" name="transaction_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="cancel_transaction" class="btn-sm btn-cancel">Cancel</button>
                                            </form>
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
                            <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function viewTransaction(id) {
            alert('Transaction details will be shown here');
        }
    </script>
</body>
</html>