<?php
// admin/escrow_management.php - Complete Admin Escrow Dashboard

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
    
    if ($action === 'release') {
        $result = adminReleasePayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['release_notes'] ?? '');
        if ($result['success']) {
            $message = "✓ Payment released successfully!";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'freeze') {
        adminFreezeTransaction($conn, $transaction_id, $_SESSION['user_id'], $_POST['freeze_reason'] ?? '');
        $message = "❄️ Transaction frozen successfully.";
    }
    
    if ($action === 'unfreeze') {
        adminUnfreezeTransaction($conn, $transaction_id, $_SESSION['user_id']);
        $message = "🔥 Transaction unfrozen successfully.";
    }
    
    if ($action === 'refund') {
        $result = refundEscrowPayment($conn, $transaction_id, $_SESSION['user_id'], $_POST['refund_notes'] ?? '');
        if ($result['success']) {
            $message = "💰 Refund processed successfully.";
        } else {
            $error = $result['error'];
        }
    }
}

// Process auto-release queue
$auto_released = processAutoReleaseQueue($conn);

// Get escrow summary
$summary = getEscrowSummary($conn);

// Get all escrow transactions
$escrow_transactions = $conn->query("
    SELECT t.*, l.title, l.type, 
           u1.full_name as buyer_name, u2.full_name as seller_name,
           ea.amount as escrow_amount,
           eq.scheduled_release_date,
           (SELECT COUNT(*) FROM transaction_timeline tt WHERE tt.transaction_id = t.id) as timeline_count
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    LEFT JOIN escrow_accounts ea ON t.id = ea.transaction_id AND ea.status = 'held'
    LEFT JOIN escrow_release_queue eq ON t.id = eq.transaction_id AND eq.status = 'pending'
    WHERE t.escrow_status IN ('active', 'released') OR t.status IN ('escrow_active', 'completed')
    ORDER BY t.created_at DESC
");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Escrow Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        
        .admin-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 28px;
            color: white;
        }
        .header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        
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
            border: 1px solid #e2e8f0;
        }
        .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }
        
        .escrow-card {
            background: white;
            border-radius: 24px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .escrow-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .escrow-body { padding: 20px 24px; }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-active { background: #dbeafe; color: #1e40af; }
        .badge-frozen { background: #fee2e2; color: #dc2626; }
        .badge-completed { background: #d1fae5; color: #059669; }
        .badge-pending { background: #fed7aa; color: #ea580c; }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-row:last-child { border-bottom: none; }
        
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
        
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
            border-radius: 24px;
            padding: 28px;
            width: 500px;
            max-width: 90%;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
        
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #059669; }
        .alert-error { background: #fee2e2; color: #dc2626; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .escrow-header { flex-direction: column; align-items: flex-start; }
            .btn-group { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="header">
        <h1><i class="fas fa-shield-alt"></i> Escrow Management Dashboard</h1>
        <p>Monitor and manage all escrow transactions</p>
    </div>
    
    <?php if ($auto_released > 0): ?>
        <div class="alert alert-success">✓ <?php echo $auto_released; ?> payment(s) auto-released.</div>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✓ <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo $error; ?></div>
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
    
    <h2 style="margin-bottom: 20px;">All Escrow Transactions</h2>
    
    <?php while($txn = $escrow_transactions->fetch_assoc()): ?>
        <div class="escrow-card">
            <div class="escrow-header">
                <div>
                    <strong>#<?php echo $txn['id']; ?></strong> - <?php echo htmlspecialchars($txn['title']); ?>
                    <span class="badge <?php 
                        if ($txn['admin_frozen']) echo 'badge-frozen';
                        elseif ($txn['status'] == 'completed') echo 'badge-completed';
                        elseif ($txn['escrow_status'] == 'active') echo 'badge-active';
                        else echo 'badge-pending';
                    ?>" style="margin-left: 10px;">
                        <?php 
                        if ($txn['admin_frozen']) echo '❄️ Frozen';
                        elseif ($txn['status'] == 'completed') echo '✓ Completed';
                        elseif ($txn['escrow_status'] == 'active') echo '💰 Escrow Active';
                        else echo '⏳ Pending';
                        ?>
                    </span>
                </div>
                <div><strong><?php echo formatMoney($txn['total_amount']); ?></strong></div>
            </div>
            <div class="escrow-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 16px;">
                    <div><small>Buyer:</small><br><strong><?php echo htmlspecialchars($txn['buyer_name']); ?></strong></div>
                    <div><small>Seller:</small><br><strong><?php echo htmlspecialchars($txn['seller_name']); ?></strong></div>
                    <div><small>Escrow Amount:</small><br><strong><?php echo formatMoney($txn['escrow_amount'] ?? 0); ?></strong></div>
                </div>
                
                <div class="info-row">
                    <span>Delivery Status:</span>
                    <span><?php echo ucfirst($txn['delivery_status'] ?? 'pending'); ?></span>
                </div>
                <div class="info-row">
                    <span>Created:</span>
                    <span><?php echo date('M d, Y H:i', strtotime($txn['created_at'])); ?></span>
                </div>
                <?php if ($txn['scheduled_release_date']): ?>
                <div class="info-row">
                    <span>Auto-Release:</span>
                    <span><?php echo date('M d, Y', strtotime($txn['scheduled_release_date'])); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <?php if ($txn['escrow_status'] == 'active' && !$txn['admin_frozen']): ?>
                        <button onclick="openReleaseModal(<?php echo $txn['id']; ?>)" class="btn btn-success">
                            <i class="fas fa-money-bill-wave"></i> Release Payment
                        </button>
                        <button onclick="openFreezeModal(<?php echo $txn['id']; ?>)" class="btn btn-warning">
                            <i class="fas fa-ice-cream"></i> Freeze
                        </button>
                        <button onclick="openRefundModal(<?php echo $txn['id']; ?>)" class="btn btn-danger">
                            <i class="fas fa-undo"></i> Refund Buyer
                        </button>
                    <?php elseif ($txn['admin_frozen']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="transaction_id" value="<?php echo $txn['id']; ?>">
                            <input type="hidden" name="action" value="unfreeze">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-fire"></i> Unfreeze
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="/broker_system/user/transaction.php?id=<?php echo $txn['id']; ?>" target="_blank" class="btn btn-outline">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
    
    <?php if ($escrow_transactions->num_rows == 0): ?>
        <div style="text-align: center; padding: 60px; background: white; border-radius: 20px;">
            <i class="fas fa-shield-alt" style="font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
            <h3>No Escrow Transactions</h3>
            <p>No escrow transactions found in the system.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-money-bill-wave"></i> Release Payment</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="releaseTransactionId">
            <input type="hidden" name="action" value="release">
            <div class="form-group">
                <label>Release Notes</label>
                <textarea name="release_notes" rows="3" placeholder="Reason for manual release..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-success">Confirm Release</button>
                <button type="button" onclick="closeReleaseModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Freeze Modal -->
<div id="freezeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-ice-cream"></i> Freeze Transaction</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="freezeTransactionId">
            <input type="hidden" name="action" value="freeze">
            <div class="form-group">
                <label>Reason for Freezing</label>
                <textarea name="freeze_reason" rows="3" placeholder="Enter reason for freezing this transaction..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-warning">Confirm Freeze</button>
                <button type="button" onclick="closeFreezeModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Refund Modal -->
<div id="refundModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-undo"></i> Refund Buyer</h3>
        <form method="POST">
            <input type="hidden" name="transaction_id" id="refundTransactionId">
            <input type="hidden" name="action" value="refund">
            <div class="form-group">
                <label>Refund Notes</label>
                <textarea name="refund_notes" rows="3" placeholder="Reason for refund..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-danger">Confirm Refund</button>
                <button type="button" onclick="closeRefundModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReleaseModal(id) {
    document.getElementById('releaseTransactionId').value = id;
    document.getElementById('releaseModal').style.display = 'flex';
}
function closeReleaseModal() { document.getElementById('releaseModal').style.display = 'none'; }

function openFreezeModal(id) {
    document.getElementById('freezeTransactionId').value = id;
    document.getElementById('freezeModal').style.display = 'flex';
}
function closeFreezeModal() { document.getElementById('freezeModal').style.display = 'none'; }

function openRefundModal(id) {
    document.getElementById('refundTransactionId').value = id;
    document.getElementById('refundModal').style.display = 'flex';
}
function closeRefundModal() { document.getElementById('refundModal').style.display = 'none'; }

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