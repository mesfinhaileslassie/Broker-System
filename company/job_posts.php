<?php
// company/job_posts.php - Complete Job Post Management for Companies

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';
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

// Get company info
$company = $conn->query("
    SELECT c.*, u.balance, u.full_name as contact_name
    FROM companies c
    JOIN users u ON c.user_id = u.id
    WHERE c.user_id = $user_id
")->fetch_assoc();

$has_active_subscription = $company && $company['subscription_plan'] != 'none' && strtotime($company['subscription_expiry']) > time();
$job_limit = $company['job_posts_limit'] ?? 5;
$jobs_used = $company['job_posts_used'] ?? 0;
$can_post_more = $has_active_subscription || $jobs_used < $job_limit;

// Handle delete job
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $job_id = sanitizeInt($_GET['delete']);
    
    $check = $conn->query("SELECT id FROM listings WHERE id = $job_id AND seller_id = $user_id AND type = 'job'");
    if ($check->num_rows > 0) {
        $conn->query("DELETE FROM listings WHERE id = $job_id");
        $message = "Job post deleted successfully";
        $conn->query("UPDATE companies SET job_posts_used = GREATEST(job_posts_used - 1, 0) WHERE user_id = $user_id");
    } else {
        $error = "Job not found or unauthorized";
    }
}

// Handle job creation/editing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitizeString($_POST['action'] ?? '');
    $job_id = sanitizeInt($_POST['job_id'] ?? 0);
    $title = sanitizeString($_POST['title'] ?? '');
    $description = sanitizeString($_POST['description'] ?? '');
    $salary = sanitizeFloat($_POST['salary'] ?? 0);
    $employment_type = sanitizeString($_POST['employment_type'] ?? '');
    $requirements = sanitizeString($_POST['requirements'] ?? '');
    $location = sanitizeString($_POST['location'] ?? '');
    $category_id = sanitizeInt($_POST['category_id'] ?? 1);
    $featured = isset($_POST['featured']) ? 1 : 0;
    
    $errors = [];
    
    // Validation
    if (empty($title)) {
        $errors[] = "Job title is required";
    } elseif (!validateLength($title, 3, 100)) {
        $errors[] = "Title must be between 3 and 100 characters";
    }
    
    if (empty($description)) {
        $errors[] = "Job description is required";
    } elseif (!validateLength($description, 50, 5000)) {
        $errors[] = "Description must be between 50 and 5000 characters";
    }
    
    if ($salary <= 0) {
        $errors[] = "Please enter a valid salary amount";
    } elseif ($salary > 1000000) {
        $errors[] = "Salary cannot exceed 1,000,000 ETB";
    }
    
    if (empty($employment_type)) {
        $errors[] = "Employment type is required";
    } else {
        $valid_types = ['Full-time', 'Part-time', 'Contract', 'Remote', 'Internship'];
        if (!in_array($employment_type, $valid_types)) {
            $errors[] = "Invalid employment type selected";
        }
    }
    
    if (empty($requirements)) {
        $errors[] = "Job requirements are required";
    } elseif (!validateLength($requirements, 20, 2000)) {
        $errors[] = "Requirements must be between 20 and 2000 characters";
    }
    
    if ($action == 'create' && !$can_post_more && !$has_active_subscription) {
        $errors[] = "You have reached your job posting limit. Please upgrade your subscription to post more jobs.";
    }
    
    if (empty($errors)) {
        $additional_details = json_encode([
            'employment_type' => $employment_type,
            'requirements' => $requirements
        ]);
        
        if ($action == 'create') {
            $stmt = $conn->prepare("
                INSERT INTO listings (seller_id, type, title, description, price, category_id, location, additional_details, featured, approval_status, status, created_at) 
                VALUES (?, 'job', ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())
            ");
            $stmt->bind_param("issdissii", $user_id, $title, $description, $salary, $category_id, $location, $additional_details, $featured);
            
            if ($stmt->execute()) {
                $conn->query("UPDATE companies SET job_posts_used = job_posts_used + 1 WHERE user_id = $user_id");
                $message = "Job posted successfully! It will be reviewed by admin within 24 hours.";
            } else {
                $error = "Failed to post job: " . $conn->error;
            }
        } elseif ($action == 'edit' && $job_id > 0) {
            $stmt = $conn->prepare("
                UPDATE listings SET title = ?, description = ?, price = ?, category_id = ?, location = ?, additional_details = ?, featured = ?
                WHERE id = ? AND seller_id = ? AND type = 'job'
            ");
            $stmt->bind_param("ssdissiii", $title, $description, $salary, $category_id, $location, $additional_details, $featured, $job_id, $user_id);
            
            if ($stmt->execute()) {
                $message = "Job updated successfully";
            } else {
                $error = "Failed to update job";
            }
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Handle feature job (premium feature)
if (isset($_GET['feature']) && is_numeric($_GET['feature'])) {
    $job_id = sanitizeInt($_GET['feature']);
    
    if ($has_active_subscription && $company['subscription_plan'] == 'premium') {
        $conn->query("UPDATE listings SET featured = 1 WHERE id = $job_id AND seller_id = $user_id");
        $message = "Job marked as featured!";
    } else {
        $error = "Featured jobs are only available for Premium subscription";
    }
}

// Get all job posts with counts
$tab = $_GET['tab'] ?? 'active';
$where = "l.seller_id = $user_id AND l.type = 'job'";

if ($tab == 'active') {
    $where .= " AND l.status = 'active' AND l.approval_status = 'approved'";
} elseif ($tab == 'pending') {
    $where .= " AND l.approval_status = 'pending'";
} elseif ($tab == 'rejected') {
    $where .= " AND l.approval_status = 'rejected'";
} elseif ($tab == 'expired') {
    $where .= " AND l.status = 'expired'";
}

// Get counts for dashboard
$counts = [
    'active' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND type = 'job' AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND type = 'job' AND approval_status = 'pending'")->fetch_assoc()['count'],
    'applications' => $conn->query("
        SELECT COUNT(*) as count FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE l.seller_id = $user_id AND l.type = 'job'
    ")->fetch_assoc()['count'],
];

// Get jobs
$jobs = $conn->query("
    SELECT l.*, c.name as category_name,
           (SELECT COUNT(*) FROM transactions WHERE listing_id = l.id) as application_count
    FROM listings l
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE $where
    ORDER BY l.created_at DESC
");

// Get applications for jobs
$applications = $conn->query("
    SELECT t.*, l.title as job_title, u.full_name as applicant_name, u.email as applicant_email, u.phone as applicant_phone
    FROM transactions t
    JOIN listings l ON t.listing_id = l.id
    JOIN users u ON t.buyer_id = u.id
    WHERE l.seller_id = $user_id AND l.type = 'job'
    ORDER BY t.created_at DESC
");

// Get job for editing
$edit_job = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = sanitizeInt($_GET['edit']);
    $edit_job = $conn->query("
        SELECT * FROM listings WHERE id = $edit_id AND seller_id = $user_id AND type = 'job'
    ")->fetch_assoc();
}

// Get categories
$categories = $conn->query("SELECT id, name FROM categories WHERE type = 'job' AND is_active = 1 ORDER BY name");

$conn->close();
?>

<style>
    /* Main Styles */
    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 28px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .page-header p { color: #64748b; font-size: 14px; }
    
    /* Stats Cards */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: all 0.3s; }
    .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15); }
    .stat-icon { font-size: 28px; margin-bottom: 12px; }
    .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
    .stat-label { font-size: 12px; color: #64748b; margin-top: 6px; }
    
    /* Subscription Banner */
    .subscription-banner { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 20px; padding: 20px 24px; margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; color: white; }
    .subscription-banner h3 { font-size: 16px; margin-bottom: 4px; }
    .subscription-banner p { font-size: 12px; opacity: 0.9; }
    .upgrade-btn { background: rgba(255,255,255,0.2); color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px; transition: all 0.3s; }
    .upgrade-btn:hover { background: white; color: #667eea; transform: translateY(-2px); }
    
    /* Tabs */
    .tabs { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; flex-wrap: wrap; }
    .tab { padding: 8px 24px; background: transparent; border-radius: 30px; text-decoration: none; color: #64748b; font-size: 14px; font-weight: 500; transition: all 0.3s; }
    .tab:hover, .tab.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .tab .count { margin-left: 6px; font-size: 11px; opacity: 0.8; }
    
    /* Cards */
    .card { background: white; border-radius: 20px; padding: 28px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; flex-wrap: wrap; gap: 12px; }
    .card-header h2 { font-size: 18px; font-weight: 600; color: #0f172a; }
    
    /* Forms */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 13px; }
    .required { color: #ef4444; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.3s; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .form-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    
    /* Buttons */
    .btn { padding: 12px 28px; border-radius: 40px; border: none; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-block; text-decoration: none; text-align: center; }
    .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; text-decoration: none; display: inline-block; transition: all 0.3s; }
    .btn-sm:hover { transform: translateY(-1px); }
    .btn-view { background: #667eea; color: white; }
    .btn-edit { background: #f59e0b; color: white; }
    .btn-delete { background: #ef4444; color: white; }
    .btn-feature { background: #10b981; color: white; }
    
    /* Tables */
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { font-weight: 600; color: #64748b; background: #fafbfc; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
    tr:hover { background: #f8fafc; }
    
    /* Badges */
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .badge-success { background: #d1fae5; color: #059669; }
    .badge-warning { background: #fed7aa; color: #ea580c; }
    .badge-info { background: #dbeafe; color: #1e40af; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-featured { background: #fef3c7; color: #d97706; }
    
    /* Messages */
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #059669; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    
    /* Empty State */
    .empty-state { text-align: center; padding: 60px; color: #64748b; }
    .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; color: #cbd5e1; }
    
    /* Modal */
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
    .modal-content { background: white; border-radius: 20px; padding: 28px; width: 500px; max-width: 90%; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .close-modal { cursor: pointer; font-size: 24px; color: #94a3b8; transition: color 0.3s; }
    .close-modal:hover { color: #ef4444; }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .form-row, .form-row-3 { grid-template-columns: 1fr; }
        .tabs { overflow-x: auto; flex-wrap: nowrap; }
        .card { padding: 20px; }
        th, td { padding: 10px 8px; font-size: 12px; }
    }
</style>

<div class="page-header">
    <h1><i class="fas fa-briefcase"></i> Manage Job Posts</h1>
    <p>Post and manage job opportunities, review applications</p>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">📋</div>
        <div class="stat-value"><?php echo $counts['active']; ?></div>
        <div class="stat-label">Active Jobs</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⏳</div>
        <div class="stat-value"><?php echo $counts['pending']; ?></div>
        <div class="stat-label">Pending Approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📝</div>
        <div class="stat-value"><?php echo $counts['applications']; ?></div>
        <div class="stat-label">Total Applications</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div class="stat-value"><?php echo $jobs_used; ?>/<?php echo $job_limit; ?></div>
        <div class="stat-label">Jobs Used</div>
    </div>
</div>

<!-- Subscription Banner -->
<?php if (!$has_active_subscription): ?>
<div class="subscription-banner">
    <div>
        <h3><i class="fas fa-crown"></i> Upgrade to Premium</h3>
        <p>Post unlimited jobs, get featured listings, and reach more candidates</p>
    </div>
    <a href="subscription.php" class="upgrade-btn">Upgrade Now →</a>
</div>
<?php endif; ?>

<!-- Messages -->
<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
    <a href="?tab=active" class="tab <?php echo $tab == 'active' ? 'active' : ''; ?>">Active Jobs <span class="count">(<?php echo $counts['active']; ?>)</span></a>
    <a href="?tab=pending" class="tab <?php echo $tab == 'pending' ? 'active' : ''; ?>">Pending Approval <span class="count">(<?php echo $counts['pending']; ?>)</span></a>
    <a href="?tab=applications" class="tab <?php echo $tab == 'applications' ? 'active' : ''; ?>">Applications <span class="count">(<?php echo $counts['applications']; ?>)</span></a>
    <a href="?action=create" class="tab <?php echo isset($_GET['action']) && $_GET['action'] == 'create' ? 'active' : ''; ?>">+ Post New Job</a>
</div>

<!-- Create/Edit Form -->
<?php if ((isset($_GET['action']) && $_GET['action'] == 'create') || $edit_job): ?>
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;">
            <i class="fas fa-<?php echo $edit_job ? 'edit' : 'plus-circle'; ?>"></i> 
            <?php echo $edit_job ? 'Edit Job' : 'Post New Job'; ?>
        </h2>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $edit_job ? 'edit' : 'create'; ?>">
            <?php if ($edit_job): ?>
                <input type="hidden" name="job_id" value="<?php echo $edit_job['id']; ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Job Title <span class="required">*</span></label>
                <input type="text" name="title" required value="<?php echo $edit_job ? htmlspecialchars($edit_job['title']) : ''; ?>" placeholder="e.g., Senior Web Developer, Marketing Manager">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Monthly Salary (ETB) <span class="required">*</span></label>
                    <input type="number" name="salary" step="100" min="1" max="1000000" required value="<?php echo $edit_job ? $edit_job['price'] : ''; ?>">
                </div>
                <div class="form-group">
                    <label>Employment Type <span class="required">*</span></label>
                    <select name="employment_type" required>
                        <option value="">Select type</option>
                        <option value="Full-time" <?php echo ($edit_job && strpos($edit_job['additional_details'] ?? '', 'Full-time') !== false) ? 'selected' : ''; ?>>Full-time</option>
                        <option value="Part-time" <?php echo ($edit_job && strpos($edit_job['additional_details'] ?? '', 'Part-time') !== false) ? 'selected' : ''; ?>>Part-time</option>
                        <option value="Contract" <?php echo ($edit_job && strpos($edit_job['additional_details'] ?? '', 'Contract') !== false) ? 'selected' : ''; ?>>Contract</option>
                        <option value="Remote" <?php echo ($edit_job && strpos($edit_job['additional_details'] ?? '', 'Remote') !== false) ? 'selected' : ''; ?>>Remote</option>
                        <option value="Internship" <?php echo ($edit_job && strpos($edit_job['additional_details'] ?? '', 'Internship') !== false) ? 'selected' : ''; ?>>Internship</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">Select category</option>
                        <?php if ($categories): ?>
                            <?php while($cat = $categories->fetch_assoc()): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_job && $edit_job['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" value="<?php echo $edit_job ? htmlspecialchars($edit_job['location']) : ''; ?>" placeholder="e.g., Addis Ababa, Remote">
                </div>
            </div>
            
            <div class="form-group">
                <label>Job Description <span class="required">*</span></label>
                <textarea name="description" rows="5" required placeholder="Describe the role, responsibilities, working conditions, and benefits..."><?php echo $edit_job ? htmlspecialchars($edit_job['description']) : ''; ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Requirements <span class="required">*</span></label>
                <textarea name="requirements" rows="5" required placeholder="List required qualifications, experience, skills, and any other requirements..."><?php 
                    if ($edit_job && $edit_job['additional_details']) {
                        $details = json_decode($edit_job['additional_details'], true);
                        echo htmlspecialchars($details['requirements'] ?? '');
                    }
                ?></textarea>
            </div>
            
            <?php if ($has_active_subscription && $company['subscription_plan'] == 'premium'): ?>
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="featured" value="1" <?php echo ($edit_job && $edit_job['featured']) ? 'checked' : ''; ?>>
                    <span>Feature this job (appears at top of search results)</span>
                </label>
            </div>
            <?php endif; ?>
            
            <button type="submit" class="btn btn-primary" style="width: auto;">
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
        <div class="card-header">
            <h2><i class="fas fa-users"></i> Job Applications</h2>
            <span><?php echo $applications->num_rows; ?> total applications</span>
        </div>
        <div class="table-wrapper">
            <?php if ($applications && $applications->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Job Title</th>
                            <th>Applicant</th>
                            <th>Contact</th>
                            <th>Expected Salary</th>
                            <th>Status</th>
                            <th>Applied</th>
                            <th>Action</th>
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
                                <td><?php echo formatMoney($app['expected_salary'] ?? $app['total_amount']); ?>/mo</td>
                                <td><?php echo getStatusBadge($app['status']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($app['created_at'])); ?></td>
                                <td>
                                    <button onclick="viewApplication(<?php echo $app['id']; ?>)" class="btn-sm btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button onclick="showCoverLetter('<?php echo addslashes($app['cover_letter'] ?? 'No cover letter provided'); ?>')" class="btn-sm btn-edit">
                                        <i class="fas fa-envelope"></i> Letter
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No applications yet</p>
                    <p style="font-size: 12px; margin-top: 8px;">When candidates apply for your jobs, they'll appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Jobs List -->
<?php if ($tab != 'applications' && !isset($_GET['action']) && !$edit_job): ?>
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-briefcase"></i> <?php echo ucfirst($tab); ?> Jobs</h2>
            <a href="?action=create" class="btn-sm btn-primary">+ Post New Job</a>
        </div>
        <div class="table-wrapper">
            <?php if ($jobs && $jobs->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Salary</th>
                            <th>Location</th>
                            <th>Applications</th>
                            <th>Status</th>
                            <th>Posted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($job = $jobs->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($job['title']); ?></strong>
                                    <?php if ($job['featured']): ?>
                                        <span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                                    <?php endif; ?>
                                </td>
                                <td class="amount-positive"><?php echo formatMoney($job['price']); ?>/mo</td>
                                <td><?php echo htmlspecialchars($job['location'] ?: 'N/A'); ?></td>
                                <td><?php echo $job['application_count']; ?> applicants</td>
                                <td>
                                    <?php if ($job['approval_status'] == 'approved' && $job['status'] == 'active'): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php elseif ($job['approval_status'] == 'pending'): ?>
                                        <span class="badge badge-warning">Pending Review</span>
                                    <?php elseif ($job['approval_status'] == 'rejected'): ?>
                                        <span class="badge badge-danger">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge badge-info"><?php echo ucfirst($job['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($job['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        <a href="/broker_system/user/product.php?id=<?php echo $job['id']; ?>" class="btn-sm btn-view" target="_blank">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="?edit=<?php echo $job['id']; ?>" class="btn-sm btn-edit">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <?php if ($job['approval_status'] == 'approved' && $job['status'] == 'active' && !$job['featured'] && $has_active_subscription && $company['subscription_plan'] == 'premium'): ?>
                                            <a href="?feature=<?php echo $job['id']; ?>" class="btn-sm btn-feature" onclick="return confirm('Feature this job? It will appear at the top of search results.')">
                                                <i class="fas fa-star"></i> Feature
                                            </a>
                                        <?php endif; ?>
                                        <a href="?delete=<?php echo $job['id']; ?>" class="btn-sm btn-delete" onclick="return confirm('Delete this job post? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-briefcase"></i>
                    <p>No <?php echo $tab; ?> jobs found</p>
                    <?php if ($tab == 'active' && $can_post_more): ?>
                        <a href="?action=create" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                            <i class="fas fa-plus-circle"></i> Post Your First Job
                        </a>
                    <?php endif; ?>
                    <?php if ($tab == 'active' && !$can_post_more && !$has_active_subscription): ?>
                        <a href="subscription.php" class="btn btn-primary" style="margin-top: 16px; display: inline-block;">
                            <i class="fas fa-crown"></i> Upgrade to Post More Jobs
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Cover Letter Modal -->
<div id="coverLetterModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-envelope"></i> Cover Letter</h3>
            <span class="close-modal" onclick="closeCoverLetterModal()">&times;</span>
        </div>
        <div id="coverLetterContent" style="max-height: 400px; overflow-y: auto; padding: 10px 0;"></div>
    </div>
</div>

<script>
// View application details
function viewApplication(transactionId) {
    window.location.href = '/broker_system/user/transaction.php?id=' + transactionId;
}

// Show cover letter modal
function showCoverLetter(letter) {
    document.getElementById('coverLetterContent').innerHTML = '<p style="white-space: pre-wrap;">' + escapeHtml(letter) + '</p>';
    document.getElementById('coverLetterModal').style.display = 'flex';
}

function closeCoverLetterModal() {
    document.getElementById('coverLetterModal').style.display = 'none';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on click outside
window.onclick = function(event) {
    const modal = document.getElementById('coverLetterModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>