<?php
// ============================================
// FILE: broker_system/user/post_listing.php
// ============================================
// Post Listing with Negotiation System - ERROR FREE

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

// Get categories
$categories = $conn->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY type, name");

// Create uploads directory if not exists
$upload_dir = '../uploads/listings/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
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
    
    // Car fields
    $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
    $mileage = isset($_POST['mileage']) ? intval($_POST['mileage']) : 0;
    $fuel_type = isset($_POST['fuel_type']) ? trim($_POST['fuel_type']) : '';
    $transmission = isset($_POST['transmission']) ? trim($_POST['transmission']) : '';
    
    // Job fields
    $employment_type = isset($_POST['employment_type']) ? trim($_POST['employment_type']) : '';
    $requirements = isset($_POST['requirements']) ? trim($_POST['requirements']) : '';
    
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
    
    // File upload
    $cover_image = '';
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadImage($_FILES['cover_image'], $upload_dir);
        if ($upload['success']) {
            $cover_image = $upload['filename'];
        } else {
            $errors[] = $upload['error'];
        }
    } else {
        $errors[] = "Cover image is required";
    }
    
    // Gallery images upload
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
                'area' => $area
            ));
        } elseif ($type == 'product') {
            $additional_json = json_encode(array(
                'year' => $year,
                'mileage' => $mileage,
                'fuel_type' => $fuel_type,
                'transmission' => $transmission
            ));
        } elseif ($type == 'job') {
            $additional_json = json_encode(array(
                'employment_type' => $employment_type,
                'requirements' => $requirements
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
    .post-form { max-width: 900px; margin: 0 auto; }
    .card { background: white; border-radius: 28px; padding: 32px; box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1); }
    .card h1 { font-size: 28px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
    .card > p { color: #64748b; margin-bottom: 28px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; }
    .form-group { margin-bottom: 24px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b; font-size: 14px; }
    .required { color: #ef4444; }
    input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.3s; }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    textarea { resize: vertical; min-height: 120px; }
    .type-selector { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
    .type-option { flex: 1; min-width: 150px; padding: 20px; border: 2px solid #e2e8f0; border-radius: 16px; text-align: center; cursor: pointer; transition: all 0.3s; }
    .type-option:hover { border-color: #667eea; background: #f8fafc; }
    .type-option.selected { border-color: #667eea; background: #eef2ff; }
    .type-option i { font-size: 36px; margin-bottom: 12px; display: block; }
    .type-option strong { display: block; margin-bottom: 4px; font-size: 16px; }
    .type-option small { font-size: 11px; color: #64748b; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; margin-top: 16px; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    .error { background: #fee2e2; color: #dc2626; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #dc2626; }
    .success { background: #d1fae5; color: #059669; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #059669; }
    .info-text { font-size: 12px; color: #64748b; margin-top: 6px; }
    .dynamic-fields { display: none; }
    .dynamic-fields.active { display: block; }
    .negotiation-info {
        background: #eef2ff;
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 24px;
        border-left: 4px solid #667eea;
    }
    .negotiation-info h4 {
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .negotiation-info p {
        font-size: 12px;
        color: #475569;
        line-height: 1.5;
    }
    @media (max-width: 768px) { 
        .card { padding: 20px; } 
        .type-selector { flex-direction: column; } 
        .form-row, .form-row-3 { grid-template-columns: 1fr; } 
    }
</style>

<div class="post-form">
    <div class="card">
        <h1><i class="fas fa-plus-circle"></i> Post New Listing</h1>
        <p>Your listing will be reviewed by our team before publication</p>
        
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
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="type-selector" id="typeSelector">
                <div class="type-option" data-type="rental" onclick="selectType('rental')">
                    <i class="fas fa-home"></i>
                    <strong>House/Property</strong>
                    <small>Apartment, Condominium, Villa, Land</small>
                </div>
                <div class="type-option" data-type="product" onclick="selectType('product')">
                    <i class="fas fa-car"></i>
                    <strong>Car/Vehicle</strong>
                    <small>Sell your car</small>
                </div>
                <div class="type-option" data-type="job" onclick="selectType('job')">
                    <i class="fas fa-briefcase"></i>
                    <strong>Job Opportunity</strong>
                    <small>Hire employees</small>
                </div>
            </div>
            <input type="hidden" name="type" id="listingType" value="rental" required>
            
            <div class="form-group">
                <label>Title <span class="required">*</span></label>
                <input type="text" name="title" required placeholder="e.g., Modern 2BR Apartment for Rent, 2020 Toyota Camry">
            </div>
            
            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <select name="category_id" required>
                    <option value="">Select category</option>
                    <?php while($cat = $categories->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <!-- Property Fields -->
            <div id="propertyFields" class="dynamic-fields active">
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
            </div>
            
            <!-- Car Fields -->
            <div id="carFields" class="dynamic-fields">
                <div class="form-row">
                    <div class="form-group">
                        <label>Year</label>
                        <input type="number" name="year" placeholder="Year">
                    </div>
                    <div class="form-group">
                        <label>Mileage (km)</label>
                        <input type="number" name="mileage" placeholder="Kilometers">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Fuel Type</label>
                        <select name="fuel_type">
                            <option value="">Select</option>
                            <option value="Petrol">Petrol</option>
                            <option value="Diesel">Diesel</option>
                            <option value="Electric">Electric</option>
                            <option value="Hybrid">Hybrid</option>
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
            </div>
            
            <!-- Job Fields -->
            <div id="jobFields" class="dynamic-fields">
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
                    <label>Requirements</label>
                    <textarea name="requirements" rows="4" placeholder="List required qualifications, experience, and skills..."></textarea>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description <span class="required">*</span></label>
                <textarea name="description" required placeholder="Describe your listing in detail..."></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Price (ETB) <span class="required">*</span></label>
                    <input type="number" name="price" step="1" min="1" required placeholder="0">
                    <div class="info-text">For properties: monthly rent or sale price | For cars: selling price | For jobs: monthly salary</div>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g., Addis Ababa, Bole">
                </div>
            </div>
            
            <div class="form-group">
                <label>Cover Image <span class="required">*</span></label>
                <input type="file" name="cover_image" accept="image/*" required>
                <div class="info-text">Main image displayed in listings (max 5MB, JPG/PNG/GIF/WEBP)</div>
            </div>
            
            <div class="form-group">
                <label>Gallery Images (Optional)</label>
                <input type="file" name="gallery_images[]" accept="image/*" multiple>
                <div class="info-text">Additional images (max 5MB each, max 10 images)</div>
            </div>
            
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit for Review</button>
        </form>
        
        <div class="info-text" style="margin-top: 20px; text-align: center; background: #fef3c7; padding: 12px; border-radius: 12px;">
            <i class="fas fa-clock"></i> <strong>Note:</strong> Your listing will be reviewed within 24-48 hours.
        </div>
    </div>
</div>

<script>
    function selectType(type) {
        document.getElementById('listingType').value = type;
        
        // Update selected class
        document.querySelectorAll('.type-option').forEach(function(opt) {
            opt.classList.remove('selected');
        });
        document.querySelector('.type-option[data-type="' + type + '"]').classList.add('selected');
        
        // Hide all dynamic fields
        document.getElementById('propertyFields').classList.remove('active');
        document.getElementById('carFields').classList.remove('active');
        document.getElementById('jobFields').classList.remove('active');
        
        // Show selected type fields
        if (type === 'rental') {
            document.getElementById('propertyFields').classList.add('active');
        } else if (type === 'product') {
            document.getElementById('carFields').classList.add('active');
        } else if (type === 'job') {
            document.getElementById('jobFields').classList.add('active');
        }
    }
    
    // Initialize with rental selected
    selectType('rental');
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>