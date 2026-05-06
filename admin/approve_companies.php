<?php
// admin/approve_companies.php - Company Approval Management

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

// Check if logged in and is admin
if (!isLoggedIn() || $_SESSION['user_role'] != 'admin') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Company Approvals';
ob_start();

$conn = getDbConnection();
$message = '';
$error = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_id = sanitizeInt($_POST['company_id'] ?? 0);
    $action = sanitizeString($_POST['action'] ?? '');
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE companies SET is_approved = 1, approved_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        if ($stmt->execute()) {
            // Get user_id to update user status
            $user = $conn->query("SELECT user_id FROM companies WHERE id = $company_id")->fetch_assoc();
            if ($user) {
                $conn->query("UPDATE users SET is_verified = 1 WHERE id = {$user['user_id']}");
                
                // Send notification
                $conn->query("INSERT INTO notifications (user_id, title, message, created_at) 
                    VALUES ({$user['user_id']}, 'Company Approved', 'Your company account has been approved! You can now post jobs.', NOW())");
            }
            $message = "Company approved successfully";
        } else {
            $error = "Failed to approve company";
        }
    } elseif ($action === 'reject') {
        $reason = sanitizeString($_POST['reason'] ?? 'No reason provided');
        $user = $conn->query("SELECT user_id FROM companies WHERE id = $company_id")->fetch_assoc();
        
        if ($user) {
            // Send rejection notification
            $conn->query("INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$user['user_id']}, 'Company Rejected', 'Your company registration was rejected. Reason: $reason', NOW())");
            
            // Delete company and user
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM companies WHERE id = $company_id");
                $conn->query("DELETE FROM users WHERE id = {$user['user_id']}");
                $conn->commit();
                $message = "Company rejected and removed";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to reject company";
            }
        }
    }
}

// Get pending companies
$pending_companies = $conn->query("
    SELECT c.*, u.full_name, u.email, u.phone, u.created_at as registered_at
    FROM companies c
    JOIN users u ON c.user_id = u.id
    WHERE c.is_approved = 0
    ORDER BY c.created_at DESC
");

// Get approved companies count
$approved_count = $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 1")->fetch_assoc()['count'];
$pending_count = $conn->query("SELECT COUNT(*) as count FROM companies WHERE is_approved = 0")->fetch_assoc()['count'];
$total_count = $conn->query("SELECT COUNT(*) as count FROM companies")->fetch_assoc()['count'];

$conn->close();
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: white; border-radius: 20px; padding: 24px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .stat-value { font-size: 32px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 13px; color: #64748b; margin-top: 6px; }
    .card { background: white; border-radius: 20px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .company-card { border: 1px solid #e2e8f0; margin-bottom: 20px; transition: all 0.3s; }
    .company-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .company-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
    .company-name { font-size: 20px; font-weight: 700; color: #0f172a; }
    .company-meta { color: #64748b; font-size: 13px; }
    .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 16px 0; padding: 16px; background: #f8fafc; border-radius: 16px; }
    .info-item { display: flex; flex-direction: column; }
    .info-label { font-size: 11px; color: #64748b; text-transform: uppercase; }
    .info-value { font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 4px; }
    .btn-group { display: flex; gap: 12px; margin-top: 16px; }
    .btn-approve { background: #10b981; color: white; padding: 10px 24px; border: none; border-radius: 40px; cursor: pointer; font-weight: 600; }
    .btn-reject { background: #ef4444; color: white; padding: 10px 24px; border: none; border-radius: 40px; cursor: pointer; font-weight: 600; }
    .btn-approve:hover { background: #059669; transform: translateY(-1px); }
    .btn-reject:hover { background: #dc2626; transform: translateY(-1px); }
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .empty-state { text-align: center; padding: 60px; }
    .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    .reject-form { display: none; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .reject-form textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; font-family: inherit; }
    @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .info-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header">
    <h1><i class="fas fa-building"></i> Company Approvals</h1>
    <p>Review and approve company registrations</p>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $total_count; ?></div>
        <div class="stat-label">Total Companies</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $pending_count; ?></div>
        <div class="stat-label">Pending Approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $approved_count; ?></div>
        <div class="stat-label">Approved</div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<?php if ($pending_companies && $pending_companies->num_rows > 0): ?>
    <?php while($company = $pending_companies->fetch_assoc()): ?>
        <div class="card company-card">
            <div class="company-header">
                <div>
                    <div class="company-name"><?php echo htmlspecialchars($company['business_name']); ?></div>
                    <div class="company-meta">Registered: <?php echo date('F d, Y', strtotime($company['registered_at'])); ?></div>
                </div>
                <div class="badge" style="background: #fed7aa; color: #ea580c; padding: 4px 12px; border-radius: 20px;">Pending Review</div>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Contact Person</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['full_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['email']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['phone'] ?: 'Not provided'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Business Type</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['business_type'] ?: 'Not specified'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">TIN Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['tin_number']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($company['address'] ?: 'Not provided'); ?></div>
                </div>
            </div>
            
            <div class="btn-group">
                <button class="btn-approve" onclick="approveCompany(<?php echo $company['id']; ?>)">
                    <i class="fas fa-check"></i> Approve Company
                </button>
                <button class="btn-reject" onclick="showRejectForm(<?php echo $company['id']; ?>)">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
            
            <div id="rejectForm_<?php echo $company['id']; ?>" class="reject-form">
                <form method="POST" onsubmit="return confirm('Reject this company? This will delete the account.')">
                    <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">
                    <input type="hidden" name="action" value="reject">
                    <textarea name="reason" rows="3" placeholder="Reason for rejection..." required></textarea>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn-reject" style="padding: 8px 20px;">Confirm Rejection</button>
                        <button type="button" onclick="hideRejectForm(<?php echo $company['id']; ?>)" style="padding: 8px 20px; background: #64748b; color: white; border: none; border-radius: 40px; cursor: pointer;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-check-circle" style="color: #10b981;"></i>
        <h3>No Pending Approvals</h3>
        <p>All company registrations have been processed.</p>
        <a href="dashboard.php" class="btn" style="display: inline-block; margin-top: 16px; background: #667eea; color: white; padding: 10px 24px; border-radius: 40px; text-decoration: none;">Back to Dashboard</a>
    </div>
<?php endif; ?>

<script>
    function approveCompany(companyId) {
        if (confirm('Approve this company? They will be able to post jobs immediately.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="company_id" value="${companyId}">
                <input type="hidden" name="action" value="approve">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function showRejectForm(companyId) {
        document.getElementById(`rejectForm_${companyId}`).style.display = 'block';
    }
    
    function hideRejectForm(companyId) {
        document.getElementById(`rejectForm_${companyId}`).style.display = 'none';
    }
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>