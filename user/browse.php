<?php
// user/browse.php - Complete Browse with Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

// Optional login - users can browse without logging in
// requireLogin(); // Comment this out to allow public browsing

$page_title = 'Browse Listings';
ob_start();

$conn = getDbConnection();

// Get and sanitize filter parameters
$type = sanitizeString($_GET['type'] ?? '');
$search = sanitizeString($_GET['search'] ?? '');
$min_price = sanitizeFloat($_GET['min_price'] ?? 0);
$max_price = sanitizeFloat($_GET['max_price'] ?? 0);
$location = sanitizeString($_GET['location'] ?? '');
$page = sanitizeInt($_GET['page'] ?? 1);
$sort = sanitizeString($_GET['sort'] ?? 'newest');

// Validate parameters
$valid_types = ['', 'product', 'job', 'rental'];
if (!in_array($type, $valid_types)) {
    $type = '';
}

$valid_sorts = ['newest', 'price_low', 'price_high', 'popular'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'newest';
}

if ($page < 1) $page = 1;
if ($page > 100) $page = 100;

if ($min_price < 0) $min_price = 0;
if ($max_price < 0) $max_price = 0;

$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$where = ["l.status = 'active'", "l.approval_status = 'approved'"];
$params = [];
$types_param = "";

if ($type) {
    $where[] = "l.type = ?";
    $params[] = $type;
    $types_param .= "s";
}

if ($search) {
    $where[] = "(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types_param .= "sss";
}

if ($min_price > 0) {
    $where[] = "l.price >= ?";
    $params[] = $min_price;
    $types_param .= "d";
}

if ($max_price > 0) {
    $where[] = "l.price <= ?";
    $params[] = $max_price;
    $types_param .= "d";
}

if ($location) {
    $where[] = "l.location LIKE ?";
    $params[] = "%$location%";
    $types_param .= "s";
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Sorting
$orderBy = match($sort) {
    'price_low' => "l.price ASC",
    'price_high' => "l.price DESC",
    'popular' => "l.views DESC",
    default => "l.created_at DESC"
};

// Get total count
$countSql = "SELECT COUNT(*) as total FROM listings l $whereClause";
$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types_param, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Get listings
$sql = "SELECT l.*, u.full_name as seller_name 
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        $whereClause
        ORDER BY $orderBy
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types_param .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_param, ...$params);
$stmt->execute();
$listings = $stmt->get_result();

$conn->close();
?>

<style>
    .browse-header { margin-bottom: 28px; }
    .browse-header h1 { font-size: 32px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .browse-header p { color: #64748b; font-size: 15px; }
    
    .search-section { background: white; border-radius: 20px; padding: 24px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .search-bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .search-bar input { flex: 1; padding: 14px 20px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; }
    .search-bar button { padding: 14px 32px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; }
    
    .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-group label { font-size: 11px; color: #64748b; font-weight: 600; }
    .filter-group input, .filter-group select { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 13px; min-width: 130px; }
    .filter-group button { padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 12px; cursor: pointer; font-weight: 500; }
    .reset-btn { background: #94a3b8 !important; }
    
    .category-filters { display: flex; gap: 12px; margin: 20px 0; flex-wrap: wrap; }
    .filter-chip { padding: 8px 20px; background: white; border-radius: 40px; text-decoration: none; color: #334155; font-size: 13px; font-weight: 500; transition: all 0.3s; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .filter-chip:hover, .filter-chip.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; transform: translateY(-2px); }
    
    .listings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 28px; margin-bottom: 32px; }
    .listing-card { background: white; border-radius: 24px; overflow: hidden; transition: all 0.3s; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
    .listing-card:hover { transform: translateY(-6px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
    .card-image { height: 200px; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; overflow: hidden; }
    .card-image img { width: 100%; height: 100%; object-fit: cover; }
    .card-content { padding: 20px; }
    .card-type { display: inline-block; padding: 4px 12px; background: #f1f5f9; border-radius: 20px; font-size: 11px; font-weight: 600; margin-bottom: 10px; }
    .card-title { font-size: 16px; font-weight: 700; margin-bottom: 8px; color: #0f172a; line-height: 1.4; }
    .card-price { font-size: 20px; font-weight: 800; color: #667eea; margin: 10px 0; }
    .card-location { font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px; margin-top: 8px; }
    .card-seller { font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid #f1f5f9; }
    .stats { display: flex; gap: 12px; margin-top: 8px; font-size: 11px; color: #94a3b8; }
    
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
    .pagination a, .pagination span { padding: 8px 14px; background: white; border-radius: 10px; text-decoration: none; color: #334155; font-size: 14px; transition: all 0.3s; }
    .pagination a:hover, .pagination .active { background: #667eea; color: white; }
    .pagination .disabled { opacity: 0.5; cursor: not-allowed; }
    
    .empty-state { text-align: center; padding: 60px; background: white; border-radius: 24px; }
    .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 16px; display: block; }
    
    .sort-select { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; background: white; }
    .result-count { font-size: 13px; color: #64748b; margin-bottom: 16px; }
    
    @media (max-width: 768px) {
        .listings-grid { grid-template-columns: 1fr; }
        .search-bar { flex-direction: column; }
        .search-bar button { border-radius: 12px; }
        .filters { flex-direction: column; }
        .filter-group input, .filter-group select { width: 100%; }
        .category-filters { overflow-x: auto; flex-wrap: nowrap; }
    }
</style>

<div class="browse-header">
    <h1>Find Your Perfect Match</h1>
    <p>Discover houses, cars, and job opportunities</p>
</div>

<!-- Search Section -->
<div class="search-section">
    <form method="GET" class="search-bar">
        <input type="text" name="search" placeholder="Search by title, description, or location..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit"><i class="fas fa-search"></i> Search</button>
    </form>
    
    <form method="GET" class="filters" id="filterForm">
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
        
        <div class="filter-group">
            <label>Min Price (ETB)</label>
            <input type="number" name="min_price" placeholder="Min" value="<?php echo $min_price ? number_format($min_price, 0) : ''; ?>" step="1000">
        </div>
        <div class="filter-group">
            <label>Max Price (ETB)</label>
            <input type="number" name="max_price" placeholder="Max" value="<?php echo $max_price ? number_format($max_price, 0) : ''; ?>" step="1000">
        </div>
        <div class="filter-group">
            <label>Location</label>
            <input type="text" name="location" placeholder="City/Area" value="<?php echo htmlspecialchars($location); ?>">
        </div>
        <div class="filter-group">
            <label>Sort by</label>
            <select name="sort" onchange="this.form.submit()">
                <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                <option value="price_low" <?php echo $sort == 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                <option value="price_high" <?php echo $sort == 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                <option value="popular" <?php echo $sort == 'popular' ? 'selected' : ''; ?>>Most Viewed</option>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit">Apply Filters</button>
        </div>
        <?php if ($search || $type || $min_price || $max_price || $location): ?>
            <div class="filter-group">
                <a href="browse.php" class="reset-btn" style="padding: 10px 20px; background: #94a3b8; color: white; border-radius: 12px; text-decoration: none;">Clear All</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Category Filters -->
<div class="category-filters">
    <a href="browse.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo empty($type) ? 'active' : ''; ?>">🏠 All</a>
    <a href="browse.php?type=rental<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $type == 'rental' ? 'active' : ''; ?>">🏡 Houses & Properties</a>
    <a href="browse.php?type=product<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $type == 'product' ? 'active' : ''; ?>">🚗 Cars & Vehicles</a>
    <a href="browse.php?type=job<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-chip <?php echo $type == 'job' ? 'active' : ''; ?>">💼 Jobs</a>
</div>

<!-- Result Count -->
<div class="result-count">
    <i class="fas fa-list"></i> Found <?php echo number_format($total); ?> listing(s)
</div>

<!-- Listings Grid -->
<?php if ($listings && $listings->num_rows > 0): ?>
    <div class="listings-grid">
        <?php while($item = $listings->fetch_assoc()): 
            $cover_image = '';
            $has_image = false;
            
            if (!empty($item['cover_image'])) {
                $cover_image = '/broker_system/uploads/listings/' . $item['cover_image'];
                $file_path = $_SERVER['DOCUMENT_ROOT'] . $cover_image;
                if (file_exists($file_path)) {
                    $has_image = true;
                }
            }
            
            $additional = $item['additional_details'] ? json_decode($item['additional_details'], true) : [];
            $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
        ?>
            <div class="listing-card" onclick="location.href='product.php?id=<?php echo $item['id']; ?>'">
                <div class="card-image">
                    <?php if ($has_image): ?>
                        <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">
                            <?php echo $icons[$item['type']]; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-content">
                    <span class="card-type">
                        <?php if ($item['type'] == 'rental'): ?>🏡 Property
                        <?php elseif ($item['type'] == 'product'): ?>🚗 Car
                        <?php else: ?>💼 Job<?php endif; ?>
                    </span>
                    <div class="card-title"><?php echo htmlspecialchars(substr($item['title'], 0, 50)); ?></div>
                    <div class="card-price"><?php echo formatMoney($item['price']); ?>
                        <?php if ($item['type'] == 'rental'): ?><span style="font-size: 12px;">/month</span><?php endif; ?>
                        <?php if ($item['type'] == 'job'): ?><span style="font-size: 12px;">/month</span><?php endif; ?>
                    </div>
                    
                    <?php if ($item['type'] == 'rental' && !empty($additional)): ?>
                        <div style="font-size: 12px; color: #64748b;">
                            <?php if (!empty($additional['bedrooms'])): ?>🛏️ <?php echo $additional['bedrooms']; ?> bed<?php endif; ?>
                            <?php if (!empty($additional['bathrooms'])): ?> 🚿 <?php echo $additional['bathrooms']; ?> bath<?php endif; ?>
                            <?php if (!empty($additional['area'])): ?> 📐 <?php echo $additional['area']; ?> sqm<?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($item['type'] == 'product' && !empty($additional)): ?>
                        <div style="font-size: 12px; color: #64748b;">
                            <?php if (!empty($additional['year'])): ?>📅 <?php echo $additional['year']; ?><?php endif; ?>
                            <?php if (!empty($additional['mileage'])): ?> 📊 <?php echo number_format($additional['mileage']); ?> km<?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($item['location'])): ?>
                        <div class="card-location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($item['location']); ?></div>
                    <?php endif; ?>
                    
                    <div class="card-seller"><i class="fas fa-user"></i> <?php echo htmlspecialchars($item['seller_name']); ?></div>
                    <div class="stats"><span><i class="fas fa-eye"></i> <?php echo number_format($item['views']); ?> views</span></div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&type=<?php echo urlencode($type); ?>&search=<?php echo urlencode($search); ?>&min_price=<?php echo $min_price; ?>&max_price=<?php echo $max_price; ?>&location=<?php echo urlencode($location); ?>&sort=<?php echo $sort; ?>">← Previous</a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?page=<?php echo $i; ?>&type=<?php echo urlencode($type); ?>&search=<?php echo urlencode($search); ?>&min_price=<?php echo $min_price; ?>&max_price=<?php echo $max_price; ?>&location=<?php echo urlencode($location); ?>&sort=<?php echo $sort; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&type=<?php echo urlencode($type); ?>&search=<?php echo urlencode($search); ?>&min_price=<?php echo $min_price; ?>&max_price=<?php echo $max_price; ?>&location=<?php echo urlencode($location); ?>&sort=<?php echo $sort; ?>">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-search"></i>
        <h3>No listings found</h3>
        <p>Try adjusting your search or filter criteria</p>
        <a href="post_listing.php" class="btn" style="display: inline-block; margin-top: 16px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 12px 28px; border-radius: 40px; text-decoration: none;">
            <i class="fas fa-plus-circle"></i> Post a Listing
        </a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>