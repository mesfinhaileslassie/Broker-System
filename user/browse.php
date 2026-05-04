<?php
// user/browse.php - Browse Listings Page

// Set page title
$page_title = 'Browse Listings';

// Start output buffering
ob_start();

// Include database and functions
require_once '../config/database.php';
require_once '../includes/functions.php';

// Get filter parameters
$type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$conn = getDbConnection();

// Build query
$where = ["l.status = 'active'", "l.approval_status = 'approved'"];
$params = [];
$types = "";

if ($type) {
    $where[] = "l.type = ?";
    $params[] = $type;
    $types .= "s";
}

if ($search) {
    $where[] = "(l.title LIKE ? OR l.description LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
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

// Get listings
$sql = "SELECT l.*, u.full_name as seller_name 
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        $whereClause
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$listings = $stmt->get_result();

$conn->close();
?>

<style>
    .browse-header {
        margin-bottom: 28px;
    }
    
    .browse-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .browse-header p {
        color: #64748b;
        font-size: 14px;
    }
    
    .search-bar {
        background: white;
        border-radius: 16px;
        padding: 8px;
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .search-bar input {
        flex: 1;
        padding: 12px 16px;
        border: none;
        outline: none;
        font-size: 14px;
        background: transparent;
    }
    
    .search-bar button {
        padding: 12px 28px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 500;
    }
    
    .category-filters {
        display: flex;
        gap: 12px;
        margin-bottom: 28px;
        flex-wrap: wrap;
    }
    
    .filter-chip {
        padding: 8px 20px;
        background: white;
        border-radius: 40px;
        text-decoration: none;
        color: #334155;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .filter-chip:hover {
        background: #667eea;
        color: white;
        transform: translateY(-2px);
    }
    
    .filter-chip.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .listings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }
    
    .listing-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.3s;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .listing-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -8px rgba(0,0,0,0.15);
    }
    
    .card-image {
        height: 180px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
    }
    
    .card-content {
        padding: 16px;
    }
    
    .card-type {
        display: inline-block;
        padding: 4px 10px;
        background: #f1f5f9;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .card-title {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #0f172a;
    }
    
    .card-price {
        font-size: 18px;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 8px;
    }
    
    .card-seller {
        font-size: 12px;
        color: #64748b;
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        background: white;
        border-radius: 10px;
        text-decoration: none;
        color: #334155;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .pagination a:hover {
        background: #667eea;
        color: white;
    }
    
    .pagination .active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
    
    .empty-state h3 {
        color: #334155;
        margin-bottom: 8px;
    }
    
    @media (max-width: 768px) {
        .listings-grid {
            grid-template-columns: 1fr;
        }
        .search-bar {
            flex-direction: column;
        }
    }
</style>

<div class="browse-header">
    <h1>Browse Listings</h1>
    <p>Discover great deals from trusted sellers</p>
</div>

<!-- Search Bar -->
<form method="GET" class="search-bar">
    <input type="text" name="search" placeholder="Search for products, jobs, rentals..." value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit"><i class="fas fa-search"></i> Search</button>
</form>

<!-- Category Filters -->
<div class="category-filters">
    <a href="browse.php" class="filter-chip <?php echo empty($type) ? 'active' : ''; ?>">All</a>
    <a href="browse.php?type=product" class="filter-chip <?php echo $type == 'product' ? 'active' : ''; ?>">📦 Products</a>
    <a href="browse.php?type=job" class="filter-chip <?php echo $type == 'job' ? 'active' : ''; ?>">💼 Jobs</a>
    <a href="browse.php?type=rental" class="filter-chip <?php echo $type == 'rental' ? 'active' : ''; ?>">🏠 Rentals</a>
</div>

<!-- Listings Grid -->
<?php if ($listings->num_rows > 0): ?>
    <div class="listings-grid">
        <?php while($item = $listings->fetch_assoc()): ?>
            <div class="listing-card" onclick="location.href='product.php?id=<?php echo $item['id']; ?>'">
                <div class="card-image">
                    <?php
                    $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
                    echo $icons[$item['type']];
                    ?>
                </div>
                <div class="card-content">
                    <span class="card-type"><?php echo ucfirst($item['type']); ?></span>
                    <div class="card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                    <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                    <div class="card-seller">by <?php echo htmlspecialchars($item['seller_name']); ?></div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&type=<?php echo urlencode($type); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-box-open"></i>
        <h3>No listings found</h3>
        <p>Try adjusting your search or filter criteria</p>
        <a href="post_listing.php" class="action-btn" style="display: inline-block; margin-top: 16px; background: #667eea; color: white; padding: 10px 24px; border-radius: 40px; text-decoration: none;">Post a Listing</a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>