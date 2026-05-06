<?php
// user/jobs.php - Complete Job Search and Browse with Filters

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

$page_title = 'Find Jobs';
ob_start();

$conn = getDbConnection();

// Get and sanitize filter parameters
$category = sanitizeInt($_GET['category'] ?? 0);
$search = sanitizeString($_GET['search'] ?? '');
$employment_type = sanitizeString($_GET['employment_type'] ?? '');
$min_salary = sanitizeFloat($_GET['min_salary'] ?? 0);
$max_salary = sanitizeFloat($_GET['max_salary'] ?? 0);
$location = sanitizeString($_GET['location'] ?? '');
$page = sanitizeInt($_GET['page'] ?? 1);
$sort = sanitizeString($_GET['sort'] ?? 'newest');

// Validate parameters
$valid_employment = ['', 'Full-time', 'Part-time', 'Contract', 'Remote', 'Internship'];
if (!in_array($employment_type, $valid_employment)) {
    $employment_type = '';
}

$valid_sorts = ['newest', 'salary_low', 'salary_high', 'company'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'newest';
}

if ($page < 1) $page = 1;
if ($page > 100) $page = 100;

if ($min_salary < 0) $min_salary = 0;
if ($max_salary < 0) $max_salary = 0;

$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$where = [
    "l.type = 'job'", 
    "l.status = 'active'", 
    "l.approval_status = 'approved'"
];
$params = [];
$types = "";

if ($search) {
    $where[] = "(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if ($category > 0) {
    $where[] = "l.category_id = ?";
    $params[] = $category;
    $types .= "i";
}

if ($employment_type) {
    $where[] = "JSON_EXTRACT(l.additional_details, '$.employment_type') = ?";
    $params[] = $employment_type;
    $types .= "s";
}

if ($min_salary > 0) {
    $where[] = "l.price >= ?";
    $params[] = $min_salary;
    $types .= "d";
}

if ($max_salary > 0) {
    $where[] = "l.price <= ?";
    $params[] = $max_salary;
    $types .= "d";
}

if ($location) {
    $where[] = "l.location LIKE ?";
    $params[] = "%$location%";
    $types .= "s";
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Sorting
switch ($sort) {
    case 'salary_low':
        $orderBy = "l.price ASC";
        break;
    case 'salary_high':
        $orderBy = "l.price DESC";
        break;
    case 'company':
        $orderBy = "u.full_name ASC";
        break;
    default:
        $orderBy = "l.created_at DESC";
}

// Get total count
$countSql = "SELECT COUNT(*) as total FROM listings l JOIN users u ON l.seller_id = u.id $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Get jobs
$sql = "SELECT l.*, u.full_name as company_name, u.id as company_id, u.is_verified as company_verified,
        c.name as category_name
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        LEFT JOIN categories c ON l.category_id = c.id
        $whereClause
        ORDER BY $orderBy
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$jobs = $stmt->get_result();

// Get categories for filter
$categories = $conn->query("SELECT id, name FROM categories WHERE type = 'job' AND is_active = 1 ORDER BY name");

// Get salary ranges for filter
$salary_ranges = [
    ['min' => 0, 'max' => 5000, 'label' => 'Under 5,000 ETB'],
    ['min' => 5000, 'max' => 10000, 'label' => '5,000 - 10,000 ETB'],
    ['min' => 10000, 'max' => 20000, 'label' => '10,000 - 20,000 ETB'],
    ['min' => 20000, 'max' => 50000, 'label' => '20,000 - 50,000 ETB'],
    ['min' => 50000, 'max' => 100000, 'label' => '50,000 - 100,000 ETB'],
    ['min' => 100000, 'max' => 999999999, 'label' => '100,000+ ETB']
];

$conn->close();
?>

<style>
    .jobs-container { max-width: 1400px; margin: 0 auto; }
    
    /* Header */
    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 32px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .page-header p { color: #64748b; font-size: 15px; }
    
    /* Search Section */
    .search-section { background: white; border-radius: 24px; padding: 24px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .search-bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .search-bar input { flex: 1; padding: 14px 20px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; }
    .search-bar button { padding: 14px 32px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
    .search-bar button:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    
    /* Filters */
    .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-group label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .filter-group select, .filter-group input { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; background: white; min-width: 140px; }
    .filter-group select:focus, .filter-group input:focus { outline: none; border-color: #667eea; }
    .reset-btn { background: #94a3b8 !important; color: white; padding: 10px 20px; border-radius: 10px; text-decoration: none; font-size: 13px; font-weight: 500; display: inline-block; transition: all 0.3s; }
    .reset-btn:hover { background: #64748b !important; transform: translateY(-2px); }
    
    /* Category Chips */
    .category-filters { display: flex; gap: 10px; margin: 20px 0; flex-wrap: wrap; }
    .filter-chip { padding: 8px 20px; background: white; border-radius: 40px; text-decoration: none; color: #334155; font-size: 13px; font-weight: 500; transition: all 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .filter-chip:hover, .filter-chip.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; transform: translateY(-2px); }
    
    /* Result Header */
    .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
    .result-count { font-size: 13px; color: #64748b; }
    .sort-select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; background: white; }
    
    /* Jobs Grid */
    .jobs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 24px; margin-bottom: 32px; }
    
    /* Job Card */
    .job-card { background: white; border-radius: 20px; overflow: hidden; transition: all 0.3s; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.05); position: relative; }
    .job-card:hover { transform: translateY(-6px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
    
    .job-card.featured { border: 2px solid #f59e0b; }
    .featured-badge { position: absolute; top: 12px; right: 12px; background: #f59e0b; color: white; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
    
    .job-header { padding: 20px; border-bottom: 1px solid #f1f5f9; }
    .job-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .company { font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
    .verified-badge { color: #10b981; font-size: 12px; }
    .salary { font-size: 22px; font-weight: 700; color: #667eea; margin-top: 8px; }
    .salary small { font-size: 12px; font-weight: normal; }
    
    .job-details { padding: 16px 20px; background: #f8fafc; display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: #475569; border-bottom: 1px solid #f1f5f9; }
    .job-details i { width: 16px; margin-right: 4px; color: #667eea; }
    
    .job-description { padding: 20px; font-size: 13px; color: #475569; line-height: 1.5; max-height: 100px; overflow: hidden; }
    
    .job-footer { padding: 16px 20px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: white; }
    
    /* Badges */
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-fulltime { background: #d1fae5; color: #059669; }
    .badge-parttime { background: #fed7aa; color: #ea580c; }
    .badge-remote { background: #dbeafe; color: #1e40af; }
    .badge-contract { background: #f3e8ff; color: #9333ea; }
    .badge-internship { background: #fce7f3; color: #db2777; }
    
    /* Buttons */
    .btn-apply { padding: 8px 20px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 30px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; }
    .btn-apply:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    .btn-save { background: none; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 30px; cursor: pointer; transition: all 0.3s; color: #64748b; }
    .btn-save:hover { background: #f1f5f9; color: #667eea; }
    
    /* Pagination */
    .pagination { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; margin-top: 20px; }
    .pagination a, .pagination span { padding: 8px 14px; background: white; border-radius: 10px; text-decoration: none; color: #334155; font-size: 14px; transition: all 0.3s; border: 1px solid #e2e8f0; }
    .pagination a:hover, .pagination .active { background: #667eea; color: white; border-color: #667eea; }
    .pagination .disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
    
    /* Empty State */
    .empty-state { text-align: center; padding: 60px; background: white; border-radius: 24px; }
    .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    .empty-state h3 { font-size: 20px; color: #334155; margin-bottom: 8px; }
    
    /* Loading */
    .loading { text-align: center; padding: 40px; }
    .loading-spinner { width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top-color: #667eea; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto; }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    @media (max-width: 768px) {
        .jobs-grid { grid-template-columns: 1fr; }
        .search-bar { flex-direction: column; }
        .filters { flex-direction: column; align-items: stretch; }
        .filter-group select, .filter-group input { width: 100%; }
        .category-filters { overflow-x: auto; flex-wrap: nowrap; }
        .result-header { flex-direction: column; align-items: flex-start; }
    }
</style>

<div class="jobs-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-briefcase"></i> Find Your Next Opportunity</h1>
        <p>Browse thousands of job opportunities from trusted employers in Ethiopia</p>
    </div>
    
    <!-- Search Section -->
    <div class="search-section">
        <form method="GET" class="search-bar" id="searchForm">
            <input type="text" name="search" placeholder="Job title, keywords, or company" 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit"><i class="fas fa-search"></i> Search Jobs</button>
        </form>
        
        <div class="filters">
            <div class="filter-group">
                <label>Category</label>
                <select name="category" form="searchForm" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php while($cat = $categories->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Employment Type</label>
                <select name="employment_type" form="searchForm" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="Full-time" <?php echo $employment_type == 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
                    <option value="Part-time" <?php echo $employment_type == 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
                    <option value="Contract" <?php echo $employment_type == 'Contract' ? 'selected' : ''; ?>>Contract</option>
                    <option value="Remote" <?php echo $employment_type == 'Remote' ? 'selected' : ''; ?>>Remote</option>
                    <option value="Internship" <?php echo $employment_type == 'Internship' ? 'selected' : ''; ?>>Internship</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Location</label>
                <input type="text" name="location" placeholder="City/Area" value="<?php echo htmlspecialchars($location); ?>" form="searchForm">
            </div>
            
            <div class="filter-group">
                <label>Min Salary (ETB)</label>
                <input type="number" name="min_salary" placeholder="Min" value="<?php echo $min_salary ? number_format($min_salary, 0) : ''; ?>" step="1000" form="searchForm">
            </div>
            
            <div class="filter-group">
                <label>Max Salary (ETB)</label>
                <input type="number" name="max_salary" placeholder="Max" value="<?php echo $max_salary ? number_format($max_salary, 0) : ''; ?>" step="1000" form="searchForm">
            </div>
            
            <?php if ($search || $category || $employment_type || $location || $min_salary || $max_salary): ?>
                <div class="filter-group">
                    <a href="jobs.php" class="reset-btn">Clear All Filters</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Category Quick Filters -->
    <div class="category-filters">
        <a href="jobs.php" class="filter-chip <?php echo empty($_GET['category']) ? 'active' : ''; ?>">All Jobs</a>
        <a href="?employment_type=Full-time<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Full-time' ? 'active' : ''; ?>">Full-time</a>
        <a href="?employment_type=Remote<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Remote' ? 'active' : ''; ?>">Remote</a>
        <a href="?employment_type=Contract<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Contract' ? 'active' : ''; ?>">Contract</a>
        <a href="?employment_type=Internship<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $employment_type == 'Internship' ? 'active' : ''; ?>">Internship</a>
    </div>
    
    <!-- Results Header -->
    <div class="result-header">
        <div class="result-count">
            <i class="fas fa-list"></i> Found <strong><?php echo number_format($total); ?></strong> job opportunity(ies)
        </div>
        <div class="sort-options">
            <select class="sort-select" onchange="location.href=this.value">
                <option value="?sort=newest<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                <option value="?sort=salary_low<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'salary_low' ? 'selected' : ''; ?>>Salary: Low to High</option>
                <option value="?sort=salary_high<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'salary_high' ? 'selected' : ''; ?>>Salary: High to Low</option>
                <option value="?sort=company<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $category ? '&category=' . $category : ''; ?><?php echo $employment_type ? '&employment_type=' . urlencode($employment_type) : ''; ?>" <?php echo $sort == 'company' ? 'selected' : ''; ?>>Company Name</option>
            </select>
        </div>
    </div>
    
    <!-- Jobs Grid -->
    <?php if ($jobs && $jobs->num_rows > 0): ?>
        <div class="jobs-grid">
            <?php while($job = $jobs->fetch_assoc()): 
                $additional = $job['additional_details'] ? json_decode($job['additional_details'], true) : [];
                $emp_type = $additional['employment_type'] ?? '';
                $requirements = $additional['requirements'] ?? '';
                $is_featured = $job['featured'] ?? false;
                
                // Badge class based on employment type
                $badge_class = '';
                switch($emp_type) {
                    case 'Full-time': $badge_class = 'badge-fulltime'; break;
                    case 'Part-time': $badge_class = 'badge-parttime'; break;
                    case 'Remote': $badge_class = 'badge-remote'; break;
                    case 'Contract': $badge_class = 'badge-contract'; break;
                    case 'Internship': $badge_class = 'badge-internship'; break;
                }
            ?>
                <div class="job-card <?php echo $is_featured ? 'featured' : ''; ?>" onclick="location.href='product.php?id=<?php echo $job['id']; ?>'">
                    <?php if ($is_featured): ?>
                        <div class="featured-badge"><i class="fas fa-star"></i> Featured</div>
                    <?php endif; ?>
                    
                    <div class="job-header">
                        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                        <div class="company">
                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?>
                            <?php if ($job['company_verified']): ?>
                                <i class="fas fa-check-circle verified-badge" title="Verified Company"></i>
                            <?php endif; ?>
                        </div>
                        <div class="salary">
                            <?php echo formatMoney($job['price']); ?><small>/month</small>
                        </div>
                    </div>
                    
                    <div class="job-details">
                        <?php if ($emp_type): ?>
                            <span><i class="fas fa-clock"></i> <?php echo $emp_type; ?></span>
                        <?php endif; ?>
                        <?php if ($job['location']): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['location']); ?></span>
                        <?php endif; ?>
                        <?php if ($job['category_name']): ?>
                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($job['category_name']); ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-calendar"></i> Posted <?php echo timeAgo($job['created_at']); ?></span>
                    </div>
                    
                    <div class="job-description">
                        <?php 
                        $desc = strip_tags($job['description']);
                        echo htmlspecialchars(substr($desc, 0, 120));
                        if (strlen($desc) > 120): ?>...<?php endif; ?>
                    </div>
                    
                    <div class="job-footer">
                        <?php if ($emp_type): ?>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo $emp_type; ?></span>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 10px;">
                            <?php if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in']): ?>
                                <?php if ($_SESSION['user_role'] != 'company'): ?>
                                    <a href="apply_job.php?id=<?php echo $job['id']; ?>" class="btn-apply" onclick="event.stopPropagation()">
                                        Apply Now →
                                    </a>
                                <?php else: ?>
                                    <span class="badge" style="background: #e2e8f0; color: #64748b;">Company Account</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="/broker_system/auth/login.php" class="btn-apply" onclick="event.stopPropagation()">
                                    Login to Apply →
                                </a>
                            <?php endif; ?>
                            <button class="btn-save" onclick="event.stopPropagation(); saveJob(<?php echo $job['id']; ?>, this)" title="Save for later">
                                <i class="far fa-bookmark"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category; ?>&employment_type=<?php echo urlencode($employment_type); ?>&location=<?php echo urlencode($location); ?>&min_salary=<?php echo $min_salary; ?>&max_salary=<?php echo $max_salary; ?>&sort=<?php echo $sort; ?>">
                        ← Previous
                    </a>
                <?php else: ?>
                    <span class="disabled">← Previous</span>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category; ?>&employment_type=<?php echo urlencode($employment_type); ?>&location=<?php echo urlencode($location); ?>&min_salary=<?php echo $min_salary; ?>&max_salary=<?php echo $max_salary; ?>&sort=<?php echo $sort; ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category; ?>&employment_type=<?php echo urlencode($employment_type); ?>&location=<?php echo urlencode($location); ?>&min_salary=<?php echo $min_salary; ?>&max_salary=<?php echo $max_salary; ?>&sort=<?php echo $sort; ?>">
                        Next →
                    </a>
                <?php else: ?>
                    <span class="disabled">Next →</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-search"></i>
            <h3>No jobs found</h3>
            <p>Try adjusting your search or filter criteria</p>
            <?php if ($search || $category || $employment_type || $location): ?>
                <a href="jobs.php" class="btn-apply" style="margin-top: 16px; display: inline-block;">Clear All Filters</a>
            <?php endif; ?>
            <?php if (!isset($_SESSION['user_logged_in'])): ?>
                <p style="margin-top: 16px;">
                    <a href="/broker_system/auth/register.php" style="color: #667eea;">Create an account</a> to apply for jobs
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Save job for later
function saveJob(jobId, button) {
    fetch('api/save_job.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ job_id: jobId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const icon = button.querySelector('i');
            if (data.saved) {
                icon.classList.remove('far');
                icon.classList.add('fas');
                button.style.color = '#f59e0b';
            } else {
                icon.classList.remove('fas');
                icon.classList.add('far');
                button.style.color = '#64748b';
            }
        } else if (data.require_login) {
            window.location.href = '/broker_system/auth/login.php';
        }
    });
}

// Quick filter chips
document.querySelectorAll('.filter-chip').forEach(chip => {
    chip.addEventListener('click', function(e) {
        if (!this.getAttribute('href')?.includes('?')) {
            e.preventDefault();
            const params = new URLSearchParams(window.location.search);
            params.set('employment_type', this.textContent.trim());
            window.location.href = '?' + params.toString();
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>