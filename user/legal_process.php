<?php
// user/legal_process.php - Legal Process Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Legal Process';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get transactions pending legal confirmation
$pending_legal = $conn->query("
    SELECT t.id, l.title, t.total_amount,
           CASE WHEN t.buyer_id = $user_id THEN 'buyer' ELSE 'seller' END as my_role,
           t.buyer_legal_confirmed, t.seller_legal_confirmed
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id)
    AND t.status = 'deposits_complete'
    AND ((t.buyer_legal_confirmed = 0 AND t.buyer_id = $user_id) OR
         (t.seller_legal_confirmed = 0 AND t.seller_id = $user_id))
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
    
    .legal-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .legal-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    }
    
    .legal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .legal-title {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    .legal-amount {
        font-size: 20px;
        font-weight: 700;
        color: #667eea;
    }
    
    .status-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .status-row:last-child {
        border-bottom: none;
    }
    
    .btn {
        padding: 10px 24px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        display: inline-block;
        transition: all 0.3s;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
    
    .badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .badge-success {
        background: #d1fae5;
        color: #059669;
    }
    
    .badge-warning {
        background: #fed7aa;
        color: #ea580c;
    }
</style>

<div class="page-header">
    <h1>Legal Process</h1>
    <p>Complete legal documentation and confirmations for your transactions</p>
</div>

<?php if ($pending_legal && $pending_legal->num_rows > 0): ?>
    <?php while($legal = $pending_legal->fetch_assoc()): ?>
        <div class="legal-card">
            <div class="legal-header">
                <div class="legal-title">Transaction #<?php echo $legal['id']; ?> - <?php echo htmlspecialchars($legal['title']); ?></div>
                <div class="legal-amount"><?php echo formatMoney($legal['total_amount']); ?></div>
            </div>
            
            <div class="status-row">
                <span><i class="fas fa-user"></i> Your Role:</span>
                <span class="badge badge-info"><?php echo ucfirst($legal['my_role']); ?></span>
            </div>
            <div class="status-row">
                <span><i class="fas fa-gavel"></i> Your Legal Status:</span>
                <span>
                    <?php 
                    $my_confirmed = ($legal['my_role'] == 'buyer') ? $legal['buyer_legal_confirmed'] : $legal['seller_legal_confirmed'];
                    if ($my_confirmed):
                        echo '<span class="badge badge-success">✓ Confirmed</span>';
                    else:
                        echo '<span class="badge badge-warning">⏳ Pending</span>';
                    endif;
                    ?>
                </span>
            </div>
            <div class="status-row">
                <span><i class="fas fa-store"></i> Other Party Status:</span>
                <span>
                    <?php 
                    $other_confirmed = ($legal['my_role'] == 'buyer') ? $legal['seller_legal_confirmed'] : $legal['buyer_legal_confirmed'];
                    if ($other_confirmed):
                        echo '<span class="badge badge-success">✓ Confirmed</span>';
                    else:
                        echo '<span class="badge badge-warning">⏳ Pending</span>';
                    endif;
                    ?>
                </span>
            </div>
            
            <?php if (!$my_confirmed): ?>
                <div style="margin-top: 20px;">
                    <a href="transaction.php?id=<?php echo $legal['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-file-signature"></i> Complete Legal Process
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-check-circle"></i>
        <h3>No pending legal processes</h3>
        <p>All your transactions have completed legal confirmation.</p>
        <a href="dashboard.php" class="btn btn-primary" style="margin-top: 16px;">Go to Dashboard</a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>