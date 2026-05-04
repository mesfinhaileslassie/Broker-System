<?php
// user/withdraw.php - Request withdrawal

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Get user balance
$user = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
$balance = $user['balance'];

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
        // Deduct balance and create withdrawal request
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdraw Funds - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 600px; margin: 40px auto; padding: 0 24px; }
        .card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h1 { font-size: 24px; margin-bottom: 8px; }
        .balance-info { background: #e3f2fd; padding: 16px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .balance-amount { font-size: 28px; font-weight: 700; color: #667eea; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        input:focus { outline: none; border-color: #667eea; }
        button { width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
        button:hover { background: #5a67d8; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .info-text { font-size: 12px; color: #888; margin-top: 4px; }
        .limits { font-size: 13px; color: #666; margin-top: 16px; text-align: center; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="wallet.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Back to Wallet</a>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h1><i class="fas fa-money-bill-wave"></i> Withdraw Funds</h1>
            <p>Request a withdrawal to your bank account</p>
            
            <div class="balance-info">
                <div>Available Balance</div>
                <div class="balance-amount"><?php echo formatMoney($balance); ?></div>
            </div>
            
            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
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
                
                <button type="submit"><i class="fas fa-paper-plane"></i> Submit Withdrawal Request</button>
            </form>
            
            <div class="limits">
                <i class="fas fa-clock"></i> Withdrawals are processed within 24-48 hours
            </div>
        </div>
    </div>
</body>
</html>