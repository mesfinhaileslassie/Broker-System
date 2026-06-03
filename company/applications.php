<?php
// company/applications.php - View job applications for employer

session_start();

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || $_SESSION['user_role'] != 'company') {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'Job Applications';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
$message = '';
$error = '';

// Handle application status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application_id = intval($_POST['application_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $admin_notes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
    
    $valid_statuses = ['pending', 'reviewed', 'accepted', 'rejected'];
    if (in_array($status, $valid_statuses)) {
        $conn->query("
            UPDATE job_applications 
            SET status = '$status', 
                admin_notes = '$admin_notes',
                updated_at = NOW()
            WHERE id = $application_id
        ");
        
        // Get application details for notification
        $app = $conn->query("
            SELECT ja.*, l.title as job_title, u.email as applicant_email, u.full_name as applicant_name
            FROM job_applications ja
            JOIN listings l ON ja.job_id = l.id
            JOIN users u ON ja.applicant_id = u.id
            WHERE ja.id = $application_id
        ")->fetch_assoc();
        
        // Notify applicant
        $notification_title = "";
        $notification_message = "";
        
        if ($status == 'accepted') {
            $notification_title = "🎉 Application Accepted!";
            $notification_message = "Congratulations! Your application for '{$app['job_title']}' has been accepted. The employer will contact you shortly.";
        } elseif ($status == 'rejected') {
            $notification_title = "Application Update";
            $notification_message = "Thank you for your interest. Your application for '{$app['job_title']}' has been reviewed. Unfortunately, we have decided to move forward with other candidates.";
        } elseif ($status == 'reviewed') {
            $notification_title = "Application Reviewed";
            $notification_message = "Your application for '{$app['job_title']}' has been reviewed. The employer will contact you if you are selected.";
        }
        
        if ($notification_title) {
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, created_at) 
                VALUES ({$app['applicant_id']}, '$notification_title', '$notification_message', '/broker_system/user/jobs.php', NOW())
            ");
        }
        
        $message = "Application status updated to " . ucfirst($status);
    }
}

// Get employer's jobs
$jobs = $conn->query("
    SELECT l.id, l.title, l.price, l.created_at, l.status,
           (SELECT COUNT(*) FROM job_applications WHERE job_id = l.id) as application_count,
           (SELECT COUNT(*) FROM job_applications WHERE job_id = l.id AND status = 'pending') as pending_count
    FROM listings l
    WHERE l.seller_id = $user_id AND l.type = 'job'
    ORDER BY l.created_at DESC
");

// Get applications for selected job
$applications = null;
$selected_job = null;
if ($job_id > 0) {
    $selected_job = $conn->query("
        SELECT l.* FROM listings l
        WHERE l.id = $job_id AND l.seller_id = $user_id AND l.type = 'job'
    ")->fetch_assoc();
    
    if ($selected_job) {
        $applications = $conn->query("
            SELECT ja.*, u.full_name, u.email, u.phone, u.first_name, u.last_name, u.age, u.gender
            FROM job_applications ja
            JOIN users u ON ja.applicant_id = u.id
            WHERE ja.job_id = $job_id
            ORDER BY 
                CASE ja.status
                    WHEN 'pending' THEN 1
                    WHEN 'reviewed' THEN 2
                    WHEN 'accepted' THEN 3
                    WHEN 'rejected' THEN 4
                END,
                ja.created_at DESC
        ");
    }
}

$conn->close();
?>

<style>
    :root {
        --primary: #667eea;
        --secondary: #764ba2;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --info: #3b82f6;
        --dark: #1e293b;
        --gray: #64748b;
        --light: #f8fafc;
        --border: #e2e8f0;
    }
    
    .applications-container { max-width: 1400px; margin: 0 auto; }
    
    .page-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 28px;
        padding: 32px;
        margin-bottom: 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .two-columns {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 28px;
    }
    
    /* Jobs Sidebar */
    .jobs-sidebar {
        background: white;
        border-radius: 24px;
        overflow: hidden;
        border: 1px solid var(--border);
        height: fit-content;
    }
    
    .sidebar-header {
        padding: 20px;
        background: var(--light);
        border-bottom: 1px solid var(--border);
    }
    
    .sidebar-header h3 {
        font-size: 16px;
        font-weight: 700;
        color: var(--dark);
    }
    
    .job-list {
        max-height: 600px;
        overflow-y: auto;
    }
    
    .job-item {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: block;
    }
    
    .job-item:hover {
        background: var(--light);
    }
    
    .job-item.active {
        background: linear-gradient(135deg, #667eea15, #764ba215);
        border-left: 3px solid var(--primary);
    }
    
    .job-title {
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 4px;
    }
    
    .job-meta {
        display: flex;
        gap: 16px;
        font-size: 11px;
        color: var(--gray);
        margin-top: 8px;
    }
    
    .badge-count {
        background: var(--warning);
        color: white;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        margin-left: 8px;
    }
    
    /* Main Content */
    .main-content {
        background: white;
        border-radius: 24px;
        border: 1px solid var(--border);
        overflow: hidden;
    }
    
    .content-header {
        padding: 20px 24px;
        background: var(--light);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .stats-badge {
        display: flex;
        gap: 16px;
    }
    
    .stat {
        text-align: center;
        padding: 8px 16px;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--border);
    }
    
    .stat-number {
        font-size: 20px;
        font-weight: 800;
        color: var(--primary);
    }
    
    .stat-label {
        font-size: 10px;
        color: var(--gray);
    }
    
    /* Application Cards */
    .applications-list {
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    
    .application-card {
        background: white;
        border-radius: 20px;
        border: 1px solid var(--border);
        overflow: hidden;
        transition: all 0.3s;
    }
    
    .application-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    
    .card-header {
        padding: 16px 20px;
        background: var(--light);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .applicant-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-reviewed { background: #dbeafe; color: #1e40af; }
    .status-accepted { background: #d1fae5; color: #059669; }
    .status-rejected { background: #fee2e2; color: #dc2626; }
    
    .card-body {
        padding: 20px;
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    
    .info-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .info-label {
        font-size: 10px;
        color: var(--gray);
        text-transform: uppercase;
    }
    
    .info-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .cover-letter {
        background: var(--light);
        border-radius: 12px;
        padding: 16px;
        margin: 16px 0;
        font-size: 13px;
        line-height: 1.6;
        color: var(--gray);
        max-height: 120px;
        overflow-y: auto;
    }
    
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--border);
    }
    
    .btn {
        padding: 8px 20px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
    .btn-success { background: var(--success); color: white; }
    .btn-danger { background: var(--danger); color: white; }
    .btn-warning { background: var(--warning); color: white; }
    .btn-info { background: var(--info); color: white; }
    .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--gray); }
    .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
    
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
        border-radius: 24px;
        padding: 28px;
        width: 500px;
        max-width: 90%;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
    .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 12px; resize: vertical; }
    .modal-buttons { display: flex; gap: 12px; margin-top: 20px; }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        color: var(--gray);
    }
    .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .alert-success { background: #d1fae5; color: #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; }
    
    @media (max-width: 900px) {
        .two-columns { grid-template-columns: 1fr; }
        .info-grid { grid-template-columns: 1fr; }
        .action-buttons { flex-direction: column; }
        .btn { justify-content: center; }
    }
</style>

<div class="applications-container">
    <div class="page-header">
        <h1><i class="fas fa-users"></i> Job Applications</h1>
        <p>Review and manage applicants for your job postings</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <div class="two-columns">
        <!-- Left Sidebar - Jobs List -->
        <div class="jobs-sidebar">
            <div class="sidebar-header">
                <h3><i class="fas fa-briefcase"></i> Your Job Posts</h3>
            </div>
            <div class="job-list">
                <?php if ($jobs && $jobs->num_rows > 0): ?>
                    <?php while($job = $jobs->fetch_assoc()): ?>
                        <a href="?job_id=<?php echo $job['id']; ?>" class="job-item <?php echo $job_id == $job['id'] ? 'active' : ''; ?>">
                            <div class="job-title">
                                <?php echo htmlspecialchars($job['title']); ?>
                                <?php if ($job['pending_count'] > 0): ?>
                                    <span class="badge-count"><?php echo $job['pending_count']; ?> new</span>
                                <?php endif; ?>
                            </div>
                            <div class="job-meta">
                                <span><i class="fas fa-dollar-sign"></i> <?php echo formatMoney($job['price']); ?>/mo</span>
                                <span><i class="fas fa-users"></i> <?php echo $job['application_count']; ?> applicants</span>
                                <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                            </div>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: var(--gray);">
                        <i class="fas fa-briefcase" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                        <p>You haven't posted any jobs yet.</p>
                        <a href="/broker_system/user/post_listing.php" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">Post a Job</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Content - Applications -->
        <div class="main-content">
            <?php if ($selected_job): ?>
                <div class="content-header">
                    <div>
                        <h2 style="font-size: 20px; font-weight: 700;"><?php echo htmlspecialchars($selected_job['title']); ?></h2>
                        <p style="font-size: 13px; color: var(--gray); margin-top: 4px;">Manage applicants for this position</p>
                    </div>
                    <div class="stats-badge">
                        <div class="stat">
                            <div class="stat-number"><?php echo $applications ? $applications->num_rows : 0; ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number"><?php 
                                $pending_count = 0;
                                if ($applications) {
                                    $applications->data_seek(0);
                                    while($app = $applications->fetch_assoc()) {
                                        if ($app['status'] == 'pending') $pending_count++;
                                    }
                                    $applications->data_seek(0);
                                }
                                echo $pending_count;
                            ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
                
                <div class="applications-list">
                    <?php if ($applications && $applications->num_rows > 0): ?>
                        <?php while($app = $applications->fetch_assoc()): 
                            $status_class = '';
                            switch($app['status']) {
                                case 'pending': $status_class = 'status-pending'; break;
                                case 'reviewed': $status_class = 'status-reviewed'; break;
                                case 'accepted': $status_class = 'status-accepted'; break;
                                case 'rejected': $status_class = 'status-rejected'; break;
                            }
                        ?>
                            <div class="application-card">
                                <div class="card-header">
                                    <div class="applicant-name">
                                        <i class="fas fa-user-circle" style="font-size: 24px; color: var(--primary);"></i>
                                        <?php echo htmlspecialchars($app['full_name']); ?>
                                    </div>
                                    <div>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($app['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Email</span>
                                            <span class="info-value"><?php echo htmlspecialchars($app['email']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Phone</span>
                                            <span class="info-value"><?php echo htmlspecialchars($app['phone'] ?? 'Not provided'); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Age / Gender</span>
                                            <span class="info-value"><?php echo $app['age'] ?? 'N/A'; ?> / <?php echo $app['gender'] ?? 'N/A'; ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Applied On</span>
                                            <span class="info-value"><?php echo date('M d, Y H:i', strtotime($app['created_at'])); ?></span>
                                        </div>
                                        <?php if ($app['expected_salary']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Expected Salary</span>
                                            <span class="info-value"><?php echo formatMoney($app['expected_salary']); ?>/month</span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($app['availability_date']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Available From</span>
                                            <span class="info-value"><?php echo date('M d, Y', strtotime($app['availability_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($app['cover_letter']): ?>
                                    <div class="cover-letter">
                                        <strong><i class="fas fa-quote-left"></i> Cover Letter</strong>
                                        <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($app['cover_letter'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="action-buttons">
                                        <a href="view_resume.php?file=<?php echo urlencode($app['resume_file']); ?>" target="_blank" class="btn btn-info">
                                            <i class="fas fa-file-pdf"></i> View Resume
                                        </a>
                                        <?php if ($app['portfolio_url']): ?>
                                        <a href="<?php echo htmlspecialchars($app['portfolio_url']); ?>" target="_blank" class="btn btn-outline">
                                            <i class="fas fa-globe"></i> Portfolio
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($app['linkedin_url']): ?>
                                        <a href="<?php echo htmlspecialchars($app['linkedin_url']); ?>" target="_blank" class="btn btn-outline">
                                            <i class="fab fa-linkedin"></i> LinkedIn
                                        </a>
                                        <?php endif; ?>
                                        <a href="/broker_system/user/chat.php?user=<?php echo $app['applicant_id']; ?>" target="_blank" class="btn btn-outline">
                                            <i class="fas fa-comment"></i> Message
                                        </a>
                                        
                                        <?php if ($app['status'] == 'pending'): ?>
                                            <button onclick="updateStatus(<?php echo $app['id']; ?>, 'reviewed')" class="btn btn-warning">
                                                <i class="fas fa-eye"></i> Mark Reviewed
                                            </button>
                                            <button onclick="updateStatus(<?php echo $app['id']; ?>, 'accepted')" class="btn btn-success">
                                                <i class="fas fa-check-circle"></i> Accept
                                            </button>
                                            <button onclick="openRejectModal(<?php echo $app['id']; ?>)" class="btn btn-danger">
                                                <i class="fas fa-times-circle"></i> Reject
                                            </button>
                                        <?php elseif ($app['status'] == 'reviewed'): ?>
                                            <button onclick="updateStatus(<?php echo $app['id']; ?>, 'accepted')" class="btn btn-success">
                                                <i class="fas fa-check-circle"></i> Accept
                                            </button>
                                            <button onclick="openRejectModal(<?php echo $app['id']; ?>)" class="btn btn-danger">
                                                <i class="fas fa-times-circle"></i> Reject
                                            </button>
                                        <?php elseif ($app['status'] == 'accepted'): ?>
                                            <span class="btn btn-success" style="cursor: default;">✓ Application Accepted</span>
                                        <?php elseif ($app['status'] == 'rejected'): ?>
                                            <span class="btn btn-danger" style="cursor: default;">✗ Application Rejected</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No applications yet</h3>
                            <p>When candidates apply for this job, they will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php else: ?>
                <div class="empty-state" style="padding: 80px;">
                    <i class="fas fa-folder-open"></i>
                    <h3>Select a job to view applications</h3>
                    <p>Choose a job from the left sidebar to see applicants.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle" style="color: #ef4444;"></i> Reject Application</h3>
            <span class="close-modal" onclick="closeRejectModal()" style="cursor: pointer; font-size: 24px;">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="application_id" id="reject_application_id">
            <input type="hidden" name="status" value="rejected">
            <div class="form-group">
                <label>Reason for Rejection (Optional)</label>
                <textarea name="admin_notes" rows="3" placeholder="Add any notes about why this application was rejected..."></textarea>
            </div>
            <div class="modal-buttons">
                <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                <button type="button" onclick="closeRejectModal()" class="btn btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateStatus(applicationId, status) {
    if (confirm(`Are you sure you want to mark this application as ${status}?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="application_id" value="${applicationId}">
            <input type="hidden" name="status" value="${status}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function openRejectModal(applicationId) {
    document.getElementById('reject_application_id').value = applicationId;
    document.getElementById('rejectModal').style.display = 'flex';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('rejectModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>