<?php
// user/browse.php - Browse all listings with filters

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();

// Get filter parameters
$type = $_GET['type'] ?? '';
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$min_price = $_GET['min_price'] ?? '';
$max_price = $_GET['max_price'] ?? '';
$sort = $_GET['sort'] ?? 'newest';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$where = ["l.status = 'active'"];
$params = [];
$types = "";

if ($type) {
    $where[] = "l.type = ?";
    $params[] = $type;
    $types .= "s";
}

if ($category) {
    $where[] = "l.category_id = ?";
    $params[] = $category;
    $types .= "i";
}

if ($search) {
    $where[] = "(l.title LIKE ? OR l.description LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if ($min_price) {
    $where[] = "l.price >= ?";
    $params[] = floatval($min_price);
    $types .= "d";
}

if ($max_price) {
    $where[] = "l.price <= ?";
    $params[] = floatval($max_price);
    $types .= "d";
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Sort order
$orderBy = match($sort) {
    'price_low' => "l.price ASC",
    'price_high' => "l.price DESC",
    'oldest' => "l.created_at ASC",
    default => "l.created_at DESC"
};

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
$sql = "SELECT l.*, u.full_name as seller_name, c.name as category_name
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
$listings = $stmt->get_result();

// Get categories for filter
$categories = $conn->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY type, name");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Listings - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; position: sticky; top: 0; z-index: 1000; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .nav a { margin-left: 20px; text-decoration: none; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; display: flex; gap: 24px; }
        
        /* Sidebar Filters */
        .filters { width: 260px; background: white; border-radius: 12px; padding: 20px; height: fit-content; position: sticky; top: 80px; }
        .filter-group { margin-bottom: 20px; }
        .filter-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; }
        .filter-group select, .filter-group input { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        .price-range { display: flex; gap: 8px; }
        .price-range input { width: 50%; }
        .btn-filter { width: 100%; padding: 10px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; margin-top: 10px; }
        .btn-reset { width: 100%; padding: 10px; background: #aaa; color: white; border: none; border-radius: 6px; cursor: pointer; margin-top: 10px; }
        
        /* Results */
        .results { flex: 1; }
        .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .sort-select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s; cursor: pointer; }
        .card:hover { transform: translateY(-4px); box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .card-image { height: 180px; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; }
        .card-content { padding: 16px; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; color: #333; }
        .card-price { font-size: 20px; font-weight: 700; color: #667eea; margin: 8px 0; }
        .card-seller { font-size: 12px; color: #888; }
        .card-type { display: inline-block; padding: 4px 8px; background: #f0f0f0; border-radius: 4px; font-size: 11px; font-weight: 600; margin-bottom: 8px; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 32px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination .active { background: #667eea; color: white; border-color: #667eea; }
        .no-results { text-align: center; padding: 60px; background: white; border-radius: 12px; }
        
        @media (max-width: 768px) {
            .container { flex-direction: column; }
            .filters { width: 100%; position: static; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <div class="nav">
                <a href="browse.php">Browse</a>
                <?php if (isset($_SESSION['user_logged_in'])): ?>
                    <a href="dashboard.php">Dashboard</a>
                    <a href="../auth/logout.php">Logout</a>
                <?php else: ?>
                    <a href="../auth/login.php">Login</a>
                    <a href="../auth/register.php">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    
    <div class="container">
        <!-- Sidebar Filters -->
        <div class="filters">
            <form method="GET" id="filterForm">
                <div class="filter-group">
                    <label>Type</label>
                    <select name="type" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <option value="product" <?php echo $type == 'product' ? 'selected' : ''; ?>>Products</option>
                        <option value="job" <?php echo $type == 'job' ? 'selected' : ''; ?>>Jobs</option>
                        <option value="rental" <?php echo $type == 'rental' ? 'selected' : ''; ?>>Rentals</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Category</label>
                    <select name="category" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php while($cat = $categories->fetch_assoc()): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Price Range</label>
                    <div class="price-range">
                        <input type="number" name="min_price" placeholder="Min" value="<?php echo htmlspecialchars($min_price); ?>" onchange="this.form.submit()">
                        <input type="number" name="max_price" placeholder="Max" value="<?php echo htmlspecialchars($max_price); ?>" onchange="this.form.submit()">
                    </div>
                </div>
                
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply Filters</button>
                <a href="browse.php" class="btn-reset" style="display: block; text-align: center; text-decoration: none;"><i class="fas fa-undo"></i> Reset</a>
            </form>
        </div>
        
        <!-- Results -->
        <div class="results">
            <div class="results-header">
                <div><strong><?php echo number_format($total); ?></strong> listings found</div>
                <select class="sort-select" onchange="updateSort(this.value)">
                    <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="price_low" <?php echo $sort == 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_high" <?php echo $sort == 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                </select>
            </div>
            
            <?php if ($listings->num_rows > 0): ?>
                <div class="grid">
                    <?php while($item = $listings->fetch_assoc()): ?>
                        <div class="card" onclick="location.href='product.php?id=<?php echo $item['id']; ?>'">
                            <div class="card-image">
                                <?php
                                $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
                                echo $icons[$item['type']];
                                ?>
                            </div>
                            <div class="card-content">
                                <span class="card-type"><?php echo ucfirst($item['type']); ?></span>
                                <h3 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h3>
                                <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                                <div class="card-seller">by <?php echo htmlspecialchars($item['seller_name']); ?></div>
                                <?php if ($item['location']): ?>
                                    <div class="card-seller"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($item['location']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
                
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-search" style="font-size: 48px; color: #ccc; margin-bottom: 16px; display: block;"></i>
                    <h3>No listings found</h3>
                    <p>Try adjusting your search or filter criteria</p>
                    <a href="post_listing.php" style="display: inline-block; margin-top: 16px; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 8px;">Post a Listing</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function updateSort(value) {
            const url = new URL(window.location.href);
            url.searchParams.set('sort', value);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>