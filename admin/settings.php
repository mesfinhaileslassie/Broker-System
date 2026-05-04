<?php
// admin/settings.php

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Create settings table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $depositPercent = intval($_POST['deposit_percent']);
        $commissionPercent = intval($_POST['commission_percent']);
        $escrowDays = intval($_POST['escrow_days']);
        
        if ($depositPercent < 0 || $depositPercent > 100) {
            $error = 'Deposit percent must be between 0 and 100';
        } elseif ($commissionPercent < 0 || $commissionPercent > 100) {
            $error = 'Commission percent must be between 0 and 100';
        } else {
            updateSetting('deposit_percent', $depositPercent);
            updateSetting('commission_percent', $commissionPercent);
            updateSetting('escrow_days', $escrowDays);
            updateSetting('site_name', $_POST['site_name'] ?? 'Ethio Brokerplace');
            
            $message = 'Settings saved successfully';
        }
    }
}

// Get current settings
$depositPercent = getSetting('deposit_percent', 30);
$commissionPercent = getSetting('commission_percent', 15);
$escrowDays = getSetting('escrow_days', 14);
$siteName = getSetting('site_name', 'Ethio Brokerplace');

$csrfToken = generateCSRF();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        
        .admin-wrapper { display: flex; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        
        .main-content { margin-left: 260px; flex: 1; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .section { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        
        .settings-form { max-width: 600px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .form-group input:focus { outline: none; border-color: #667eea; }
        .form-group small { display: block; margin-top: 6px; font-size: 12px; color: #888; }
        
        .btn-save { background: #667eea; color: white; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 500; cursor: pointer; }
        .btn-save:hover { background: #5a67d8; }
        
        .preview-card { background: #f8f9fa; border-radius: 8px; padding: 16px; margin-top: 20px; }
        .preview-card h4 { margin-bottom: 12px; color: #333; }
        .preview-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .preview-label { font-weight: 500; color: #666; }
        .preview-value { color: #333; }
        
        .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 16px; margin-top: 20px; border-radius: 8px; }
        .info-box p { margin-bottom: 8px; }
        .info-box strong { color: #1976d2; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🏪 Brokerplace</h2>
                <p>Admin Dashboard</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item" onclick="location.href='dashboard.php'">📊 Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'">👥 Users</li>
                <li class="nav-item" onclick="location.href='companies.php'">🏢 Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'">💰 Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'">⚖️ Disputes</li>
                <li class="nav-item active" onclick="location.href='settings.php'">⚙️ Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">System Settings</h1>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="section">
                <div class="section-title">General Settings</div>
                
                <form method="POST" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <div class="form-group">
                        <label>Site Name</label>
                        <input type="text" name="site_name" value="<?php echo htmlspecialchars($siteName); ?>" placeholder="Ethio Brokerplace">
                    </div>
                    
                    <div class="form-group">
                        <label>Deposit Percentage (%)</label>
                        <input type="number" name="deposit_percent" value="<?php echo $depositPercent; ?>" min="0" max="100" step="1" required>
                        <small>Percentage of total transaction amount that both buyer and seller must deposit</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Commission Percentage (%)</label>
                        <input type="number" name="commission_percent" value="<?php echo $commissionPercent; ?>" min="0" max="100" step="1" required>
                        <small>System commission deducted from each transaction</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Escrow Hold Period (Days)</label>
                        <input type="number" name="escrow_days" value="<?php echo $escrowDays; ?>" min="1" max="90" step="1" required>
                        <small>Number of days funds are held in escrow after completion</small>
                    </div>
                    
                    <button type="submit" class="btn-save">Save Settings</button>
                </form>
                
                <!-- Preview Calculation -->
                <div class="preview-card">
                    <h4>📊 Preview: Transaction Calculation</h4>
                    <div class="preview-item">
                        <span class="preview-label">Sample Item Price:</span>
                        <span class="preview-value">1,000.00 ETB</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Deposit Amount (<?php echo $depositPercent; ?>% each):</span>
                        <span class="preview-value"><?php echo number_format(1000 * $depositPercent / 100, 2); ?> ETB (buyer) + <?php echo number_format(1000 * $depositPercent / 100, 2); ?> ETB (seller)</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Commission (<?php echo $commissionPercent; ?>%):</span>
                        <span class="preview-value"><?php echo number_format(1000 * $commissionPercent / 100, 2); ?> ETB</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Buyer Pays Upfront:</span>
                        <span class="preview-value"><?php echo number_format(1000 * ($depositPercent + $commissionPercent) / 100, 2); ?> ETB</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Seller Receives (after completion):</span>
                        <span class="preview-value"><?php echo number_format(1000 * (100 - $commissionPercent) / 100, 2); ?> ETB</span>
                    </div>
                </div>
                
                <div class="info-box">
                    <p><strong>ℹ️ How the Payment Flow Works:</strong></p>
                    <p>1. Buyer pays deposit (<?php echo $depositPercent; ?>%) + commission (<?php echo $commissionPercent; ?>%) upfront</p>
                    <p>2. Seller pays deposit (<?php echo $depositPercent; ?>%) upfront</p>
                    <p>3. Both deposits are held in escrow until transaction completes</p>
                    <p>4. After buyer confirms delivery, seller receives full payment minus commission</p>
                    <p>5. Deposits are returned to both parties after successful completion</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>