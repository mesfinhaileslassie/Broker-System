<?php
// user/wallet.php - Wallet Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Wallet';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

// Get transaction history
$transactions = $conn->query("
    SELECT * FROM wallet_transactions 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 20
");

$conn->close();
?>

<style>
    .wallet-header {
        margin-bottom: 28px;
    }
    
    .wallet-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .balance-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 24px;
        padding: 32px;
        color: white;
        margin-bottom: 28px;
        text-align: center;
    }
    
    .balance-label {
        font-size: 14px;
        opacity: 0.9;
        margin-bottom: 8px;
    }
    
    .balance-amount {
        font-size: 48px;
        font-weight: 800;
        margin-bottom: 16px;
    }
    
    .action-buttons {
        display: flex;
        gap: 16px;
        justify-content: center;
    }
    
    .action-btn {
        padding: 10px 24px;
        background: rgba(255,255,255,0.2);
        border-radius: 40px;
        text-decoration: none;
        color: white;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .action-btn:hover {
        background: rgba(255,255,255,0.3);
    }
    
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
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .card-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 12px 8px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
    }
    
    .amount-positive {
        color: #10b981;
        font-weight: 600;
    }
    
    .amount-negative {
        color: #ef4444;
        font-weight: 600;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        color: #64748b;
    }
</style>

<div class="wallet-header">
    <h1>My Wallet</h1>
    <p>Manage your funds and transactions</p>
</div>

<!-- Balance Card -->
<div class="balance-card">
    <div class="balance-label">Available Balance</div>
    <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
    <div class="action-buttons">
        <a href="#" class="action-btn" onclick="alert('Use Telebirr to add funds');"><i class="fas fa-plus-circle"></i> Add Funds</a>
        <a href="withdraw.php" class="action-btn"><i class="fas fa-money-bill-wave"></i> Withdraw</a>
    </div>
</div>

<!-- Transaction History -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Transaction History</h3>
    </div>
    <div class="table-wrapper">
        <?php if ($transactions && $transactions->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($txn = $transactions->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime($txn['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($txn['description']); ?></td>
                            <td class="<?php echo $txn['amount'] > 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                <?php echo ($txn['amount'] > 0 ? '+' : '') . formatMoney($txn['amount']); ?>
                            </td>
                            <td><span class="badge badge-success">Completed</span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <p>No transactions yet</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>