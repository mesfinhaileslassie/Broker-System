<?php
// admin/payments.php - Payment Management

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';

// Get all payments with transaction details
$sql = "SELECT p.*, t.payment_code_5digit, t.status as transaction_status,
        u.full_name as user_name, 
        CASE WHEN p.type = 'deposit_buyer' THEN 'Buyer Deposit'
             WHEN p.type = 'deposit_seller' THEN 'Seller Deposit'
             WHEN p.type = 'commission' THEN 'System Commission'
             WHEN p.type = 'remaining_balance' THEN 'Remaining Balance'
             WHEN p.type = 'release_to_seller' THEN 'Released to Seller'
        END as payment_type_name
        FROM payments p
        JOIN transactions t ON p.transaction_id = t.id
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC
        LIMIT 50";
$payments = $conn->query($sql);

$stats = [
    'total' => $conn->query("SELECT SUM(amount) as total FROM payments WHERE status = 'confirmed'")->fetch_assoc()['total'] ?? 0,
    'escrow' => $conn->query("SELECT SUM(escrow_held) as total FROM transactions WHERE status NOT IN ('completed', 'cancelled')")->fetch_assoc()['total'] ?? 0,
    'commission' => $conn->query("SELECT SUM(amount) as total FROM payments WHERE type = 'commission' AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0,
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Admin Panel</title>
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
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card .value { font-size: 28px; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 14px; }
        .section { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar">
            <div class="sidebar-header"><h2>🏪 Brokerplace</h2><p>Admin Dashboard</p></div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item active"><i class="fas fa-credit-card"></i> Payments</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Payment Management</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="value"><?php echo formatMoney($stats['total']); ?></div><div class="label">Total Processed</div></div>
                <div class="stat-card"><div class="value"><?php echo formatMoney($stats['escrow']); ?></div><div class="label">Escrow Held</div></div>
                <div class="stat-card"><div class="value"><?php echo formatMoney($stats['commission']); ?></div><div class="label">Commission Earned</div></div>
            </div>
            
            <div class="section">
                <div class="section-title">Recent Payments</div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>User</th><th>Type</th><th>Amount</th><th>Transaction</th><th>Status</th><th>Telebirr Code</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                            <?php while($row = $payments->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                                    <td><?php echo $row['payment_type_name']; ?></td>
                                    <td><?php echo formatMoney($row['amount']); ?></td>
                                    <td>#<?php echo $row['transaction_id']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $row['status'] == 'confirmed' ? 'badge-success' : 'badge-warning'; ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                     </td>
                                    <td><code><?php echo $row['telebirr_code_5digit'] ?? '-'; ?></code></td>
                                    <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>