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
           t.buyer_legal_confirmed, t.seller_legal_confirmed,
           t.status
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    WHERE (t.buyer_id = $user_id OR t.seller_id = $user_id)
    AND t.status = 'deposits_complete'
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
    
    .page-header p {
        color: #64748b;
        font-size: 14px;
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
        border: none;
        cursor: pointer;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
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
    
    .badge-info {
        background: #dbeafe;
        color: #1e40af;
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
    
    .empty-state h3 {
        font-size: 20px;
        color: #334155;
        margin-bottom: 8px;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    @media (max-width: 768px) {
        .legal-header {
            flex-direction: column;
            align-items: flex-start;
        }
    </style>

<div class="page-header">
    <h1>Legal Process</h1>
    <p>Complete legal documentation and confirmations for your transactions</p>
</div>

<?php if ($pending_legal && $pending_legal->num_rows > 0): ?>
    <?php while($legal = $pending_legal->fetch_assoc()): 
        $my_confirmed = ($legal['my_role'] == 'buyer') ? $legal['buyer_legal_confirmed'] : $legal['seller_legal_confirmed'];
        $other_confirmed = ($legal['my_role'] == 'buyer') ? $legal['seller_legal_confirmed'] : $legal['buyer_legal_confirmed'];
    ?>
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
                    <?php if ($my_confirmed): ?>
                        <span class="badge badge-success">✓ Confirmed</span>
                    <?php else: ?>
                        <span class="badge badge-warning">⏳ Pending</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="status-row">
                <span><i class="fas fa-store"></i> Other Party Status:</span>
                <span>
                    <?php if ($other_confirmed): ?>
                        <span class="badge badge-success">✓ Confirmed</span>
                    <?php else: ?>
                        <span class="badge badge-warning">⏳ Pending</span>
                    <?php endif; ?>
                </span>
            </div>
            
            <?php if (!$my_confirmed): ?>
                <div style="margin-top: 20px;">
                    <a href="transaction.php?id=<?php echo $legal['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-file-signature"></i> Complete Legal Process
                    </a>
                </div>
            <?php else: ?>
                <div style="margin-top: 20px;">
                    <span class="badge badge-success" style="background: #d1fae5; color: #059669; padding: 8px 16px;">
                        <i class="fas fa-check-circle"></i> You have completed the legal process
                    </span>
                    <?php if (!$other_confirmed): ?>
                        <p style="margin-top: 12px; font-size: 13px; color: #64748b;">
                            Waiting for the other party to complete their legal confirmation.
                        </p>
                    <?php else: ?>
                        <a href="transaction.php?id=<?php echo $legal['id']; ?>" class="btn btn-success" style="margin-top: 12px;">
                            <i class="fas fa-truck"></i> Proceed to Delivery Confirmation
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-check-circle"></i>
        <h3>No pending legal processes</h3>
        <p>All your transactions have completed legal confirmation or are awaiting deposits.</p>
        <a href="dashboard.php" class="btn btn-primary" style="margin-top: 16px;">Go to Dashboard</a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>