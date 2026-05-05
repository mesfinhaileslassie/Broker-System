<?php
// index.php - Landing page with images

require_once 'config/database.php';
require_once 'includes/functions.php';

$conn = getDbConnection();

// Get featured listings
$featured = $conn->query("
    SELECT l.*, u.full_name as seller_name, c.name as category_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE l.status = 'active' AND l.approval_status = 'approved' AND l.featured = 1
    ORDER BY l.created_at DESC
    LIMIT 6
");

// Get recent listings by type
$recentProducts = $conn->query("
    SELECT l.*, u.full_name as seller_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.type = 'product' AND l.status = 'active' AND l.approval_status = 'approved'
    ORDER BY l.created_at DESC
    LIMIT 6
");

$recentJobs = $conn->query("
    SELECT l.*, u.full_name as seller_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.type = 'job' AND l.status = 'active' AND l.approval_status = 'approved'
    ORDER BY l.created_at DESC
    LIMIT 6
");

$recentRentals = $conn->query("
    SELECT l.*, u.full_name as seller_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.type = 'rental' AND l.status = 'active' AND l.approval_status = 'approved'
    ORDER BY l.created_at DESC
    LIMIT 6
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ethio Brokerplace - Buy, Sell, Rent, Find Jobs</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
        }
        
        /* Header */
        .header {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-content {
            max-width: 1280px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 800;
            color: #667eea;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav {
            display: flex;
            gap: 24px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .nav a {
            text-decoration: none;
            color: #475569;
            transition: color 0.3s;
            font-weight: 500;
        }
        
        .nav a:hover {
            color: #667eea;
        }
        
        .btn-login {
            padding: 8px 24px;
            background: #667eea;
            color: white !important;
            border-radius: 30px;
        }
        
        .btn-login:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .btn-register {
            padding: 8px 24px;
            background: #10b981;
            color: white !important;
            border-radius: 30px;
        }
        
        .btn-register:hover {
            background: #059669;
            transform: translateY(-2px);
        }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 80px 24px;
            text-align: center;
        }
        
        .hero h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 16px;
        }
        
        .hero p {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 32px;
        }
        
        .search-bar {
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            background: white;
            border-radius: 60px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .search-bar input {
            flex: 1;
            padding: 16px 24px;
            border: none;
            outline: none;
            font-size: 16px;
        }
        
        .search-bar button {
            padding: 16px 32px;
            background: #ff9800;
            border: none;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .search-bar button:hover {
            background: #f57c00;
        }
        
        /* Container */
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 48px 24px;
        }
        
        .section-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 32px;
            color: #0f172a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .section-title a {
            font-size: 14px;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 28px;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .card:hover {
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
            padding: 4px 10px;
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
        
        .card-seller {
            font-size: 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        /* Categories Section */
        .categories {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 48px;
        }
        
        .category-card {
            background: white;
            padding: 32px 20px;
            text-align: center;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        .category-card:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-4px);
        }
        
        .category-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        
        .category-name {
            font-size: 16px;
            font-weight: 600;
        }
        
        /* Footer */
        .footer {
            background: #0f172a;
            color: #94a3b8;
            padding: 48px 24px;
            text-align: center;
        }
        
        .footer p {
            margin-top: 16px;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 32px;
            }
            .hero p {
                font-size: 16px;
            }
            .section-title {
                font-size: 24px;
            }
            .grid {
                grid-template-columns: 1fr;
            }
            .categories {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="index.php" class="logo">
                <span>🏪</span> Ethio Brokerplace
            </a>
            <div class="nav">
                <a href="user/browse.php">Browse</a>
                <a href="user/post_listing.php">Sell</a>
                <?php if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true): ?>
                    <a href="user/dashboard.php">Dashboard</a>
                    <a href="user/wallet.php">Wallet</a>
                    <a href="auth/logout.php" style="color: #dc2626;">Logout</a>
                <?php else: ?>
                    <a href="auth/login.php" class="btn-login">Login</a>
                    <a href="auth/register.php" class="btn-register">Register</a>
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
                <div class="category-name">Products</div>
            </div>
            <div class="category-card" onclick="location.href='user/browse.php?type=job'">
                <div class="category-icon">💼</div>
                <div class="category-name">Jobs</div>
            </div>
            <div class="category-card" onclick="location.href='user/browse.php?type=rental'">
                <div class="category-icon">🏠</div>
                <div class="category-name">Rentals</div>
            </div>
            <div class="category-card" onclick="location.href='user/post_listing.php'">
                <div class="category-icon">➕</div>
                <div class="category-name">Sell/Post</div>
            </div>
        </div>
        
        <!-- Featured Listings -->
        <?php if ($featured && $featured->num_rows > 0): ?>
        <div class="section-title">
            Featured Listings
            <a href="user/browse.php?featured=1">View All →</a>
        </div>
        <div class="grid">
            <?php while($item = $featured->fetch_assoc()): 
                $cover_image = $item['cover_image'] ? '/broker_system/uploads/listings/' . $item['cover_image'] : '';
                $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
            ?>
                <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'">
                    <div class="card-image">
                        <?php if ($cover_image && file_exists(str_replace('/broker_system/', '', $cover_image))): ?>
                            <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                        <?php else: ?>
                            <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">
                                <?php echo $icons[$item['type']]; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-content">
                        <span class="card-type"><?php echo ucfirst($item['type']); ?></span>
                        <div class="card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                        <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                        <div class="card-seller"><i class="fas fa-user"></i> <?php echo htmlspecialchars($item['seller_name']); ?></div>
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
            <?php if ($recentProducts && $recentProducts->num_rows > 0): ?>
                <?php while($item = $recentProducts->fetch_assoc()): 
                    $cover_image = $item['cover_image'] ? '/broker_system/uploads/listings/' . $item['cover_image'] : '';
                ?>
                    <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'">
                        <div class="card-image">
                            <?php if ($cover_image && file_exists(str_replace('/broker_system/', '', $cover_image))): ?>
                                <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">📦</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-content">
                            <span class="card-type">Product</span>
                            <div class="card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                            <div class="card-seller"><i class="fas fa-user"></i> <?php echo htmlspecialchars($item['seller_name']); ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="card" style="text-align: center; padding: 40px;">No products yet</div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Jobs -->
        <div class="section-title">
            Recent Jobs
            <a href="user/browse.php?type=job">View All →</a>
        </div>
        <div class="grid">
            <?php if ($recentJobs && $recentJobs->num_rows > 0): ?>
                <?php while($item = $recentJobs->fetch_assoc()): 
                    $cover_image = $item['cover_image'] ? '/broker_system/uploads/listings/' . $item['cover_image'] : '';
                ?>
                    <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'">
                        <div class="card-image">
                            <?php if ($cover_image && file_exists(str_replace('/broker_system/', '', $cover_image))): ?>
                                <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">💼</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-content">
                            <span class="card-type">Job</span>
                            <div class="card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="card-price"><?php echo formatMoney($item['price']); ?></div>
                            <div class="card-seller"><i class="fas fa-building"></i> <?php echo htmlspecialchars($item['seller_name']); ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="card" style="text-align: center; padding: 40px;">No jobs yet</div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Rentals -->
        <div class="section-title">
            Recent Rentals
            <a href="user/browse.php?type=rental">View All →</a>
        </div>
        <div class="grid">
            <?php if ($recentRentals && $recentRentals->num_rows > 0): ?>
                <?php while($item = $recentRentals->fetch_assoc()): 
                    $cover_image = $item['cover_image'] ? '/broker_system/uploads/listings/' . $item['cover_image'] : '';
                ?>
                    <div class="card" onclick="location.href='user/product.php?id=<?php echo $item['id']; ?>'">
                        <div class="card-image">
                            <?php if ($cover_image && file_exists(str_replace('/broker_system/', '', $cover_image))): ?>
                                <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 48px;">🏠</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-content">
                            <span class="card-type">Rental</span>
                            <div class="card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="card-price"><?php echo formatMoney($item['price']); ?> / month</div>
                            <div class="card-seller"><i class="fas fa-user"></i> <?php echo htmlspecialchars($item['seller_name']); ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="card" style="text-align: center; padding: 40px;">No rentals yet</div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; 2024 Ethio Brokerplace. All rights reserved.</p>
        <p style="margin-top: 8px;">Secure escrow payments | 24/7 support | Trusted marketplace</p>
    </footer>
</body>
</html>