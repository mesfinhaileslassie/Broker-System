<?php
// admin/resolve_dispute.php - Admin resolves dispute

require_once '../config/database.php';
require_once '../includes/auth.php';
requireAdminLogin();

$conn = getDbConnection();
$dispute_id = intval($_GET['id'] ?? 0);
$dispute = $conn->query("
    SELECT d.*, t.buyer_id, t.seller_id, t.total_amount, t.commission_amount, t.escrow_held
    FROM disputes d
    JOIN transactions t ON d.transaction_id = t.id
    WHERE d.id = $dispute_id
")->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'];
    $refund_to = $_POST['refund_to']; // 'buyer', 'seller', 'both'
    $admin_notes = $_POST['admin_notes'];
    $admin_id = $_SESSION['admin_id'];
    
    $conn->begin_transaction();
    
    try {
        // Update dispute
        $conn->query("
            UPDATE disputes 
            SET status = 'resolved', admin_decision = '$decision', decision_notes = '$admin_notes', resolved_at = NOW() 
            WHERE id = $dispute_id
        ");
        
        if ($decision == 'refund') {
            // Calculate refund amount (minus commission)
            $commission = $dispute['commission_amount'];
            $refund_amount = $dispute['total_amount'] - $commission;
            
            if ($refund_to == 'buyer') {
                $conn->query("UPDATE users SET balance = balance + $refund_amount WHERE id = {$dispute['buyer_id']}");
                $conn->query("UPDATE users SET admin_balance = admin_balance - $refund_amount WHERE role = 'admin'");
            } elseif ($refund_to == 'seller') {
                $conn->query("UPDATE users SET balance = balance + $refund_amount WHERE id = {$dispute['seller_id']}");
                $conn->query("UPDATE users SET admin_balance = admin_balance - $refund_amount WHERE role = 'admin'");
            } elseif ($refund_to == 'both') {
                $half = $refund_amount / 2;
                $conn->query("UPDATE users SET balance = balance + $half WHERE id = {$dispute['buyer_id']}");
                $conn->query("UPDATE users SET balance = balance + $half WHERE id = {$dispute['seller_id']}");
                $conn->query("UPDATE users SET admin_balance = admin_balance - $refund_amount WHERE role = 'admin'");
            }
            
            $conn->query("UPDATE transactions SET status = 'cancelled', escrow_released = 1 WHERE id = {$dispute['transaction_id']}");
        } elseif ($decision == 'release') {
            // Release payment to seller
            $release_amount = $dispute['total_amount'] - $dispute['commission_amount'];
            $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$dispute['seller_id']}");
            $conn->query("UPDATE users SET admin_balance = admin_balance - $release_amount WHERE role = 'admin'");
            $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW(), escrow_released = 1 WHERE id = {$dispute['transaction_id']}");
        }
        
        $conn->commit();
        $message = "Dispute resolved successfully";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to resolve dispute: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Resolve Dispute</title>
    <style>
        body { font-family: Arial; padding: 20px; max-width: 600px; margin: 0 auto; }
        .card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Resolve Dispute #<?php echo $dispute_id; ?></h1>
        
        <div class="info">
            <p><strong>Transaction ID:</strong> #<?php echo $dispute['transaction_id']; ?></p>
            <p><strong>Amount:</strong> <?php echo number_format($dispute['total_amount'], 2); ?> ETB</p>
            <p><strong>Commission:</strong> <?php echo number_format($dispute['commission_amount'], 2); ?> ETB</p>
            <p><strong>Reason:</strong> <?php echo htmlspecialchars($dispute['reason']); ?></p>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label>Decision</label>
                <select name="decision" required onchange="toggleRefundTo(this.value)">
                    <option value="release">Release Payment to Seller</option>
                    <option value="refund">Refund to User(s) (minus commission)</option>
                </select>
            </div>
            
            <div class="form-group" id="refundToGroup" style="display: none;">
                <label>Refund To</label>
                <select name="refund_to">
                    <option value="buyer">Refund to Buyer Only</option>
                    <option value="seller">Refund to Seller Only</option>
                    <option value="both">Refund to Both (50/50)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Admin Notes</label>
                <textarea name="admin_notes" rows="3" required></textarea>
            </div>
            
            <button type="submit">Resolve Dispute</button>
        </form>
    </div>
    
    <script>
        function toggleRefundTo(value) {
            const group = document.getElementById('refundToGroup');
            group.style.display = value === 'refund' ? 'block' : 'none';
        }
    </script>
</body>
</html>