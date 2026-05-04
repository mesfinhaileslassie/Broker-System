<?php
// index.php - Landing page

require_once 'config/database.php';
require_once 'includes/functions.php';

$conn = getDbConnection();

// Get featured listings
$featured = $conn->query("
    SELECT l.*, u.full_name as seller_name, c.name as category_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE l.status = 'active' AND l.featured = 1
    ORDER BY l.created_at DESC
    LIMIT 6
");

// Get recent listings by type
$recentProducts = $conn->query("
    SELECT l.*, u.full_name as seller_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.type = 'product' AND l.status = 'active'
    ORDER BY l.created_at DESC
    LIMIT 4
");

$recentJobs = $conn->query("
    SELECT l.*, u.full_name as seller_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.type = 'job' AND l.status = 'active'
    ORDER BY l.created_at DESC
    LIMIT 4
");

$recentRentals = $conn->query("
    SELECT l.*, u.full_name as seller_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.type = 'rental' AND l.status = 'active'
    ORDER BY l.created_at DESC
    LIMIT 4
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ethio Brokerplace - Buy, Sell, Rent, Find Jobs</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        
        /* Header */
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 1000; }
        .header-content { max-width: 1200px; margin: 0 auto; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .nav { display: flex; gap: 24px; align-items: center; }
        .nav a { text-decoration: none; color: #333; transition: color 0.3s; }
        .nav a:hover { color: #667eea; }
        .btn-login { padding: 8px 20px; background: #667eea; color: white; border-radius: 8px; text-decoration: none; }
        .btn-login:hover { background: #5a67d8; color: white; }
        
        /* Hero Section */
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 60px 24px; text-align: center; }
        .hero h1 { font-size: 48px; margin-bottom: 16px; }
        .hero p { font-size: 18px; margin-bottom: 32px; opacity: 0.9; }
        .search-bar { max-width: 600px; margin: 0 auto; display: flex; background: white; border-radius: 50px; overflow: hidden; }
        .search-bar input { flex: 1; padding: 16px 24px; border: none; outline: none; font-size: 16px; }
        .search-bar button { padding: 16px 32px; background: #ff9800; border: none; color: white; font-weight: 600; cursor: pointer; }
        
        /* Sections */
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 24px; }
        .section-title { font-size: 28px; font-weight: 600; margin-bottom: 24px; color: #333; }
        .section-title a { float: right; font-size: 14px; color: #667eea; text-decoration: none; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
        
        /* Card */
        .card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s, box-shadow 0.3s; }
        .card:hover { transform: translateY(-4px); box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .card-image { height: 180px; background: #e0e0e0; display: flex; align-items: center; justify-content: center; font-size: 48px; }
        .card-content { padding: 16px; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; color: #333; text-decoration: none; display: block; }
        .card-price { font-size: 20px; font-weight: 700; color: #667eea; margin: 8px 0; }
        .card-seller { font-size: 12px; color: #888; margin-bottom: 8px; }
        .card-type { display: inline-block; padding: 4px 8px; background: #f0f0f0; border-radius: 4px; font-size: 11px; font-weight: 600; }
        
        /* Categories */
        .categories { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 16px; margin-bottom: 40px; }
        .category-card { background: white; padding: 24px; text-align: center; border-radius: 12px; cursor: pointer; transition: all 0.3s; }
        .category-card:hover { background: #667eea; color: white; transform: translateY(-4px); }
        .category-icon { font-size: 32px; margin-bottom: 12px; }
        
        /* Footer */
        .footer { background: #1a1a2e; color: #aaa; padding: 40px 24px; text-align: center; }
        
        @media (max-width: 768px) {
            .hero h1 { font-size: 32px; }
            .grid { grid-template-columns: 1fr; }
            .categories { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="index.php" class="logo">🏪 Ethio Brokerplace</a>
            <div class="nav">
                <a href="user/browse.php">Browse</a>
                <?php if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true): ?>
                    <a href="user/dashboard.php">Dashboard</a>
                    <a href="user/wallet.php">Wallet: <?php echo formatMoney($_SESSION['user_balance'] ?? 0); ?></a>
                    <a href="auth/logout.php" style="color:#dc3545;">Logout</a>
                <?php else: ?>
                    <a href="user/post_listing.php">Sell</a>
                    <a href="auth/login.php" class="btn-login">Login</a>
                    <a href="auth/register.php" style="background:#28a745; color:white; padding:8px 20px; border-radius:8px;">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    
    <section class="hero">
        <h1>Buy, Sell, Rent, or Find Work</h1>
        <p>The trusted marketplace with secure escrow payments</p>
        <form class="search-bar" action="user/browse.php" method="GET">
            <input type="text" name="search" placeholder="Search for products, jobs, rentals...">
            <button type="submit">Search</button>
        </form>
    </section>
    
    <div class="container">
        <!-- Categories -->
        <div class="categories">
            <div class="category-card" onclick="location.href='user/browse.php?type=product'">
                <div class="category-icon">🛍️</div>
                <div>Products</div>
            </div>
            <div class="category-card" onclick="location.href='user/browse.php?type=job'">
                <div class="category-icon">💼</div>
                <div>Jobs</div>
            </div>
            <div class="category-card" onclick="location.href='user/browse.php?type=rental'">
                <div class="category-icon">🏠</div>
                <div>Rentals</div>
            </div>
            <div class="category-card" onclick="location.href='user/post_listing.php'">
                <div class="category-icon">➕</div>
                <div>Sell/Post</div>
            </div>
        </div>
        
        <!-- Featured Listings -->
        <?php if ($featured->num_rows > 0): ?>
        <div class="section-title">
            Featured Listings
            <a href="user/browse.php?featured=1">View All →</a>
        </div>
        <div class="grid">
            <?php while($item = $featured->fetch_assoc()): ?>
                <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'" style="cursor:pointer;">
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
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <!-- Recent Products -->
        <div class="section-title">
            Recent Products
            <a href="user/browse.php?type=product">View All →</a>
        </div>
        <div class="grid">
            <?php while($item = $recentProducts->fetch_assoc()): ?>
                <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'" style="cursor:pointer;">
                    <div class="card-image">📦</div>
                    <div class="card-content">
                        <span class="card-type">Product</span>
                        <h3 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h3>
                        <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        
        <!-- Recent Jobs -->
        <div class="section-title">
            Recent Jobs
            <a href="user/browse.php?type=job">View All →</a>
        </div>
        <div class="grid">
            <?php while($item = $recentJobs->fetch_assoc()): ?>
                <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'" style="cursor:pointer;">
                    <div class="card-image">💼</div>
                    <div class="card-content">
                        <span class="card-type">Job</span>
                        <h3 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h3>
                        <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        
        <!-- Recent Rentals -->
        <div class="section-title">
            Recent Rentals
            <a href="user/browse.php?type=rental">View All →</a>
        </div>
        <div class="grid">
            <?php while($item = $recentRentals->fetch_assoc()): ?>
                <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'" style="cursor:pointer;">
                    <div class="card-image">🏠</div>
                    <div class="card-content">
                        <span class="card-type">Rental</span>
                        <h3 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h3>
                        <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; 2024 Ethio Brokerplace. All rights reserved.</p>
        <p style="margin-top: 8px;">Secure escrow payments | 24/7 support | Trusted marketplace</p>
    </footer>
</body>
</html>