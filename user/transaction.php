<?php
// user/transaction.php - Transaction details and payment processing

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$transaction_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Get transaction details
$transaction = $conn->query("
    SELECT t.*, l.title as listing_title, l.type as listing_type,
           u1.full_name as buyer_name, u1.email as buyer_email,
           u2.full_name as seller_name, u2.email as seller_email
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    WHERE t.id = $transaction_id AND (t.buyer_id = $user_id OR t.seller_id = $user_id)
")->fetch_assoc();

if (!$transaction) {
    header('Location: dashboard.php');
    exit;
}

$is_buyer = ($transaction['buyer_id'] == $user_id);
$is_seller = ($transaction['seller_id'] == $user_id);

// Calculate amounts
$depositPercent = $transaction['deposit_amount'] / $transaction['total_amount'] * 100;
$commissionPercent = $transaction['commission_amount'] / $transaction['total_amount'] * 100;

// Handle payment confirmation
$payment_error = '';
$payment_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $payment_code = $_POST['payment_code'];
    $payment_amount = floatval($_POST['payment_amount']);
    
    // Verify payment code (simulate Telebirr verification)
    if (strlen($payment_code) == 5 && ctype_digit($payment_code)) {
        // Process payment
        $conn->begin_transaction();
        
        try {
            // Deduct from user balance
            $conn->query("UPDATE users SET balance = balance - $payment_amount WHERE id = $user_id AND balance >= $payment_amount");
            
            if ($conn->affected_rows > 0) {
                // Record payment
                $payment_type = $is_buyer ? 'deposit_buyer' : 'deposit_seller';
                $stmt = $conn->prepare("INSERT INTO payments (transaction_id, user_id, amount, type, telebirr_code_5digit, status, confirmed_at) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())");
                $stmt->bind_param("iidss", $transaction_id, $user_id, $payment_amount, $payment_type, $payment_code);
                $stmt->execute();
                
                // Update transaction escrow
                $conn->query("UPDATE transactions SET escrow_held = escrow_held + $payment_amount WHERE id = $transaction_id");
                
                // Update transaction status
                if ($is_buyer) {
                    $conn->query("UPDATE transactions SET status = 'awaiting_seller_deposit' WHERE id = $transaction_id");
                } else {
                    // Check if both deposits are complete
                    $payments = $conn->query("SELECT SUM(amount) as total FROM payments WHERE transaction_id = $transaction_id AND status = 'confirmed'")->fetch_assoc();
                    $required = $transaction['deposit_amount'] * 2 + $transaction['commission_amount'];
                    if ($payments['total'] >= $required) {
                        $conn->query("UPDATE transactions SET status = 'deposits_complete' WHERE id = $transaction_id");
                    } else {
                        $conn->query("UPDATE transactions SET status = 'awaiting_buyer_deposit' WHERE id = $transaction_id");
                    }
                }
                
                $conn->commit();
                $payment_success = "Payment confirmed successfully!";
                header("Refresh: 2");
            } else {
                throw new Exception("Insufficient balance");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $payment_error = $e->getMessage();
        }
    } else {
        $payment_error = "Invalid payment code. Please enter a valid 5-digit code.";
    }
}

// Handle delivery confirmation (buyer only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delivery']) && $is_buyer) {
    $conn->query("UPDATE transactions SET status = 'completed', completed_at = NOW() WHERE id = $transaction_id");
    
    // Release payment to seller
    $release_amount = $transaction['total_amount'] - $transaction['commission_amount'];
    $conn->query("UPDATE users SET balance = balance + $release_amount WHERE id = {$transaction['seller_id']}");
    
    $payment_success = "Delivery confirmed! Payment released to seller.";
    header("Refresh: 2");
}

// Get payments made
$payments = $conn->query("SELECT * FROM payments WHERE transaction_id = $transaction_id AND status = 'confirmed'");
$total_paid = 0;
while($p = $payments->fetch_assoc()) {
    $total_paid += $p['amount'];
}

$buyer_paid = $conn->query("SELECT SUM(amount) as total FROM payments WHERE transaction_id = $transaction_id AND user_id = {$transaction['buyer_id']} AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0;
$seller_paid = $conn->query("SELECT SUM(amount) as total FROM payments WHERE transaction_id = $transaction_id AND user_id = {$transaction['seller_id']} AND status = 'confirmed'")->fetch_assoc()['total'] ?? 0;

$buyer_required = $transaction['deposit_amount'] + $transaction['commission_amount'];
$seller_required = $transaction['deposit_amount'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction #<?php echo $transaction_id; ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 1000px; margin: 40px auto; padding: 0 24px; }
        .card { background: white; border-radius: 12px; padding: 32px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h2 { margin-bottom: 20px; color: #333; }
        .status-bar { display: flex; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; }
        .status-step { flex: 1; text-align: center; padding: 16px; position: relative; }
        .status-step .step-icon { width: 40px; height: 40px; background: #e0e0e0; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px; }
        .status-step.completed .step-icon { background: #28a745; color: white; }
        .status-step.active .step-icon { background: #667eea; color: white; }
        .status-step .step-label { font-size: 12px; color: #666; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px; }
        .info-item { padding: 12px; background: #f8f9fa; border-radius: 8px; }
        .info-label { font-size: 12px; color: #888; margin-bottom: 4px; }
        .info-value { font-size: 16px; font-weight: 500; }
        .payment-section { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-top: 20px; }
        .payment-code { font-size: 32px; font-weight: 700; letter-spacing: 4px; text-align: center; padding: 16px; background: white; border-radius: 8px; margin: 16px 0; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #28a745; color: white; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        input { padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; text-align: center; letter-spacing: 4px; }
        .amount-due { font-size: 24px; font-weight: 700; color: #667eea; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="dashboard.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>
    
    <div class="container">
        <!-- Status Timeline -->
        <div class="card">
            <h2>Transaction Status</h2>
            <div class="status-bar">
                <?php
                $statuses = [
                    'awaiting_buyer_deposit' => 1,
                    'awaiting_seller_deposit' => 2,
                    'deposits_complete' => 3,
                    'in_progress' => 3,
                    'completed' => 4,
                    'disputed' => 3,
                    'cancelled' => 0
                ];
                $current_step = $statuses[$transaction['status']] ?? 0;
                ?>
                <div class="status-step <?php echo $current_step >= 1 ? 'completed' : ($current_step == 0 ? 'active' : ''); ?>">
                    <div class="step-icon"><i class="fas fa-credit-card"></i></div>
                    <div class="step-label">Deposit</div>
                </div>
                <div class="status-step <?php echo $current_step >= 2 ? 'completed' : ($current_step == 1 ? 'active' : ''); ?>">
                    <div class="step-icon"><i class="fas fa-handshake"></i></div>
                    <div class="step-label">Both Deposits</div>
                </div>
                <div class="status-step <?php echo $current_step >= 3 ? 'completed' : ($current_step == 2 ? 'active' : ''); ?>">
                    <div class="step-icon"><i class="fas fa-truck"></i></div>
                    <div class="step-label">Delivery</div>
                </div>
                <div class="status-step <?php echo $current_step >= 4 ? 'completed' : ''; ?>">
                    <div class="step-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="step-label">Completed</div>
                </div>
            </div>
        </div>
        
        <!-- Transaction Details -->
        <div class="card">
            <h2>Transaction Details</h2>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Transaction ID</div><div class="info-value">#<?php echo $transaction['id']; ?></div></div>
                <div class="info-item"><div class="info-label">Item</div><div class="info-value"><?php echo htmlspecialchars($transaction['listing_title']); ?></div></div>
                <div class="info-item"><div class="info-label">Total Amount</div><div class="info-value"><?php echo formatMoney($transaction['total_amount']); ?></div></div>
                <div class="info-item"><div class="info-label">Status</div><div class="info-value"><?php echo getStatusBadge($transaction['status']); ?></div></div>
                <div class="info-item"><div class="info-label">Buyer</div><div class="info-value"><?php echo htmlspecialchars($transaction['buyer_name']); ?></div></div>
                <div class="info-item"><div class="info-label">Seller</div><div class="info-value"><?php echo htmlspecialchars($transaction['seller_name']); ?></div></div>
            </div>
        </div>
        
        <?php if ($payment_success): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($payment_success); ?></div>
        <?php endif; ?>
        
        <?php if ($payment_error): ?>
            <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($payment_error); ?></div>
        <?php endif; ?>
        
        <!-- Payment Section -->
        <?php if ($transaction['status'] != 'completed' && $transaction['status'] != 'cancelled'): ?>
            <?php if ($is_buyer && $buyer_paid < $buyer_required): ?>
                <div class="card">
                    <h2>Complete Your Payment</h2>
                    <p>You need to pay <span class="amount-due"><?php echo formatMoney($buyer_required - $buyer_paid); ?></span> to proceed.</p>
                    <div class="payment-section">
                        <h3>Payment Code</h3>
                        <div class="payment-code"><?php echo $transaction['payment_code_5digit']; ?></div>
                        <p>Use this 5-digit code in the Telebirr app to complete your payment.</p>
                        <form method="POST" style="margin-top: 20px;">
                            <input type="hidden" name="payment_amount" value="<?php echo $buyer_required - $buyer_paid; ?>">
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <input type="text" name="payment_code" placeholder="Enter 5-digit code" maxlength="5" pattern="\d{5}" required style="flex: 1;">
                                <button type="submit" name="confirm_payment" class="btn btn-primary">Confirm Payment</button>
                            </div>
                        </form>
                        <p style="margin-top: 12px; font-size: 12px; color: #888;">
                            <i class="fas fa-info-circle"></i> After paying in Telebirr, enter the same 5-digit code here to confirm.
                        </p>
                    </div>
                </div>
            <?php elseif ($is_seller && $seller_paid < $seller_required): ?>
                <div class="card">
                    <h2>Seller Deposit Required</h2>
                    <p>As a seller, you need to deposit <span class="amount-due"><?php echo formatMoney($seller_required - $seller_paid); ?></span> to secure this transaction.</p>
                    <div class="payment-section">
                        <h3>Payment Code</h3>
                        <div class="payment-code"><?php echo $transaction['payment_code_5digit']; ?></div>
                        <form method="POST">
                            <input type="hidden" name="payment_amount" value="<?php echo $seller_required - $seller_paid; ?>">
                            <input type="text" name="payment_code" placeholder="Enter 5-digit code from Telebirr" maxlength="5" pattern="\d{5}" required style="width: 100%; margin-bottom: 12px;">
                            <button type="submit" name="confirm_payment" class="btn btn-primary">Confirm Seller Deposit</button>
                        </form>
                    </div>
                </div>
            <?php elseif ($transaction['status'] == 'deposits_complete' && $is_buyer): ?>
                <div class="card">
                    <h2>Confirm Delivery</h2>
                    <p>Have you received the item/service?</p>
                    <form method="POST" onsubmit="return confirm('Confirm that you have received the item/service? The payment will be released to the seller.');">
                        <button type="submit" name="confirm_delivery" class="btn btn-success"><i class="fas fa-check-circle"></i> Confirm Delivery & Release Payment</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php elseif ($transaction['status'] == 'completed'): ?>
            <div class="card">
                <h2><i class="fas fa-check-circle" style="color: #28a745;"></i> Transaction Completed</h2>
                <p>This transaction has been completed successfully.</p>
                <?php if ($is_seller): ?>
                    <p>Payment has been released to your wallet.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Payment History -->
        <?php 
        $payments_history = $conn->query("SELECT * FROM payments WHERE transaction_id = $transaction_id AND status = 'confirmed' ORDER BY created_at DESC");
        if ($payments_history && $payments_history->num_rows > 0): 
        ?>
        <div class="card">
            <h2>Payment History</h2>
            <table>
                <thead><tr><th>Date</th><th>Amount</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>
                    <?php while($p = $payments_history->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('M d, H:i', strtotime($p['created_at'])); ?></td>
                        <td><?php echo formatMoney($p['amount']); ?></td>
                        <td><?php echo str_replace('_', ' ', ucfirst($p['type'])); ?></td>
                        <td><span class="badge badge-success">Confirmed</span></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>