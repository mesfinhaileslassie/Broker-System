<?php
// user/wallet.php - Complete Wallet Management with Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'My Wallet';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

// Get wallet transactions
$transactions = $conn->query("
    SELECT * FROM wallet_transactions 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 50
");

// Get pending withdrawals
$pending_withdrawals = $conn->query("
    SELECT * FROM withdrawal_requests 
    WHERE user_id = $user_id AND status = 'pending'
    ORDER BY created_at DESC
");

$conn->close();
?>

<style>
    .wallet-container { max-width: 1200px; margin: 0 auto; }
    .balance-card { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 28px; padding: 32px; color: white; margin-bottom: 28px; text-align: center; }
    .balance-label { font-size: 14px; opacity: 0.9; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; }
    .balance-amount { font-size: 56px; font-weight: 800; margin-bottom: 16px; }
    .balance-actions { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
    .action-btn { padding: 12px 28px; background: rgba(255,255,255,0.2); border-radius: 40px; text-decoration: none; color: white; font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; }
    .action-btn:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); }
    
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 24px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }
    
    .card { background: white; border-radius: 20px; padding: 24px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
    .card-header h3 { font-size: 18px; font-weight: 600; color: #0f172a; }
    
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; }
    
    .amount-positive { color: #10b981; font-weight: 600; }
    .amount-negative { color: #ef4444; font-weight: 600; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #1e40af; }
    .badge-completed { background: #d1fae5; color: #059669; }
    
    .empty-state { text-align: center; padding: 60px; color: #64748b; }
    .empty-state i { font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: 1fr; }
        .balance-amount { font-size: 36px; }
        .card-header { flex-direction: column; align-items: flex-start; gap: 12px; }
    }
</style>

<div class="wallet-container">
    <!-- Balance Card -->
    <div class="balance-card">
        <div class="balance-label">Available Balance</div>
        <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
        <div class="balance-actions">
            <a href="add_funds.php" class="action-btn"><i class="fas fa-plus-circle"></i> Add Funds</a>
            <a href="withdraw.php" class="action-btn"><i class="fas fa-money-bill-wave"></i> Withdraw</a>
            <a href="transactions.php" class="action-btn"><i class="fas fa-history"></i> History</a>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($transactions->num_rows); ?></div>
            <div class="stat-label">Total Transactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($pending_withdrawals->num_rows); ?></div>
            <div class="stat-label">Pending Withdrawals</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($balance); ?></div>
            <div class="stat-label">Current Balance</div>
        </div>
    </div>
    
    <!-- Pending Withdrawals -->
    <?php if ($pending_withdrawals && $pending_withdrawals->num_rows > 0): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock"></i> Pending Withdrawals</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Date</th><th>Amount</th><th>Bank</th><th>Account</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php while($wd = $pending_withdrawals->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($wd['created_at'])); ?></td>
                        <td class="amount-negative">-<?php echo formatMoney($wd['amount']); ?></td>
                        <td><?php echo htmlspecialchars($wd['bank_name']); ?></td>
                        <td><?php echo htmlspecialchars(substr($wd['account_number'], -4)); ?></td>
                        <td><span class="badge badge-pending">Pending</span></td>
                        <td><a href="withdraw.php?cancel=<?php echo $wd['id']; ?>" class="btn-sm" style="background: #ef4444; color: white; padding: 4px 10px; border-radius: 6px; text-decoration: none;" onclick="return confirm('Cancel this withdrawal request?')">Cancel</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Transaction History -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Recent Transactions</h3>
            <a href="transactions.php" style="font-size: 12px; color: #667eea;">View All →</a>
        </div>
        <div class="table-wrapper">
            <?php if ($transactions && $transactions->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr><th>Date</th><th>Description</th><th>Amount</th><th>Type</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php while($txn = $transactions->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($txn['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($txn['description']); ?></td>
                            <td class="<?php echo $txn['amount'] > 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                <?php echo ($txn['amount'] > 0 ? '+' : '') . formatMoney($txn['amount']); ?>
                            </td>
                            <td><span class="badge" style="background: #e0e7ff; color: #4f46e5;"><?php echo ucfirst($txn['type']); ?></span></td>
                            <td><span class="badge badge-completed">Completed</span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No transactions yet</p>
                    <p style="font-size: 12px; margin-top: 8px;">When you make payments or receive funds, they'll appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>