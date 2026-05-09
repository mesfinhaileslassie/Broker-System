<?php
// user/withdrawal_request.php - Request withdrawal from wallet

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Request Withdrawal';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

$min_withdrawal = getSetting('min_withdrawal', 100);
$max_withdrawal = getSetting('max_withdrawal', 100000);

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount'] ?? 0);
    $bank_name = $_POST['bank_name'] ?? '';
    $account_number = $_POST['account_number'] ?? '';
    $account_name = $_POST['account_name'] ?? '';
    
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount";
    } elseif ($amount < $min_withdrawal) {
        $errors[] = "Minimum withdrawal amount is " . formatMoney($min_withdrawal);
    } elseif ($amount > $max_withdrawal) {
        $errors[] = "Maximum withdrawal amount is " . formatMoney($max_withdrawal);
    } elseif ($amount > $balance) {
        $errors[] = "Insufficient balance. Your balance is " . formatMoney($balance);
    }
    
    if (empty($bank_name)) $errors[] = "Bank name is required";
    if (empty($account_number)) $errors[] = "Account number is required";
    if (empty($account_name)) $errors[] = "Account holder name is required";
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Deduct from balance
            $conn->query("UPDATE users SET balance = balance - $amount WHERE id = $user_id AND balance >= $amount");
            
            if ($conn->affected_rows > 0) {
                // Create withdrawal request
                $stmt = $conn->prepare("
                    INSERT INTO withdrawal_requests (user_id, amount, bank_name, account_number, account_name, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->bind_param("idsss", $user_id, $amount, $bank_name, $account_number, $account_name);
                $stmt->execute();
                
                // Record wallet transaction
                $conn->query("
                    INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                    VALUES ($user_id, $amount, 'withdrawal_pending', 'Withdrawal request pending approval', NOW())
                ");
                
                // Notify admin
                $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, created_at) 
                    VALUES (?, '💰 New Withdrawal Request', 'User has requested withdrawal of " . formatMoney($amount) . "', NOW())
                ");
                $notif_stmt->bind_param("i", $admin['id']);
                $notif_stmt->execute();
                
                $conn->commit();
                $success = "Withdrawal request submitted successfully! Admin will process within 24-48 hours.";
                
                // Refresh balance
                $balance = $balance - $amount;
            } else {
                throw new Exception("Insufficient balance");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to process withdrawal: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Get recent withdrawal requests
$withdrawals = $conn->query("
    SELECT * FROM withdrawal_requests 
    WHERE user_id = $user_id 
    ORDER BY created_at DESC 
    LIMIT 10
");

$conn->close();
?>

<style>
    .withdraw-container { max-width: 800px; margin: 0 auto; }
    .balance-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 24px;
        padding: 28px;
        color: white;
        text-align: center;
        margin-bottom: 28px;
    }
    .balance-amount { font-size: 48px; font-weight: 700; margin: 16px 0; }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
    .form-group input, .form-group select {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }
    
    .btn-submit {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #1e40af; }
    .badge-completed { background: #d1fae5; color: #059669; }
    
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    
    @media (max-width: 640px) {
        .balance-amount { font-size: 32px; }
        .card { padding: 20px; }
    }
</style>

<div class="withdraw-container">
    <div class="balance-card">
        <h3><i class="fas fa-wallet"></i> Available Balance</h3>
        <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
        <p>Minimum withdrawal: <?php echo formatMoney($min_withdrawal); ?></p>
    </div>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h3 style="margin-bottom: 20px;"><i class="fas fa-money-bill-wave"></i> Request Withdrawal</h3>
        <form method="POST">
            <div class="form-group">
                <label>Amount (ETB)</label>
                <input type="number" name="amount" step="100" min="<?php echo $min_withdrawal; ?>" max="<?php echo min($max_withdrawal, $balance); ?>" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Bank Name</label>
                <select name="bank_name" required>
                    <option value="">Select Bank</option>
                    <option>Commercial Bank of Ethiopia</option>
                    <option>Dashen Bank</option>
                    <option>Awash Bank</option>
                    <option>Bank of Abyssinia</option>
                    <option>Hibret Bank</option>
                    <option>Nib International Bank</option>
                    <option>United Bank</option>
                    <option>Oromia Bank</option>
                    <option>Wegagen Bank</option>
                    <option>Zemen Bank</option>
                </select>
            </div>
            <div class="form-group">
                <label>Account Number</label>
                <input type="text" name="account_number" required placeholder="Your bank account number">
            </div>
            <div class="form-group">
                <label>Account Holder Name</label>
                <input type="text" name="account_name" required placeholder="Name as it appears on account">
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </form>
    </div>
    
    <?php if ($withdrawals->num_rows > 0): ?>
    <div class="card">
        <h3 style="margin-bottom: 16px;"><i class="fas fa-history"></i> Recent Withdrawal Requests</h3>
        <table>
            <thead>
                <tr><th>Date</th><th>Amount</th><th>Bank</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php while($wd = $withdrawals->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($wd['created_at'])); ?></td>
                    <td class="amount-negative">-<?php echo formatMoney($wd['amount']); ?></td>
                    <td><?php echo htmlspecialchars($wd['bank_name']); ?></td>
                    <td>
                        <span class="badge badge-<?php echo $wd['status']; ?>">
                            <?php echo ucfirst($wd['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>