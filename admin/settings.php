<?php
// admin/settings.php - System Settings

$page_title = 'System Settings';
ob_start();

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    updateSetting('deposit_percent', intval($_POST['deposit_percent']));
    updateSetting('commission_percent', intval($_POST['commission_percent']));
    updateSetting('escrow_days', intval($_POST['escrow_days']));
    updateSetting('min_withdrawal', floatval($_POST['min_withdrawal']));
    updateSetting('max_withdrawal', floatval($_POST['max_withdrawal']));
    $message = "Settings saved successfully";
}

$depositPercent = getSetting('deposit_percent', 30);
$commissionPercent = getSetting('commission_percent', 15);
$escrowDays = getSetting('escrow_days', 14);
$minWithdrawal = getSetting('min_withdrawal', 100);
$maxWithdrawal = getSetting('max_withdrawal', 100000);

$conn->close();
?>

<style>
    .settings-form { max-width: 600px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
    .form-group input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; }
    .form-group small { display: block; margin-top: 6px; font-size: 12px; color: #64748b; }
    .btn-save { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 12px 24px; border: none; border-radius: 40px; font-size: 16px; font-weight: 500; cursor: pointer; }
    .preview-card { background: #f8fafc; border-radius: 16px; padding: 20px; margin-top: 24px; }
    .preview-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0; }
</style>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-cog"></i> General Settings</h2></div>
    
    <?php if ($message): ?>
    <div class="alert alert-success" style="background:#d1fae5; color:#059669; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <form method="POST" class="settings-form">
        <div class="form-group"><label>Deposit Percentage (%)</label><input type="number" name="deposit_percent" value="<?php echo $depositPercent; ?>" min="0" max="100" required><small>Percentage of total transaction amount that both buyer and seller must deposit</small></div>
        <div class="form-group"><label>Commission Percentage (%)</label><input type="number" name="commission_percent" value="<?php echo $commissionPercent; ?>" min="0" max="100" required><small>System commission deducted from each transaction</small></div>
        <div class="form-group"><label>Escrow Hold Period (Days)</label><input type="number" name="escrow_days" value="<?php echo $escrowDays; ?>" min="1" max="90" required><small>Number of days funds are held in escrow after completion</small></div>
        <div class="form-group"><label>Minimum Withdrawal (ETB)</label><input type="number" name="min_withdrawal" value="<?php echo $minWithdrawal; ?>" min="1" required></div>
        <div class="form-group"><label>Maximum Withdrawal (ETB)</label><input type="number" name="max_withdrawal" value="<?php echo $maxWithdrawal; ?>" min="1" required></div>
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Settings</button>
    </form>
    
    <div class="preview-card">
        <h4>Preview: Transaction Calculation</h4>
        <div class="preview-item"><span>Sample Item Price:</span><span>1,000.00 ETB</span></div>
        <div class="preview-item"><span>Deposit Amount (<?php echo $depositPercent; ?>% each):</span><span><?php echo number_format(1000 * $depositPercent / 100, 2); ?> ETB (buyer + seller)</span></div>
        <div class="preview-item"><span>Commission (<?php echo $commissionPercent; ?>%):</span><span><?php echo number_format(1000 * $commissionPercent / 100, 2); ?> ETB</span></div>
        <div class="preview-item"><span>Buyer Pays Upfront:</span><span><?php echo number_format(1000 * ($depositPercent + $commissionPercent) / 100, 2); ?> ETB</span></div>
        <div class="preview-item"><span>Seller Receives:</span><span><?php echo number_format(1000 * (100 - $commissionPercent) / 100, 2); ?> ETB</span></div>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>