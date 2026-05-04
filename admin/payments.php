<?php
// admin/payments.php - Payments Overview

$page_title = 'Payments Overview';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();

$sql = "SELECT p.*, t.payment_code_5digit, t.status as transaction_status, u.full_name as user_name,
        CASE WHEN p.type = 'deposit_buyer' THEN 'Buyer Deposit'
             WHEN p.type = 'deposit_seller' THEN 'Seller Deposit'
             WHEN p.type = 'commission' THEN 'System Commission'
             WHEN p.type = 'remaining_balance' THEN 'Remaining Balance'
             WHEN p.type = 'release_to_seller' THEN 'Released to Seller'
             ELSE p.type END as payment_type_name
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

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['total']); ?></div><div class="stat-label">Total Processed</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['escrow']); ?></div><div class="stat-label">Escrow Held</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo formatMoney($stats['commission']); ?></div><div class="stat-label">Commission Earned</div></div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-credit-card"></i> Recent Payments</h2></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>ID</th><th>User</th><th>Type</th><th>Amount</th><th>Transaction</th><th>Status</th><th>Telebirr Code</th><th>Date</th></tr></thead>
            <tbody>
                <?php while($row = $payments->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                    <td><?php echo $row['payment_type_name']; ?></td>
                    <td><?php echo formatMoney($row['amount']); ?></td>
                    <td>#<?php echo $row['transaction_id']; ?></td>
                    <td><span class="badge badge-success"><?php echo $row['status']; ?></span></td>
                    <td><code><?php echo $row['telebirr_code_5digit'] ?? '-'; ?></code></td>
                    <td><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>