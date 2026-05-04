<?php
// user/wallet.php - Wallet management

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'];

// Get wallet transactions
$transactions = $conn->query("
    SELECT * FROM wallet_transactions 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 20
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallet - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 1000px; margin: 40px auto; padding: 0 24px; }
        .balance-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 16px; padding: 32px; margin-bottom: 24px; text-align: center; }
        .balance-label { font-size: 14px; opacity: 0.9; margin-bottom: 8px; }
        .balance-amount { font-size: 48px; font-weight: 700; }
        .action-buttons { display: flex; gap: 16px; justify-content: center; margin-top: 24px; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-deposit { background: #ffc107; color: #333; }
        .btn-withdraw { background: white; color: #667eea; }
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h2 { margin-bottom: 20px; color: #333; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        .amount-positive { color: #28a745; font-weight: 600; }
        .amount-negative { color: #dc3545; font-weight: 600; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="dashboard.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>
    
    <div class="container">
        <div class="balance-card">
            <div class="balance-label">Available Balance</div>
            <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
            <div class="action-buttons">
                <a href="#" class="btn btn-deposit" onclick="alert('Use Telebirr to add funds to your account. Contact support for assistance.');"><i class="fas fa-plus-circle"></i> Add Funds</a>
                <a href="withdraw.php" class="btn btn-withdraw"><i class="fas fa-money-bill-wave"></i> Withdraw Funds</a>
            </div>
        </div>
        
        <div class="card">
            <h2><i class="fas fa-history"></i> Transaction History</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Date</th><th>Description</th><th>Amount</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php while($txn = $transactions->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i', strtotime($txn['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($txn['description']); ?></td>
                                <td class="<?php echo $txn['amount'] > 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                    <?php echo ($txn['amount'] > 0 ? '+' : '') . formatMoney($txn['amount']); ?>
                                </td>
                                <td><span class="badge badge-completed">Completed</span></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>