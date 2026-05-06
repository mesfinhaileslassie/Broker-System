<?php
// company/job_posts.php - Manage Job Posts

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';

requireLogin();

if ($_SESSION['user_role'] != 'company') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

$page_title = 'Manage Jobs';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get company subscription status
$company = $conn->query("
    SELECT id, subscription_plan, subscription_expiry 
    FROM companies WHERE user_id = $user_id
")->fetch_assoc();

$has_active_subscription = $company && $company['subscription_plan'] != 'none' && strtotime($company['subscription_expiry']) > time();

// Handle job deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $job_id = intval($_GET['delete']);
    
    // Verify ownership
    $check = $conn->query("SELECT id FROM listings WHERE id = $job_id AND seller_id = $user_id AND type = 'job'");
    if ($check->num_rows > 0) {
        $conn->query("DELETE FROM listings WHERE id = $job_id");
        $message = "Job post deleted successfully";
    } else {
        $error = "Job not found or unauthorized";
    }
}

// Handle job creation/editing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_id = intval($_POST['job_id'] ?? 0);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $salary = floatval($_POST['salary']);
    $employment_type = $_POST['employment_type'];
    $requirements = trim($_POST['requirements']);
    $location = trim($_POST['location']);
    $category_id = intval($_POST['category_id'] ?? 1);
    
    if (empty($title) || empty($description) || $salary <= 0) {
        $error = "Please fill in all required fields";
    } elseif ($action == 'create' && !$has_active_subscription) {
        // Check how many active jobs the company has
        $job_count = $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND type = 'job' AND status = 'active'")->fetch_assoc()['count'];
        
        if ($job_count >= 5) {
            $error = "Free plan allows maximum 5 active jobs. Please upgrade your subscription to post more jobs.";
        }
    }
    
    if (empty($error)) {
        $additional_details = json_encode([
            'employment_type' => $employment_type,
            'requirements' => $requirements
        ]);
        
        if ($action == 'create') {
            $stmt = $conn->prepare("
                INSERT INTO listings (seller_id, type, title, description, price, category_id, location, additional_details, approval_status, status) 
                VALUES (?, 'job', ?, ?, ?, ?, ?, ?, 'pending', 'pending')
            ");
            $stmt->bind_param("issdiss", $user_id, $title, $description, $salary, $category_id, $location, $additional_details);
            
            if ($stmt->execute()) {
                $message = "Job posted successfully! It will be reviewed by admin.";
            } else {
                $error = "Failed to post job: " . $conn->error;
            }
        } elseif ($action == 'edit' && $job_id > 0) {
            $stmt = $conn->prepare("
                UPDATE listings SET title = ?, description = ?, price = ?, category_id = ?, location = ?, additional_details = ?
                WHERE id = ? AND seller_id = ? AND type = 'job'
            ");
            $stmt->bind_param("ssdissii", $title, $description, $salary, $category_id, $location, $additional_details, $job_id, $user_id);
            
            if ($stmt->execute()) {
                $message = "Job updated successfully";
            } else {
                $error = "Failed to update job";
            }
        }
    }
}

// Get all job posts
$tab = $_GET['tab'] ?? 'active';
$where = "l.seller_id = $user_id AND l.type = 'job'";

if ($tab == 'active') {
    $where .= " AND l.status = 'active' AND l.approval_status = 'approved'";
} elseif ($tab == 'pending') {
    $where .= " AND l.approval_status = 'pending'";
} elseif ($tab == 'applications') {
    // Show applications instead of jobs
    $applications = $conn->query("
        SELECT t.*, l.title as job_title, u.full_name as applicant_name, u.email as applicant_email, u.phone as applicant_phone
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        JOIN users u ON t.buyer_id = u.id
        WHERE l.seller_id = $user_id AND l.type = 'job'
        ORDER BY t.created_at DESC
    ");
}

$jobs = $conn->query("
    SELECT l.*, c.name as category_name
    FROM listings l
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE $where
    ORDER BY l.created_at DESC
");

// Get job for editing
$edit_job = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_job = $conn->query("
        SELECT * FROM listings WHERE id = $edit_id AND seller_id = $user_id AND type = 'job'
    ")->fetch_assoc();
}

// Get categories
$categories = $conn->query("SELECT id, name FROM categories WHERE type = 'job' AND is_active = 1");

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
    
    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 12px;
        flex-wrap: wrap;
    }
    
    .tab {
        padding: 8px 24px;
        background: transparent;
        border-radius: 30px;
        text-decoration: none;
        color: #64748b;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .tab:hover, .tab.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .card {
        background: white;
        border-radius: 20px;
        padding: 28px;
        margin-bottom: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #334155;
    }
    
    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
    }
    
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    
    .btn {
        padding: 12px 28px;
        border-radius: 40px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
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
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 14px 12px;
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
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
        text-decoration: none;
        display: inline-block;
        transition: all 0.3s;
    }
    
    .btn-sm:hover {
        transform: translateY(-1px);
    }
    
    .subscription-warning {
        background: #fef3c7;
        border-left: 4px solid #f59e0b;
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        .action-buttons {
            flex-direction: column;
        }
    }
</style>

<div class="page-header">
    <h1>Manage Job Posts</h1>
    <p>Post and manage job opportunities</p>
</div>

<?php if ($message): ?>
    <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (!$has_active_subscription): ?>
    <div class="subscription-warning">
        <div>
            <strong><i class="fas fa-info-circle"></i> Free Plan Limitations</strong><br>
            <small>You can have up to 5 active jobs. Upgrade to premium for unlimited posts and featured listings.</small>
        </div>
        <a href="subscription.php" class="btn btn-primary" style="padding: 8px 20px;">Upgrade Now</a>
    </div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
    <a href="?tab=active" class="tab <?php echo $tab == 'active' ? 'active' : ''; ?>">Active Jobs</a>
    <a href="?tab=pending" class="tab <?php echo $tab == 'pending' ? 'active' : ''; ?>">Pending Approval</a>
    <a href="?tab=applications" class="tab <?php echo $tab == 'applications' ? 'active' : ''; ?>">Applications</a>
    <a href="?action=create" class="tab <?php echo isset($_GET['action']) && $_GET['action'] == 'create' ? 'active' : ''; ?>">+ Post New Job</a>
</div>

<!-- Create/Edit Form -->
<?php if ((isset($_GET['action']) && $_GET['action'] == 'create') || $edit_job): ?>
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;">
            <i class="fas fa-<?php echo $edit_job ? 'edit' : 'plus-circle'; ?>"></i> 
            <?php echo $edit_job ? 'Edit Job' : 'Post New Job'; ?>
        </h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_job ? 'edit' : 'create'; ?>">
            <?php if ($edit_job): ?>
                <input type="hidden" name="job_id" value="<?php echo $edit_job['id']; ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Job Title *</label>
                <input type="text" name="title" required value="<?php echo $edit_job ? htmlspecialchars($edit_job['title']) : ''; ?>" placeholder="e.g., Senior Web Developer">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Monthly Salary (ETB) *</label>
                    <input type="number" name="salary" step="100" min="1" required value="<?php echo $edit_job ? $edit_job['price'] : ''; ?>" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Employment Type *</label>
                    <select name="employment_type" required>
                        <option value="">Select type</option>
                        <option value="Full-time" <?php echo $edit_job && strpos($edit_job['additional_details'] ?? '', 'Full-time') !== false ? 'selected' : ''; ?>>Full-time</option>
                        <option value="Part-time" <?php echo $edit_job && strpos($edit_job['additional_details'] ?? '', 'Part-time') !== false ? 'selected' : ''; ?>>Part-time</option>
                        <option value="Contract" <?php echo $edit_job && strpos($edit_job['additional_details'] ?? '', 'Contract') !== false ? 'selected' : ''; ?>>Contract</option>
                        <option value="Remote" <?php echo $edit_job && strpos($edit_job['additional_details'] ?? '', 'Remote') !== false ? 'selected' : ''; ?>>Remote</option>
                        <option value="Internship" <?php echo $edit_job && strpos($edit_job['additional_details'] ?? '', 'Internship') !== false ? 'selected' : ''; ?>>Internship</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <?php while($cat = $categories->fetch_assoc()): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_job && $edit_job['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" value="<?php echo $edit_job ? htmlspecialchars($edit_job['location']) : ''; ?>" placeholder="e.g., Addis Ababa, Remote">
                </div>
            </div>
            
            <div class="form-group">
                <label>Job Description *</label>
                <textarea name="description" rows="5" required placeholder="Describe the role, responsibilities, and benefits..."><?php echo $edit_job ? htmlspecialchars($edit_job['description']) : ''; ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Requirements *</label>
                <textarea name="requirements" rows="5" required placeholder="List required qualifications, experience, and skills..."><?php 
                    if ($edit_job && $edit_job['additional_details']) {
                        $details = json_decode($edit_job['additional_details'], true);
                        echo htmlspecialchars($details['requirements'] ?? '');
                    }
                ?></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo $edit_job ? 'Update Job' : 'Post Job'; ?>
            </button>
            
            <?php if ($edit_job): ?>
                <a href="job_posts.php" class="btn" style="background: #64748b; color: white; margin-left: 10px;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>

<!-- Applications Tab -->
<?php if ($tab == 'applications'): ?>
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;">
            <i class="fas fa-users"></i> Job Applications
        </h2>
        <div class="table-wrapper">
            <?php if (isset($applications) && $applications->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Job Title</th>
                            <th>Applicant</th>
                            <th>Contact</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Applied</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($app = $applications->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($app['job_title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($app['applicant_name']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($app['applicant_email']); ?><br>
                                    <small><?php echo htmlspecialchars($app['applicant_phone'] ?? 'No phone'); ?></small>
                                 </td>
                                <td><?php echo formatMoney($app['total_amount']); ?></td>
                                <td><?php echo getStatusBadge($app['status']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($app['created_at'])); ?></td>
                                <td>
                                    <a href="/broker_system/user/transaction.php?id=<?php echo $app['id']; ?>" class="btn-sm" style="background: #667eea; color: white;">View</a>
                                 </td>
                             </tr>
                        <?php endwhile; ?>
                    </tbody>
                 </table>
            <?php else: ?>
                <div style="text-align: center; padding: 60px; color: #64748b;">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                    No applications yet
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Jobs List -->
<?php if ($tab != 'applications' && !isset($_GET['action']) && !$edit_job): ?>
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;">
            <i class="fas fa-briefcase"></i> 
            <?php echo $tab == 'active' ? 'Active Jobs' : 'Pending Approval'; ?>
        </h2>
        <div class="table-wrapper">
            <?php if ($jobs && $jobs->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Salary</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Posted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($job = $jobs->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($job['title']); ?></strong></td>
                                <td><?php echo formatMoney($job['price']); ?>/mo</td>
                                <td><?php echo htmlspecialchars($job['location'] ?: 'N/A'); ?></td>
                                <td>
                                    <?php if ($job['approval_status'] == 'approved' && $job['status'] == 'active'): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php elseif ($job['approval_status'] == 'pending'): ?>
                                        <span class="badge badge-warning">Pending Review</span>
                                    <?php elseif ($job['status'] == 'pending'): ?>
                                        <span class="badge badge-warning">Awaiting Payment</span>
                                    <?php else: ?>
                                        <span class="badge"><?php echo ucfirst($job['status']); ?></span>
                                    <?php endif; ?>
                                 </td>
                                <td><?php echo date('M d, Y', strtotime($job['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="/broker_system/user/product.php?id=<?php echo $job['id']; ?>" class="btn-sm" style="background: #667eea; color: white;">View</a>
                                        <a href="?edit=<?php echo $job['id']; ?>" class="btn-sm" style="background: #f59e0b; color: white;">Edit</a>
                                        <a href="?delete=<?php echo $job['id']; ?>" class="btn-sm" style="background: #ef4444; color: white;" onclick="return confirm('Delete this job post?')">Delete</a>
                                    </div>
                                 </td>
                             </tr>
                        <?php endwhile; ?>
                    </tbody>
                 </table>
            <?php else: ?>
                <div style="text-align: center; padding: 60px; color: #64748b;">
                    <i class="fas fa-briefcase" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                    No <?php echo $tab; ?> jobs found
                    <div style="margin-top: 16px;">
                        <a href="?action=create" class="btn btn-primary" style="background: #667eea; color: white;">Post a Job</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>