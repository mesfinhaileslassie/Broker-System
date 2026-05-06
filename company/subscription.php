<?php
// company/subscription.php - Subscription Management

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

if ($_SESSION['user_role'] != 'company') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

$page_title = 'Subscription Plans';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get company info
$company = $conn->query("
    SELECT c.*, u.balance 
    FROM companies c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.user_id = $user_id
")->fetch_assoc();

$current_plan = $company['subscription_plan'] ?? 'none';
$current_expiry = $company['subscription_expiry'] ?? null;
$is_active = $current_plan != 'none' && strtotime($current_expiry) > time();

// Handle subscription purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan = $_POST['plan'];
    $duration = $_POST['duration'];
    
    $plans = [
        'basic' => ['monthly' => 500, 'yearly' => 5000],
        'premium' => ['monthly' => 1500, 'yearly' => 15000],
        'enterprise' => ['monthly' => 5000, 'yearly' => 50000]
    ];
    
    if (!isset($plans[$plan][$duration])) {
        $error = "Invalid plan selected";
    } else {
        $amount = $plans[$plan][$duration];
        
        if ($company['balance'] < $amount) {
            $error = "Insufficient balance. You need " . formatMoney($amount);
        } else {
            $conn->begin_transaction();
            
            try {
                // Deduct balance
                $conn->query("UPDATE users SET balance = balance - $amount WHERE id = $user_id");
                
                // Calculate new expiry date
                if ($is_active && strtotime($current_expiry) > time()) {
                    $start_date = $current_expiry;
                } else {
                    $start_date = date('Y-m-d H:i:s');
                }
                
                $months = ($duration == 'monthly') ? 1 : 12;
                $expiry_date = date('Y-m-d H:i:s', strtotime("$start_date + $months months"));
                
                // Update company subscription
                $stmt = $conn->prepare("
                    UPDATE companies SET subscription_plan = ?, subscription_expiry = ?, updated_at = NOW() 
                    WHERE user_id = ?
                ");
                $stmt->bind_param("ssi", $plan, $expiry_date, $user_id);
                $stmt->execute();
                
                // Record payment
                $stmt2 = $conn->prepare("
                    INSERT INTO payments (user_id, amount, type, status, created_at) 
                    VALUES (?, ?, 'subscription', 'confirmed', NOW())
                ");
                $stmt2->bind_param("id", $user_id, $amount);
                $stmt2->execute();
                
                // Record wallet transaction
                $stmt3 = $conn->prepare("
                    INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                    VALUES (?, ?, 'withdrawal', ?, NOW())
                ");
                $description = "Subscription payment - $plan " . ucfirst($duration);
                $stmt3->bind_param("ids", $user_id, $amount, $description);
                $stmt3->execute();
                
                $conn->commit();
                $message = "Subscription activated successfully! Your plan is valid until " . date('M d, Y', strtotime($expiry_date));
                
                // Refresh company data
                $company = $conn->query("SELECT * FROM companies WHERE user_id = $user_id")->fetch_assoc();
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to process subscription: " . $e->getMessage();
            }
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
    
    .current-plan {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 20px;
        padding: 28px;
        color: white;
        margin-bottom: 32px;
        text-align: center;
    }
    
    .current-plan h3 {
        font-size: 18px;
        margin-bottom: 8px;
        opacity: 0.9;
    }
    
    .plan-name {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .expiry-date {
        font-size: 14px;
        opacity: 0.8;
    }
    
    .plans-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }
    
    .plan-card {
        background: white;
        border-radius: 24px;
        overflow: hidden;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        position: relative;
    }
    
    .plan-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 20px 35px -10px rgba(0,0,0,0.15);
    }
    
    .plan-card.featured {
        border: 2px solid #667eea;
        transform: scale(1.02);
    }
    
    .plan-card.featured:hover {
        transform: scale(1.04);
    }
    
    .popular-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        background: #f59e0b;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .plan-header {
        padding: 28px;
        text-align: center;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .plan-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }
    
    .plan-title {
        font-size: 24px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .plan-price {
        font-size: 36px;
        font-weight: 800;
        color: #667eea;
    }
    
    .plan-price small {
        font-size: 14px;
        font-weight: 400;
        color: #64748b;
    }
    
    .plan-features {
        padding: 28px;
    }
    
    .feature {
        padding: 8px 0;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        color: #475569;
    }
    
    .feature i {
        width: 20px;
        color: #10b981;
    }
    
    .plan-footer {
        padding: 20px 28px 28px;
        text-align: center;
    }
    
    .btn-subscribe {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-subscribe:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-current {
        width: 100%;
        padding: 12px;
        background: #d1fae5;
        color: #059669;
        border: none;
        border-radius: 40px;
        font-weight: 600;
        cursor: default;
    }
    
    .message {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    .message-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .message-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    .balance-info {
        background: #f8fafc;
        border-radius: 16px;
        padding: 16px;
        margin-top: 20px;
        text-align: center;
    }
    
    @media (max-width: 768px) {
        .plans-grid {
            grid-template-columns: 1fr;
        }
        .plan-card.featured {
            transform: none;
        }
    }
</style>

<div class="page-header">
    <h1>Subscription Plans</h1>
    <p>Choose the perfect plan for your business</p>
</div>

<?php if ($message): ?>
    <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Current Plan -->
<div class="current-plan">
    <h3>Current Plan</h3>
    <div class="plan-name">
        <?php 
        $plan_names = ['none' => 'Free Plan', 'basic' => 'Basic Plan', 'premium' => 'Premium Plan', 'enterprise' => 'Enterprise Plan'];
        echo $plan_names[$current_plan];
        ?>
    </div>
    <?php if ($is_active): ?>
        <div class="expiry-date">
            <i class="fas fa-calendar-alt"></i> Valid until <?php echo date('F d, Y', strtotime($current_expiry)); ?>
        </div>
    <?php else: ?>
        <div class="expiry-date">
            <i class="fas fa-info-circle"></i> No active subscription
        </div>
    <?php endif; ?>
</div>

<!-- Plans Grid -->
<div class="plans-grid">
    <!-- Free Plan -->
    <div class="plan-card">
        <div class="plan-header">
            <div class="plan-icon">🎁</div>
            <div class="plan-title">Free</div>
            <div class="plan-price">0 <small>ETB</small></div>
        </div>
        <div class="plan-features">
            <div class="feature"><i class="fas fa-check"></i> Up to 5 active jobs</div>
            <div class="feature"><i class="fas fa-check"></i> Basic support</div>
            <div class="feature"><i class="fas fa-check"></i> 30 days listing duration</div>
            <div class="feature"><i class="fas fa-times" style="color: #ef4444;"></i> Featured listings</div>
            <div class="feature"><i class="fas fa-times" style="color: #ef4444;"></i> Priority support</div>
        </div>
        <div class="plan-footer">
            <?php if ($current_plan == 'none' && !$is_active): ?>
                <button class="btn-current" disabled>Current Plan</button>
            <?php else: ?>
                <button class="btn-subscribe" onclick="alert('Free plan is always available')">Current Plan</button>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Basic Plan -->
    <div class="plan-card">
        <div class="plan-header">
            <div class="plan-icon">⭐</div>
            <div class="plan-title">Basic</div>
            <div class="plan-price">500 <small>ETB/mo</small></div>
            <div style="font-size: 12px; color: #64748b;">or 5,000 ETB/year</div>
        </div>
        <div class="plan-features">
            <div class="feature"><i class="fas fa-check"></i> Up to 20 active jobs</div>
            <div class="feature"><i class="fas fa-check"></i> Priority support</div>
            <div class="feature"><i class="fas fa-check"></i> 60 days listing duration</div>
            <div class="feature"><i class="fas fa-check"></i> Basic analytics</div>
            <div class="feature"><i class="fas fa-times" style="color: #ef4444;"></i> Featured listings</div>
        </div>
        <div class="plan-footer">
            <?php if ($current_plan == 'basic' && $is_active): ?>
                <button class="btn-current" disabled>Current Plan</button>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="plan" value="basic">
                    <select name="duration" style="width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <option value="monthly">Monthly (500 ETB)</option>
                        <option value="yearly">Yearly (5,000 ETB) Save 17%</option>
                    </select>
                    <button type="submit" class="btn-subscribe">Subscribe Now</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Premium Plan (Featured) -->
    <div class="plan-card featured">
        <div class="popular-badge">Most Popular</div>
        <div class="plan-header">
            <div class="plan-icon">👑</div>
            <div class="plan-title">Premium</div>
            <div class="plan-price">1,500 <small>ETB/mo</small></div>
            <div style="font-size: 12px; color: #64748b;">or 15,000 ETB/year</div>
        </div>
        <div class="plan-features">
            <div class="feature"><i class="fas fa-check"></i> Unlimited active jobs</div>
            <div class="feature"><i class="fas fa-check"></i> 24/7 priority support</div>
            <div class="feature"><i class="fas fa-check"></i> 90 days listing duration</div>
            <div class="feature"><i class="fas fa-check"></i> Advanced analytics</div>
            <div class="feature"><i class="fas fa-check"></i> Featured listings (5/month)</div>
            <div class="feature"><i class="fas fa-check"></i> Dedicated account manager</div>
        </div>
        <div class="plan-footer">
            <?php if ($current_plan == 'premium' && $is_active): ?>
                <button class="btn-current" disabled>Current Plan</button>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="plan" value="premium">
                    <select name="duration" style="width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <option value="monthly">Monthly (1,500 ETB)</option>
                        <option value="yearly">Yearly (15,000 ETB) Save 17%</option>
                    </select>
                    <button type="submit" class="btn-subscribe">Subscribe Now</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Enterprise Plan -->
    <div class="plan-card">
        <div class="plan-header">
            <div class="plan-icon">🏢</div>
            <div class="plan-title">Enterprise</div>
            <div class="plan-price">5,000 <small>ETB/mo</small></div>
            <div style="font-size: 12px; color: #64748b;">or 50,000 ETB/year</div>
        </div>
        <div class="plan-features">
            <div class="feature"><i class="fas fa-check"></i> Everything in Premium</div>
            <div class="feature"><i class="fas fa-check"></i> API access</div>
            <div class="feature"><i class="fas fa-check"></i> Custom integrations</div>
            <div class="feature"><i class="fas fa-check"></i> Multiple users/accounts</div>
            <div class="feature"><i class="fas fa-check"></i> Custom contract terms</div>
            <div class="feature"><i class="fas fa-check"></i> Dedicated support team</div>
        </div>
        <div class="plan-footer">
            <?php if ($current_plan == 'enterprise' && $is_active): ?>
                <button class="btn-current" disabled>Current Plan</button>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="plan" value="enterprise">
                    <select name="duration" style="width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <option value="monthly">Monthly (5,000 ETB)</option>
                        <option value="yearly">Yearly (50,000 ETB) Save 17%</option>
                    </select>
                    <button type="submit" class="btn-subscribe">Contact Sales</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="balance-info">
    <strong>Available Balance:</strong> <?php echo formatMoney($company['balance'] ?? 0); ?>
    <div style="margin-top: 8px;">
        <a href="/broker_system/user/wallet.php" class="btn-sm" style="background: #667eea; color: white; padding: 6px 16px; border-radius: 20px; text-decoration: none;">Add Funds →</a>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>