<?php
// user/post_listing.php - Completely Fixed Version

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
    $type = isset($_POST['type']) ? htmlspecialchars(trim($_POST['type']), ENT_QUOTES, 'UTF-8') : '';
    $title = isset($_POST['title']) ? htmlspecialchars(trim($_POST['title']), ENT_QUOTES, 'UTF-8') : '';
    $description = isset($_POST['description']) ? htmlspecialchars(trim($_POST['description']), ENT_QUOTES, 'UTF-8') : '';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $location = isset($_POST['location']) ? htmlspecialchars(trim($_POST['location']), ENT_QUOTES, 'UTF-8') : '';
    
    // Rental fields
    $bedrooms = isset($_POST['bedrooms']) ? intval($_POST['bedrooms']) : 0;
    $bathrooms = isset($_POST['bathrooms']) ? intval($_POST['bathrooms']) : 0;
    $area = isset($_POST['area']) ? floatval($_POST['area']) : 0;
    
    // Car fields
    $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
    $mileage = isset($_POST['mileage']) ? intval($_POST['mileage']) : 0;
    $fuel_type = isset($_POST['fuel_type']) ? htmlspecialchars(trim($_POST['fuel_type']), ENT_QUOTES, 'UTF-8') : '';
    $transmission = isset($_POST['transmission']) ? htmlspecialchars(trim($_POST['transmission']), ENT_QUOTES, 'UTF-8') : '';
    
    // Job fields
    $employment_type = isset($_POST['employment_type']) ? htmlspecialchars(trim($_POST['employment_type']), ENT_QUOTES, 'UTF-8') : '';
    $requirements = isset($_POST['requirements']) ? htmlspecialchars(trim($_POST['requirements']), ENT_QUOTES, 'UTF-8') : '';
    
    $errors = [];
    
    // Validation
    $valid_types = ['product', 'job', 'rental'];
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
    } elseif (strlen($description) > 5000) {
        $errors[] = "Description must not exceed 5000 characters";
    }
    
    if ($price <= 0) {
        $errors[] = "Please enter a valid price greater than 0";
    } elseif ($price > 100000000) {
        $errors[] = "Price cannot exceed 100,000,000 ETB";
    }
    
    if ($category_id <= 0) {
        $errors[] = "Please select a category";
    }
    
    if ($type == 'rental') {
        if ($bedrooms < 0 || $bedrooms > 50) $errors[] = "Bedrooms must be between 0 and 50";
        if ($bathrooms < 0 || $bathrooms > 50) $errors[] = "Bathrooms must be between 0 and 50";
        if ($area < 0 || $area > 10000) $errors[] = "Area must be between 0 and 10,000 sqm";
    }
    
    if ($type == 'product') {
        $current_year = date('Y');
        if ($year < 1950 || $year > $current_year + 1) $errors[] = "Please enter a valid year between 1950 and " . ($current_year + 1);
        if ($mileage < 0 || $mileage > 1000000) $errors[] = "Mileage must be between 0 and 1,000,000 km";
        $valid_fuel = ['Petrol', 'Diesel', 'Electric', 'Hybrid'];
        if (!empty($fuel_type) && !in_array($fuel_type, $valid_fuel)) $errors[] = "Please select a valid fuel type";
        $valid_transmission = ['Manual', 'Automatic', 'Semi-Automatic'];
        if (!empty($transmission) && !in_array($transmission, $valid_transmission)) $errors[] = "Please select a valid transmission type";
    }
    
    if ($type == 'job') {
        $valid_employment = ['Full-time', 'Part-time', 'Contract', 'Remote', 'Internship'];
        if (!empty($employment_type) && !in_array($employment_type, $valid_employment)) $errors[] = "Please select a valid employment type";
        if (!empty($requirements) && (strlen($requirements) < 10 || strlen($requirements) > 2000)) $errors[] = "Requirements must be between 10 and 2000 characters";
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
    
    if (empty($errors)) {
        // Build additional details JSON
        $additional_json = null;
        if ($type == 'rental') {
            $additional_json = json_encode([
                'bedrooms' => $bedrooms,
                'bathrooms' => $bathrooms,
                'area' => $area
            ]);
        } elseif ($type == 'product') {
            $additional_json = json_encode([
                'year' => $year,
                'mileage' => $mileage,
                'fuel_type' => $fuel_type,
                'transmission' => $transmission
            ]);
        } elseif ($type == 'job') {
            $additional_json = json_encode([
                'employment_type' => $employment_type,
                'requirements' => $requirements
            ]);
        }
        
        $user_id = $_SESSION['user_id'];
        
        // FIXED: Use a simple INSERT without bind_param issues
        // Escape strings for safe insertion
        $type_escaped = $conn->real_escape_string($type);
        $title_escaped = $conn->real_escape_string($title);
        $description_escaped = $conn->real_escape_string($description);
        $location_escaped = $conn->real_escape_string($location);
        $additional_escaped = $additional_json ? $conn->real_escape_string($additional_json) : null;
        
        $sql = "INSERT INTO listings (
            seller_id, type, title, description, price, category_id, location, 
            cover_image, additional_details, approval_status, status, created_at
        ) VALUES (
            $user_id, 
            '$type_escaped', 
            '$title_escaped', 
            '$description_escaped', 
            $price, 
            $category_id, 
            '$location_escaped', 
            '$cover_image', 
            " . ($additional_escaped ? "'$additional_escaped'" : "NULL") . ", 
            'pending', 
            'pending', 
            NOW()
        )";
        
        if ($conn->query($sql)) {
            $listing_id = $conn->insert_id;
            $success = "Listing submitted for admin approval. You will be notified once approved.";
            header("Refresh: 2; URL=listings.php");
        } else {
            $error = "Failed to post listing: " . $conn->error;
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
    .type-selector { display: flex; gap: 16px; margin-bottom: 28px; }
    .type-option { flex: 1; padding: 20px; border: 2px solid #e2e8f0; border-radius: 16px; text-align: center; cursor: pointer; transition: all 0.3s; }
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
    @media (max-width: 640px) { .card { padding: 20px; } .type-selector { flex-direction: column; } .form-row, .form-row-3 { grid-template-columns: 1fr; } }
</style>

<div class="post-form">
    <div class="card">
        <h1><i class="fas fa-plus-circle"></i> Post New Listing</h1>
        <p>Your listing will be reviewed by admin before going live</p>
        
        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
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
                <select name="category_id" id="categorySelect" required>
                    <option value="">Select category</option>
                    <?php 
                    $currentType = '';
                    while($cat = $categories->fetch_assoc()): 
                        if ($currentType != $cat['type']): 
                            if ($currentType != ''): echo '</optgroup>'; endif;
                            $currentType = $cat['type'];
                            $typeLabel = $currentType == 'rental' ? '🏠 Properties' : ($currentType == 'product' ? '🚗 Cars' : '💼 Jobs');
                            echo '<optgroup label="' . $typeLabel . '">';
                        endif;
                    ?>
                        <option value="<?php echo $cat['id']; ?>" data-type="<?php echo $cat['type']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endwhile; ?>
                    </optgroup>
                </select>
            </div>
            
            <!-- Property Fields -->
            <div id="propertyFields" class="dynamic-fields active">
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Bedrooms</label>
                        <input type="number" name="bedrooms" min="0" max="50" placeholder="Number of bedrooms">
                    </div>
                    <div class="form-group">
                        <label>Bathrooms</label>
                        <input type="number" name="bathrooms" min="0" max="50" placeholder="Number of bathrooms">
                    </div>
                    <div class="form-group">
                        <label>Area (sqm)</label>
                        <input type="number" name="area" min="0" max="10000" step="1" placeholder="Size in square meters">
                    </div>
                </div>
            </div>
            
            <!-- Car Fields -->
            <div id="carFields" class="dynamic-fields">
                <div class="form-row">
                    <div class="form-group">
                        <label>Year</label>
                        <input type="number" name="year" min="1950" max="<?php echo date('Y') + 1; ?>" placeholder="Manufacturing year">
                    </div>
                    <div class="form-group">
                        <label>Mileage (km)</label>
                        <input type="number" name="mileage" min="0" max="1000000" step="1" placeholder="Kilometers driven">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Fuel Type</label>
                        <select name="fuel_type">
                            <option value="">Select fuel type</option>
                            <option value="Petrol">Petrol</option>
                            <option value="Diesel">Diesel</option>
                            <option value="Electric">Electric</option>
                            <option value="Hybrid">Hybrid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Transmission</label>
                        <select name="transmission">
                            <option value="">Select transmission</option>
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
                        <option value="">Select employment type</option>
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                        <option value="Contract">Contract</option>
                        <option value="Remote">Remote</option>
                        <option value="Internship">Internship</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Requirements <span class="required">*</span></label>
                    <textarea name="requirements" rows="4" placeholder="List required qualifications, experience, and skills..."></textarea>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description <span class="required">*</span></label>
                <textarea name="description" required placeholder="Describe your property, car, or job opportunity in detail..."></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Price (ETB) <span class="required">*</span></label>
                    <input type="number" name="price" step="0.01" min="1" max="100000000" required placeholder="0.00">
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
            <i class="fas fa-clock"></i> <strong>Note:</strong> Your listing will be reviewed by an admin. You'll be notified once approved.
        </div>
    </div>
</div>

<script>
    function selectType(type) {
        document.getElementById('listingType').value = type;
        document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('selected'));
        document.querySelector(`.type-option[data-type="${type}"]`).classList.add('selected');
        
        document.getElementById('propertyFields').classList.remove('active');
        document.getElementById('carFields').classList.remove('active');
        document.getElementById('jobFields').classList.remove('active');
        
        if (type === 'rental') {
            document.getElementById('propertyFields').classList.add('active');
        } else if (type === 'product') {
            document.getElementById('carFields').classList.add('active');
        } else if (type === 'job') {
            document.getElementById('jobFields').classList.add('active');
        }
        
        const categorySelect = document.getElementById('categorySelect');
        for (let i = 0; i < categorySelect.options.length; i++) {
            const option = categorySelect.options[i];
            if (option.value === '') continue;
            const optionType = option.getAttribute('data-type');
            option.style.display = optionType === type ? '' : 'none';
        }
    }
    
    selectType('rental');
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>