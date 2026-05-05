<?php
// user/browse.php - Browse Listings with Working Images

$page_title = 'Browse Listings';
ob_start();

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
    $where[] = "(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
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
        font-size: 32px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .browse-header p {
        color: #64748b;
        font-size: 15px;
    }
    
    .search-bar {
        background: white;
        border-radius: 60px;
        padding: 4px;
        display: flex;
        gap: 8px;
        margin-bottom: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .search-bar input {
        flex: 1;
        padding: 14px 20px;
        border: none;
        outline: none;
        font-size: 15px;
        background: transparent;
        border-radius: 60px;
    }
    
    .search-bar button {
        padding: 12px 32px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 60px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .search-bar button:hover {
        transform: scale(1.02);
    }
    
    .category-filters {
        display: flex;
        gap: 12px;
        margin-bottom: 32px;
        flex-wrap: wrap;
    }
    
    .filter-chip {
        padding: 10px 24px;
        background: white;
        border-radius: 40px;
        text-decoration: none;
        color: #334155;
        font-size: 14px;
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
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 28px;
        margin-bottom: 32px;
    }
    
    .listing-card {
        background: white;
        border-radius: 24px;
        overflow: hidden;
        transition: all 0.3s;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
    
    .listing-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.12);
    }
    
    .card-image {
        height: 200px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
        overflow: hidden;
    }
    
    .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .card-content {
        padding: 20px;
    }
    
    .card-type {
        display: inline-block;
        padding: 4px 12px;
        background: #f1f5f9;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 10px;
    }
    
    .card-title {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #0f172a;
        line-height: 1.4;
    }
    
    .card-price {
        font-size: 20px;
        font-weight: 800;
        color: #667eea;
        margin: 10px 0;
    }
    
    .card-location {
        font-size: 12px;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
    }
    
    .card-seller {
        font-size: 12px;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #f1f5f9;
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
        border-radius: 24px;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    
    @media (max-width: 768px) {
        .listings-grid {
            grid-template-columns: 1fr;
        }
        .search-bar {
            flex-direction: column;
            border-radius: 20px;
        }
        .search-bar button {
            border-radius: 20px;
            padding: 12px;
        }
        .category-filters {
            overflow-x: auto;
            flex-wrap: nowrap;
        }
    }
</style>

<div class="browse-header">
    <h1>Find Your Perfect Match</h1>
    <p>Discover houses, cars, and job opportunities</p>
</div>

<!-- Search Bar -->
<form method="GET" class="search-bar">
    <input type="text" name="search" placeholder="Search by title, description, or location..." value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit"><i class="fas fa-search"></i> Search</button>
</form>

<!-- Category Filters -->
<div class="category-filters">
    <a href="browse.php" class="filter-chip <?php echo empty($type) ? 'active' : ''; ?>">🏠 All</a>
    <a href="browse.php?type=rental" class="filter-chip <?php echo $type == 'rental' ? 'active' : ''; ?>">🏡 Houses & Properties</a>
    <a href="browse.php?type=product" class="filter-chip <?php echo $type == 'product' ? 'active' : ''; ?>">🚗 Cars & Vehicles</a>
    <a href="browse.php?type=job" class="filter-chip <?php echo $type == 'job' ? 'active' : ''; ?>">💼 Jobs</a>
</div>

<!-- Listings Grid -->
<?php if ($listings->num_rows > 0): ?>
    <div class="listings-grid">
        <?php while($item = $listings->fetch_assoc()): 
            // Build image path
            $cover_image = '';
            $has_image = false;
            
            if (!empty($item['cover_image'])) {
                $cover_image = '/broker_system/uploads/listings/' . $item['cover_image'];
                // Check if file exists
                $file_path = $_SERVER['DOCUMENT_ROOT'] . $cover_image;
                if (file_exists($file_path)) {
                    $has_image = true;
                }
            }
            
            $additional = $item['additional_details'] ? json_decode($item['additional_details'], true) : [];
        ?>
            <div class="listing-card" onclick="location.href='product.php?id=<?php echo $item['id']; ?>'">
                <div class="card-image">
                    <?php if ($has_image): ?>
                        <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                    <?php else: ?>
                        <?php if ($item['type'] == 'rental'): ?>
                            <i class="fas fa-home" style="font-size: 48px;"></i>
                        <?php elseif ($item['type'] == 'product'): ?>
                            <i class="fas fa-car" style="font-size: 48px;"></i>
                        <?php else: ?>
                            <i class="fas fa-briefcase" style="font-size: 48px;"></i>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="card-content">
                    <span class="card-type">
                        <?php if ($item['type'] == 'rental'): ?>
                            🏡 Property
                        <?php elseif ($item['type'] == 'product'): ?>
                            🚗 Car
                        <?php else: ?>
                            💼 Job
                        <?php endif; ?>
                    </span>
                    <div class="card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                    <div class="card-price"><?php echo formatMoney($item['price']); ?>
                        <?php if ($item['type'] == 'rental'): ?>
                            <span style="font-size: 12px; font-weight: normal;">/ month</span>
                        <?php elseif ($item['type'] == 'job'): ?>
                            <span style="font-size: 12px; font-weight: normal;">/ month</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Property Details -->
                    <?php if ($item['type'] == 'rental' && !empty($additional)): ?>
                        <div style="font-size: 12px; color: #64748b; margin: 8px 0;">
                            <?php if (!empty($additional['bedrooms'])): ?>
                                🛏️ <?php echo $additional['bedrooms']; ?> bed
                            <?php endif; ?>
                            <?php if (!empty($additional['bathrooms'])): ?>
                                🚿 <?php echo $additional['bathrooms']; ?> bath
                            <?php endif; ?>
                            <?php if (!empty($additional['area'])): ?>
                                📐 <?php echo $additional['area']; ?> sqm
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Car Details -->
                    <?php if ($item['type'] == 'product' && !empty($additional)): ?>
                        <div style="font-size: 12px; color: #64748b; margin: 8px 0;">
                            <?php if (!empty($additional['year'])): ?>
                                📅 <?php echo $additional['year']; ?>
                            <?php endif; ?>
                            <?php if (!empty($additional['mileage'])): ?>
                                📊 <?php echo number_format($additional['mileage']); ?> km
                            <?php endif; ?>
                            <?php if (!empty($additional['fuel_type'])): ?>
                                ⛽ <?php echo $additional['fuel_type']; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Location -->
                    <?php if (!empty($item['location'])): ?>
                        <div class="card-location">
                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($item['location']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Seller -->
                    <div class="card-seller">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($item['seller_name']); ?>
                    </div>
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