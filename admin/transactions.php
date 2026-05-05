<?php
// admin/transactions.php - Transactions Management with proper styling

$page_title = 'Transactions Management';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();

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
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    /* Filters */
    .filters {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .filter-group label {
        font-size: 12px;
        color: #64748b;
        font-weight: 500;
    }
    
    .filter-group input, .filter-group select {
        padding: 10px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        min-width: 160px;
        background: #f8fafc;
        transition: all 0.3s;
    }
    
    .filter-group input:focus, .filter-group select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .btn-filter {
        padding: 10px 24px;
        border-radius: 12px;
        border: none;
        background: #667eea;
        color: white;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .btn-filter:hover {
        background: #5a67d8;
        transform: translateY(-2px);
    }
    
    /* Card */
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
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
    
    .card-header h2 {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    .card-header span {
        font-size: 13px;
        color: #64748b;
        background: #f1f5f9;
        padding: 4px 12px;
        border-radius: 20px;
    }
    
    /* Table Styles */
    .table-wrapper {
        overflow-x: auto;
        border-radius: 12px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 14px 12px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    tr:hover {
        background: #f8fafc;
        cursor: pointer;
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
    .badge-secondary { background: #f1f5f9; color: #64748b; }
    
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
    
    .btn-sm:hover {
        transform: translateY(-1px);
    }
    
    .btn-primary { background: #667eea; color: white; }
    
    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
        padding-top: 16px;
        border-top: 1px solid #f1f5f9;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        text-decoration: none;
        color: #475569;
        font-size: 13px;
        transition: all 0.3s;
    }
    
    .pagination a:hover {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    .pagination .active {
        background: #667eea;
        color: white;
        border-color: #667eea;
    }
    
    code {
        background: #f1f5f9;
        padding: 2px 6px;
        border-radius: 6px;
        font-size: 11px;
        font-family: monospace;
    }
    
    @media (max-width: 1024px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters { flex-direction: column; align-items: stretch; }
        .filter-group input, .filter-group select { min-width: auto; }
    }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: 1fr; }
        .card { padding: 16px; }
        th, td { padding: 10px 8px; font-size: 12px; }
    }
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Transactions</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['completed']); ?></div>
        <div class="stat-label">Completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatMoney($stats['total_volume']); ?></div>
        <div class="stat-label">Total Volume</div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filters">
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="">All Status</option>
            <option value="pending_deposit" <?php echo $status == 'pending_deposit' ? 'selected' : ''; ?>>Pending Deposit</option>
            <option value="awaiting_buyer_deposit" <?php echo $status == 'awaiting_buyer_deposit' ? 'selected' : ''; ?>>Awaiting Buyer Deposit</option>
            <option value="awaiting_seller_deposit" <?php echo $status == 'awaiting_seller_deposit' ? 'selected' : ''; ?>>Awaiting Seller Deposit</option>
            <option value="deposits_complete" <?php echo $status == 'deposits_complete' ? 'selected' : ''; ?>>Deposits Complete</option>
            <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="disputed" <?php echo $status == 'disputed' ? 'selected' : ''; ?>>Disputed</option>
            <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="search" placeholder="Buyer, Seller, or Code" value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="filter-group">
        <button type="submit" class="btn-filter">Apply Filter</button>
    </div>
</form>

<!-- Transactions Table -->
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-exchange-alt"></i> All Transactions</h2>
        <span><?php echo number_format($total); ?> transactions</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Buyer</th>
                    <th>Seller</th>
                    <th>Amount</th>
                    <th>Commission</th>
                    <th>Status</th>
                    <th>Code</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transactions && $transactions->num_rows > 0): ?>
                    <?php while($row = $transactions->fetch_assoc()): ?>
                        <tr onclick="location.href='transactions.php?view=<?php echo $row['id']; ?>'">
                            <td>#<?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['buyer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo formatMoney($row['total_amount']); ?></strong></td>
                            <td><?php echo formatMoney($row['commission_amount']); ?></td>
                            <td><?php echo getStatusBadge($row['status']); ?></td>
                            <td><code><?php echo $row['payment_code_5digit'] ?? '-'; ?></code></td>
                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <a href="transactions.php?view=<?php echo $row['id']; ?>" class="btn-sm btn-primary" onclick="event.stopPropagation()">View</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                            No transactions found
                        </td>
                    </tr>
                <?php endif; ?>
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