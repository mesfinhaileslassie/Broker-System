<?php
// admin/settings.php - System Settings (Redesigned)

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
    :root {
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --primary-soft: #eef2ff;
        --success: #10b981;
        --warning: #f59e0b;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        --radius-lg: 1rem;
        --radius-xl: 1.5rem;
        --radius-2xl: 2rem;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }

    /* Main content wrapper - assumes layout.php provides container */
    .settings-wrapper {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.5rem;
    }

    /* Header area */
    .settings-header {
        margin-bottom: 2rem;
    }

    .settings-header h1 {
        font-size: 1.875rem;
        font-weight: 700;
        background: linear-gradient(135deg, #0f172a, #334155);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        letter-spacing: -0.02em;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .settings-header h1 i {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        font-size: 1.8rem;
    }

    .settings-header p {
        color: #475569;
        margin-top: 0.5rem;
        font-size: 0.9rem;
    }

    /* Alert toasts */
    .alert-toast {
        background: white;
        border-radius: var(--radius-lg);
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: var(--shadow-lg);
        border-left: 5px solid var(--success);
        animation: slideIn 0.3s ease;
    }

    .alert-toast.success {
        border-left-color: var(--success);
        background: #ecfdf5;
    }

    .alert-toast i {
        font-size: 1.25rem;
        color: var(--success);
    }

    .alert-toast span {
        color: #065f46;
        font-weight: 500;
        font-size: 0.9rem;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Two column layout */
    .settings-grid {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 1.75rem;
        align-items: start;
    }

    /* Form card */
    .form-card {
        background: white;
        border-radius: var(--radius-2xl);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        border: 1px solid rgba(226, 232, 240, 0.6);
    }

    .form-header {
        padding: 1.5rem 2rem;
        background: white;
        border-bottom: 1px solid #eef2ff;
    }

    .form-header h2 {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--gray-800);
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .form-header h2 i {
        color: var(--primary);
        font-size: 1.3rem;
    }

    .form-body {
        padding: 1.75rem 2rem 2rem;
    }

    /* Form groups - modern */
    .setting-group {
        margin-bottom: 1.75rem;
    }

    .setting-group label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        color: var(--gray-700);
        font-size: 0.85rem;
        margin-bottom: 0.6rem;
        letter-spacing: -0.2px;
    }

    .setting-group label i {
        color: var(--primary);
        font-size: 0.9rem;
        width: 20px;
    }

    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-wrapper input {
        width: 100%;
        padding: 0.85rem 1rem;
        border: 1.5px solid #e2e8f0;
        border-radius: 1rem;
        font-size: 0.9rem;
        transition: all 0.2s;
        background: #fefefe;
        font-weight: 500;
    }

    .input-wrapper input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        background: white;
    }

    .input-symbol {
        position: absolute;
        right: 1rem;
        color: #94a3b8;
        font-weight: 500;
        font-size: 0.85rem;
        pointer-events: none;
    }

    .setting-group small {
        display: block;
        margin-top: 0.5rem;
        font-size: 0.7rem;
        color: #64748b;
        line-height: 1.4;
        padding-left: 0.25rem;
    }

    .btn-modern {
        background: linear-gradient(105deg, var(--primary), var(--primary-dark));
        color: white;
        border: none;
        padding: 0.9rem 1.75rem;
        border-radius: 3rem;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.25s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        width: 100%;
        margin-top: 0.75rem;
        box-shadow: 0 4px 8px rgba(79, 70, 229, 0.2);
    }

    .btn-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 20px -8px rgba(79, 70, 229, 0.4);
        background: linear-gradient(105deg, #5b52f0, #5b21b6);
    }

    /* Right preview card */
    .preview-card {
        background: white;
        border-radius: var(--radius-2xl);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        position: sticky;
        top: 1.5rem;
        border: 1px solid rgba(226, 232, 240, 0.8);
        transition: all 0.2s;
    }

    .preview-header {
        background: #fefce8;
        padding: 1.2rem 1.5rem;
        border-bottom: 1px solid #fde68a;
    }

    .preview-header h3 {
        font-size: 1rem;
        font-weight: 700;
        color: #854d0e;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .preview-header h3 i {
        color: #eab308;
        font-size: 1.2rem;
    }

    .preview-body {
        padding: 1.5rem;
    }

    .calc-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.9rem 0;
        border-bottom: 1px dashed #f1f5f9;
    }

    .calc-row:last-of-type {
        border-bottom: none;
    }

    .calc-label {
        font-size: 0.8rem;
        color: #475569;
        font-weight: 500;
    }

    .calc-value {
        font-weight: 700;
        color: #1e293b;
        background: #f8fafc;
        padding: 0.2rem 0.6rem;
        border-radius: 40px;
        font-size: 0.85rem;
    }

    .calc-total {
        background: linear-gradient(115deg, #4f46e5, #7c3aed);
        color: white;
        padding: 0.3rem 0.9rem;
        border-radius: 40px;
        font-weight: 700;
    }

    .separator {
        height: 2px;
        background: linear-gradient(to right, #e2e8f0, transparent);
        margin: 0.5rem 0 0.25rem;
    }

    .highlight-box {
        background: #f0f9ff;
        border-radius: 1rem;
        padding: 1rem;
        margin-top: 1.2rem;
        display: flex;
        align-items: center;
        gap: 0.8rem;
        border: 1px solid #bae6fd;
    }

    .highlight-box i {
        font-size: 1.3rem;
        color: #0284c7;
    }

    .highlight-box p {
        font-size: 0.7rem;
        color: #0c4a6e;
        line-height: 1.4;
        margin: 0;
    }

    .badge-step {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        background: #e0e7ff;
        color: #4338ca;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: bold;
        margin-right: 0.5rem;
    }

    /* Responsive */
    @media (max-width: 900px) {
        .settings-grid {
            grid-template-columns: 1fr;
        }
        .preview-card {
            position: static;
        }
        .form-body {
            padding: 1.5rem;
        }
        .settings-wrapper {
            padding: 1rem;
        }
    }

    @media (max-width: 480px) {
        .form-header h2 {
            font-size: 1.1rem;
        }
        .calc-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }
    }
</style>

<div class="settings-wrapper">
    <div class="settings-header">
        <h1>
            <i class="fas fa-sliders-h"></i> 
            Platform Configuration
        </h1>
        <p>Fine-tune broker fees, deposit rules, withdrawal limits, and escrow logic</p>
    </div>

    <?php if ($message): ?>
    <div class="alert-toast success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <div class="settings-grid">
        <!-- MAIN FORM -->
        <div class="form-card">
            <div class="form-header">
                <h2>
                    <i class="fas fa-cog"></i> 
                    Core System Parameters
                </h2>
            </div>
            <div class="form-body">
                <form method="POST">
                    <div class="setting-group">
                        <label><i class="fas fa-percent"></i> Deposit Percentage</label>
                        <div class="input-wrapper">
                            <input type="number" name="deposit_percent" value="<?php echo $depositPercent; ?>" min="0" max="100" step="1" required>
                            <span class="input-symbol">%</span>
                        </div>
                        <small>Buyer and seller each deposit this % of total transaction value. Held safely in escrow.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-hand-holding-usd"></i> Commission Fee</label>
                        <div class="input-wrapper">
                            <input type="number" name="commission_percent" value="<?php echo $commissionPercent; ?>" min="0" max="100" step="1" required>
                            <span class="input-symbol">%</span>
                        </div>
                        <small>Platform revenue share deducted from the final payout to seller.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-clock"></i> Escrow Hold Period</label>
                        <div class="input-wrapper">
                            <input type="number" name="escrow_days" value="<?php echo $escrowDays; ?>" min="1" max="90" step="1" required>
                            <span class="input-symbol">days</span>
                        </div>
                        <small>Duration funds remain locked after transaction completion, ensuring dispute resolution.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-money-bill-wave"></i> Minimum Withdrawal</label>
                        <div class="input-wrapper">
                            <input type="number" name="min_withdrawal" value="<?php echo $minWithdrawal; ?>" min="1" step="1" required>
                            <span class="input-symbol">ETB</span>
                        </div>
                        <small>Lowest amount users can request to withdraw from their wallet.</small>
                    </div>

                    <div class="setting-group">
                        <label><i class="fas fa-chart-line"></i> Maximum Withdrawal</label>
                        <div class="input-wrapper">
                            <input type="number" name="max_withdrawal" value="<?php echo $maxWithdrawal; ?>" min="1" step="1" required>
                            <span class="input-symbol">ETB</span>
                        </div>
                        <small>Per-request withdrawal ceiling to manage risk and compliance.</small>
                    </div>

                    <button type="submit" name="save_settings" class="btn-modern">
                        <i class="fas fa-save"></i> Apply Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- RIGHT PREVIEW CARD (dynamic preview) -->
        <div class="preview-card">
            <div class="preview-header">
                <h3>
                    <i class="fas fa-calculator"></i> 
                    Live Preview Simulation
                </h3>
            </div>
            <div class="preview-body">
                <div class="calc-row">
                    <span class="calc-label">📦 Item Price (sample)</span>
                    <span class="calc-value">1,000.00 ETB</span>
                </div>
                <div class="calc-row">
                    <span class="calc-label">🔒 Deposit (<?php echo $depositPercent; ?>% each)</span>
                    <span class="calc-value"><?php echo number_format(1000 * $depositPercent / 100, 2); ?> ETB <span style="color:#6c757d;">(Buyer + Seller)</span></span>
                </div>
                <div class="calc-row">
                    <span class="calc-label">🏛️ Platform Commission (<?php echo $commissionPercent; ?>%)</span>
                    <span class="calc-value"><?php echo number_format(1000 * $commissionPercent / 100, 2); ?> ETB</span>
                </div>
                <div class="separator"></div>
                <div class="calc-row">
                    <span class="calc-label">💳 Buyer pays upfront</span>
                    <span class="calc-value"><strong><?php echo number_format(1000 * ($depositPercent + $commissionPercent) / 100, 2); ?> ETB</strong></span>
                </div>
                <div class="calc-row">
                    <span class="calc-label">💰 Seller receives (net)</span>
                    <span class="calc-value calc-total"><?php echo number_format(1000 * (100 - $commissionPercent) / 100, 2); ?> ETB</span>
                </div>
                
                <div class="highlight-box">
                    <i class="fas fa-shield-alt"></i>
                    <p><strong>Escrow flow overview:</strong> Both deposits locked → successful trade confirmation → seller gets paid minus fee, deposits returned.</p>
                </div>
                
                <div style="margin-top: 1rem; font-size:0.7rem; background:#faf5ff; border-radius:1rem; padding:0.7rem;">
                    <p style="display:flex; gap:12px; flex-wrap:wrap; margin:0;">
                        <span><span class="badge-step">1</span> Buyer deposit + fee</span>
                        <span><span class="badge-step">2</span> Seller deposit</span>
                        <span><span class="badge-step">3</span> Milestone release</span>
                        <span><span class="badge-step">4</span> Completion</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>