<?php
// admin/withdrawal_approval.php - Admin approve/reject withdrawals

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Withdrawal Approvals';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $withdrawal_id = intval($_POST['withdrawal_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    if ($action === 'approve') {
        $wd = $conn->query("SELECT user_id, amount FROM withdrawal_requests WHERE id = $withdrawal_id")->fetch_assoc();
        
        $conn->begin_transaction();
        try {
            $conn->query("
                UPDATE withdrawal_requests 
                SET status = 'approved', admin_notes = '$admin_notes', processed_by = {$_SESSION['user_id']}, processed_at = NOW()
                WHERE id = $withdrawal_id
            ");
            
            // Update wallet transaction
            $conn->query("
                UPDATE wallet_transactions 
                SET type = 'withdrawal_approved', description = 'Withdrawal approved by admin'
                WHERE user_id = {$wd['user_id']} AND amount = {$wd['amount']} AND type = 'withdrawal_pending'
                ORDER BY id DESC LIMIT 1
            ");
            
            // Notify user
            $conn->query("
                INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$wd['user_id']}, '✅ Withdrawal Approved', 'Your withdrawal of " . formatMoney($wd['amount']) . " has been approved and will be processed shortly.', NOW())
            ");
            
            $conn->commit();
            $message = "Withdrawal approved successfully";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to approve withdrawal";
        }
    }
    
    if ($action === 'reject') {
        $wd = $conn->query("SELECT user_id, amount FROM withdrawal_requests WHERE id = $withdrawal_id")->fetch_assoc();
        
        $conn->begin_transaction();
        try {
            // Refund amount back to user
            $conn->query("UPDATE users SET balance = balance + {$wd['amount']} WHERE id = {$wd['user_id']}");
            
            $conn->query("
                UPDATE withdrawal_requests 
                SET status = 'rejected', admin_notes = '$admin_notes', processed_by = {$_SESSION['user_id']}, processed_at = NOW()
                WHERE id = $withdrawal_id
            ");
            
            // Update wallet transaction
            $conn->query("
                UPDATE wallet_transactions 
                SET type = 'withdrawal_rejected', description = 'Withdrawal rejected: $admin_notes'
                WHERE user_id = {$wd['user_id']} AND amount = {$wd['amount']} AND type = 'withdrawal_pending'
                ORDER BY id DESC LIMIT 1
            ");
            
            // Notify user
            $conn->query("
                INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$wd['user_id']}, '❌ Withdrawal Rejected', 'Your withdrawal request was rejected. Reason: $admin_notes', NOW())
            ");
            
            $conn->commit();
            $message = "Withdrawal rejected and amount refunded";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to reject withdrawal";
        }
    }
}

// Get pending withdrawals
$pending_withdrawals = $conn->query("
    SELECT w.*, u.full_name, u.email, u.phone
    FROM withdrawal_requests w
    JOIN users u ON w.user_id = u.id
    WHERE w.status = 'pending'
    ORDER BY w.created_at ASC
");

// Get approved/completed withdrawals
$completed_withdrawals = $conn->query("
    SELECT w.*, u.full_name, u.email
    FROM withdrawal_requests w
    JOIN users u ON w.user_id = u.id
    WHERE w.status IN ('approved', 'completed', 'rejected')
    ORDER BY w.created_at DESC
    LIMIT 20
");

$stats = [
    'pending' => $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc()['count'],
    'pending_amount' => $conn->query("SELECT SUM(amount) as total FROM withdrawal_requests WHERE status = 'pending'")->fetch_assoc()['total'] ?? 0,
    'approved_today' => $conn->query("SELECT COUNT(*) as count FROM withdrawal_requests WHERE status = 'approved' AND DATE(processed_at) = CURDATE()")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }
    
    .withdrawal-card {
        background: white;
        border-radius: 20px;
        margin-bottom: 20px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    .card-header {
        padding: 16px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .card-body { padding: 20px; }
    
    .btn-group { display: flex; gap: 10px; margin-top: 16px; }
    .btn {
        padding: 8px 16px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        border: none;
    }
    .btn-success { background: #10b981; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .modal-content {
        background: white;
        border-radius: 20px;
        padding: 24px;
        width: 450px;
        max-width: 90%;
    }
    .form-group { margin-bottom: 16px; }
    .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
</style>

<div>
    <h1 style="margin-bottom: 20px;"><i class="fas fa-money-bill-wave"></i> Withdrawal Approvals</h1>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($stats['pending_amount']); ?></div>
            <div class="stat-label">Pending Amount</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['approved_today']; ?></div>
            <div class="stat-label">Approved Today</div>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✓ <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <h2 style="margin: 24px 0 16px;">Pending Withdrawals</h2>
    
    <?php if ($pending_withdrawals->num_rows > 0): ?>
        <?php while($wd = $pending_withdrawals->fetch_assoc()): ?>
            <div class="withdrawal-card">
                <div class="card-header">
                    <div>
                        <strong><?php echo htmlspecialchars($wd['full_name']); ?></strong><br>
                        <small><?php echo htmlspecialchars($wd['email']); ?></small>
                    </div>
                    <div class="amount" style="font-size: 20px; font-weight: 700; color: #667eea;">
                        <?php echo formatMoney($wd['amount']); ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <span>Bank:</span>
                        <strong><?php echo htmlspecialchars($wd['bank_name']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Account Number:</span>
                        <strong><?php echo htmlspecialchars($wd['account_number']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Account Name:</span>
                        <strong><?php echo htmlspecialchars($wd['account_name']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Requested:</span>
                        <span><?php echo date('M d, Y H:i', strtotime($wd['created_at'])); ?></span>
                    </div>
                    
                    <div class="btn-group">
                        <button onclick="openActionModal('approve', <?php echo $wd['id']; ?>)" class="btn btn-success">
                            <i class="fas fa-check"></i> Approve Withdrawal
                        </button>
                        <button onclick="openActionModal('reject', <?php echo $wd['id']; ?>)" class="btn btn-danger">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; background: white; border-radius: 20px;">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 16px; display: block;"></i>
            <p>No pending withdrawal requests.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Action Modal -->
<div id="actionModal" class="modal">
    <div class="modal-content">
        <h3 id="modalTitle" style="margin-bottom: 16px;">Process Withdrawal</h3>
        <form method="POST">
            <input type="hidden" name="withdrawal_id" id="withdrawalId">
            <input type="hidden" name="action" id="actionType">
            <div class="form-group">
                <label>Admin Notes</label>
                <textarea name="admin_notes" rows="3" placeholder="Add notes about this decision..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" id="actionButton" class="btn btn-success">Confirm</button>
                <button type="button" onclick="closeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openActionModal(action, id) {
    document.getElementById('withdrawalId').value = id;
    document.getElementById('actionType').value = action;
    const modalTitle = document.getElementById('modalTitle');
    const actionButton = document.getElementById('actionButton');
    
    if (action === 'approve') {
        modalTitle.innerHTML = '<i class="fas fa-check"></i> Approve Withdrawal';
        actionButton.innerHTML = 'Approve';
        actionButton.className = 'btn btn-success';
    } else {
        modalTitle.innerHTML = '<i class="fas fa-times"></i> Reject Withdrawal';
        actionButton.innerHTML = 'Reject';
        actionButton.className = 'btn btn-danger';
    }
    document.getElementById('actionModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('actionModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>