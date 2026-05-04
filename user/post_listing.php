<?php
// user/post_listing.php - Post new listing with images and admin approval

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';

requireLogin();

$conn = getDbConnection();
$error = '';
$success = '';

// Get categories
$categories = $conn->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY type, name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = floatval($_POST['price']);
    $category_id = intval($_POST['category_id']);
    $location = trim($_POST['location']);
    $user_id = $_SESSION['user_id'];
    
    // Handle cover image
    $cover_image = '';
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadImage($_FILES['cover_image']);
        if ($upload['success']) {
            $cover_image = $upload['filename'];
        } else {
            $error = $upload['error'];
        }
    }
    
    // Handle gallery images
    $gallery_images = [];
    if (isset($_FILES['gallery_images'])) {
        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['gallery_images']['name'][$key],
                    'tmp_name' => $_FILES['gallery_images']['tmp_name'][$key],
                    'size' => $_FILES['gallery_images']['size'][$key],
                    'error' => $_FILES['gallery_images']['error'][$key]
                ];
                $upload = uploadImage($file);
                if ($upload['success']) {
                    $gallery_images[] = $upload['filename'];
                }
            }
        }
    }
    
    $gallery_json = !empty($gallery_images) ? json_encode($gallery_images) : null;
    
    if (empty($title) || empty($description) || $price <= 0) {
        $error = 'Please fill in all required fields';
    } elseif (empty($cover_image)) {
        $error = 'Please upload a cover image';
    } else {
        // Insert listing with pending approval
        $stmt = $conn->prepare("INSERT INTO listings (seller_id, type, title, description, price, category_id, location, cover_image, gallery_images, approval_status, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')");
        $stmt->bind_param("isssdissi", $user_id, $type, $title, $description, $price, $category_id, $location, $cover_image, $gallery_json);
        
        if ($stmt->execute()) {
            $listing_id = $conn->insert_id;
            
            // Create admin notification
            $stmt2 = $conn->prepare("INSERT INTO admin_notifications (type, title, message, listing_id, user_id) VALUES ('new_listing', 'New Listing Pending Approval', ?, ?, ?)");
            $message = "User {$_SESSION['user_name']} posted a new {$type}: {$title} for {$price} ETB";
            $stmt2->bind_param("sii", $message, $listing_id, $user_id);
            $stmt2->execute();
            
            $success = "Listing submitted for admin approval. You will be notified once approved.";
            header("Refresh: 3; URL=dashboard.php");
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
        .card h1 { font-size: 24px; margin-bottom: 8px; color: #333; }
        .card h1 small { font-size: 14px; color: #888; font-weight: normal; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; }
        textarea { resize: vertical; min-height: 120px; }
        button { width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
        button:hover { background: #5a67d8; }
        .error { background: #fee; color: #c33; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .image-preview { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .preview-item { position: relative; width: 100px; height: 100px; }
        .preview-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
        .remove-image { position: absolute; top: -8px; right: -8px; background: red; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; }
        .type-selector { display: flex; gap: 12px; margin-bottom: 20px; }
        .type-option { flex: 1; padding: 16px; border: 2px solid #ddd; border-radius: 8px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .type-option.selected { border-color: #667eea; background: #f0f4ff; }
        .type-option i { font-size: 24px; margin-bottom: 8px; display: block; }
        .info-text { font-size: 12px; color: #888; margin-top: 4px; }
        .approval-info { background: #e3f2fd; padding: 16px; border-radius: 8px; margin-top: 20px; text-align: center; }
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
            <p style="color: #666; margin-bottom: 24px;">Your listing will be reviewed by admin before going live</p>
            
            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="listingForm">
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
                    <textarea name="description" required placeholder="Describe your item, job requirements, or rental property in detail..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Price (ETB) *</label>
                    <input type="number" name="price" step="0.01" min="1" required placeholder="0.00">
                    <div class="info-text">Admin will set deposit and commission percentages for this listing</div>
                </div>
                
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g., Addis Ababa, Bole">
                </div>
                
                <div class="form-group">
                    <label>Cover Image *</label>
                    <input type="file" name="cover_image" accept="image/*" required onchange="previewCoverImage(this)">
                    <div class="info-text">This will be the main image displayed (max 5MB)</div>
                    <div id="coverPreview" class="image-preview"></div>
                </div>
                
                <div class="form-group">
                    <label>Gallery Images (Optional)</label>
                    <input type="file" name="gallery_images[]" accept="image/*" multiple onchange="previewGalleryImages(this)">
                    <div class="info-text">You can select multiple images (max 5MB each)</div>
                    <div id="galleryPreview" class="image-preview"></div>
                </div>
                
                <button type="submit"><i class="fas fa-paper-plane"></i> Submit for Admin Review</button>
            </form>
            
            <div class="approval-info">
                <i class="fas fa-clock"></i> <strong>Note:</strong> Your listing will be reviewed by an admin. You'll be notified once approved. After approval, you'll need to pay the required deposit and commission before your listing goes live.
            </div>
        </div>
    </div>
    
    <script>
        let coverImages = [];
        let galleryImages = [];
        
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
        
        function previewCoverImage(input) {
            const preview = document.getElementById('coverPreview');
            preview.innerHTML = '';
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `<img src="${e.target.result}" alt="Cover"><div class="remove-image" onclick="this.parentElement.remove()">×</div>`;
                    preview.appendChild(div);
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function previewGalleryImages(input) {
            const preview = document.getElementById('galleryPreview');
            preview.innerHTML = '';
            if (input.files) {
                Array.from(input.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        div.innerHTML = `<img src="${e.target.result}" alt="Gallery"><div class="remove-image" onclick="this.parentElement.remove()">×</div>`;
                        preview.appendChild(div);
                    }
                    reader.readAsDataURL(file);
                });
            }
        }
        
        // Initialize with product type
        selectType('product');
    </script>
</body>
</html>