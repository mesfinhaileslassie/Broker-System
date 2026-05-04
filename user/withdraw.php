<?php
// user/withdraw.php - Withdrawal Request Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Withdraw Funds';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'] ?? 0;

$error = '';
$success = '';

$min_withdrawal = getSetting('min_withdrawal', 100);
$max_withdrawal = getSetting('max_withdrawal', 100000);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount']);
    $bank_name = trim($_POST['bank_name']);
    $account_number = trim($_POST['account_number']);
    $account_name = trim($_POST['account_name']);
    
    if ($amount < $min_withdrawal) {
        $error = "Minimum withdrawal amount is " . formatMoney($min_withdrawal);
    } elseif ($amount > $max_withdrawal) {
        $error = "Maximum withdrawal amount is " . formatMoney($max_withdrawal);
    } elseif ($amount > $balance) {
        $error = "Insufficient balance";
    } elseif (empty($bank_name) || empty($account_number) || empty($account_name)) {
        $error = "Please fill in all bank details";
    } else {
        $conn->begin_transaction();
        
        try {
            $conn->query("UPDATE users SET balance = balance - $amount WHERE id = $user_id AND balance >= $amount");
            
            if ($conn->affected_rows > 0) {
                $stmt = $conn->prepare("INSERT INTO withdrawal_requests (user_id, amount, bank_name, account_number, account_name, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                $stmt->bind_param("idsss", $user_id, $amount, $bank_name, $account_number, $account_name);
                $stmt->execute();
                
                $conn->commit();
                $success = "Withdrawal request submitted successfully. It will be processed within 24-48 hours.";
                
                // Refresh balance
                $user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
                $balance = $user['balance'];
            } else {
                throw new Exception("Insufficient balance");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

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
    
    .balance-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 24px;
        padding: 24px;
        color: white;
        margin-bottom: 24px;
        text-align: center;
    }
    
    .balance-label {
        font-size: 14px;
        opacity: 0.9;
        margin-bottom: 8px;
    }
    
    .balance-amount {
        font-size: 36px;
        font-weight: 700;
    }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 32px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
    }
    
    .form-group input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .form-group input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .btn {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .message {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    .message-success {
        background: #d1fae5;
        color: #059669;
    }
    
    .message-error {
        background: #fee2e2;
        color: #dc2626;
    }
    
    .info-text {
        font-size: 12px;
        color: #64748b;
        margin-top: 4px;
    }
    
    .limits {
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #f1f5f9;
        text-align: center;
        font-size: 13px;
        color: #64748b;
    }
</style>

<div class="page-header">
    <h1>Withdraw Funds</h1>
    <p>Request a withdrawal to your bank account</p>
</div>

<div class="balance-card">
    <div class="balance-label">Available Balance</div>
    <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
</div>

<div class="card">
    <?php if ($error): ?>
        <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Amount (ETB)</label>
            <input type="number" name="amount" step="0.01" min="<?php echo $min_withdrawal; ?>" max="<?php echo min($max_withdrawal, $balance); ?>" required placeholder="Enter amount">
            <div class="info-text">Min: <?php echo formatMoney($min_withdrawal); ?> | Max: <?php echo formatMoney(min($max_withdrawal, $balance)); ?></div>
        </div>
        
        <div class="form-group">
            <label>Bank Name</label>
            <input type="text" name="bank_name" required placeholder="e.g., Commercial Bank of Ethiopia, Dashen Bank">
        </div>
        
        <div class="form-group">
            <label>Account Number</label>
            <input type="text" name="account_number" required placeholder="Your bank account number">
        </div>
        
        <div class="form-group">
            <label>Account Holder Name</label>
            <input type="text" name="account_name" required placeholder="Name as it appears on the account">
        </div>
        
        <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Submit Withdrawal Request</button>
    </form>
    
    <div class="limits">
        <i class="fas fa-clock"></i> Withdrawals are processed within 24-48 hours
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>