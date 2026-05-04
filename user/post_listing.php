<?php
// user/post_listing.php - Post new item for sale

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$conn = getDbConnection();
$error = '';
$success = '';

// Get categories for dropdown
$categories = $conn->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY type, name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = floatval($_POST['price']);
    $category_id = intval($_POST['category_id']);
    $location = trim($_POST['location']);
    $user_id = $_SESSION['user_id'];
    
    // Get deposit and commission percentages for this item type
    $depositPercent = getSetting("deposit_percent_{$type}", 30);
    $commissionPercent = getSetting("commission_percent_{$type}", 15);
    
    if (empty($title) || empty($description) || $price <= 0) {
        $error = 'Please fill in all required fields';
    } else {
        $stmt = $conn->prepare("INSERT INTO listings (seller_id, type, title, description, price, category_id, location, deposit_percent, commission_percent, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("isssdissi", $user_id, $type, $title, $description, $price, $category_id, $location, $depositPercent, $commissionPercent);
        
        if ($stmt->execute()) {
            $listing_id = $conn->insert_id;
            $success = "Listing posted successfully!";
            header("Refresh: 2; URL=product.php?id=$listing_id");
        } else {
            $error = "Failed to post listing: " . $conn->error;
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post New Listing - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .header { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 16px 24px; }
        .header-content { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 700; color: #667eea; text-decoration: none; }
        .container { max-width: 800px; margin: 40px auto; padding: 0 24px; }
        .card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h1 { font-size: 24px; margin-bottom: 24px; color: #333; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; }
        textarea { resize: vertical; min-height: 120px; }
        button { width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
        button:hover { background: #5a67d8; }
        .error { background: #fee; color: #c33; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .info-text { font-size: 12px; color: #888; margin-top: 4px; }
        .type-selector { display: flex; gap: 12px; margin-bottom: 20px; }
        .type-option { flex: 1; padding: 16px; border: 2px solid #ddd; border-radius: 8px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .type-option.selected { border-color: #667eea; background: #f0f4ff; }
        .type-option i { font-size: 24px; margin-bottom: 8px; display: block; }
        .type-option.active { border-color: #667eea; background: #f0f4ff; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <a href="/broker_system/index.php" class="logo">🏪 Ethio Brokerplace</a>
            <a href="dashboard.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h1><i class="fas fa-plus-circle"></i> Post New Listing</h1>
            
            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" id="listingForm">
                <div class="type-selector" id="typeSelector">
                    <div class="type-option" data-type="product" onclick="selectType('product')">
                        <i class="fas fa-box"></i>
                        <strong>Product</strong>
                        <small>Physical or digital items</small>
                    </div>
                    <div class="type-option" data-type="job" onclick="selectType('job')">
                        <i class="fas fa-briefcase"></i>
                        <strong>Job</strong>
                        <small>Hire or find work</small>
                    </div>
                    <div class="type-option" data-type="rental" onclick="selectType('rental')">
                        <i class="fas fa-home"></i>
                        <strong>Rental</strong>
                        <small>Rent items or property</small>
                    </div>
                </div>
                <input type="hidden" name="type" id="listingType" value="product" required>
                
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" required placeholder="e.g., iPhone 14 Pro, Web Developer Needed, 2BR Apartment">
                </div>
                
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category_id" required>
                        <option value="">Select category</option>
                        <?php 
                        $currentType = '';
                        while($cat = $categories->fetch_assoc()): 
                            if ($currentType != $cat['type']): 
                                if ($currentType != ''): echo '</optgroup>'; endif;
                                $currentType = $cat['type'];
                                echo '<optgroup label="' . ucfirst($currentType) . '">';
                            endif;
                        ?>
                            <option value="<?php echo $cat['id']; ?>" data-type="<?php echo $cat['type']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endwhile; ?>
                        </optgroup>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Description *</label>
                    <textarea name="description" required placeholder="Describe your item, job requirements, or rental property..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Price (ETB) *</label>
                    <input type="number" name="price" step="0.01" min="1" required placeholder="0.00">
                    <div class="info-text">Buyer will pay deposit + commission upfront. Seller also pays deposit.</div>
                </div>
                
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g., Addis Ababa, Bole">
                </div>
                
                <button type="submit"><i class="fas fa-save"></i> Post Listing</button>
            </form>
        </div>
    </div>
    
    <script>
        function selectType(type) {
            document.getElementById('listingType').value = type;
            document.querySelectorAll('.type-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.querySelector(`.type-option[data-type="${type}"]`).classList.add('selected');
            
            // Filter categories by type
            const categorySelect = document.querySelector('select[name="category_id"]');
            for (let i = 0; i < categorySelect.options.length; i++) {
                const option = categorySelect.options[i];
                if (option.value === '') continue;
                const optionType = option.getAttribute('data-type');
                if (optionType === type) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
        }
        
        // Initialize with product type
        selectType('product');
    </script>
</body>
</html>