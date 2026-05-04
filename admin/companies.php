<?php
// admin/companies.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_company'])) {
        $companyId = intval($_POST['company_id']);
        $stmt = $conn->prepare("UPDATE companies SET is_approved = 1 WHERE id = ?");
        $stmt->bind_param("i", $companyId);
        if ($stmt->execute()) {
            $message = "Company approved successfully";
        }
    }
    
    if (isset($_POST['update_subscription'])) {
        $companyId = intval($_POST['company_id']);
        $plan = $_POST['subscription_plan'];
        $expiry = $_POST['subscription_expiry'];
        $stmt = $conn->prepare("UPDATE companies SET subscription_plan = ?, subscription_expiry = ? WHERE id = ?");
        $stmt->bind_param("ssi", $plan, $expiry, $companyId);
        if ($stmt->execute()) {
            $message = "Subscription updated successfully";
        }
    }
}

// Get companies with user info
$sql = "SELECT c.*, u.full_name, u.email, u.phone, u.is_verified 
        FROM companies c 
        JOIN users u ON c.user_id = u.id 
        ORDER BY c.created_at DESC";
$companies = $conn->query($sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .sidebar { width: 260px; background: #1a1a2e; color: white; height: 100vh; position: fixed; }
        .sidebar-header { padding: 24px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #aaa; cursor: pointer; transition: all 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-item i { width: 20px; }
        .main-content { margin-left: 260px; padding: 24px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 28px; font-weight: 600; }
        .logout-btn { padding: 8px 16px; background: #e74c3c; color: white; border-radius: 6px; text-decoration: none; }
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; font-size: 13px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; margin: 2px; }
        .btn-approve { background: #28a745; color: white; }
        .btn-edit { background: #007bff; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 12px; padding: 24px; width: 500px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        .close-modal { float: right; cursor: pointer; font-size: 24px; }
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
                <li class="nav-item active"><i class="fas fa-building"></i> Companies</li>
                <li class="nav-item" onclick="location.href='transactions.php'"><i class="fas fa-exchange-alt"></i> Transactions</li>
                <li class="nav-item" onclick="location.href='disputes.php'"><i class="fas fa-gavel"></i> Disputes</li>
                <li class="nav-item" onclick="location.href='settings.php'"><i class="fas fa-cog"></i> Settings</li>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="top-header">
                <h1 class="page-title">Company Management</h1>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            
            <?php if ($message): ?>
                <div class="message message-success" style="background: #d4edda; padding: 12px; border-radius: 8px; margin-bottom: 20px;"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <div class="section">
                <div class="section-title">All Companies</div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Company Name</th><th>Contact Person</th><th>Email</th><th>Subscription</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php while($row = $companies->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['business_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td>
                                        <span class="badge badge-info"><?php echo ucfirst($row['subscription_plan']); ?></span><br>
                                        <small>Expires: <?php echo $row['subscription_expiry'] ?? 'N/A'; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($row['is_approved']): ?>
                                            <span class="badge badge-success">Approved</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$row['is_approved']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="company_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="approve_company" class="btn-sm btn-approve">Approve</button>
                                            </form>
                                        <?php endif; ?>
                                        <button onclick="openSubscriptionModal(<?php echo $row['id']; ?>)" class="btn-sm btn-edit">Manage Subscription</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div id="subscriptionModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeSubscriptionModal()">&times;</span>
            <h3>Manage Subscription</h3>
            <form method="POST">
                <input type="hidden" name="company_id" id="subCompanyId">
                <div class="form-group">
                    <label>Subscription Plan</label>
                    <select name="subscription_plan">
                        <option value="none">None</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Expiry Date</label>
                    <input type="date" name="subscription_expiry">
                </div>
                <button type="submit" name="update_subscription" class="btn-sm btn-approve">Update</button>
            </form>
        </div>
    </div>
    
    <script>
        function openSubscriptionModal(companyId) {
            document.getElementById('subCompanyId').value = companyId;
            document.getElementById('subscriptionModal').style.display = 'flex';
        }
        
        function closeSubscriptionModal() {
            document.getElementById('subscriptionModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>