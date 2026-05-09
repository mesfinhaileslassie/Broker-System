<?php
// ============================================
// FILE: admin/escrow_management.php
// ============================================
// Admin Escrow Management Dashboard

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';

if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Escrow Management';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'release' && isset($_POST['release_payment'])) {
        $result = adminReleasePayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['release_notes'] ?? '');
        if ($result['success']) {
            $message = "Payment released successfully!";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'freeze') {
        adminFreezeTransaction($conn, $transaction_id, $_SESSION['user_id'], $_POST['freeze_reason'] ?? '');
        $message = "Transaction frozen successfully.";
    }
    
    if ($action === 'unfreeze') {
        adminUnfreezeTransaction($conn, $transaction_id, $_SESSION['user_id']);
        $message = "Transaction unfrozen successfully.";
    }
}

// Process auto-release queue
$auto_released = processAutoReleaseQueue($conn);

// Get escrow summary
$summary = getEscrowSummary($conn);

// Get active escrow transactions
$active_escrow = $conn->query("
    SELECT t.*, l.title, l.type, u1.full_name as buyer_name, u2.full_name as seller_name,
           ea.amount as escrow_amount,
           eq.scheduled_release_date
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    LEFT JOIN escrow_accounts ea ON t.id = ea.transaction_id AND ea.status = 'held'
    LEFT JOIN escrow_release_queue eq ON t.id = eq.transaction_id AND eq.status = 'pending'
    WHERE t.escrow_status = 'active' OR t.status = 'escrow_active'
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
    
    .escrow-card {
        background: white;
        border-radius: 20px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    .escrow-header {
        padding: 16px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .escrow-body { padding: 20px; }
    .timeline { margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .timeline-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 0;
        font-size: 13px;
    }
    .timeline-dot {
        width: 8px;
        height: 8px;
        background: #667eea;
        border-radius: 50%;
    }
    .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
    .btn { padding: 8px 16px; border-radius: 8px; font-weight: 500; cursor: pointer; border: none; }
    .btn-primary { background: #667eea; color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    .badge-active { background: #dbeafe; color: #1e40af; }
    .badge-frozen { background: #fee2e2; color: #dc2626; }
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
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .alert-success { background: #d1fae5; color: #059669; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
    .alert-error { background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
</style>

<div>
    <h1 style="margin-bottom: 20px;"><i class="fas fa-shield-alt"></i> Escrow Management</h1>
    
    <?php if ($auto_released > 0): ?>
        <div class="alert-success">✓ <?php echo $auto_released; ?> payment(s) auto-released.</div>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <div class="alert-success">✓ <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert-error">⚠️ <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_held']); ?></div>
            <div class="stat-label">Total Escrow Held</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo formatMoney($summary['total_released']); ?></div>
            <div class="stat-label">Total Released</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['active_transactions']; ?></div>
            <div class="stat-label">Active Escrow</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['pending_release']; ?></div>
            <div class="stat-label">Pending Release</div>
        </div>
    </div>
    
    <h2 style="margin-bottom: 20px;">Active Escrow Transactions</h2>
    
    <?php while($txn = $active_escrow->fetch_assoc()): ?>
        <div class="escrow-card">
            <div class="escrow-header">
                <div>
                    <strong>#<?php echo $txn['id']; ?></strong> - <?php echo htmlspecialchars($txn['title']); ?>
                    <span class="badge <?php echo $txn['admin_frozen'] ? 'badge-frozen' : 'badge-active'; ?>" style="margin-left: 10px;">
                        <?php echo $txn['admin_frozen'] ? '❄️ Frozen' : '🟢 Active'; ?>
                    </span>
                </div>
                <div class="price"><?php echo formatMoney($txn['total_amount']); ?></div>
            </div>
            <div class="escrow-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;">
                    <div><small>Buyer:</small><br><strong><?php echo htmlspecialchars($txn['buyer_name']); ?></strong></div>
                    <div><small>Seller:</small><br><strong><?php echo htmlspecialchars($txn['seller_name']); ?></strong></div>
                    <div><small>Escrow Amount:</small><br><strong><?php echo formatMoney($txn['escrow_amount'] ?? 0); ?></strong></div>
                </div>
                
                <?php if ($txn['scheduled_release_date']): ?>
                    <div style="background: #fef3c7; padding: 10px; border-radius: 8px; margin-bottom: 16px;">
                        ⏰ Auto-release scheduled: <?php echo date('M d, Y H:i', strtotime($txn['scheduled_release_date'])); ?>
                    </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="transaction_id" value="<?php echo $txn['id']; ?>">
                        <input type="hidden" name="action" value="release">
                        <button type="button" name="release_payment" class="btn btn-success" onclick="openReleaseModal(<?php echo $txn['id']; ?>, '<?php echo addslashes($txn['title']); ?>')">
                            💰 Release Payment
                        </button>
                    </form>
                    
                    <?php if (!$txn['admin_frozen']): ?>
                        <button onclick="openFreezeModal(<?php echo $txn['id']; ?>, '<?php echo addslashes($txn['title']); ?>')" class="btn btn-warning">
                            ❄️ Freeze Transaction
                        </button>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="transaction_id" value="<?php echo $txn['id']; ?>">
                            <input type="hidden" name="action" value="unfreeze">
                            <button type="submit" class="btn btn-primary">🔥 Unfreeze</button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="transaction_details.php?id=<?php echo $txn['id']; ?>" class="btn btn-primary">📋 View Details</a>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
    
    <?php if ($active_escrow->num_rows == 0): ?>
        <div style="text-align: center; padding: 60px; background: white; border-radius: 20px;">
            <i class="fas fa-shield-alt" style="font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
            <h3>No Active Escrow Transactions</h3>
            <p>All escrow funds have been released or no payments are pending.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;">Release Payment</h3>
        <form method="POST" id="releaseForm">
            <input type="hidden" name="transaction_id" id="releaseTransactionId">
            <input type="hidden" name="action" value="release">
            <div class="form-group">
                <label>Release Notes</label>
                <textarea name="release_notes" rows="3" placeholder="Reason for manual release..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" name="release_payment" class="btn btn-success">Confirm Release</button>
                <button type="button" onclick="closeReleaseModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Freeze Modal -->
<div id="freezeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;">Freeze Transaction</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="freezeTransactionId">
            <input type="hidden" name="action" value="freeze">
            <div class="form-group">
                <label>Reason for Freezing</label>
                <textarea name="freeze_reason" rows="3" placeholder="Enter reason for freezing this transaction..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-warning">Confirm Freeze</button>
                <button type="button" onclick="closeFreezeModal()" class="btn" style="background: #e2e8f0;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReleaseModal(transactionId, title) {
    document.getElementById('releaseTransactionId').value = transactionId;
    document.getElementById('releaseModal').style.display = 'flex';
}

function closeReleaseModal() {
    document.getElementById('releaseModal').style.display = 'none';
}

function openFreezeModal(transactionId, title) {
    document.getElementById('freezeTransactionId').value = transactionId;
    document.getElementById('freezeModal').style.display = 'flex';
}

function closeFreezeModal() {
    document.getElementById('freezeModal').style.display = 'none';
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