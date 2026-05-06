<?php
// user/post_listing.php - Complete with Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Post New Listing';
ob_start();

$conn = getDbConnection();
$error = '';
$success = '';
$form_data = [];

// Get categories
$categories = $conn->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY type, name");

// Create uploads directory if not exists
$upload_dir = '../uploads/listings/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize all inputs
    $form_data = [
        'type' => sanitizeString($_POST['type'] ?? ''),
        'title' => sanitizeString($_POST['title'] ?? ''),
        'description' => sanitizeString($_POST['description'] ?? ''),
        'price' => sanitizeFloat($_POST['price'] ?? 0),
        'category_id' => sanitizeInt($_POST['category_id'] ?? 0),
        'location' => sanitizeString($_POST['location'] ?? ''),
        // Rental fields
        'bedrooms' => sanitizeInt($_POST['bedrooms'] ?? 0),
        'bathrooms' => sanitizeInt($_POST['bathrooms'] ?? 0),
        'area' => sanitizeFloat($_POST['area'] ?? 0),
        // Car fields
        'year' => sanitizeInt($_POST['year'] ?? 0),
        'mileage' => sanitizeInt($_POST['mileage'] ?? 0),
        'fuel_type' => sanitizeString($_POST['fuel_type'] ?? ''),
        'transmission' => sanitizeString($_POST['transmission'] ?? ''),
        // Job fields
        'employment_type' => sanitizeString($_POST['employment_type'] ?? ''),
        'requirements' => sanitizeString($_POST['requirements'] ?? '')
    ];
    
    $errors = [];
    
    // ============================================
    // VALIDATION RULES
    // ============================================
    
    // Listing type validation
    if (!validateListingType($form_data['type'])) {
        $errors[] = "Invalid listing type selected";
    }
    
    // Title validation
    if (empty($form_data['title'])) {
        $errors[] = "Title is required";
    } elseif (!validateLength($form_data['title'], 3, 100)) {
        $errors[] = "Title must be between 3 and 100 characters";
    } elseif (!validateAlphaNumeric($form_data['title'], true)) {
        $errors[] = "Title can only contain letters, numbers, and spaces";
    }
    
    // Description validation
    if (empty($form_data['description'])) {
        $errors[] = "Description is required";
    } elseif (!validateLength($form_data['description'], 20, 5000)) {
        $errors[] = "Description must be between 20 and 5000 characters";
    }
    
    // Price validation
    if ($form_data['price'] <= 0) {
        $errors[] = "Please enter a valid price greater than 0";
    } elseif (!validateAmount($form_data['price'])) {
        $errors[] = "Please enter a valid price (max 2 decimal places)";
    } elseif ($form_data['price'] > 100000000) {
        $errors[] = "Price cannot exceed 100,000,000 ETB";
    }
    
    // Category validation
    if ($form_data['category_id'] <= 0) {
        $errors[] = "Please select a category";
    }
    
    // Location validation
    if (!empty($form_data['location']) && !validateLength($form_data['location'], 2, 200)) {
        $errors[] = "Location must be between 2 and 200 characters";
    }
    
    // Type-specific validations
    if ($form_data['type'] == 'rental') {
        if ($form_data['bedrooms'] < 0 || $form_data['bedrooms'] > 50) {
            $errors[] = "Bedrooms must be between 0 and 50";
        }
        if ($form_data['bathrooms'] < 0 || $form_data['bathrooms'] > 50) {
            $errors[] = "Bathrooms must be between 0 and 50";
        }
        if ($form_data['area'] < 0 || $form_data['area'] > 10000) {
            $errors[] = "Area must be between 0 and 10,000 sqm";
        }
    }
    
    if ($form_data['type'] == 'product') {
        if ($form_data['year'] < 1950 || $form_data['year'] > date('Y') + 1) {
            $errors[] = "Please enter a valid year between 1950 and " . (date('Y') + 1);
        }
        if ($form_data['mileage'] < 0 || $form_data['mileage'] > 1000000) {
            $errors[] = "Mileage must be between 0 and 1,000,000 km";
        }
        $valid_fuel = ['Petrol', 'Diesel', 'Electric', 'Hybrid'];
        if (!empty($form_data['fuel_type']) && !in_array($form_data['fuel_type'], $valid_fuel)) {
            $errors[] = "Please select a valid fuel type";
        }
        $valid_transmission = ['Manual', 'Automatic', 'Semi-Automatic'];
        if (!empty($form_data['transmission']) && !in_array($form_data['transmission'], $valid_transmission)) {
            $errors[] = "Please select a valid transmission type";
        }
    }
    
    if ($form_data['type'] == 'job') {
        $valid_employment = ['Full-time', 'Part-time', 'Contract', 'Remote', 'Internship'];
        if (!empty($form_data['employment_type']) && !in_array($form_data['employment_type'], $valid_employment)) {
            $errors[] = "Please select a valid employment type";
        }
        if (!empty($form_data['requirements']) && !validateLength($form_data['requirements'], 10, 2000)) {
            $errors[] = "Requirements must be between 10 and 2000 characters";
        }
    }
    
    // File upload validation
    $cover_image = '';
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $file_errors = validateFileUpload($_FILES['cover_image']);
        if (!empty($file_errors)) {
            $errors = array_merge($errors, $file_errors);
        }
    } else {
        $errors[] = "Cover image is required";
    }
    
    // Process if no errors
    if (empty($errors)) {
        // Upload cover image
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadImage($_FILES['cover_image'], $upload_dir);
            if ($upload['success']) {
                $cover_image = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }
        
        // Upload gallery images
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
                    $file_errors = validateFileUpload($file);
                    if (empty($file_errors)) {
                        $upload = uploadImage($file, $upload_dir);
                        if ($upload['success']) {
                            $gallery_images[] = $upload['filename'];
                        }
                    }
                }
            }
        }
        
        $gallery_json = !empty($gallery_images) ? json_encode($gallery_images) : null;
        
        // Build additional details JSON
        $additional_details = [];
        if ($form_data['type'] == 'rental') {
            $additional_details = [
                'bedrooms' => $form_data['bedrooms'],
                'bathrooms' => $form_data['bathrooms'],
                'area' => $form_data['area']
            ];
        } elseif ($form_data['type'] == 'product') {
            $additional_details = [
                'year' => $form_data['year'],
                'mileage' => $form_data['mileage'],
                'fuel_type' => $form_data['fuel_type'],
                'transmission' => $form_data['transmission']
            ];
        } elseif ($form_data['type'] == 'job') {
            $additional_details = [
                'employment_type' => $form_data['employment_type'],
                'requirements' => $form_data['requirements']
            ];
        }
        
        $additional_json = !empty($additional_details) ? json_encode($additional_details) : null;
        $user_id = $_SESSION['user_id'];
        
        // Insert listing
        $stmt = $conn->prepare("
            INSERT INTO listings (seller_id, type, title, description, price, category_id, location, cover_image, gallery_images, additional_details, approval_status, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')
        ");
        $stmt->bind_param("isssdissss", 
            $user_id, 
            $form_data['type'], 
            $form_data['title'], 
            $form_data['description'], 
            $form_data['price'], 
            $form_data['category_id'], 
            $form_data['location'], 
            $cover_image, 
            $gallery_json, 
            $additional_json
        );
        
        if ($stmt->execute()) {
            $listing_id = $conn->insert_id;
            $success = "Listing submitted for admin approval. You will be notified once approved.";
            header("Refresh: 3; URL=listings.php");
        } else {
            $error = "Failed to post listing: " . $conn->error;
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$conn->close();
?>

<!-- Rest of the HTML form (same as before but with error display) -->
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
            <div id="propertyFields" class="dynamic-fields">
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