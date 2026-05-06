<?php
// user/jobs.php - Browse and Apply for Jobs

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = 'Find Jobs';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();

// Get filter parameters
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$employment_type = $_GET['employment_type'] ?? '';
$min_salary = $_GET['min_salary'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$where = ["l.type = 'job'", "l.status = 'active'", "l.approval_status = 'approved'"];
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

if ($category) {
    $where[] = "l.category_id = ?";
    $params[] = $category;
    $types .= "i";
}

if ($employment_type) {
    $where[] = "JSON_EXTRACT(l.additional_details, '$.employment_type') = ?";
    $params[] = $employment_type;
    $types .= "s";
}

if ($min_salary) {
    $where[] = "l.price >= ?";
    $params[] = $min_salary;
    $types .= "d";
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Get total count
$countSql = "SELECT COUNT(*) as total FROM listings l $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Get jobs
$sql = "SELECT l.*, u.full_name as company_name, c.name as category_name
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        LEFT JOIN categories c ON l.category_id = c.id
        $whereClause
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$jobs = $stmt->get_result();

// Get categories for filter
$categories = $conn->query("SELECT id, name FROM categories WHERE type = 'job' AND is_active = 1");

$conn->close();
?>

<style>
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 32px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .search-section {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .search-bar {
        display: flex;
        gap: 12px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    
    .search-bar input {
        flex: 1;
        padding: 14px 20px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
    }
    
    .search-bar button {
        padding: 14px 32px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .filters {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .filter-select {
        padding: 10px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        background: white;
        min-width: 150px;
    }
    
    .jobs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }
    
    .job-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.3s;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .job-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .job-header {
        padding: 20px;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .job-title {
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .company {
        font-size: 13px;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 8px;
    }
    
    .salary {
        font-size: 20px;
        font-weight: 700;
        color: #667eea;
    }
    
    .salary small {
        font-size: 12px;
        font-weight: normal;
    }
    
    .job-details {
        padding: 16px 20px;
        background: #f8fafc;
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        font-size: 12px;
        color: #475569;
    }
    
    .job-details i {
        width: 16px;
        margin-right: 4px;
    }
    
    .job-description {
        padding: 20px;
        font-size: 13px;
        color: #475569;
        line-height: 1.5;
    }
    
    .job-footer {
        padding: 16px 20px;
        border-top: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    
    .badge-fulltime { background: #d1fae5; color: #059669; }
    .badge-parttime { background: #fed7aa; color: #ea580c; }
    .badge-remote { background: #dbeafe; color: #1e40af; }
    .badge-contract { background: #f3e8ff; color: #9333ea; }
    
    .btn-apply {
        padding: 8px 20px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-apply:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border-radius: 10px;
        text-decoration: none;
        color: #334155;
        font-size: 14px;
    }
    
    .pagination a:hover, .pagination .active {
        background: #667eea;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    @media (max-width: 768px) {
        .jobs-grid {
            grid-template-columns: 1fr;
        }
        .search-bar {
            flex-direction: column;
        }
        .filters {
            flex-direction: column;
        }
        .filter-select {
            width: 100%;
        }
    }
</style>

<div class="page-header">
    <h1>Find Your Next Opportunity</h1>
    <p>Browse thousands of job opportunities from trusted employers</p>
</div>

<!-- Search Section -->
<div class="search-section">
    <form method="GET" class="search-bar">
        <input type="text" name="search" placeholder="Job title, keywords, or company" value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit"><i class="fas fa-search"></i> Search Jobs</button>
    </form>
    
    <div class="filters">
        <select name="category" class="filter-select" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php while($cat = $categories->fetch_assoc()): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
            <?php endwhile; ?>
        </select>
        
        <select name="employment_type" class="filter-select" onchange="this.form.submit()">
            <option value="">Employment Type</option>
            <option value="Full-time" <?php echo $employment_type == 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
            <option value="Part-time" <?php echo $employment_type == 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
            <option value="Contract" <?php echo $employment_type == 'Contract' ? 'selected' : ''; ?>>Contract</option>
            <option value="Remote" <?php echo $employment_type == 'Remote' ? 'selected' : ''; ?>>Remote</option>
            <option value="Internship" <?php echo $employment_type == 'Internship' ? 'selected' : ''; ?>>Internship</option>
        </select>
        
        <select name="min_salary" class="filter-select" onchange="this.form.submit()">
            <option value="">Minimum Salary</option>
            <option value="5000" <?php echo $min_salary == '5000' ? 'selected' : ''; ?>>5,000+ ETB</option>
            <option value="10000" <?php echo $min_salary == '10000' ? 'selected' : ''; ?>>10,000+ ETB</option>
            <option value="20000" <?php echo $min_salary == '20000' ? 'selected' : ''; ?>>20,000+ ETB</option>
            <option value="50000" <?php echo $min_salary == '50000' ? 'selected' : ''; ?>>50,000+ ETB</option>
        </select>
    </form>
</div>

<!-- Jobs Grid -->
<?php if ($jobs && $jobs->num_rows > 0): ?>
    <div class="jobs-grid">
        <?php while($job = $jobs->fetch_assoc()): 
            $additional = $job['additional_details'] ? json_decode($job['additional_details'], true) : [];
            $emp_type = $additional['employment_type'] ?? '';
            $badge_class = '';
            if ($emp_type == 'Full-time') $badge_class = 'badge-fulltime';
            elseif ($emp_type == 'Part-time') $badge_class = 'badge-parttime';
            elseif ($emp_type == 'Remote') $badge_class = 'badge-remote';
            elseif ($emp_type == 'Contract') $badge_class = 'badge-contract';
        ?>
            <div class="job-card" onclick="location.href='product.php?id=<?php echo $job['id']; ?>'">
                <div class="job-header">
                    <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                    <div class="company">
                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?>
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
                </div>
                
                <div class="job-description">
                    <?php echo nl2br(htmlspecialchars(substr($job['description'], 0, 120))); ?>
                    <?php if (strlen($job['description']) > 120): ?>...<?php endif; ?>
                </div>
                
                <div class="job-footer">
                    <?php if ($emp_type): ?>
                        <span class="badge <?php echo $badge_class; ?>"><?php echo $emp_type; ?></span>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <button class="btn-apply" onclick="event.stopPropagation(); location.href='product.php?id=<?php echo $job['id']; ?>'">
                        Apply Now →
                    </button>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category); ?>&employment_type=<?php echo urlencode($employment_type); ?>&min_salary=<?php echo urlencode($min_salary); ?>" 
                   class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-search" style="font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
        <h3>No jobs found</h3>
        <p>Try adjusting your search or filter criteria</p>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>