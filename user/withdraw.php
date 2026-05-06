<?php
// user/withdraw.php - Withdrawal Request with Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Withdraw Funds';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

$min_withdrawal = getSetting('min_withdrawal', 100);
$max_withdrawal = getSetting('max_withdrawal', 100000);

$error = '';
$success = '';

// Handle cancellation
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $wd_id = sanitizeInt($_GET['cancel']);
    $check = $conn->query("SELECT id, amount FROM withdrawal_requests WHERE id = $wd_id AND user_id = $user_id AND status = 'pending'");
    if ($check->num_rows > 0) {
        $wd = $check->fetch_assoc();
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM withdrawal_requests WHERE id = $wd_id");
            $conn->query("UPDATE users SET balance = balance + {$wd['amount']} WHERE id = $user_id");
            $conn->commit();
            $success = "Withdrawal request cancelled. " . formatMoney($wd['amount']) . " returned to your balance.";
            
            // Refresh balance
            $user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
            $balance = $user['balance'];
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to cancel withdrawal";
        }
    }
}

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = sanitizeFloat($_POST['amount'] ?? 0);
    $bank_name = sanitizeString($_POST['bank_name'] ?? '');
    $account_number = sanitizeString($_POST['account_number'] ?? '');
    $account_name = sanitizeString($_POST['account_name'] ?? '');
    
    $errors = [];
    
    // Validate amount
    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount";
    } elseif ($amount < $min_withdrawal) {
        $errors[] = "Minimum withdrawal amount is " . formatMoney($min_withdrawal);
    } elseif ($amount > $max_withdrawal) {
        $errors[] = "Maximum withdrawal amount per request is " . formatMoney($max_withdrawal);
    } elseif ($amount > $balance) {
        $errors[] = "Insufficient balance. Your current balance is " . formatMoney($balance);
    }
    
    // Validate bank details
    if (empty($bank_name)) {
        $errors[] = "Bank name is required";
    } elseif (strlen($bank_name) < 2) {
        $errors[] = "Please enter a valid bank name";
    }
    
    if (empty($account_number)) {
        $errors[] = "Account number is required";
    } elseif (!validateBankAccount($account_number)) {
        $errors[] = "Please enter a valid account number (8-20 digits)";
    }
    
    if (empty($account_name)) {
        $errors[] = "Account holder name is required";
    } elseif (strlen($account_name) < 3) {
        $errors[] = "Please enter the full account holder name";
    }
    
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
                    VALUES ($user_id, $amount, 'withdrawal', 'Withdrawal request to $bank_name', NOW())
                ");
                
                $conn->commit();
                $success = "Withdrawal request submitted successfully! It will be processed within 24-48 hours.";
                
                // Refresh balance
                $user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
                $balance = $user['balance'];
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
    .balance-card { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 24px; padding: 28px; color: white; text-align: center; margin-bottom: 28px; }
    .balance-label { font-size: 13px; opacity: 0.9; margin-bottom: 8px; }
    .balance-amount { font-size: 42px; font-weight: 700; }
    
    .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .form-group { margin-bottom: 20px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 13px; }
    .required { color: #ef4444; }
    input, select { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; transition: all 0.3s; }
    input:focus, select:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; margin-top: 20px; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .info-text { font-size: 11px; color: #64748b; margin-top: 6px; }
    .limits { background: #f8fafc; padding: 12px; border-radius: 12px; margin-top: 16px; text-align: center; font-size: 12px; color: #64748b; }
    .table-wrapper { overflow-x: auto; margin-top: 20px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-pending { background: #fed7aa; color: #ea580c; }
    .badge-approved { background: #dbeafe; color: #1e40af; }
    .badge-completed { background: #d1fae5; color: #059669; }
    
    @media (max-width: 640px) {
        .form-row { grid-template-columns: 1fr; }
        .balance-amount { font-size: 32px; }
    }
</style>

<div class="withdraw-container">
    <!-- Balance Display -->
    <div class="balance-card">
        <div class="balance-label">Available Balance</div>
        <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Withdrawal Form -->
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;"><i class="fas fa-money-bill-wave"></i> Request Withdrawal</h2>
        
        <form method="POST">
            <div class="form-group">
                <label>Amount (ETB) <span class="required">*</span></label>
                <input type="number" name="amount" step="0.01" min="<?php echo $min_withdrawal; ?>" max="<?php echo min($max_withdrawal, $balance); ?>" required placeholder="0.00">
                <div class="info-text">Min: <?php echo formatMoney($min_withdrawal); ?> | Max: <?php echo formatMoney(min($max_withdrawal, $balance)); ?></div>
            </div>
            
            <div class="form-group">
                <label>Bank Name <span class="required">*</span></label>
                <select name="bank_name" required>
                    <option value="">Select your bank</option>
                    <option value="Commercial Bank of Ethiopia">Commercial Bank of Ethiopia (CBE)</option>
                    <option value="Dashen Bank">Dashen Bank</option>
                    <option value="Awash Bank">Awash Bank</option>
                    <option value="Bank of Abyssinia">Bank of Abyssinia</option>
                    <option value="Hibret Bank">Hibret Bank</option>
                    <option value="Nib International Bank">Nib International Bank</option>
                    <option value="United Bank">United Bank</option>
                    <option value="Oromia Bank">Oromia Bank</option>
                    <option value="Wegagen Bank">Wegagen Bank</option>
                    <option value="Zemen Bank">Zemen Bank</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Account Number <span class="required">*</span></label>
                    <input type="text" name="account_number" required placeholder="Your bank account number">
                    <div class="info-text">8-20 digits, numbers only</div>
                </div>
                <div class="form-group">
                    <label>Account Holder Name <span class="required">*</span></label>
                    <input type="text" name="account_name" required placeholder="Name as it appears on account">
                </div>
            </div>
            
            <div class="limits">
                <i class="fas fa-clock"></i> Withdrawals are processed within 24-48 hours
            </div>
            
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit Withdrawal Request</button>
        </form>
    </div>
    
    <!-- Recent Withdrawal Requests -->
    <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 16px;"><i class="fas fa-history"></i> Recent Withdrawal Requests</h2>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Date</th><th>Amount</th><th>Bank</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php while($wd = $withdrawals->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($wd['created_at'])); ?></td>
                        <td class="amount-negative">-<?php echo formatMoney($wd['amount']); ?></td>
                        <td><?php echo htmlspecialchars($wd['bank_name']); ?></td>
                        <td>
                            <?php
                            $badge_class = match($wd['status']) {
                                'pending' => 'badge-pending',
                                'approved' => 'badge-approved',
                                'completed' => 'badge-completed',
                                default => ''
                            };
                            ?>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($wd['status']); ?></span>
                        </td>
                        <td>
                            <?php if ($wd['status'] == 'pending'): ?>
                                <a href="?cancel=<?php echo $wd['id']; ?>" class="btn-sm" style="background: #ef4444; color: white; padding: 4px 10px; border-radius: 6px; text-decoration: none;" onclick="return confirm('Cancel this withdrawal request?')">Cancel</a>
                            <?php else: ?>
                                <span style="color: #64748b; font-size: 11px;">Processed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>