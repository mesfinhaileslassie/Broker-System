<?php
// admin/release_payment.php - Admin manual payment release

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdminLogin();

$page_title = 'Manual Payment Release';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($transaction_id) {
    $transaction = $conn->query("
        SELECT t.*, l.title, u1.full_name as buyer_name, u2.full_name as seller_name
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        JOIN users u1 ON t.buyer_id = u1.id
        JOIN users u2 ON t.seller_id = u2.id
        WHERE t.id = $transaction_id
    ")->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = intval($_POST['transaction_id']);
    $action = $_POST['action'];
    
    if ($action === 'release') {
        $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
        
        $conn->begin_transaction();
        
        try {
            // Release to seller
            $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$transaction['seller_id']}");
            $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
            $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW(), released_at = NOW() WHERE id = $transaction_id");
            
            // Add wallet transaction
            $conn->query("
                INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                VALUES ({$transaction['seller_id']}, $release_amount, 'deposit', 
                       'Admin released payment for: {$transaction['title']}', NOW())
            ");
            
            $conn->commit();
            $message = "Payment released successfully to {$transaction['seller_name']}";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to release payment: " . $e->getMessage();
        }
    }
}

$conn->close();
?>

<style>
    .release-container { max-width: 800px; margin: 0 auto; }
    .card { background: white; border-radius: 20px; padding: 28px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .info-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
    .btn-release { background: #10b981; color: white; padding: 14px 28px; border: none; border-radius: 40px; font-weight: 600; cursor: pointer; width: 100%; }
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
</style>

<div class="release-container">
    <h1 style="margin-bottom: 20px;">Manual Payment Release</h1>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($transaction): ?>
        <div class="card">
            <h3>Transaction #<?php echo $transaction['id']; ?></h3>
            <div class="info-row">
                <span>Item:</span>
                <strong><?php echo htmlspecialchars($transaction['title']); ?></strong>
            </div>
            <div class="info-row">
                <span>Buyer:</span>
                <span><?php echo htmlspecialchars($transaction['buyer_name']); ?></span>
            </div>
            <div class="info-row">
                <span>Seller:</span>
                <span><?php echo htmlspecialchars($transaction['seller_name']); ?></span>
            </div>
            <div class="info-row">
                <span>Total Amount:</span>
                <strong><?php echo formatMoney($transaction['total_amount']); ?></strong>
            </div>
            <div class="info-row">
                <span>Commission (<?php echo $transaction['commission_percent']; ?>%):</span>
                <span><?php echo formatMoney($transaction['commission_amount']); ?></span>
            </div>
            <div class="info-row">
                <span>Amount to Release:</span>
                <strong style="color: #10b981;"><?php echo formatMoney($transaction['total_amount'] - $transaction['commission_amount']); ?></strong>
            </div>
            <div class="info-row">
                <span>Current Status:</span>
                <span class="badge"><?php echo $transaction['status']; ?></span>
            </div>
            
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                <input type="hidden" name="action" value="release">
                <button type="submit" class="btn-release" onclick="return confirm('Release payment to seller? This action cannot be undone.')">
                    <i class="fas fa-money-bill-wave"></i> Release Payment to Seller
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <p>Enter a transaction ID to release payment:</p>
            <form method="GET">
                <input type="number" name="id" placeholder="Transaction ID" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px;">
                <button type="submit" class="btn-release">Load Transaction</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>