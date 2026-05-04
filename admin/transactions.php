<?php
// admin/transactions.php - Transactions Management

$page_title = 'Transactions Management';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
if ($status) $where[] = "t.status = '$status'";
if ($search) $where[] = "(u1.full_name LIKE '%$search%' OR u2.full_name LIKE '%$search%' OR t.payment_code_5digit LIKE '%$search%')";
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$transactions = $conn->query("
    SELECT t.*, u1.full_name as buyer_name, u2.full_name as seller_name 
    FROM transactions t
    LEFT JOIN users u1 ON t.buyer_id = u1.id
    LEFT JOIN users u2 ON t.seller_id = u2.id
    $whereClause
    ORDER BY t.created_at DESC
    LIMIT $limit OFFSET $offset
");

$total = $conn->query("SELECT COUNT(*) as total FROM transactions t $whereClause")->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'],
    'completed' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'completed'")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'],
    'total_volume' => $conn->query("SELECT SUM(total_amount) as total FROM transactions WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 32px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    .filters { background: white; border-radius: 16px; padding: 16px; margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; }
    .filter-group select, .filter-group input { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .btn-filter { padding: 8px 20px; background: #667eea; color: white; border: none; border-radius: 10px; cursor: pointer; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #333; }
    .pagination .active { background: #667eea; color: white; border-color: #667eea; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['total']); ?></div><div class="stat-label">Total Transactions</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['completed']); ?></div><div class="stat-label">Completed</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo number_format($stats['pending']); ?></div><div class="stat-label">Pending</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['total_volume']); ?></div><div class="stat-label">Total Volume</div></div>
</div>

<form method="GET" class="filters">
    <div class="filter-group"><select name="status"><option value="">All Status</option><option value="pending_deposit" <?php echo $status == 'pending_deposit' ? 'selected' : ''; ?>>Pending Deposit</option><option value="awaiting_buyer_deposit" <?php echo $status == 'awaiting_buyer_deposit' ? 'selected' : ''; ?>>Awaiting Buyer</option><option value="awaiting_seller_deposit" <?php echo $status == 'awaiting_seller_deposit' ? 'selected' : ''; ?>>Awaiting Seller</option><option value="deposits_complete" <?php echo $status == 'deposits_complete' ? 'selected' : ''; ?>>Deposits Complete</option><option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option><option value="disputed" <?php echo $status == 'disputed' ? 'selected' : ''; ?>>Disputed</option></select></div>
    <div class="filter-group"><input type="text" name="search" placeholder="Search buyer/seller/code" value="<?php echo htmlspecialchars($search); ?>"></div>
    <button type="submit" class="btn-filter">Filter</button>
</form>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-exchange-alt"></i> All Transactions</h2></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>ID</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Commission</th><th>Status</th><th>Code</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
                <?php while($row = $transactions->fetch_assoc()): ?>
                <tr onclick="location.href='transactions.php?view=<?php echo $row['id']; ?>'" style="cursor:pointer;">
                    <td>#<?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['buyer_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($row['seller_name'] ?? 'N/A'); ?></td>
                    <td><?php echo formatMoney($row['total_amount']); ?></td>
                    <td><?php echo formatMoney($row['commission_amount']); ?></td>
                    <td><?php echo getStatusBadge($row['status']); ?></td>
                    <td><code><?php echo $row['payment_code_5digit'] ?? '-'; ?></code></td>
                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                    <td><a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">View</a></td>
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

<?php
$content = ob_get_clean();
include 'layout.php';
?>