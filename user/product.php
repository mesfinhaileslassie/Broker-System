<?php
// user/product.php - Complete Product Page with Rental Booking

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/seller_listing_payment.php';

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

// Handle product purchase (for non-rental items)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase']) && !$is_seller && $listing['type'] != 'rental') {
    $buyer_id = $user_id;
    
    $existing = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id AND buyer_id = $buyer_id");
    if ($existing->num_rows > 0) {
        $txn = $existing->fetch_assoc();
        header("Location: transaction.php?id={$txn['id']}");
        exit;
    }
    
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
        $error = "Failed to create transaction. Please try again.";
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

$additional = $listing['additional_details'] ? json_decode($listing['additional_details'], true) : [];

$seller_payment = null;
if ($is_seller) {
    $seller_payment = getSellerListingPaymentInfo($conn, $listing_id, $user_id);
}

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
        
        .product-container {
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 32px;
        }
        
        .main-content {
            background: white;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
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
        
        .alert {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
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
                    <img src="<?php echo $cover_image; ?>" class="main-image" id="mainImage">
                <?php else: ?>
                    <div class="main-image" style="display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-image" style="font-size: 80px; color: rgba(255,255,255,0.5);"></i>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($gallery_paths) || $cover_image): ?>
                <div class="thumbnail-gallery">
                    <?php if ($cover_image): ?>
                        <img src="<?php echo $cover_image; ?>" class="thumbnail active" onclick="changeImage(this.src, this)">
                    <?php endif; ?>
                    <?php foreach ($gallery_paths as $index => $img): ?>
                        <img src="<?php echo $img; ?>" class="thumbnail" onclick="changeImage(this.src, this)">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="product-info">
                <h1 class="title"><?php echo htmlspecialchars($listing['title']); ?></h1>
                <div class="price">
                    <?php echo formatMoney($listing['price']); ?>
                    <?php if ($listing['type'] == 'rental'): ?>
                        <small>/night</small>
                    <?php elseif ($listing['type'] == 'job'): ?>
                        <small>/month</small>
                    <?php endif; ?>
                </div>
                
                <div class="seller-card">
                    <div class="seller-avatar"><?php echo strtoupper(substr($listing['seller_name'], 0, 1)); ?></div>
                    <div class="seller-details">
                        <h4><?php echo htmlspecialchars($listing['seller_name']); ?></h4>
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
                            <div class="detail-info"><label>Bedrooms</label><span><?php echo $additional['bedrooms']; ?></span></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['bathrooms'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-bath"></i></div>
                            <div class="detail-info"><label>Bathrooms</label><span><?php echo $additional['bathrooms']; ?></span></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['area'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-arrows-alt"></i></div>
                            <div class="detail-info"><label>Area</label><span><?php echo $additional['area']; ?> sqm</span></div>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($listing['type'] == 'product'): ?>
                        <?php if (!empty($additional['year'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-calendar"></i></div>
                            <div class="detail-info"><label>Year</label><span><?php echo $additional['year']; ?></span></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($additional['mileage'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-tachometer-alt"></i></div>
                            <div class="detail-info"><label>Mileage</label><span><?php echo number_format($additional['mileage']); ?> km</span></div>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($listing['type'] == 'job'): ?>
                        <?php if (!empty($additional['employment_type'])): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-clock"></i></div>
                            <div class="detail-info"><label>Employment</label><span><?php echo $additional['employment_type']; ?></span></div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($listing['location']): ?>
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="detail-info"><label>Location</label><span><?php echo htmlspecialchars($listing['location']); ?></span></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-eye"></i></div>
                        <div class="detail-info"><label>Views</label><span><?php echo number_format($listing['views']); ?></span></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="description">
                    <h3><i class="fas fa-align-left"></i> Description</h3>
                    <p><?php echo nl2br(htmlspecialchars($listing['description'])); ?></p>
                </div>
            </div>
        </div>
        
        <div class="sidebar">
            <div class="sidebar-title">
                <i class="fas fa-tag"></i> Pricing Summary
            </div>
            
            <div class="payment-breakdown">
                <div class="breakdown-item">
                    <span><?php echo ($listing['type'] == 'rental') ? 'Price per night' : (($listing['type'] == 'job') ? 'Monthly Salary' : 'Total Price'); ?></span>
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
                <?php if ($listing['type'] == 'rental'): ?>
                <div class="breakdown-item">
                    <span>Remaining (pay at check-in)</span>
                    <span><?php echo formatMoney($remainingAmount); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($is_seller): ?>
                <?php if ($seller_payment && $seller_payment['has_deposit_payment']): ?>
                <div class="payment-breakdown" style="margin-bottom: 16px; border: 1px solid #bbf7d0; background: #f0fdf4;">
                    <div class="breakdown-item">
                        <span>Total Price</span>
                        <span><?php echo formatMoney($seller_payment['total_price']); ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span>Deposit Paid</span>
                        <span><?php echo formatMoney($seller_payment['deposit_paid']); ?></span>
                    </div>
                    <div class="breakdown-item total">
                        <span>Remaining Balance</span>
                        <span><?php echo formatMoney($seller_payment['remaining_balance']); ?></span>
                    </div>
                    <?php if ($seller_payment['payment_status'] === 'fully_paid'): ?>
                        <p style="text-align:center;color:#059669;font-weight:600;margin-top:8px;">
                            <i class="fas fa-check-circle"></i> Fully Paid
                        </p>
                    <?php elseif ($seller_payment['can_pay_remaining']): ?>
                        <button type="button" class="btn-purchase pay-remaining-btn" style="margin-top:12px;border:none;width:100%;cursor:pointer;background:#10b981;" data-listing-id="<?php echo $listing_id; ?>">
                            <i class="fas fa-wallet"></i> Pay Remaining Balance
                        </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>This is your <?php echo $listing['type']; ?>. View bookings in "My Renters".</div>
                </div>
                <?php if ($listing['type'] == 'rental'): ?>
                    <a href="owner_bookings.php" class="btn-purchase" style="background: var(--primary); text-decoration: none;">
                        <i class="fas fa-users"></i> View My Renters
                    </a>
                <?php else: ?>
                    <a href="listings.php" class="btn-purchase" style="background: var(--gray); text-decoration: none;">
                        <i class="fas fa-box"></i> Manage My Listings
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($listing['type'] == 'rental'): ?>
                    <a href="rental_booking.php?id=<?php echo $listing['id']; ?>" class="btn-purchase">
                        <i class="fas fa-calendar-check"></i> Book Now
                    </a>
                    <p style="font-size: 11px; color: var(--gray); text-align: center; margin-top: 12px;">
                        <i class="fas fa-shield-alt"></i> Pay deposit to secure your booking
                    </p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="purchase" value="1">
                        <button type="submit" class="btn-purchase">
                            <i class="fas fa-shopping-cart"></i> Purchase Now
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="security-badge">
                <i class="fas fa-lock" style="font-size: 24px;"></i>
                <div>
                    <strong>Secure Escrow Protection</strong><br>
                    <small>Your payment is protected until you confirm satisfaction</small>
                </div>
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
        
        document.querySelectorAll('img').forEach(img => {
            img.onerror = function() {
                this.style.display = 'none';
            };
        });

        document.querySelectorAll('.pay-remaining-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const listingId = this.dataset.listingId;
                if (!confirm('Are you sure you want to pay the remaining balance?')) return;
                const original = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                try {
                    const res = await fetch('/broker_system/user/api/pay_remaining.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ listing_id: parseInt(listingId, 10), action: 'initiate' })
                    });
                    const data = await res.json();
                    if (data.success && data.pay_url) {
                        window.location.href = data.pay_url;
                    } else {
                        alert(data.error || 'Could not start payment');
                        this.disabled = false;
                        this.innerHTML = original;
                    }
                } catch (e) {
                    alert('Network error');
                    this.disabled = false;
                    this.innerHTML = original;
                }
            });
        });
    </script>
</body>
</html>