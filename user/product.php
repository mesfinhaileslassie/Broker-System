<?php
// user/product.php - Complete Product Page with Availability Check
// REDESIGNED with compact card sizes

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/seller_listing_payment.php';
require_once '../includes/AvailabilityManager.php';

requireLogin();

$page_title = htmlspecialchars($listing['title'] ?? 'Product Details');
ob_start();

$conn = getDbConnection();
$listing_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$availabilityManager = new AvailabilityManager($conn);

// Check what columns exist in listings table
$columns_result = $conn->query("SHOW COLUMNS FROM listings");
$existing_listing_columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $existing_listing_columns[] = $col['Field'];
}

// Build SELECT query with only existing columns
$select_fields = [
    'l.*', 
    'u.full_name as seller_name', 
    'u.id as seller_id', 
    'u.email as seller_email', 
    'u.is_verified as seller_verified',
    'c.name as category_name'
];

// Add availability_status if it exists
if (in_array('availability_status', $existing_listing_columns)) {
    $select_fields[] = 'l.availability_status';
} else {
    $select_fields[] = "'available' as availability_status";
}

// Add sold_to_user_id if it exists
if (in_array('sold_to_user_id', $existing_listing_columns)) {
    $select_fields[] = 'l.sold_to_user_id';
} else {
    $select_fields[] = 'NULL as sold_to_user_id';
}

// Add sold_at if it exists
if (in_array('sold_at', $existing_listing_columns)) {
    $select_fields[] = 'l.sold_at';
} else {
    $select_fields[] = 'NULL as sold_at';
}

// Get listing details
$sql = "SELECT " . implode(", ", $select_fields) . " 
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        LEFT JOIN categories c ON l.category_id = c.id
        WHERE l.id = $listing_id AND l.status = 'active' AND l.approval_status = 'approved'";

$listing = $conn->query($sql)->fetch_assoc();

if (!$listing) {
    header('Location: browse.php');
    exit;
}

// Set page title
$page_title = htmlspecialchars($listing['title']);

// Determine availability
$availability_status = $listing['availability_status'] ?? 'available';
$sold_to_user_id = isset($listing['sold_to_user_id']) ? intval($listing['sold_to_user_id']) : null;

$is_available_for_booking = ($availability_status === 'available');
$is_reserved_by_me = ($sold_to_user_id == $user_id && $availability_status === 'reserved');
$unavailable_reason = '';

if (!$is_available_for_booking && !$is_reserved_by_me) {
    switch ($availability_status) {
        case 'reserved': $unavailable_reason = 'This item is currently reserved. Please check back later.'; break;
        case 'sold': $unavailable_reason = 'This item has been sold.'; break;
        case 'rented': $unavailable_reason = 'This property is currently rented.'; break;
        default: $unavailable_reason = 'This item is not available.';
    }
}

// Increment view count
$conn->query("UPDATE listings SET views = views + 1 WHERE id = $listing_id");

$is_seller = ($listing['seller_id'] == $user_id);

// Calculate payment amounts
$depositPercent = $listing['admin_deposit_percent'] ?? getSetting("deposit_percent_{$listing['type']}", 30);
$commissionPercent = $listing['admin_commission_percent'] ?? getSetting("commission_percent_{$listing['type']}", 15);
$depositAmount = $listing['price'] * ($depositPercent / 100);
$commissionAmount = $listing['price'] * ($commissionPercent / 100);
$totalPayment = $depositAmount + $commissionAmount;
$remainingAmount = $listing['price'] - $depositAmount;

$error = '';

function getExistingTransactionId($conn, $listing_id, $user_id) {
    $result = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id AND buyer_id = $user_id ORDER BY id DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['id'];
    }
    return null;
}

// Handle product purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase']) && !$is_seller && $listing['type'] != 'rental') {
    if (!$is_available_for_booking && !$is_reserved_by_me) {
        $error = "This item is no longer available for purchase.";
    } else {
        $buyer_id = $user_id;
        $existing = $conn->query("SELECT id FROM transactions WHERE listing_id = $listing_id AND buyer_id = $buyer_id");
        if ($existing->num_rows > 0) {
            $txn = $existing->fetch_assoc();
            header("Location: transaction.php?id={$txn['id']}");
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW())");
        $stmt->bind_param("iiiiddd", $listing_id, $buyer_id, $listing['seller_id'], $listing['price'], $depositAmount, $commissionAmount, $remainingAmount);
        
        if ($stmt->execute()) {
            $transaction_id = $conn->insert_id;
            header("Location: pay_rent.php?transaction_id=$transaction_id");
            exit;
        } else {
            $error = "Failed to create transaction. Please try again.";
        }
    }
}

// Get gallery images
$cover_image = $listing['cover_image'] && file_exists('../uploads/listings/' . $listing['cover_image']) 
    ? '/broker_system/uploads/listings/' . $listing['cover_image'] : '';
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
    
    /* Product Container */
    .product-container {
        max-width: 1400px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1fr 360px;
        gap: 24px;
    }
    
    /* Image Gallery */
    .image-gallery {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        border: 1px solid var(--border);
    }
    
    .main-image-container {
        position: relative;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        cursor: pointer;
    }
    
    .main-image {
        width: 100%;
        height: 380px;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    
    .main-image:hover {
        transform: scale(1.02);
    }
    
    .badge-container {
        position: absolute;
        top: 16px;
        left: 16px;
        right: 16px;
        display: flex;
        justify-content: space-between;
        z-index: 10;
    }
    
    .type-badge {
        background: rgba(0,0,0,0.7);
        backdrop-filter: blur(8px);
        padding: 6px 14px;
        border-radius: 30px;
        color: white;
        font-size: 12px;
        font-weight: 600;
    }
    
    .availability-badge {
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 12px;
        font-weight: 600;
        backdrop-filter: blur(8px);
    }
    
    .availability-badge.available { background: #10b981; color: white; }
    .availability-badge.reserved { background: #f59e0b; color: white; }
    .availability-badge.sold { background: #ef4444; color: white; }
    
    .thumbnail-gallery {
        display: flex;
        gap: 10px;
        padding: 12px;
        background: white;
        overflow-x: auto;
    }
    
    .thumbnail {
        width: 70px;
        height: 70px;
        object-fit: cover;
        border-radius: 12px;
        cursor: pointer;
        border: 2px solid transparent;
        transition: all 0.2s;
    }
    
    .thumbnail:hover, .thumbnail.active {
        border-color: var(--primary);
        transform: translateY(-2px);
    }
    
    /* Product Info */
    .product-info {
        padding: 20px;
    }
    
    .title {
        font-size: 22px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }
    
    .price {
        font-size: 28px;
        font-weight: 800;
        color: var(--primary);
        margin-bottom: 16px;
    }
    
    .price small {
        font-size: 13px;
        font-weight: normal;
        color: var(--gray);
    }
    
    /* Seller Card - Compact */
    .seller-card {
        background: var(--light);
        border-radius: 16px;
        padding: 14px;
        margin: 16px 0;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        border: 1px solid var(--border);
    }
    
    .seller-avatar {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: white;
    }
    
    .seller-details h4 {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 2px;
    }
    
    .seller-details p {
        font-size: 11px;
        color: var(--gray);
    }
    
    .contact-btn {
        margin-left: auto;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        padding: 8px 16px;
        border-radius: 30px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.2s;
    }
    
    .contact-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.3);
    }
    
    /* Details Grid - Compact */
    .details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin: 16px 0;
    }
    
    .detail-card {
        background: var(--light);
        border-radius: 12px;
        padding: 10px 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid var(--border);
        transition: all 0.2s;
    }
    
    .detail-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    
    .detail-icon {
        width: 36px;
        height: 36px;
        background: white;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        color: var(--primary);
    }
    
    .detail-info label {
        font-size: 9px;
        color: var(--gray);
        display: block;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    
    .detail-info span {
        font-size: 13px;
        font-weight: 700;
        color: var(--dark);
    }
    
    /* Description - Compact */
    .description {
        margin-top: 16px;
        padding-top: 12px;
        border-top: 1px solid var(--border);
    }
    
    .description h3 {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--dark);
    }
    
    .description p {
        line-height: 1.5;
        color: var(--gray);
        font-size: 13px;
        max-height: 80px;
        overflow-y: auto;
    }
    
    /* Sidebar - Compact */
    .sidebar-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        position: sticky;
        top: 20px;
        height: fit-content;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        border: 1px solid var(--border);
    }
    
    .sidebar-title {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--dark);
    }
    
    /* Payment Breakdown - Compact */
    .payment-breakdown {
        background: var(--light);
        border-radius: 16px;
        padding: 14px;
        margin: 16px 0;
    }
    
    .breakdown-item {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid var(--border);
        font-size: 13px;
    }
    
    .breakdown-item:last-child {
        border-bottom: none;
    }
    
    .breakdown-item.total {
        font-weight: 700;
        font-size: 15px;
        color: var(--primary);
        border-top: 2px solid var(--border);
        margin-top: 6px;
        padding-top: 10px;
    }
    
    /* Buttons - Compact */
    .btn-purchase {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border: none;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-decoration: none;
    }
    
    .btn-purchase:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(102,126,234,0.4);
    }
    
    .btn-purchase:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    /* Security Badge - Compact */
    .security-badge {
        background: #e0e7ff;
        border-radius: 12px;
        padding: 12px;
        margin-top: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 11px;
        color: var(--primary);
    }
    
    .security-badge i {
        font-size: 20px;
    }
    
    /* Alerts - Compact */
    .alert {
        padding: 10px 14px;
        border-radius: 12px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 12px;
    }
    
    .alert-warning {
        background: #fed7aa;
        color: #9a3412;
        border-left: 3px solid #f59e0b;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 3px solid #dc2626;
    }
    
    .alert-info {
        background: #dbeafe;
        color: #1e40af;
        border-left: 3px solid #3b82f6;
    }
    
    /* Responsive */
    @media (max-width: 968px) {
        .product-container {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .main-image {
            height: 320px;
        }
        .sidebar-card {
            position: static;
        }
        .title {
            font-size: 20px;
        }
        .price {
            font-size: 24px;
        }
    }
    
    @media (max-width: 640px) {
        .product-container {
            gap: 16px;
        }
        .title {
            font-size: 18px;
        }
        .price {
            font-size: 22px;
        }
        .product-info {
            padding: 16px;
        }
        .thumbnail {
            width: 55px;
            height: 55px;
        }
        .details-grid {
            grid-template-columns: 1fr;
        }
        .sidebar-card {
            padding: 16px;
        }
        .main-image {
            height: 260px;
        }
    }
</style>

<div class="product-container">
    <!-- Left Column - Image Gallery -->
    <div class="image-gallery">
        <div class="main-image-container">
            <div class="badge-container">
                <span class="type-badge">
                    <?php 
                    if ($listing['type'] == 'rental') echo '🏠 For Rent';
                    elseif ($listing['type'] == 'product') echo '🚗 For Sale';
                    else echo '💼 Job';
                    ?>
                </span>
                <span class="availability-badge <?php echo $availability_status; ?>">
                    <?php 
                    if ($availability_status == 'available') echo '✓ Available';
                    elseif ($availability_status == 'reserved') {
                        echo $is_reserved_by_me ? '⏳ Reserved' : '⏳ Reserved';
                    }
                    elseif ($availability_status == 'sold') echo '🔒 Sold';
                    else echo '⚡ Unavailable';
                    ?>
                </span>
            </div>
            <?php if ($cover_image): ?>
                <img src="<?php echo $cover_image; ?>" class="main-image" id="mainImage">
            <?php else: ?>
                <div class="main-image" style="display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-image" style="font-size: 60px; color: rgba(255,255,255,0.5);"></i>
                </div>
            <?php endif; ?>
        </div>
        
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

    <!-- Right Column - Product Info -->
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

        <!-- Seller Information -->
        <div class="seller-card">
            <div class="seller-avatar"><?php echo strtoupper(substr($listing['seller_name'], 0, 1)); ?></div>
            <div class="seller-details">
                <h4><?php echo htmlspecialchars($listing['seller_name']); ?></h4>
                <p><i class="fas fa-store"></i> Since <?php echo date('Y', strtotime($listing['created_at'] ?? 'now')); ?></p>
            </div>
            <a href="chat.php?user=<?php echo $listing['seller_id']; ?>" class="contact-btn">
                <i class="fas fa-comment"></i> Contact
            </a>
        </div>

        <!-- Details Grid -->
        <?php if (!empty($additional)): ?>
        <div class="details-grid">
            <?php if ($listing['type'] == 'rental'): ?>
                <?php if (!empty($additional['bedrooms'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-bed"></i></div>
                    <div class="detail-info">
                        <label>Beds</label>
                        <span><?php echo $additional['bedrooms']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($additional['bathrooms'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-bath"></i></div>
                    <div class="detail-info">
                        <label>Baths</label>
                        <span><?php echo $additional['bathrooms']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($additional['area'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-arrows-alt"></i></div>
                    <div class="detail-info">
                        <label>Area</label>
                        <span><?php echo $additional['area']; ?> sqm</span>
                    </div>
                </div>
                <?php endif; ?>
            <?php elseif ($listing['type'] == 'product'): ?>
                <?php if (!empty($additional['year'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-calendar"></i></div>
                    <div class="detail-info">
                        <label>Year</label>
                        <span><?php echo $additional['year']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($additional['mileage'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="detail-info">
                        <label>Mileage</label>
                        <span><?php echo number_format($additional['mileage']); ?> km</span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($additional['fuel_type'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-gas-pump"></i></div>
                    <div class="detail-info">
                        <label>Fuel</label>
                        <span><?php echo $additional['fuel_type']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($additional['transmission'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-cogs"></i></div>
                    <div class="detail-info">
                        <label>Transmission</label>
                        <span><?php echo $additional['transmission']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
            <?php elseif ($listing['type'] == 'job'): ?>
                <?php if (!empty($additional['employment_type'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-clock"></i></div>
                    <div class="detail-info">
                        <label>Type</label>
                        <span><?php echo $additional['employment_type']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($additional['experience_level'])): ?>
                <div class="detail-card">
                    <div class="detail-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="detail-info">
                        <label>Experience</label>
                        <span><?php echo $additional['experience_level']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($listing['location']): ?>
            <div class="detail-card">
                <div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                <div class="detail-info">
                    <label>Location</label>
                    <span><?php echo htmlspecialchars(substr($listing['location'], 0, 20)); ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="detail-card">
                <div class="detail-icon"><i class="fas fa-eye"></i></div>
                <div class="detail-info">
                    <label>Views</label>
                    <span><?php echo number_format($listing['views']); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Description -->
        <div class="description">
            <h3><i class="fas fa-align-left"></i> Description</h3>
            <p><?php echo nl2br(htmlspecialchars(substr($listing['description'], 0, 200) . (strlen($listing['description']) > 200 ? '...' : ''))); ?></p>
        </div>
    </div>

    <!-- Sidebar - Pricing & Actions -->
    <div class="sidebar-card">
        <div class="sidebar-title">
            <i class="fas fa-tag"></i> Pricing Summary
        </div>

        <div class="payment-breakdown">
            <div class="breakdown-item">
                <span><?php echo ($listing['type'] == 'rental') ? 'Price/night' : (($listing['type'] == 'job') ? 'Monthly' : 'Total'); ?></span>
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
                <span>Total to Pay</span>
                <span><?php echo formatMoney($totalPayment); ?></span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($is_seller): ?>
            <?php if ($seller_payment && $seller_payment['has_deposit_payment']): ?>
            <div class="payment-breakdown" style="border: 2px solid #10b981; background: #f0fdf4;">
                <div class="breakdown-item">
                    <span>Total Price</span>
                    <span><?php echo formatMoney($seller_payment['total_price']); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Deposit Paid</span>
                    <span><?php echo formatMoney($seller_payment['deposit_paid']); ?></span>
                </div>
                <div class="breakdown-item total">
                    <span>Remaining</span>
                    <span><?php echo formatMoney($seller_payment['remaining_balance']); ?></span>
                </div>
                <?php if ($seller_payment['payment_status'] === 'fully_paid'): ?>
                    <p style="text-align:center;color:#059669;font-weight:600;margin-top:10px;font-size:12px;">
                        <i class="fas fa-check-circle"></i> Fully Paid
                    </p>
                <?php elseif ($seller_payment['can_pay_remaining']): ?>
                    <button type="button" class="btn-purchase pay-remaining-btn" style="margin-top:10px;background:#10b981;" data-listing-id="<?php echo $listing_id; ?>">
                        <i class="fas fa-wallet"></i> Pay Remaining
                    </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="alert alert-info" style="margin-bottom: 0;">
                <i class="fas fa-info-circle"></i>
                <div>Your <?php echo $listing['type']; ?> listing</div>
            </div>
            <a href="listings.php" class="btn-purchase" style="margin-top: 12px; background: #64748b;">
                <i class="fas fa-box"></i> My Listings
            </a>
        <?php elseif ($listing['type'] == 'rental'): ?>
            <?php if ($is_available_for_booking): ?>
                <a href="rental_booking.php?id=<?php echo $listing['id']; ?>" class="btn-purchase">
                    <i class="fas fa-calendar-check"></i> Book Now
                </a>
                <p style="font-size: 10px; color: #64748b; text-align: center; margin-top: 10px;">
                    <i class="fas fa-shield-alt"></i> Pay deposit to secure
                </p>
            <?php elseif ($is_reserved_by_me): ?>
                <?php $txn_id = getExistingTransactionId($conn, $listing_id, $user_id); ?>
                <a href="pay_rent.php?transaction_id=<?php echo $txn_id; ?>" class="btn-purchase">
                    <i class="fas fa-credit-card"></i> Complete Payment
                </a>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom: 0;">
                    <i class="fas fa-ban"></i>
                    <div><?php echo $unavailable_reason; ?></div>
                </div>
                <button class="btn-purchase" disabled style="margin-top: 12px;">
                    <i class="fas fa-calendar-check"></i> Not Available
                </button>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($is_available_for_booking): ?>
                <form method="POST">
                    <input type="hidden" name="purchase" value="1">
                    <button type="submit" class="btn-purchase">
                        <i class="fas fa-shopping-cart"></i> Purchase Now
                    </button>
                </form>
                <p style="font-size: 10px; color: #64748b; text-align: center; margin-top: 10px;">
                    <i class="fas fa-shield-alt"></i> Pay deposit to secure
                </p>
            <?php elseif ($is_reserved_by_me): ?>
                <?php $txn_id = getExistingTransactionId($conn, $listing_id, $user_id); ?>
                <a href="pay_rent.php?transaction_id=<?php echo $txn_id; ?>" class="btn-purchase">
                    <i class="fas fa-credit-card"></i> Complete Payment
                </a>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom: 0;">
                    <i class="fas fa-ban"></i>
                    <div><?php echo $unavailable_reason; ?></div>
                </div>
                <button class="btn-purchase" disabled style="margin-top: 12px;">
                    <i class="fas fa-shopping-cart"></i> Not Available
                </button>
            <?php endif; ?>
        <?php endif; ?>

        <div class="security-badge">
            <i class="fas fa-shield-alt"></i>
            <div>
                <strong>Escrow Protection</strong><br>
                <small>Payment protected</small>
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
            if (!confirm('Pay remaining balance?')) return;
            const original = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
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

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>