<?php
// user/product.php - Modern Redesigned Product Page

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$conn = getDbConnection();
$listing_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Get listing details
$listing = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.id as seller_id, u.email as seller_email, u.is_verified as seller_verified,
           c.name as category_name
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    LEFT JOIN categories c ON l.category_id = c.id
    WHERE l.id = $listing_id AND l.status = 'active' AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$listing) {
    header('Location: browse.php');
    exit;
}

// Increment view count
$conn->query("UPDATE listings SET views = views + 1 WHERE id = $listing_id");

// Check if user is the seller
$is_seller = ($listing['seller_id'] == $user_id);

// Calculate payment amounts
$depositPercent = $listing['admin_deposit_percent'] ?? getSetting("deposit_percent_{$listing['type']}", 30);
$commissionPercent = $listing['admin_commission_percent'] ?? getSetting("commission_percent_{$listing['type']}", 15);
$depositAmount = $listing['price'] * ($depositPercent / 100);
$commissionAmount = $listing['price'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;
$remainingAmount = $listing['price'] - $depositAmount;

$error = '';

// Handle rental initiation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rent_now']) && !$is_seller) {
    $buyer_id = $user_id;
    
    // Check if transaction already exists
    $existing = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id AND buyer_id = $buyer_id");
    if ($existing->num_rows > 0) {
        $txn = $existing->fetch_assoc();
        header("Location: pay_rent.php?transaction_id={$txn['id']}");
        exit;
    }
    
    // Create transaction
    $stmt = $conn->prepare("
        INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW())
    ");
    $stmt->bind_param("iiiiddd", $listing_id, $buyer_id, $listing['seller_id'], $listing['price'], $depositAmount, $commissionAmount, $remainingAmount);
    
    if ($stmt->execute()) {
        $transaction_id = $conn->insert_id;
        header("Location: pay_rent.php?transaction_id=$transaction_id");
        exit;
    } else {
        $error = "Failed to process request. Please try again.";
    }
}

// Get gallery images
$cover_image = $listing['cover_image'] && file_exists('../uploads/listings/' . $listing['cover_image']) 
    ? '/broker_system/uploads/listings/' . $listing['cover_image'] 
    : '';
$gallery_images = $listing['gallery_images'] ? json_decode($listing['gallery_images'], true) : [];
$gallery_paths = [];
foreach ($gallery_images as $img) {
    if (file_exists('../uploads/listings/' . $img)) {
        $gallery_paths[] = '/broker_system/uploads/listings/' . $img;
    }
}

// Get additional details
$additional = $listing['additional_details'] ? json_decode($listing['additional_details'], true) : [];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($listing['title']); ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        /* Header */
        .header {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
        }
        
        .back-btn {
            background: var(--light);
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            color: var(--gray);
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Main Container */
        .product-container {
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 32px;
        }
        
        /* Main Content */
        .main-content {
            background: white;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        /* Image Gallery */
        .image-gallery {
            position: relative;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
        
        .main-image {
            width: 100%;
            height: 450px;
            object-fit: cover;
            display: block;
        }
        
        .thumbnail-gallery {
            display: flex;
            gap: 12px;
            padding: 16px;
            background: white;
            overflow-x: auto;
        }
        
        .thumbnail-gallery::-webkit-scrollbar {
            height: 4px;
        }
        
        .thumbnail-gallery::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 10px;
        }
        
        .thumbnail-gallery::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }
        
        .thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 12px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .thumbnail:hover, .thumbnail.active {
            border-color: var(--primary);
            transform: scale(1.05);
        }
        
        .type-badge {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 30px;
            color: white;
            font-size: 13px;
            font-weight: 500;
            z-index: 10;
        }
        
        /* Product Info */
        .product-info {
            padding: 28px;
        }
        
        .title {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 12px;
        }
        
        .price {
            font-size: 36px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 16px;
        }
        
        .price small {
            font-size: 14px;
            font-weight: normal;
            color: var(--gray);
        }
        
        /* Seller Card */
        .seller-card {
            background: var(--light);
            border-radius: 20px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .seller-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .seller-details h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .seller-details p {
            font-size: 12px;
            color: var(--gray);
        }
        
        .verified-badge {
            color: var(--success);
            margin-left: 6px;
        }
        
        /* Details Grid */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 20px 0;
            padding: 20px;
            background: var(--light);
            border-radius: 20px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .detail-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--primary);
        }
        
        .detail-info label {
            font-size: 11px;
            color: var(--gray);
            display: block;
        }
        
        .detail-info span {
            font-size: 14px;
            font-weight: 600;
            color: var(--dark);
        }
        
        /* Description */
        .description {
            margin-top: 24px;
        }
        
        .description h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .description p {
            line-height: 1.7;
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Sidebar */
        .sidebar {
            background: white;
            border-radius: 28px;
            padding: 28px;
            position: sticky;
            top: 20px;
            height: fit-content;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .sidebar-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .payment-breakdown {
            background: var(--light);
            border-radius: 20px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .breakdown-item:last-child {
            border-bottom: none;
        }
        
        .breakdown-item.total {
            font-weight: 700;
            font-size: 18px;
            color: var(--primary);
            border-top: 2px solid var(--border);
            margin-top: 8px;
            padding-top: 16px;
        }
        
        .btn-purchase {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-purchase:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102,126,234,0.4);
        }
        
        .btn-purchase:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .security-badge {
            background: #e0e7ff;
            border-radius: 16px;
            padding: 16px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: var(--primary);
        }
        
        .share-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }
        
        .share-btn {
            flex: 1;
            padding: 10px;
            background: var(--light);
            border: none;
            border-radius: 40px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 13px;
            color: var(--gray);
        }
        
        .share-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-error {
            background: #fee2e2;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-info {
            background: #dbeafe;
            color: var(--primary);
            border-left: 4px solid var(--primary);
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 968px) {
            .product-container {
                grid-template-columns: 1fr;
            }
            .main-image {
                height: 350px;
            }
            .sidebar {
                position: static;
            }
        }
        
        @media (max-width: 640px) {
            .product-container {
                padding: 0 16px;
                margin: 20px auto;
            }
            .title {
                font-size: 22px;
            }
            .price {
                font-size: 28px;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .product-info {
                padding: 20px;
            }
            .thumbnail {
                width: 60px;
                height: 60px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">
                <i class="fas fa-store"></i> Ethio Brokerplace
            </a>
            <a href="browse.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Browse
            </a>
        </div>
    </header>
    
    <div class="product-container">
        <!-- Main Content -->
        <div class="main-content">
            <div class="image-gallery">
                <span class="type-badge">
                    <?php 
                    if ($listing['type'] == 'rental') echo '🏠 For Rent';
                    elseif ($listing['type'] == 'product') echo '🚗 For Sale';
                    else echo '💼 Job Opportunity';
                    ?>
                </span>
                <?php if ($cover_image): ?>
                    <img src="<?php echo $cover_image; ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>" class="main-image" id="mainImage">
                <?php else: ?>
                    <div class="main-image" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--primary), var(--secondary));">
                        <i class="fas fa-image" style="font-size: 80px; color: rgba(255,255,255,0.5);"></i>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($gallery_paths) || $cover_image): ?>
                <div class="thumbnail-gallery">
                    <?php if ($cover_image): ?>
                        <img src="<?php echo $cover_image; ?>" class="thumbnail active" onclick="changeImage(this.src, this)" alt="Main">
                    <?php endif; ?>
                    <?php foreach ($gallery_paths as $index => $img): ?>
                        <img src="<?php echo $img; ?>" class="thumbnail" onclick="changeImage(this.src, this)" alt="Gallery <?php echo $index + 1; ?>">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="product-info">
                <h1 class="title"><?php echo htmlspecialchars($listing['title']); ?></h1>
                <div class="price">
                    <?php echo formatMoney($listing['price']); ?>
                    <?php if ($listing['type'] == 'rental'): ?>
                        <small>/month</small>
                    <?php elseif ($listing['type'] == 'job'): ?>
                        <small>/month</small>
                    <?php endif; ?>
                </div>
                
                <div class="seller-card">
                    <div class="seller-avatar">
                        <?php echo strtoupper(substr($listing['seller_name'], 0, 1)); ?>
                    </div>
                    <div class="seller-details">
                        <h4>
                            <?php echo htmlspecialchars($listing['seller_name']); ?>
                            <?php if ($listing['seller_verified']): ?>
                                <i class="fas fa-check-circle verified-badge" title="Verified Seller"></i>
                            <?php endif; ?>
                        </h4>
                        <p><i class="fas fa-store"></i> Member since <?php echo date('Y', strtotime($listing['created_at'] ?? 'now')); ?></p>
                    </div>
                    <div style="margin-left: auto;">
                        <a href="chat.php?user=<?php echo $listing['seller_id']; ?>" class="back-btn" style="background: var(--primary); color: white;">
                            <i class="fas fa-comment"></i> Contact
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($additional)): ?>
                <div class="details-grid">
                    <?php if ($listing['type'] == 'rental'): ?>
                        <?php if (!empty($additional['bedrooms'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-bed"></i></div>
                            <div class="detail-info">
                                <label>Bedrooms</label>
                                <span><?php echo $additional['bedrooms']; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['bathrooms'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-bath"></i></div>
                            <div class="detail-info">
                                <label>Bathrooms</label>
                                <span><?php echo $additional['bathrooms']; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['area'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-arrows-alt"></i></div>
                            <div class="detail-info">
                                <label>Area</label>
                                <span><?php echo $additional['area']; ?> sqm</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($listing['type'] == 'product'): ?>
                        <?php if (!empty($additional['year'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-calendar"></i></div>
                            <div class="detail-info">
                                <label>Year</label>
                                <span><?php echo $additional['year']; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['mileage'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-tachometer-alt"></i></div>
                            <div class="detail-info">
                                <label>Mileage</label>
                                <span><?php echo number_format($additional['mileage']); ?> km</span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['fuel_type'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-gas-pump"></i></div>
                            <div class="detail-info">
                                <label>Fuel Type</label>
                                <span><?php echo $additional['fuel_type']; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['transmission'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-cogs"></i></div>
                            <div class="detail-info">
                                <label>Transmission</label>
                                <span><?php echo $additional['transmission']; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($listing['type'] == 'job'): ?>
                        <?php if (!empty($additional['employment_type'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-clock"></i></div>
                            <div class="detail-info">
                                <label>Employment Type</label>
                                <span><?php echo $additional['employment_type']; ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($listing['location']): ?>
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="detail-info">
                            <label>Location</label>
                            <span><?php echo htmlspecialchars($listing['location']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-eye"></i></div>
                        <div class="detail-info">
                            <label>Views</label>
                            <span><?php echo number_format($listing['views']); ?></span>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="detail-info">
                            <label>Posted</label>
                            <span><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="description">
                    <h3><i class="fas fa-align-left"></i> Description</h3>
                    <p><?php echo nl2br(htmlspecialchars($listing['description'])); ?></p>
                </div>
                
                <?php if ($listing['type'] == 'job' && !empty($additional['requirements'])): ?>
                <div class="description" style="margin-top: 20px;">
                    <h3><i class="fas fa-clipboard-list"></i> Requirements</h3>
                    <p><?php echo nl2br(htmlspecialchars($additional['requirements'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-title">
                <i class="fas fa-shopping-cart"></i> Booking Summary
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="payment-breakdown">
                <div class="breakdown-item">
                    <span><?php echo ($listing['type'] == 'rental') ? 'Monthly Rent' : (($listing['type'] == 'job') ? 'Monthly Salary' : 'Total Price'); ?></span>
                    <span><?php echo formatMoney($listing['price']); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <div class="breakdown-item total">
                    <span>Total to Pay Now</span>
                    <span><?php echo formatMoney($totalPayment); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Remaining (pay at move-in)</span>
                    <span><?php echo formatMoney($remainingAmount); ?></span>
                </div>
            </div>
            
            <?php if ($is_seller): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>This is your listing. You cannot rent your own property.</div>
                </div>
                <a href="listings.php" class="btn-purchase" style="background: var(--gray); text-decoration: none;">
                    <i class="fas fa-box"></i> Manage Your Listings
                </a>
            <?php else: ?>
                <form method="POST" id="rentForm">
                    <input type="hidden" name="rent_now" value="1">
                    <button type="submit" class="btn-purchase" id="rentBtn">
                        <i class="fas fa-hand-holding-usd"></i> Rent Now - Pay Deposit
                    </button>
                </form>
                <p style="font-size: 11px; color: var(--gray); text-align: center; margin-top: 12px;">
                    <i class="fas fa-shield-alt"></i> Your deposit is held securely in escrow.<br>
                    Full refund if the property is not as described.
                </p>
            <?php endif; ?>
            
            <div class="security-badge">
                <i class="fas fa-lock" style="font-size: 24px;"></i>
                <div>
                    <strong>Secure Escrow Protection</strong><br>
                    <small>Your payment is protected until you confirm satisfaction</small>
                </div>
            </div>
            
            <div class="share-buttons">
                <button class="share-btn" onclick="shareProduct('facebook')">
                    <i class="fab fa-facebook-f"></i> Share
                </button>
                <button class="share-btn" onclick="shareProduct('twitter')">
                    <i class="fab fa-twitter"></i> Tweet
                </button>
                <button class="share-btn" onclick="shareProduct('whatsapp')">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </button>
            </div>
        </div>
    </div>
    
    <script>
        function changeImage(src, element) {
            document.getElementById('mainImage').src = src;
            document.querySelectorAll('.thumbnail').forEach(thumb => {
                thumb.classList.remove('active');
            });
            element.classList.add('active');
        }
        
        const rentForm = document.getElementById('rentForm');
        const rentBtn = document.getElementById('rentBtn');
        
        if (rentForm) {
            rentForm.addEventListener('submit', function(e) {
                rentBtn.disabled = true;
                rentBtn.innerHTML = '<div class="loading"></div> Processing...';
            });
        }
        
        function shareProduct(platform) {
            const url = encodeURIComponent(window.location.href);
            const text = encodeURIComponent('Check out this property on Ethio Brokerplace:');
            
            let shareUrl = '';
            switch(platform) {
                case 'facebook':
                    shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${url}`;
                    break;
                case 'twitter':
                    shareUrl = `https://twitter.com/intent/tweet?text=${text}&url=${url}`;
                    break;
                case 'whatsapp':
                    shareUrl = `https://wa.me/?text=${text}%20${url}`;
                    break;
            }
            
            if (shareUrl) {
                window.open(shareUrl, '_blank', 'width=600,height=400');
            }
        }
        
        document.querySelectorAll('img').forEach(img => {
            img.onerror = function() {
                this.style.display = 'none';
            };
        });
    </script>
</body>
</html>