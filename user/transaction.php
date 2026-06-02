<?php
// user/transaction.php - Complete Transaction Page with Escrow and Seller Notifications
// FIXED: Database connection handling and Pay Remaining functionality

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/escrow_functions.php';
require_once '../includes/transaction_workflow.php';



// user/pay_rent.php - Complete with Availability Reservation System

// ============================================
// DEBUGGING - Add at the very top
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Create a simple debug file
$simple_debug = __DIR__ . '/simple_debug.log';
file_put_contents($simple_debug, date('Y-m-d H:i:s') . " - pay_rent.php loaded\n", FILE_APPEND);
file_put_contents($simple_debug, date('Y-m-d H:i:s') . " - GET params: " . print_r($_GET, true) . "\n", FILE_APPEND);
file_put_contents($simple_debug, date('Y-m-d H:i:s') . " - POST params: " . print_r($_POST, true) . "\n", FILE_APPEND);

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/transaction_workflow.php';
require_once '../includes/AvailabilityManager.php';

// Rest of your code continues...

requireLogin();

$page_title = 'Transaction Details';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_delivered') {
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        $result = markDelivery($conn, $transaction_id, $user_id, $delivery_notes);
        if ($result['success']) {
            $message = "✓ Delivery marked successfully! Waiting for buyer confirmation.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'confirm_receipt') {
        $confirm_notes = $_POST['confirm_notes'] ?? '';
        $result = confirmReceiptAndRelease($conn, $transaction_id, $user_id, $confirm_notes);
        if ($result['success']) {
            $message = "✓ Delivery confirmed! You can now pay the remaining balance.";
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'raise_dispute') {
        $result = openTransactionDispute($conn, $transaction_id, $user_id, $_POST['dispute_reason'] ?? '');
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['error'];
        }
    }
}

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as listing_title, l.type as listing_type, l.cover_image,
           l.admin_deposit_percent, l.admin_commission_percent,
           u1.full_name as buyer_name, u1.email as buyer_email, u1.phone as buyer_phone,
           u2.full_name as seller_name, u2.email as seller_email, u2.phone as seller_phone
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
")->fetch_assoc();

if (!$transaction) {
    $conn->close();
    header('Location: dashboard.php');
    exit;
}

$workflow = getTransactionWorkflowView($conn, $transaction_id);
if ($workflow) {
    $transaction = array_merge($transaction, $workflow);
}

// Get payment totals - DO THIS BEFORE CLOSING CONNECTION
$buyerDepositPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type = 'deposit_buyer' AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);

$remainingPaid = (float) ($conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM payments
    WHERE transaction_id = $transaction_id AND type = 'remaining_balance' AND status = 'confirmed'
")->fetch_assoc()['total'] ?? 0);

$totalPaid = $buyerDepositPaid + $remainingPaid;
$totalAmount = floatval($transaction['total_amount']);
$remainingBalance = max(0, $totalAmount - $buyerDepositPaid - $remainingPaid);

// Get payment history - DO THIS BEFORE CLOSING CONNECTION
$payments_list = $conn->query("
    SELECT * FROM payments 
    WHERE transaction_id = $transaction_id AND status = 'confirmed' 
    ORDER BY created_at DESC
");

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);

// Check delivery confirmation status
$seller_confirmed = ($transaction['seller_delivery_confirmed'] == 1);
$buyer_confirmed = ($transaction['buyer_delivery_confirmed'] == 1);
$both_confirmed = ($seller_confirmed && $buyer_confirmed);

// Determine if remaining payment is available
$can_pay_remaining = ($is_buyer && $both_confirmed && $remainingBalance > 0 && $remainingPaid == 0);

// Calculate amounts
$depositPercent = $transaction['admin_deposit_percent'] ?? 30;
$commissionPercent = $transaction['admin_commission_percent'] ?? 15;
$depositAmount = $totalAmount * ($depositPercent / 100);
$commissionAmount = $totalAmount * ($commissionPercent / 100);

// Close connection AFTER all queries are done
$conn->close();
?>

<style>
    .transaction-container { max-width: 1200px; margin: 0 auto; }
    
    .transaction-header {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .transaction-header h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
    .transaction-header p { opacity: 0.9; }
    
    .card {
        background: white;
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .card-header h3 { font-size: 18px; font-weight: 600; color: #0f172a; }
    
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    .status-active { background: #dbeafe; color: #1e40af; }
    .status-delivered { background: #fed7aa; color: #9a3412; }
    .status-completed { background: #d1fae5; color: #059669; }
    .status-disputed { background: #fee2e2; color: #dc2626; }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .info-item { padding: 12px; background: #f8fafc; border-radius: 16px; }
    .info-label { font-size: 11px; color: #64748b; margin-bottom: 4px; }
    .info-value { font-size: 16px; font-weight: 700; color: #0f172a; }
    
    /* Delivery Confirmation Cards */
    .delivery-card {
        background: #f8fafc;
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 20px;
        border-left: 4px solid #667eea;
    }
    
    .delivery-card.confirmed {
        background: #d1fae5;
        border-left-color: #10b981;
    }
    
    .delivery-card h4 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .btn-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
    .btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-warning { background: #f59e0b; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .alert-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #1e40af; }
    .alert-warning { background: #fed7aa; color: #9a3412; border-left: 4px solid #f59e0b; }
    
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
    
    .remaining-payment-box {
        background: linear-gradient(135deg, #ecfdf5, #d1fae5);
        border: 2px solid #10b981;
        border-radius: 20px;
        padding: 20px;
        margin-top: 20px;
        text-align: center;
    }
    
    .remaining-amount {
        font-size: 28px;
        font-weight: 800;
        color: #059669;
        margin: 10px 0;
    }
    
    .payment-history-table {
        width: 100%;
        border-collapse: collapse;
    }
    .payment-history-table th, 
    .payment-history-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #e2e8f0;
    }
    .payment-history-table th {
        font-weight: 600;
        color: #64748b;
        background: #f8fafc;
    }
    
    @media (max-width: 768px) {
        .info-grid { grid-template-columns: 1fr; }
        .btn-group { flex-direction: column; }
        .btn { justify-content: center; }
    }
</style>

<div class="transaction-container">
    <!-- Header -->
    <div class="transaction-header">
        <h1><i class="fas fa-receipt"></i> Transaction #<?php echo $transaction['id']; ?></h1>
        <p><?php echo htmlspecialchars($transaction['listing_title']); ?></p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Status Overview -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line"></i> Status Overview</h3>
            <span class="status-badge <?php 
                if ($remainingPaid > 0) echo 'status-completed';
                elseif ($both_confirmed) echo 'status-active';
                elseif ($seller_confirmed) echo 'status-delivered';
                elseif ($buyerDepositPaid > 0) echo 'status-active';
                else echo 'status-active';
            ?>">
                <?php 
                if ($remainingPaid > 0) echo '✓ Fully Paid';
                elseif ($both_confirmed) echo '✓ Delivery Confirmed - Ready for Payment';
                elseif ($seller_confirmed) echo '📦 Delivered - Awaiting Your Confirmation';
                elseif ($buyerDepositPaid > 0) echo '💰 Deposit Paid - Awaiting Delivery';
                else echo '📋 Pending Payment';
                ?>
            </span>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Total Price</div>
                <div class="info-value"><?php echo formatMoney($totalAmount); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Deposit Paid</div>
                <div class="info-value"><?php echo formatMoney($buyerDepositPaid); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Remaining Balance</div>
                <div class="info-value"><?php echo formatMoney($remainingBalance); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <?php if ($remainingPaid > 0): ?>
                        Fully Paid ✓
                    <?php elseif ($both_confirmed): ?>
                        Ready for Remaining Payment
                    <?php elseif ($seller_confirmed): ?>
                        Waiting for Your Confirmation
                    <?php elseif ($buyerDepositPaid > 0): ?>
                        Waiting for Seller Delivery
                    <?php else: ?>
                        Awaiting Deposit
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delivery Confirmation Section -->
    <?php if ($buyerDepositPaid > 0 && !$remainingPaid): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-truck"></i> Delivery Status</h3>
        </div>
        
        <!-- Seller Confirmation Status -->
        <div class="delivery-card <?php echo $seller_confirmed ? 'confirmed' : ''; ?>">
            <h4>
                <?php if ($seller_confirmed): ?>
                    <i class="fas fa-check-circle" style="color: #10b981;"></i> ✓ Seller Confirmed Delivery
                <?php else: ?>
                    <i class="fas fa-clock" style="color: #f59e0b;"></i> ⏳ Waiting for Seller to Confirm Delivery
                <?php endif; ?>
            </h4>
            <?php if ($seller_confirmed): ?>
                <p style="color: #065f46; font-size: 13px;">The seller has marked this item as delivered.</p>
            <?php else: ?>
                <p style="color: #92400e; font-size: 13px;">The seller will confirm when the item has been delivered.</p>
            <?php endif; ?>
        </div>
        
        <!-- Buyer Confirmation Status (only visible to buyer) -->
        <?php if ($is_buyer): ?>
        <div class="delivery-card <?php echo $buyer_confirmed ? 'confirmed' : ''; ?>">
            <h4>
                <?php if ($buyer_confirmed): ?>
                    <i class="fas fa-check-circle" style="color: #10b981;"></i> ✓ You Confirmed Receipt
                <?php else: ?>
                    <i class="fas fa-clock" style="color: #f59e0b;"></i> ⏳ Your Confirmation Needed
                <?php endif; ?>
            </h4>
            <?php if (!$buyer_confirmed && $seller_confirmed): ?>
                <p style="color: #1e40af; margin-bottom: 16px;">The seller has confirmed delivery. Please confirm that you have received the item.</p>
                <button onclick="openConfirmModal()" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Confirm Receipt
                </button>
            <?php elseif ($buyer_confirmed): ?>
                <p style="color: #065f46;">You have confirmed receipt of this item.</p>
            <?php elseif (!$seller_confirmed): ?>
                <p style="color: #64748b;">Waiting for seller to confirm delivery before you can confirm.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Seller Actions -->
        <?php if ($is_seller && !$seller_confirmed && $buyerDepositPaid > 0): ?>
        <div style="margin-top: 16px;">
            <button onclick="openDeliveryModal()" class="btn btn-primary">
                <i class="fas fa-truck"></i> Mark as Delivered
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Remaining Payment Section -->
    <?php if ($can_pay_remaining): ?>
    <div class="remaining-payment-box">
        <h4><i class="fas fa-wallet"></i> Complete Your Purchase</h4>
        <div class="remaining-amount"><?php echo formatMoney($remainingBalance); ?></div>
        <p>Both you and the seller have confirmed delivery.</p>
        <p style="font-size: 13px; margin: 10px 0;">Pay the remaining balance to complete your purchase.</p>
        <button onclick="initiateRemainingPayment()" id="payRemainingBtn" class="btn btn-success" style="margin-top: 10px;">
            <i class="fas fa-credit-card"></i> Pay Remaining Balance
        </button>
        <div id="paymentError" style="color: #dc2626; font-size: 13px; margin-top: 12px; display: none;"></div>
    </div>
    <?php elseif ($both_confirmed && $is_buyer && $remainingBalance <= 0): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <div>Transaction Complete! The full amount has been paid.</div>
    </div>
    <?php elseif ($both_confirmed && $is_buyer && $remainingBalance > 0 && $remainingPaid == 0): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <div>Both parties have confirmed delivery. Click the "Pay Remaining Balance" button above to complete your purchase.</div>
    </div>
    <?php endif; ?>
    
    <!-- Party Information -->
    <div class="info-grid">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user"></i> Buyer Information</h3></div>
            <div class="info-item"><div class="info-label">Name</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_email']); ?></div></div>
            <?php if ($transaction['buyer_phone']): ?>
            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_phone']); ?></div></div>
            <?php endif; ?>
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['buyer_id']; ?>" class="btn btn-outline"><i class="fas fa-comment"></i> Message Buyer</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-store"></i> Seller Information</h3></div>
            <div class="info-item"><div class="info-label">Name</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_email']); ?></div></div>
            <?php if ($transaction['seller_phone']): ?>
            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_phone']); ?></div></div>
            <?php endif; ?>
            <div class="btn-group" style="margin-top: 16px;">
                <a href="chat.php?user=<?php echo $transaction['seller_id']; ?>" class="btn btn-outline"><i class="fas fa-comment"></i> Message Seller</a>
            </div>
        </div>
    </div>
    
    <!-- Payment History -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-history"></i> Payment History</h3></div>
        <?php if ($payments_list && $payments_list->num_rows > 0): ?>
            <table class="payment-history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($p = $payments_list->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($p['created_at'])); ?></td>
                            <td><?php echo formatMoney($p['amount']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $p['type'])); ?></td>
                            <td><span class="status-badge" style="background: #d1fae5; color: #059669;">Confirmed</span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; padding: 20px; color: #64748b;">No payments recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Delivery Modal -->
<div id="deliveryModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-truck"></i> Mark as Delivered</h3>
        <form method="POST">
            <input type="hidden" name="action" value="mark_delivered">
            <div class="form-group">
                <label>Delivery Notes (Optional)</label>
                <textarea name="delivery_notes" rows="3" placeholder="Add any delivery details or tracking information..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Confirm Delivery</button>
                <button type="button" onclick="closeDeliveryModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Receipt Modal -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-check-circle"></i> Confirm Receipt</h3>
        <div style="background: #fef3c7; padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <p><strong>⚠️ Important:</strong> Confirm that you have received the item in good condition.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="confirm_receipt">
            <div class="form-group">
                <label>Confirmation Notes (Optional)</label>
                <textarea name="confirm_notes" rows="3" placeholder="Add any notes about the delivery..."></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-success">Confirm Receipt</button>
                <button type="button" onclick="closeConfirmModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Dispute Modal -->
<div id="disputeModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-flag"></i> Raise a Dispute</h3>
        <form method="POST">
            <input type="hidden" name="action" value="raise_dispute">
            <div class="form-group">
                <label>Reason for Dispute <span style="color: red;">*</span></label>
                <textarea name="dispute_reason" rows="4" placeholder="Please explain in detail why you're raising this dispute..." required></textarea>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-danger">Submit Dispute</button>
                <button type="button" onclick="closeDisputeModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeliveryModal() { document.getElementById('deliveryModal').style.display = 'flex'; }
function closeDeliveryModal() { document.getElementById('deliveryModal').style.display = 'none'; }
function openConfirmModal() { document.getElementById('confirmModal').style.display = 'flex'; }
function closeConfirmModal() { document.getElementById('confirmModal').style.display = 'none'; }
function openDisputeModal() { document.getElementById('disputeModal').style.display = 'flex'; }
function closeDisputeModal() { document.getElementById('disputeModal').style.display = 'none'; }

function initiateRemainingPayment() {
    // Simple redirect - no API call needed
    window.location.href = 'pay_rent.php?transaction_id=<?php echo $transaction_id; ?>&pay=remaining';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>