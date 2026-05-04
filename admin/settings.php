<?php
// admin/settings.php - Updated with per-item deposit/commission settings

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_general'])) {
        // Save general settings
        updateSetting('site_name', $_POST['site_name'] ?? 'Ethio Brokerplace');
        updateSetting('escrow_days', intval($_POST['escrow_days']));
        updateSetting('min_withdrawal', floatval($_POST['min_withdrawal']));
        updateSetting('max_withdrawal', floatval($_POST['max_withdrawal']));
        updateSetting('maintenance_mode', isset($_POST['maintenance_mode']) ? 1 : 0);
        $message = "General settings saved successfully";
    }
    
    if (isset($_POST['save_percentages'])) {
        // Save deposit and commission percentages per item type
        $itemTypes = ['product', 'job', 'rental'];
        foreach ($itemTypes as $type) {
            $deposit = intval($_POST["deposit_{$type}"]);
            $commission = intval($_POST["commission_{$type}"]);
            updateSetting("deposit_percent_{$type}", $deposit);
            updateSetting("commission_percent_{$type}", $commission);
        }
        $message = "Percentage settings saved successfully";
    }
    
    if (isset($_POST['save_subscription_plan'])) {
        $planId = intval($_POST['plan_id'] ?? 0);
        $name = $_POST['name'];
        $type = $_POST['type'];
        $price = floatval($_POST['price']);
        $features = json_encode([
            'job_posts' => intval($_POST['job_posts']),
            'featured_listings' => intval($_POST['featured_listings']),
            'priority_support' => isset($_POST['priority_support'])
        ]);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($planId > 0) {
            $stmt = $conn->prepare("UPDATE subscription_plans SET name=?, type=?, price=?, features=?, is_active=? WHERE id=?");
            $stmt->bind_param("ssdsi", $name, $type, $price, $features, $is_active, $planId);
        } else {
            $stmt = $conn->prepare("INSERT INTO subscription_plans (name, type, price, features, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdsi", $name, $type, $price, $features, $is_active);
        }
        
        if ($stmt->execute()) {
            $message = "Subscription plan saved successfully";
        } else {
            $error = "Failed to save subscription plan";
        }
    }
    
    if (isset($_POST['delete_plan'])) {
        $planId = intval($_POST['plan_id']);
        $stmt = $conn->prepare("DELETE FROM subscription_plans WHERE id = ?");
        $stmt->bind_param("i", $planId);
        if ($stmt->execute()) {
            $message = "Subscription plan deleted";
        }
    }
}

// Get current settings
$siteName = getSetting('site_name', 'Ethio Brokerplace');
$escrowDays = getSetting('escrow_days', 14);
$minWithdrawal = getSetting('min_withdrawal', 100);
$maxWithdrawal = getSetting('max_withdrawal', 100000);
$maintenanceMode = getSetting('maintenance_mode', 0);

// Get percentages per item type
$itemTypes = [
    'product' => ['name' => 'Products (Buy/Sell)', 'icon' => '🛍️'],
    'job' => ['name' => 'Jobs', 'icon' => '💼'],
    'rental' => ['name' => 'Rentals', 'icon' => '🏠']
];

foreach ($itemTypes as $key => $type) {
    $itemTypes[$key]['deposit'] = getSetting("deposit_percent_{$key}", 30);
    $itemTypes[$key]['commission'] = getSetting("commission_percent_{$key}", 15);
}

// Get subscription plans
$subscriptionPlans = $conn->query("SELECT * FROM subscription_plans ORDER BY type, price");

$csrfToken = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; overflow-y: auto; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .section { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .form-group input:focus { outline: none; border-color: #667eea; }
        .form-group small { display: block; margin-top: 6px; font-size: 12px; color: #888; }
        .form-group.checkbox { display: flex; align-items: center; gap: 10px; }
        .form-group.checkbox input { width: auto; }
        .btn-save { background: #667eea; color: white; padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; font-weight: 500; cursor: pointer; }
        .btn-add { background: #28a745; color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; }
        .btn-delete { background: #dc3545; color: white; padding: 4px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .percentage-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .percentage-card { background: #f8f9fa; border-radius: 12px; padding: 20px; border: 1px solid #e0e0e0; }
        .percentage-card h3 { margin-bottom: 15px; color: #333; }
        .plans-table { width: 100%; border-collapse: collapse; }
        .plans-table th, .plans-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90%; max-height: 80vh; overflow-y: auto; }
        .close-modal { float: right; cursor: pointer; font-size: 24px; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; }
        .tab { padding: 10px 20px; cursor: pointer; border: none; background: none; font-size: 16px; }
        .tab.active { border-bottom: 2px solid #667eea; color: #667eea; font-weight: 600; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
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
                <li class="nav-item" onclick="location.href='dashboard.php'"><i class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="location.href='users.php'"><i class="fas fa-users"></i> Users</li>
                <li class="nav-item" onclick="location.href='companies.php'"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='payments.php'"><i class="fas fa-credit-card"></i> Payments</li>
                <li class="nav-item" onclick="location.href='analytics.php'"><i class="fas fa-chart-line"></i> Analytics</li>
                <li class="nav-item" onclick="location.href='messages.php'"><i class="fas fa-envelope"></i> Messages</li>
                <li class="nav-item" onclick="location.href='tickets.php'"><i class="fas fa-ticket-alt"></i> Support</li>
                <li class="nav-item" onclick="location.href='withdrawals.php'"><i class="fas fa-money-bill-wave"></i> Withdrawals</li>
                <li class="nav-item active"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">System Settings</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="tabs">
                <button class="tab active" onclick="showTab('general')">General Settings</button>
                <button class="tab" onclick="showTab('percentages')">Deposit & Commission</button>
                <button class="tab" onclick="showTab('subscriptions')">Subscription Plans</button>
            </div>
            
            <!-- General Settings Tab -->
            <div id="general" class="tab-content active">
                <div class="section">
                    <div class="section-title">General Settings</div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="form-group">
                            <label>Site Name</label>
                            <input type="text" name="site_name" value="<?php echo htmlspecialchars($siteName); ?>">
                        </div>
                        <div class="form-group">
                            <label>Escrow Hold Period (Days)</label>
                            <input type="number" name="escrow_days" value="<?php echo $escrowDays; ?>" min="1" max="90">
                        </div>
                        <div class="form-group">
                            <label>Minimum Withdrawal (ETB)</label>
                            <input type="number" name="min_withdrawal" value="<?php echo $minWithdrawal; ?>" min="1">
                        </div>
                        <div class="form-group">
                            <label>Maximum Withdrawal (ETB)</label>
                            <input type="number" name="max_withdrawal" value="<?php echo $maxWithdrawal; ?>" min="1">
                        </div>
                        <div class="form-group checkbox">
                            <input type="checkbox" name="maintenance_mode" id="maintenance_mode" value="1" <?php echo $maintenanceMode ? 'checked' : ''; ?>>
                            <label for="maintenance_mode">Enable Maintenance Mode</label>
                        </div>
                        <button type="submit" name="save_general" class="btn-save"><i class="fas fa-save"></i> Save Settings</button>
                    </form>
                </div>
            </div>
            
            <!-- Deposit & Commission Tab -->
            <div id="percentages" class="tab-content">
                <div class="section">
                    <div class="section-title">Deposit & Commission by Item Type</div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="percentage-grid">
                            <?php foreach ($itemTypes as $key => $type): ?>
                                <div class="percentage-card">
                                    <h3><?php echo $type['icon']; ?> <?php echo $type['name']; ?></h3>
                                    <div class="form-group">
                                        <label>Deposit Percentage (%)</label>
                                        <input type="number" name="deposit_<?php echo $key; ?>" value="<?php echo $type['deposit']; ?>" min="0" max="100" required>
                                        <small>Buyer and seller must deposit this %</small>
                                    </div>
                                    <div class="form-group">
                                        <label>Commission Percentage (%)</label>
                                        <input type="number" name="commission_<?php echo $key; ?>" value="<?php echo $type['commission']; ?>" min="0" max="100" required>
                                        <small>System takes this % from each transaction</small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" name="save_percentages" class="btn-save"><i class="fas fa-save"></i> Save Percentage Settings</button>
                    </form>
                </div>
            </div>
            
            <!-- Subscription Plans Tab -->
            <div id="subscriptions" class="tab-content">
                <div class="section">
                    <div class="section-title">
                        Subscription Plans
                        <button onclick="openPlanModal()" class="btn-add"><i class="fas fa-plus"></i> Add Plan</button>
                    </div>
                    <table class="plans-table">
                        <thead>
                            <tr><th>Name</th><th>Type</th><th>Price</th><th>Job Posts</th><th>Featured</th><th>Priority Support</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php while($plan = $subscriptionPlans->fetch_assoc()): 
                                $features = json_decode($plan['features'], true);
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($plan['name']); ?></strong></td>
                                    <td><span class="badge badge-info"><?php echo ucfirst($plan['type']); ?></span></td>
                                    <td><?php echo formatMoney($plan['price']); ?></td>
                                    <td><?php echo $features['job_posts'] ?? 0; ?></td>
                                    <td><?php echo $features['featured_listings'] ?? 0; ?></td>
                                    <td><?php echo ($features['priority_support'] ?? false) ? '✓ Yes' : '✗ No'; ?></td>
                                    <td><?php echo $plan['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge">Inactive</span>'; ?></td>
                                    <td>
                                        <button onclick="editPlan(<?php echo htmlspecialchars(json_encode($plan)); ?>)" class="btn-add" style="background:#007bff; padding:4px 10px;">Edit</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this plan?')">
                                            <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                            <button type="submit" name="delete_plan" class="btn-delete">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Plan Modal -->
    <div id="planModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closePlanModal()">&times;</span>
            <h3 id="modalTitle">Add Subscription Plan</h3>
            <form method="POST">
                <input type="hidden" name="plan_id" id="plan_id" value="0">
                <div class="form-group">
                    <label>Plan Name</label>
                    <input type="text" name="name" id="plan_name" required>
                </div>
                <div class="form-group">
                    <label>Plan Type</label>
                    <select name="type" id="plan_type" required>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Price (ETB)</label>
                    <input type="number" name="price" id="plan_price" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Number of Job Posts</label>
                    <input type="number" name="job_posts" id="plan_job_posts" value="0">
                </div>
                <div class="form-group">
                    <label>Featured Listings</label>
                    <input type="number" name="featured_listings" id="plan_featured" value="0">
                </div>
                <div class="form-group checkbox">
                    <input type="checkbox" name="priority_support" id="plan_support" value="1">
                    <label for="plan_support">Priority Support</label>
                </div>
                <div class="form-group checkbox">
                    <input type="checkbox" name="is_active" id="plan_active" value="1" checked>
                    <label for="plan_active">Active</label>
                </div>
                <button type="submit" name="save_subscription_plan" class="btn-save">Save Plan</button>
            </form>
        </div>
    </div>
    
    <script>
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
        
        function openPlanModal() {
            document.getElementById('modalTitle').innerText = 'Add Subscription Plan';
            document.getElementById('plan_id').value = '';
            document.getElementById('plan_name').value = '';
            document.getElementById('plan_type').value = 'monthly';
            document.getElementById('plan_price').value = '';
            document.getElementById('plan_job_posts').value = '0';
            document.getElementById('plan_featured').value = '0';
            document.getElementById('plan_support').checked = false;
            document.getElementById('plan_active').checked = true;
            document.getElementById('planModal').style.display = 'flex';
        }
        
        function editPlan(plan) {
            document.getElementById('modalTitle').innerText = 'Edit Subscription Plan';
            document.getElementById('plan_id').value = plan.id;
            document.getElementById('plan_name').value = plan.name;
            document.getElementById('plan_type').value = plan.type;
            document.getElementById('plan_price').value = plan.price;
            let features = JSON.parse(plan.features);
            document.getElementById('plan_job_posts').value = features.job_posts || 0;
            document.getElementById('plan_featured').value = features.featured_listings || 0;
            document.getElementById('plan_support').checked = features.priority_support || false;
            document.getElementById('plan_active').checked = plan.is_active == 1;
            document.getElementById('planModal').style.display = 'flex';
        }
        
        function closePlanModal() {
            document.getElementById('planModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>