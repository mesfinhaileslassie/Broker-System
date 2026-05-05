<?php
// admin/settings.php - System Settings

$page_title = 'System Settings';
ob_start();

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_settings'])) {
        updateSetting('deposit_percent', intval($_POST['deposit_percent']));
        updateSetting('commission_percent', intval($_POST['commission_percent']));
        updateSetting('escrow_days', intval($_POST['escrow_days']));
        updateSetting('min_withdrawal', floatval($_POST['min_withdrawal']));
        updateSetting('max_withdrawal', floatval($_POST['max_withdrawal']));
        $message = "Settings saved successfully";
    }
}

$depositPercent = getSetting('deposit_percent', 30);
$commissionPercent = getSetting('commission_percent', 15);
$escrowDays = getSetting('escrow_days', 14);
$minWithdrawal = getSetting('min_withdrawal', 100);
$maxWithdrawal = getSetting('max_withdrawal', 100000);

$conn->close();
?>

<style>
    .settings-container {
        display: grid;
        grid-template-columns: 1fr 400px;
        gap: 24px;
    }
    
    .settings-form {
        background: white;
        border-radius: 20px;
        padding: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .settings-form h2 {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .form-group {
        margin-bottom: 24px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #334155;
        font-size: 13px;
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
    
    .form-group small {
        display: block;
        margin-top: 6px;
        font-size: 11px;
        color: #64748b;
    }
    
    .btn-save {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 12px 28px;
        border: none;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        width: 100%;
    }
    
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    /* Preview Card */
    .preview-card {
        background: white;
        border-radius: 20px;
        padding: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        height: fit-content;
        position: sticky;
        top: 20px;
    }
    
    .preview-card h3 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .preview-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    .preview-item:last-child {
        border-bottom: none;
    }
    
    .preview-label {
        color: #64748b;
    }
    
    .preview-value {
        font-weight: 600;
        color: #0f172a;
    }
    
    .preview-total {
        font-weight: 700;
        color: #667eea;
        font-size: 16px;
    }
    
    .info-box {
        background: #e3f2fd;
        border-radius: 12px;
        padding: 16px;
        margin-top: 20px;
    }
    
    .info-box p {
        font-size: 12px;
        color: #1e40af;
        margin-bottom: 8px;
    }
    
    .info-box p:last-child {
        margin-bottom: 0;
    }
    
    .alert {
        padding: 14px 18px;
        border-radius: 16px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    @media (max-width: 768px) {
        .settings-container {
            grid-template-columns: 1fr;
        }
        
        .preview-card {
            position: static;
        }
    }
</style>

<?php if ($message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="settings-container">
    <!-- Settings Form -->
    <div class="settings-form">
        <h2><i class="fas fa-cog"></i> General Settings</h2>
        
        <form method="POST">
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
            
            <div class="form-group">
                <label>Minimum Withdrawal (ETB)</label>
                <input type="number" name="min_withdrawal" value="<?php echo $minWithdrawal; ?>" min="1" required>
                <small>Minimum amount users can withdraw</small>
            </div>
            
            <div class="form-group">
                <label>Maximum Withdrawal (ETB)</label>
                <input type="number" name="max_withdrawal" value="<?php echo $maxWithdrawal; ?>" min="1" required>
                <small>Maximum amount users can withdraw per request</small>
            </div>
            
            <button type="submit" name="save_settings" class="btn-save">
                <i class="fas fa-save"></i> Save Settings
            </button>
        </form>
    </div>
    
    <!-- Preview Card -->
    <div class="preview-card">
        <h3><i class="fas fa-calculator"></i> Preview: Transaction Calculation</h3>
        
        <div class="preview-item">
            <span class="preview-label">Sample Item Price:</span>
            <span class="preview-value">1,000.00 ETB</span>
        </div>
        <div class="preview-item">
            <span class="preview-label">Deposit Amount (<?php echo $depositPercent; ?>% each):</span>
            <span class="preview-value"><?php echo number_format(1000 * $depositPercent / 100, 2); ?> ETB (buyer + seller)</span>
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
            <span class="preview-label">Seller Receives:</span>
            <span class="preview-value preview-total"><?php echo number_format(1000 * (100 - $commissionPercent) / 100, 2); ?> ETB</span>
        </div>
        
        <div class="info-box">
            <p><i class="fas fa-info-circle"></i> <strong>How it works:</strong></p>
            <p>1️⃣ Buyer pays deposit + commission upfront</p>
            <p>2️⃣ Seller pays deposit upfront</p>
            <p>3️⃣ Both deposits held in escrow</p>
            <p>4️⃣ After confirmation, seller receives payment minus commission</p>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>