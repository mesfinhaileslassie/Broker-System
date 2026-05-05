<?php
// user/transactions.php - Transactions Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Transactions';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get filter status
$status_filter = $_GET['status'] ?? '';

// Build query
$where = "t.buyer_id = $user_id OR t.seller_id = $user_id";
if ($status_filter) {
    $where .= " AND t.status = '$status_filter'";
}

$transactions = $conn->query("
    SELECT t.*, l.title as listing_title,
           CASE WHEN t.buyer_id = $user_id THEN 'bought' ELSE 'sold' END as action,
           CASE WHEN t.buyer_id = $user_id THEN u2.full_name ELSE u1.full_name END as other_party
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE $where
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<style>
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .filters {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    
    .filter-btn {
        padding: 8px 20px;
        background: white;
        border-radius: 30px;
        text-decoration: none;
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .filter-btn:hover, .filter-btn.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
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
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
    }
    
    tr {
        cursor: pointer;
        transition: background 0.3s;
    }
    
    tr:hover {
        background: #f8fafc;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
        text-decoration: none;
        background: #667eea;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        color: #64748b;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
</style>

<div class="page-header">
    <h1>Transactions</h1>
    <p>View all your buying and selling activity</p>
</div>

<!-- Filters -->
<div class="filters">
    <a href="transactions.php" class="filter-btn <?php echo empty($status_filter) ? 'active' : ''; ?>">All</a>
    <a href="?status=pending_deposit" class="filter-btn <?php echo $status_filter == 'pending_deposit' ? 'active' : ''; ?>">Pending</a>
    <a href="?status=deposits_complete" class="filter-btn <?php echo $status_filter == 'deposits_complete' ? 'active' : ''; ?>">Deposits Complete</a>
    <a href="?status=completed" class="filter-btn <?php echo $status_filter == 'completed' ? 'active' : ''; ?>">Completed</a>
    <a href="?status=disputed" class="filter-btn <?php echo $status_filter == 'disputed' ? 'active' : ''; ?>">Disputed</a>
</div>

<div class="card">
    <div class="table-wrapper">
        <?php if ($transactions && $transactions->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Other Party</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($txn = $transactions->fetch_assoc()): ?>
                        <tr onclick="location.href='transaction.php?id=<?php echo $txn['id']; ?>'">
                            <td>#<?php echo $txn['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars(substr($txn['listing_title'], 0, 35)); ?></strong></td>
                            <td>
                                <span class="badge <?php echo $txn['action'] == 'bought' ? 'badge-info' : 'badge-success'; ?>" style="background: #dbeafe; color: #1e40af;">
                                    <?php echo ucfirst($txn['action']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($txn['other_party']); ?></td>
                            <td><strong><?php echo formatMoney($txn['total_amount']); ?></strong></td>
                            <td><?php echo getStatusBadge($txn['status']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($txn['created_at'])); ?></td>
                            <td><a href="transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm" onclick="event.stopPropagation()">View</a></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-exchange-alt"></i>
                <h3>No transactions yet</h3>
                <p>Start buying or selling to see your transactions here.</p>
                <a href="browse.php" class="btn" style="display: inline-block; margin-top: 16px; background: #667eea; color: white; padding: 10px 24px; border-radius: 40px; text-decoration: none;">Browse Listings</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>