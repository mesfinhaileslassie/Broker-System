<?php
// company/transactions.php - Company Transactions

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

if ($_SESSION['user_role'] != 'company') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

$page_title = 'Transactions';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get all transactions for jobs posted by this company
$transactions = $conn->query("
    SELECT t.*, l.title as job_title, u.full_name as applicant_name
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.buyer_id = u.id
    WHERE l.seller_id = $user_id AND l.type = 'job'
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<style>
    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 28px; font-weight: 700; color: #0f172a; }
    .card { background: white; border-radius: 20px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .btn-sm { padding: 6px 12px; background: #667eea; color: white; border-radius: 6px; text-decoration: none; font-size: 11px; }
    .empty-state { text-align: center; padding: 60px; color: #64748b; }
</style>

<div class="page-header">
    <h1>Job Applications & Transactions</h1>
    <p>View all applications for your job posts</p>
</div>

<div class="card">
    <div class="table-wrapper">
        <?php if ($transactions && $transactions->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Job Title</th>
                        <th>Applicant</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Applied Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($txn = $transactions->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($txn['job_title']); ?></strong></td>
                            <td><?php echo htmlspecialchars($txn['applicant_name']); ?></td>
                            <td><?php echo formatMoney($txn['total_amount']); ?></td>
                            <td><?php echo getStatusBadge($txn['status']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($txn['created_at'])); ?></td>
                            <td>
                                <a href="/broker_system/user/transaction.php?id=<?php echo $txn['id']; ?>" class="btn-sm">View Details</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                <p>No transactions yet</p>
                <p style="font-size: 13px; margin-top: 8px;">When users apply for your jobs, they'll appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>