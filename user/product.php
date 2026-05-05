<?php
// user/product.php - View single product and initiate purchase with immediate code generation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$listing_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Get listing details
$listing = $conn->query("
    SELECT l.*, u.full_name as seller_name, u.id as seller_id, u.email as seller_email, c.name as category_name
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

// Check if user is logged in and if they are the seller
$is_seller = ($listing['seller_id'] == $user_id);
$can_purchase = !$is_seller;

// Calculate payment amounts
$depositPercent = $listing['admin_deposit_percent'] ?? getSetting("deposit_percent_{$listing['type']}", 30);
$commissionPercent = $listing['admin_commission_percent'] ?? getSetting("commission_percent_{$listing['type']}", 15);

$depositAmount = $listing['price'] * ($depositPercent / 100);
$commissionAmount = $listing['price'] * ($commissionPercent / 100);
$totalUpfront = $depositAmount + $commissionAmount;
$remainingAmount = $listing['price'] - $depositAmount;

$error = '';
$generated_code = '';
$transaction_id = 0;

// Handle purchase initiation - GENERATE CODE IMMEDIATELY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase']) && $can_purchase) {
    $buyer_id = $user_id;
    
    // Create transaction
    $stmt = $conn->prepare("
        INSERT INTO transactions (listing_id, buyer_id, seller_id, total_amount, deposit_amount, commission_amount, remaining_balance, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit')
    ");
    $stmt->bind_param("iiiiddd", $listing_id, $buyer_id, $listing['seller_id'], $listing['price'], $depositAmount, $commissionAmount, $remainingAmount);
    
    if ($stmt->execute()) {
        $transaction_id = $conn->insert_id;
        
        // Generate a unique 5-digit payment code
        do {
            $new_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $check_code = $conn->query("SELECT id FROM payment_codes WHERE code = '$new_code'");
        } while ($check_code->num_rows > 0);
        
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // Store payment code in payment_codes table
        $stmt2 = $conn->prepare("
            INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
            VALUES (?, ?, ?, ?, 'deposit_buyer', ?, 'pending')
        ");
        $stmt2->bind_param("siids", $new_code, $transaction_id, $totalUpfront, $buyer_id, $expires_at);
        $stmt2->execute();
        
        $generated_code = $new_code;
        
        // Redirect to transaction page with the code
        header("Location: transaction.php?id=$transaction_id&code=$generated_code");
        exit;
    } else {
        $error = "Failed to create transaction. Please try again.";
    }
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        
        .header { background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        
        .container { max-width: 1200px; margin: 40px auto; padding: 0 24px; display: grid; grid-template-columns: 1fr 380px; gap: 32px; }
        
        .main-content { background: white; border-radius: 24px; padding: 32px; }
        .listing-type { display: inline-block; padding: 6px 12px; background: #f1f5f9; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 16px; }
        .title { font-size: 28px; font-weight: 700; margin-bottom: 16px; color: #0f172a; }
        .price { font-size: 32px; font-weight: 700; color: #667eea; margin-bottom: 16px; }
        .seller-info { padding: 16px; background: #f8fafc; border-radius: 16px; margin-bottom: 24px; }
        .description { margin-top: 24px; }
        .description h3 { margin-bottom: 12px; font-size: 18px; }
        .description p { line-height: 1.6; color: #475569; }
        
        .sidebar { background: white; border-radius: 24px; padding: 24px; height: fit-content; position: sticky; top: 20px; }
        .payment-breakdown { background: #f8fafc; border-radius: 16px; padding: 20px; margin: 16px 0; }
        .breakdown-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
        .breakdown-item.total { font-weight: 700; font-size: 18px; border-bottom: none; margin-top: 8px; padding-top: 12px; border-top: 2px solid #e2e8f0; }
        .btn-purchase { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 16px; transition: all 0.3s; }
        .btn-purchase:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102,126,234,0.4); }
        .warning { background: #fef3c7; color: #92400e; padding: 12px; border-radius: 12px; margin-top: 16px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .error { background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .title { font-size: 24px; }
            .price { font-size: 28px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="browse.php" style="text-decoration: none; color: #64748b;"><i class="fas fa-arrow-left"></i> Back to Browse</a>
        </div>
    </header>
    
    <div class="container">
        <div class="main-content">
            <span class="listing-type">
                <?php
                $icons = ['product' => '📦', 'job' => '💼', 'rental' => '🏠'];
                echo $icons[$listing['type']] . ' ' . ucfirst($listing['type']);
                ?>
            </span>
            <h1 class="title"><?php echo htmlspecialchars($listing['title']); ?></h1>
            <div class="price"><?php echo formatMoney($listing['price']); ?></div>
            
            <div class="seller-info">
                <i class="fas fa-store"></i> Sold by: <strong><?php echo htmlspecialchars($listing['seller_name']); ?></strong><br>
                <?php if ($listing['location']): ?>
                    <i class="fas fa-map-marker-alt"></i> Location: <?php echo htmlspecialchars($listing['location']); ?>
                <?php endif; ?>
            </div>
            
            <div class="description">
                <h3>Description</h3>
                <p><?php echo nl2br(htmlspecialchars($listing['description'])); ?></p>
            </div>
        </div>
        
        <div class="sidebar">
            <h3>Purchase Summary</h3>
            <div class="payment-breakdown">
                <div class="breakdown-item">
                    <span>Item Price</span>
                    <span><?php echo formatMoney($listing['price']); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Commission (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <div class="breakdown-item total">
                    <span>You Pay Today</span>
                    <span><?php echo formatMoney($totalUpfront); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Remaining (after delivery)</span>
                    <span><?php echo formatMoney($remainingAmount); ?></span>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($is_seller): ?>
                <div class="warning">
                    <i class="fas fa-info-circle"></i> This is your own listing. You cannot purchase your own items.
                </div>
                <a href="listings.php" class="btn-purchase" style="background: #64748b; text-align: center; display: block; text-decoration: none;">Manage Your Listings</a>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="purchase" value="1">
                    <button type="submit" class="btn-purchase">
                        <i class="fas fa-shopping-cart"></i> Purchase Now
                    </button>
                </form>
            <?php endif; ?>
            
            <div class="warning">
                <i class="fas fa-shield-alt"></i> Secure Escrow Payment<br>
                <small>Your payment is protected. Funds are held in escrow until you confirm delivery.</small>
            </div>
        </div>
    </div>
</body>
</html>