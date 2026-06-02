<?php
// ============================================
// FILE: broker_system/user/post_listing.php
// ============================================
// Post Listing with Dynamic Category Filtering - COMPLETE REDESIGN
// FIXED: Cover image optional for jobs, proper category filtering

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';

requireLogin();

$page_title = 'Post New Listing';
ob_start();

$conn = getDbConnection();
$error = '';
$success = '';

// Create uploads directory if not exists
$upload_dir = '../uploads/listings/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Get all categories
$all_categories = $conn->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY type, name");

// Organize categories by type
$categories_by_type = [
    'rental' => [],
    'product' => [],
    'job' => []
];

while ($cat = $all_categories->fetch_assoc()) {
    $type = $cat['type'];
    if (isset($categories_by_type[$type])) {
        $categories_by_type[$type][] = $cat;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize inputs
    $type = isset($_POST['type']) ? trim($_POST['type']) : '';
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    
    // Rental fields
    $bedrooms = isset($_POST['bedrooms']) ? intval($_POST['bedrooms']) : 0;
    $bathrooms = isset($_POST['bathrooms']) ? intval($_POST['bathrooms']) : 0;
    $area = isset($_POST['area']) ? floatval($_POST['area']) : 0;
    $parking = isset($_POST['parking']) ? trim($_POST['parking']) : '';
    $furnished = isset($_POST['furnished']) ? trim($_POST['furnished']) : '';
    
    // Car fields
    $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
    $mileage = isset($_POST['mileage']) ? intval($_POST['mileage']) : 0;
    $fuel_type = isset($_POST['fuel_type']) ? trim($_POST['fuel_type']) : '';
    $transmission = isset($_POST['transmission']) ? trim($_POST['transmission']) : '';
    $color = isset($_POST['color']) ? trim($_POST['color']) : '';
    
    // Job fields
    $employment_type = isset($_POST['employment_type']) ? trim($_POST['employment_type']) : '';
    $requirements = isset($_POST['requirements']) ? trim($_POST['requirements']) : '';
    $experience_level = isset($_POST['experience_level']) ? trim($_POST['experience_level']) : '';
    $deadline = isset($_POST['deadline']) ? trim($_POST['deadline']) : '';
    
    $errors = array();
    
    // Validation
    $valid_types = array('product', 'job', 'rental');
    if (!in_array($type, $valid_types)) {
        $errors[] = "Invalid listing type selected";
    }
    
    if (empty($title)) {
        $errors[] = "Title is required";
    } elseif (strlen($title) < 3) {
        $errors[] = "Title must be at least 3 characters";
    } elseif (strlen($title) > 100) {
        $errors[] = "Title must not exceed 100 characters";
    }
    
    if (empty($description)) {
        $errors[] = "Description is required";
    } elseif (strlen($description) < 20) {
        $errors[] = "Description must be at least 20 characters";
    }
    
    if ($price <= 0) {
        $errors[] = "Please enter a valid price greater than 0";
    }
    
    if ($category_id <= 0) {
        $errors[] = "Please select a category";
    }
    
    // File upload - cover image is optional for jobs
    $cover_image = '';
    $cover_image_required = ($type !== 'job'); // Only required for rentals and products
    
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadImage($_FILES['cover_image'], $upload_dir);
        if ($upload['success']) {
            $cover_image = $upload['filename'];
        } else {
            $errors[] = $upload['error'];
        }
    } elseif ($cover_image_required) {
        $errors[] = "Cover image is required for " . ucfirst($type) . " listings";
    }
    
    // Gallery images upload (optional for all types)
    $gallery_images = array();
    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
        $total_files = count($_FILES['gallery_images']['name']);
        for ($i = 0; $i < min($total_files, 10); $i++) {
            if ($_FILES['gallery_images']['error'][$i] === UPLOAD_ERR_OK) {
                $file = array(
                    'name' => $_FILES['gallery_images']['name'][$i],
                    'type' => $_FILES['gallery_images']['type'][$i],
                    'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i],
                    'error' => $_FILES['gallery_images']['error'][$i],
                    'size' => $_FILES['gallery_images']['size'][$i]
                );
                $upload = uploadImage($file, $upload_dir);
                if ($upload['success']) {
                    $gallery_images[] = $upload['filename'];
                }
            }
        }
    }
    
    if (empty($errors)) {
        // Build additional details JSON
        $additional_json = null;
        if ($type == 'rental') {
            $additional_json = json_encode(array(
                'bedrooms' => $bedrooms,
                'bathrooms' => $bathrooms,
                'area' => $area,
                'parking' => $parking,
                'furnished' => $furnished
            ));
        } elseif ($type == 'product') {
            $additional_json = json_encode(array(
                'year' => $year,
                'mileage' => $mileage,
                'fuel_type' => $fuel_type,
                'transmission' => $transmission,
                'color' => $color
            ));
        } elseif ($type == 'job') {
            $additional_json = json_encode(array(
                'employment_type' => $employment_type,
                'requirements' => $requirements,
                'experience_level' => $experience_level,
                'deadline' => $deadline
            ));
        }
        
        $gallery_json = !empty($gallery_images) ? json_encode($gallery_images) : null;
        $user_id = $_SESSION['user_id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert listing using prepared statement
            $stmt = $conn->prepare("
                INSERT INTO listings (
                    seller_id, type, title, description, price, category_id, location, 
                    cover_image, gallery_images, additional_details, approval_status, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())
            ");
            
            $stmt->bind_param(
                "isssdsssss", 
                $user_id, $type, $title, $description, $price, $category_id, $location,
                $cover_image, $gallery_json, $additional_json
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert listing: " . $stmt->error);
            }
            
            $listing_id = $conn->insert_id;
            
            // Check if negotiation tables exist and create negotiation
            $table_check = $conn->query("SHOW TABLES LIKE 'listing_negotiations'");
            if ($table_check->num_rows > 0) {
                $neg_stmt = $conn->prepare("
                    INSERT INTO listing_negotiations (listing_id, seller_id, status, created_at, updated_at) 
                    VALUES (?, ?, 'under_review', NOW(), NOW())
                ");
                $neg_stmt->bind_param("ii", $listing_id, $user_id);
                $neg_stmt->execute();
                $negotiation_id = $conn->insert_id;
                
                // Update listing with negotiation ID
                $update_stmt = $conn->prepare("UPDATE listings SET negotiation_id = ? WHERE id = ?");
                $update_stmt->bind_param("ii", $negotiation_id, $listing_id);
                $update_stmt->execute();
            }
            
            $conn->commit();
            
            $success = "✓ Listing submitted successfully! Our team will review your listing within 24-48 hours.";
            
            // Redirect
            echo '<meta http-equiv="refresh" content="2;url=dashboard.php">';
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to submit listing: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$conn->close();
?>

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
    
    .post-form { max-width: 900px; margin: 0 auto; }
    
    /* Main Card */
    .card { 
        background: white; 
        border-radius: 32px; 
        padding: 36px; 
        box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
        border: 1px solid var(--border);
    }
    
    .card h1 { 
        font-size: 28px; 
        font-weight: 700; 
        color: var(--dark); 
        margin-bottom: 8px; 
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .card h1 i {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    
    .card > p { 
        color: var(--gray); 
        margin-bottom: 28px; 
        padding-bottom: 20px; 
        border-bottom: 2px solid var(--border);
    }
    
    /* Form Groups */
    .form-group { margin-bottom: 24px; }
    
    label { 
        display: block; 
        margin-bottom: 8px; 
        font-weight: 600; 
        color: var(--dark); 
        font-size: 14px; 
    }
    
    .required { color: var(--danger); margin-left: 4px; }
    
    input, select, textarea { 
        width: 100%; 
        padding: 12px 16px; 
        border: 2px solid var(--border); 
        border-radius: 14px; 
        font-size: 14px; 
        font-family: inherit; 
        transition: all 0.3s; 
        background: white;
    }
    
    input:focus, select:focus, textarea:focus { 
        outline: none; 
        border-color: var(--primary); 
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1); 
    }
    
    textarea { resize: vertical; min-height: 120px; }
    
    /* Type Selector */
    .type-selector { 
        display: flex; 
        gap: 20px; 
        margin-bottom: 32px; 
        flex-wrap: wrap; 
    }
    
    .type-option { 
        flex: 1; 
        min-width: 160px; 
        padding: 24px 20px; 
        border: 2px solid var(--border); 
        border-radius: 20px; 
        text-align: center; 
        cursor: pointer; 
        transition: all 0.3s; 
        background: white;
    }
    
    .type-option:hover { 
        border-color: var(--primary); 
        background: var(--light); 
        transform: translateY(-3px);
    }
    
    .type-option.selected { 
        border-color: var(--primary); 
        background: linear-gradient(135deg, var(--primary), var(--secondary));
    }
    
    .type-option.selected i,
    .type-option.selected strong,
    .type-option.selected small { 
        color: white; 
    }
    
    .type-option i { 
        font-size: 42px; 
        margin-bottom: 12px; 
        display: block; 
        color: var(--primary);
    }
    
    .type-option strong { 
        display: block; 
        margin-bottom: 6px; 
        font-size: 16px; 
        font-weight: 700;
        color: var(--dark);
    }
    
    .type-option small { 
        font-size: 11px; 
        color: var(--gray); 
    }
    
    /* Dynamic Fields */
    .dynamic-fields { 
        display: none; 
        animation: fadeIn 0.4s ease;
    }
    
    .dynamic-fields.active { 
        display: block; 
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Form Layouts */
    .form-row { 
        display: grid; 
        grid-template-columns: repeat(2, 1fr); 
        gap: 20px; 
    }
    
    .form-row-3 { 
        display: grid; 
        grid-template-columns: repeat(3, 1fr); 
        gap: 20px; 
    }
    
    /* Section Headers */
    .section-header {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark);
        margin: 24px 0 16px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .section-header i {
        color: var(--primary);
    }
    
    /* Buttons */
    .btn-submit { 
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
        margin-top: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    
    .btn-submit:hover { 
        transform: translateY(-3px); 
        box-shadow: 0 8px 25px rgba(102,126,234,0.4); 
    }
    
    /* Alerts */
    .error { 
        background: #fee2e2; 
        color: var(--danger); 
        padding: 14px 18px; 
        border-radius: 16px; 
        margin-bottom: 24px; 
        border-left: 4px solid var(--danger);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .success { 
        background: #d1fae5; 
        color: #059669; 
        padding: 14px 18px; 
        border-radius: 16px; 
        margin-bottom: 24px; 
        border-left: 4px solid #059669;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .info-text { 
        font-size: 12px; 
        color: var(--gray); 
        margin-top: 6px; 
    }
    
    /* Negotiation Info Box */
    .negotiation-info {
        background: linear-gradient(135deg, #eef2ff, #f8fafc);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 28px;
        border-left: 4px solid var(--primary);
    }
    
    .negotiation-info h4 {
        font-size: 15px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .negotiation-info p {
        font-size: 13px;
        color: var(--gray);
        line-height: 1.6;
    }
    
    .negotiation-info strong {
        color: var(--primary);
    }
    
    /* File Input Styling */
    input[type="file"] {
        padding: 10px;
        background: var(--light);
        border: 2px dashed var(--border);
    }
    
    input[type="file"]:hover {
        border-color: var(--primary);
        background: #eef2ff;
    }
    
    /* Responsive */
    @media (max-width: 768px) { 
        .card { padding: 24px; } 
        .type-selector { flex-direction: column; } 
        .type-option { min-width: auto; }
        .form-row, .form-row-3 { grid-template-columns: 1fr; gap: 16px; }
        .card h1 { font-size: 24px; }
    }
</style>

<div class="post-form">
    <div class="card">
        <h1>
            <i class="fas fa-plus-circle"></i> 
            Post New Listing
        </h1>
        <p>Your listing will be reviewed by our team before publication</p>
        
        <!-- How It Works Box -->
        <div class="negotiation-info">
            <h4><i class="fas fa-handshake"></i> How It Works</h4>
            <p>
                <strong>1. Submit your listing</strong> → Our team reviews your listing (24-48 hours)<br>
                <strong>2. Receive proposal</strong> → We will propose commission and deposit terms<br>
                <strong>3. Negotiate or accept</strong> → You can counter-offer or accept the terms<br>
                <strong>4. Pay deposit</strong> → After agreement, pay the deposit to publish<br>
                <strong>5. Go live</strong> → Your listing becomes visible to buyers!
            </p>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="listingForm">
            <!-- Listing Type Selector -->
            <div class="type-selector" id="typeSelector">
                <div class="type-option" data-type="rental" onclick="selectType('rental')">
                    <i class="fas fa-home"></i>
                    <strong>🏠 House/Property</strong>
                    <small>Apartment, Condominium, Villa, Land</small>
                </div>
                <div class="type-option" data-type="product" onclick="selectType('product')">
                    <i class="fas fa-car"></i>
                    <strong>🚗 Car/Vehicle</strong>
                    <small>Sell your car</small>
                </div>
                <div class="type-option" data-type="job" onclick="selectType('job')">
                    <i class="fas fa-briefcase"></i>
                    <strong>💼 Job Opportunity</strong>
                    <small>Hire employees</small>
                </div>
            </div>
            <input type="hidden" name="type" id="listingType" value="rental" required>
            
            <!-- Basic Information Section -->
            <div class="section-header">
                <i class="fas fa-info-circle"></i> Basic Information
            </div>
            
            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <input type="text" name="title" id="title" required>
            </div>
            
            <!-- Category Selection - Dynamic based on type -->
            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <select name="category_id" id="categorySelect" required>
                    <option value="">Select category</option>
                </select>
            </div>
            
            <!-- ============================================ -->
            <!-- PROPERTY FIELDS (For Rentals) -->
            <!-- ============================================ -->
            <div id="propertyFields" class="dynamic-fields active">
                <div class="section-header">
                    <i class="fas fa-home"></i> Property Details
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Bedrooms</label>
                        <input type="number" name="bedrooms" min="0" placeholder="Number of bedrooms">
                    </div>
                    <div class="form-group">
                        <label>Bathrooms</label>
                        <input type="number" name="bathrooms" min="0" placeholder="Number of bathrooms">
                    </div>
                    <div class="form-group">
                        <label>Area (sqm)</label>
                        <input type="number" name="area" min="0" placeholder="Size in sqm">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Parking</label>
                        <select name="parking">
                            <option value="">Select</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Street">Street Parking</option>
                            <option value="Garage">Garage</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Furnished</label>
                        <select name="furnished">
                            <option value="">Select</option>
                            <option value="Fully Furnished">Fully Furnished</option>
                            <option value="Semi-Furnished">Semi-Furnished</option>
                            <option value="Unfurnished">Unfurnished</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- ============================================ -->
            <!-- CAR FIELDS (For Products) -->
            <!-- ============================================ -->
            <div id="carFields" class="dynamic-fields">
                <div class="section-header">
                    <i class="fas fa-car"></i> Vehicle Details
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Year</label>
                        <input type="number" name="year" min="1950" max="2025" placeholder="Year (e.g., 2020)">
                    </div>
                    <div class="form-group">
                        <label>Mileage (km)</label>
                        <input type="number" name="mileage" min="0" placeholder="Kilometers driven">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Fuel Type</label>
                        <select name="fuel_type">
                            <option value="">Select</option>
                            <option value="Petrol">⛽ Petrol</option>
                            <option value="Diesel">⛽ Diesel</option>
                            <option value="Electric">⚡ Electric</option>
                            <option value="Hybrid">🔋 Hybrid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Transmission</label>
                        <select name="transmission">
                            <option value="">Select</option>
                            <option value="Manual">Manual</option>
                            <option value="Automatic">Automatic</option>
                            <option value="Semi-Automatic">Semi-Automatic</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <input type="text" name="color" placeholder="e.g., White, Black, Silver, Red">
                </div>
            </div>
            
            <!-- ============================================ -->
            <!-- JOB FIELDS (For Jobs) -->
            <!-- ============================================ -->
            <div id="jobFields" class="dynamic-fields">
                <div class="section-header">
                    <i class="fas fa-briefcase"></i> Job Details
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Employment Type</label>
                        <select name="employment_type">
                            <option value="">Select</option>
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                            <option value="Contract">Contract</option>
                            <option value="Remote">Remote</option>
                            <option value="Internship">Internship</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Experience Level</label>
                        <select name="experience_level">
                            <option value="">Select</option>
                            <option value="Entry Level">Entry Level (0-2 years)</option>
                            <option value="Mid Level">Mid Level (3-5 years)</option>
                            <option value="Senior">Senior (5+ years)</option>
                            <option value="Manager">Manager</option>
                            <option value="Executive">Executive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Application Deadline</label>
                    <input type="date" name="deadline">
                </div>
                <div class="form-group">
                    <label>Requirements <span class="required">*</span></label>
                    <textarea name="requirements" rows="4" placeholder="List required qualifications, experience, and skills..."></textarea>
                </div>
            </div>
            
            <!-- Description -->
            <div class="section-header">
                <i class="fas fa-align-left"></i> Detailed Description
            </div>
            
            <div class="form-group">
                <label>Description <span class="required">*</span></label>
                <textarea name="description" id="description" required placeholder="Describe your listing in detail..."></textarea>
            </div>
            
            <!-- Price & Location -->
            <div class="section-header">
                <i class="fas fa-tag"></i> Pricing & Location
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Price (ETB) <span class="required">*</span></label>
                    <input type="number" name="price" step="1" min="1" required placeholder="0">
                    <div class="info-text" id="priceHint">For properties: monthly rent or sale price | For cars: selling price | For jobs: monthly salary</div>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g., Addis Ababa, Bole">
                </div>
            </div>
            
            <!-- Images Section -->
            <div class="section-header">
                <i class="fas fa-image"></i> Images
            </div>
            
            <div class="form-group" id="coverImageGroup">
                <label>Cover Image <span class="required" id="coverImageRequired">*</span></label>
                <input type="file" name="cover_image" id="coverImage" accept="image/*">
                <div class="info-text" id="coverImageHint">Main image displayed in listings (max 5MB, JPG/PNG/GIF/WEBP)</div>
            </div>
            
            <div class="form-group" id="galleryImageGroup">
                <label>Gallery Images (Optional)</label>
                <input type="file" name="gallery_images[]" accept="image/*" multiple>
                <div class="info-text">Additional images (max 5MB each, max 10 images)</div>
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Submit for Review
            </button>
        </form>
        
        <div class="info-text" style="margin-top: 24px; text-align: center; background: #fef3c7; padding: 14px; border-radius: 16px;">
            <i class="fas fa-clock"></i> <strong>Note:</strong> Your listing will be reviewed within 24-48 hours.
        </div>
    </div>
</div>

<script>
    // Categories data from PHP
    const categoriesByType = {
        rental: <?php echo json_encode($categories_by_type['rental']); ?>,
        product: <?php echo json_encode($categories_by_type['product']); ?>,
        job: <?php echo json_encode($categories_by_type['job']); ?>
    };
    
    // Update category dropdown based on selected type
    function updateCategories(type) {
        const categorySelect = document.getElementById('categorySelect');
        const categories = categoriesByType[type] || [];
        
        categorySelect.innerHTML = '<option value="">Select category</option>';
        
        categories.forEach(cat => {
            const option = document.createElement('option');
            option.value = cat.id;
            option.textContent = cat.name;
            categorySelect.appendChild(option);
        });
    }
    
    // Update price hint text based on type
    function updatePriceHint(type) {
        const priceHint = document.getElementById('priceHint');
        if (type === 'rental') {
            priceHint.innerHTML = '🏠 For properties: Monthly rent or sale price';
        } else if (type === 'product') {
            priceHint.innerHTML = '🚗 For cars: Selling price';
        } else if (type === 'job') {
            priceHint.innerHTML = '💼 For jobs: Monthly salary';
        }
    }
    
    // Select listing type
    function selectType(type) {
        document.getElementById('listingType').value = type;
        
        // Update selected class on type options
        document.querySelectorAll('.type-option').forEach(opt => {
            opt.classList.remove('selected');
        });
        document.querySelector(`.type-option[data-type="${type}"]`).classList.add('selected');
        
        // Hide all dynamic fields
        document.getElementById('propertyFields').classList.remove('active');
        document.getElementById('carFields').classList.remove('active');
        document.getElementById('jobFields').classList.remove('active');
        
        // Show selected type fields
        if (type === 'rental') {
            document.getElementById('propertyFields').classList.add('active');
            // Cover image required for rentals
            document.getElementById('coverImage').required = true;
            document.getElementById('coverImageRequired').style.display = 'inline';
            document.getElementById('coverImageHint').innerHTML = 'Main image displayed in listings (max 5MB, JPG/PNG/GIF/WEBP)';
            document.getElementById('galleryImageGroup').style.display = 'block';
        } else if (type === 'product') {
            document.getElementById('carFields').classList.add('active');
            // Cover image required for products
            document.getElementById('coverImage').required = true;
            document.getElementById('coverImageRequired').style.display = 'inline';
            document.getElementById('coverImageHint').innerHTML = 'Main image of the car (max 5MB, JPG/PNG/GIF/WEBP)';
            document.getElementById('galleryImageGroup').style.display = 'block';
        } else if (type === 'job') {
            document.getElementById('jobFields').classList.add('active');
            // Cover image NOT required for jobs
            document.getElementById('coverImage').required = false;
            document.getElementById('coverImageRequired').style.display = 'none';
            document.getElementById('coverImageHint').innerHTML = 'Optional: Company logo or job poster (max 5MB)';
            document.getElementById('galleryImageGroup').style.display = 'none';
        }
        
        // Update category dropdown
        updateCategories(type);
        
        // Update price hint
        updatePriceHint(type);
        
        // Update title placeholder
        const titleInput = document.getElementById('title');
        if (type === 'rental') {
            titleInput.placeholder = 'e.g., Modern 2BR Apartment for Rent, Villa with Pool';
        } else if (type === 'product') {
            titleInput.placeholder = 'e.g., 2020 Toyota Camry, Honda CR-V 2019';
        } else if (type === 'job') {
            titleInput.placeholder = 'e.g., Senior Software Engineer, Marketing Manager';
        }
    }
    
    // Initialize with rental selected
    document.addEventListener('DOMContentLoaded', function() {
        selectType('rental');
    });
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>