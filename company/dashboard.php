<?php
// company/dashboard.php - Company Dashboard

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

// Check if user has company role
if ($_SESSION['user_role'] != 'company') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

$page_title = 'Company Dashboard';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$company_id = null;

// Get company info
$company = $conn->query("
    SELECT c.*, u.balance 
    FROM companies c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.user_id = $user_id
")->fetch_assoc();

if ($company) {
    $company_id = $company['id'];
}

// Get statistics
$stats = [
    'active_jobs' => 0,
    'total_applications' => 0,
    'active_subscription' => $company ? ($company['subscription_plan'] != 'none' && strtotime($company['subscription_expiry']) > time()) : false,
    'total_spent' => 0,
    'pending_jobs' => 0
];

if ($company_id) {
    // Active job posts
    $result = $conn->query("
        SELECT COUNT(*) as count FROM listings 
        WHERE seller_id = $user_id AND type = 'job' AND status = 'active' AND approval_status = 'approved'
    ");
    $stats['active_jobs'] = $result->fetch_assoc()['count'];
    
    // Pending jobs
    $result = $conn->query("
        SELECT COUNT(*) as count FROM listings 
        WHERE seller_id = $user_id AND type = 'job' AND approval_status = 'pending'
    ");
    $stats['pending_jobs'] = $result->fetch_assoc()['count'];
    
    // Total applications (through transactions)
    $result = $conn->query("
        SELECT COUNT(*) as count FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE l.seller_id = $user_id AND l.type = 'job'
    ");
    $stats['total_applications'] = $result->fetch_assoc()['count'];
    
    // Total spent on subscriptions
    $result = $conn->query("
        SELECT SUM(amount) as total FROM payments 
        WHERE user_id = $user_id AND type = 'subscription' AND status = 'confirmed'
    ");
    $stats['total_spent'] = $result->fetch_assoc()['total'] ?? 0;
}

// Get recent applications
$recent_applications = $conn->query("
    SELECT t.*, l.title as job_title, u.full_name as applicant_name, u.email as applicant_email
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.buyer_id = u.id
    WHERE l.seller_id = $user_id AND l.type = 'job'
    ORDER BY t.created_at DESC
    LIMIT 5
");

// Get recent job posts
$recent_jobs = $conn->query("
    SELECT * FROM listings 
    WHERE seller_id = $user_id AND type = 'job'
    ORDER BY created_at DESC
    LIMIT 5
");

$conn->close();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .stat-icon {
        font-size: 32px;
        margin-bottom: 12px;
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
    }
    
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
    }
    
    .subscription-banner {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 20px;
        padding: 24px;
        color: white;
        margin-bottom: 28px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .subscription-status {
        background: rgba(255,255,255,0.2);
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 13px;
    }
    
    .btn {
        padding: 10px 24px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-block;
    }
    
    .btn-primary {
        background: white;
        color: #667eea;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid white;
        color: white;
    }
    
    .btn-outline:hover {
        background: white;
        color: #667eea;
    }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f1f5f9;
    }
    
    .card-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: #0f172a;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }
    
    th {
        font-weight: 600;
        color: #64748b;
        background: #fafbfc;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    
    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-info { background: #dbeafe; color: #1e40af; }
    
    .quick-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 28px;
    }
    
    .action-btn {
        background: white;
        padding: 12px 24px;
        border-radius: 40px;
        text-decoration: none;
        color: #334155;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        color: #667eea;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .subscription-banner {
            flex-direction: column;
            text-align: center;
        }
    }
</style>

<!-- Welcome Section -->
<div class="welcome-section" style="margin-bottom: 24px;">
    <h1 style="font-size: 28px; font-weight: 700; color: #0f172a;">Company Dashboard</h1>
    <p style="color: #64748b;">Manage your job posts and track applicants</p>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <a href="job_posts.php?action=create" class="action-btn">
        <i class="fas fa-plus-circle"></i> Post New Job
    </a>
    <a href="job_posts.php" class="action-btn">
        <i class="fas fa-briefcase"></i> Manage Jobs
    </a>
    <a href="subscription.php" class="action-btn">
        <i class="fas fa-crown"></i> Subscription
    </a>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">💼</div>
        <div class="stat-value"><?php echo $stats['active_jobs']; ?></div>
        <div class="stat-label">Active Jobs</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📝</div>
        <div class="stat-value"><?php echo $stats['total_applications']; ?></div>
        <div class="stat-label">Total Applications</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⏳</div>
        <div class="stat-value"><?php echo $stats['pending_jobs']; ?></div>
        <div class="stat-label">Pending Approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div class="stat-value"><?php echo formatMoney($stats['total_spent']); ?></div>
        <div class="stat-label">Total Spent</div>
    </div>
</div>

<!-- Subscription Banner -->
<div class="subscription-banner">
    <div>
        <h3 style="margin-bottom: 4px;">
            <i class="fas fa-crown"></i> 
            <?php echo $stats['active_subscription'] ? 'Active Subscription' : 'No Active Subscription'; ?>
        </h3>
        <?php if ($company && $company['subscription_plan'] != 'none'): ?>
            <p style="font-size: 13px; opacity: 0.9;">
                Plan: <?php echo ucfirst($company['subscription_plan']); ?> | 
                Expires: <?php echo date('M d, Y', strtotime($company['subscription_expiry'])); ?>
            </p>
        <?php else: ?>
            <p style="font-size: 13px; opacity: 0.9;">Subscribe to post more jobs and get premium features</p>
        <?php endif; ?>
    </div>
    <div>
        <?php if (!$stats['active_subscription']): ?>
            <a href="subscription.php" class="btn btn-outline">Upgrade Now →</a>
        <?php else: ?>
            <span class="subscription-status">
                <i class="fas fa-check-circle"></i> Active
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Applications -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-users"></i> Recent Applications</h3>
        <a href="job_posts.php?tab=applications" style="font-size: 12px; color: #667eea;">View All →</a>
    </div>
    <div class="table-wrapper">
        <?php if ($recent_applications && $recent_applications->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Job Title</th>
                        <th>Applicant</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($app = $recent_applications->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($app['job_title'], 0, 30)); ?></td>
                            <td><?php echo htmlspecialchars($app['applicant_name']); ?></td>
                            <td><?php echo formatMoney($app['total_amount']); ?></td>
                            <td><?php echo getStatusBadge($app['status']); ?></td>
                            <td><?php echo date('M d', strtotime($app['created_at'])); ?></td>
                            <td>
                                <a href="/broker_system/user/transaction.php?id=<?php echo $app['id']; ?>" class="btn-sm" style="padding: 4px 10px; background: #667eea; color: white; border-radius: 6px; text-decoration: none; font-size: 11px;">View</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #64748b;">
                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                No applications yet
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Job Posts -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-briefcase"></i> Recent Job Posts</h3>
        <a href="job_posts.php" style="font-size: 12px; color: #667eea;">Manage All →</a>
    </div>
    <div class="table-wrapper">
        <?php if ($recent_jobs && $recent_jobs->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Salary</th>
                        <th>Status</th>
                        <th>Posted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($job = $recent_jobs->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($job['title'], 0, 40)); ?></td>
                            <td><?php echo formatMoney($job['price']); ?>/mo</td>
                            <td>
                                <?php if ($job['approval_status'] == 'approved' && $job['status'] == 'active'): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php elseif ($job['approval_status'] == 'pending'): ?>
                                    <span class="badge badge-warning">Pending Approval</span>
                                <?php else: ?>
                                    <span class="badge badge-info"><?php echo ucfirst($job['status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($job['created_at'])); ?></td>
                            <td>
                                <a href="/broker_system/user/product.php?id=<?php echo $job['id']; ?>" class="btn-sm" style="padding: 4px 10px; background: #667eea; color: white; border-radius: 6px; text-decoration: none; font-size: 11px;">View</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #64748b;">
                <i class="fas fa-briefcase" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                No job posts yet
                <div style="margin-top: 16px;">
                    <a href="job_posts.php?action=create" class="btn btn-primary" style="background: #667eea; color: white;">Post Your First Job</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>