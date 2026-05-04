<?php
// admin/companies.php - Companies Management

$page_title = 'Companies Management';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_company'])) {
        $companyId = intval($_POST['company_id']);
        $conn->query("UPDATE companies SET is_approved = 1 WHERE id = $companyId");
        $message = "Company approved successfully";
    }
    
    if (isset($_POST['update_subscription'])) {
        $companyId = intval($_POST['company_id']);
        $plan = $_POST['subscription_plan'];
        $expiry = $_POST['subscription_expiry'];
        $conn->query("UPDATE companies SET subscription_plan = '$plan', subscription_expiry = '$expiry' WHERE id = $companyId");
        $message = "Subscription updated successfully";
    }
}

$companies = $conn->query("
    SELECT c.*, u.full_name, u.email, u.phone, u.is_verified 
    FROM companies c 
    JOIN users u ON c.user_id = u.id 
    ORDER BY c.created_at DESC
");

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM companies")->fetch_assoc()['count'],
    'approved' => $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 1")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 0")->fetch_assoc()['count'],
    'subscribed' => $conn->query("SELECT COUNT(*) as count FROM companies WHERE subscription_plan != 'none'")->fetch_assoc()['count'],
];

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stat-value { font-size: 32px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .modal-content {
        background: white;
        border-radius: 20px;
        padding: 24px;
        width: 400px;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
</style>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Companies</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['approved']; ?></div><div class="stat-label">Approved</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['pending']; ?></div><div class="stat-label">Pending Approval</div></div>
    <div class="stat-card"><div class="stat-value"><?php echo $stats['subscribed']; ?></div><div class="stat-label">Active Subscriptions</div></div>
</div>

<?php if ($message): ?>
<div class="alert alert-success" style="background:#d1fae5; color:#059669; padding:12px; border-radius:12px; margin-bottom:20px;"><?php echo $message; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-building"></i> All Companies</h2></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>ID</th><th>Company Name</th><th>Contact Person</th><th>Email</th><th>Phone</th><th>Subscription</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while($row = $companies->fetch_assoc()): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['business_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                    <td><span class="badge badge-info"><?php echo ucfirst($row['subscription_plan']); ?></span><br><small>Expires: <?php echo $row['subscription_expiry'] ?? 'N/A'; ?></small></td>
                    <td><?php echo $row['is_approved'] ? '<span class="badge badge-success">Approved</span>' : '<span class="badge badge-warning">Pending</span>'; ?></td>
                    <td>
                        <?php if (!$row['is_approved']): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="company_id" value="<?php echo $row['id']; ?>"><button type="submit" name="approve_company" class="btn-sm btn-success">Approve</button></form>
                        <?php endif; ?>
                        <button onclick="openSubscriptionModal(<?php echo $row['id']; ?>, '<?php echo $row['subscription_plan']; ?>', '<?php echo $row['subscription_expiry']; ?>')" class="btn-sm btn-primary">Subscription</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="subscriptionModal" class="modal">
    <div class="modal-content">
        <div class="card-header"><h2>Manage Subscription</h2><span onclick="closeSubscriptionModal()" style="cursor:pointer;">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="company_id" id="subCompanyId">
            <div class="form-group"><label>Plan</label><select name="subscription_plan" id="subPlan"><option value="none">None</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></div>
            <div class="form-group"><label>Expiry Date</label><input type="date" name="subscription_expiry" id="subExpiry"></div>
            <button type="submit" name="update_subscription" class="btn-sm btn-primary">Update</button>
            <button type="button" onclick="closeSubscriptionModal()" class="btn-sm">Cancel</button>
        </form>
    </div>
</div>

<script>
function openSubscriptionModal(id, plan, expiry) {
    document.getElementById('subCompanyId').value = id;
    document.getElementById('subPlan').value = plan || 'none';
    document.getElementById('subExpiry').value = expiry || '';
    document.getElementById('subscriptionModal').style.display = 'flex';
}
function closeSubscriptionModal() { document.getElementById('subscriptionModal').style.display = 'none'; }
window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = 'none'; }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>