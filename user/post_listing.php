<?php
// user/post_listing.php - Post listings for Houses, Cars, Jobs

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
    $type = $_POST['type'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = floatval($_POST['price']);
    $category_id = intval($_POST['category_id']);
    $location = trim($_POST['location']);
    $user_id = $_SESSION['user_id'];
    
    // Additional fields based on type
    $bedrooms = isset($_POST['bedrooms']) ? intval($_POST['bedrooms']) : null;
    $bathrooms = isset($_POST['bathrooms']) ? intval($_POST['bathrooms']) : null;
    $area = isset($_POST['area']) ? floatval($_POST['area']) : null;
    $year = isset($_POST['year']) ? intval($_POST['year']) : null;
    $mileage = isset($_POST['mileage']) ? intval($_POST['mileage']) : null;
    $fuel_type = isset($_POST['fuel_type']) ? $_POST['fuel_type'] : null;
    $transmission = isset($_POST['transmission']) ? $_POST['transmission'] : null;
    $requirements = isset($_POST['requirements']) ? trim($_POST['requirements']) : null;
    $employment_type = isset($_POST['employment_type']) ? $_POST['employment_type'] : null;
    
    // Handle cover image
    $cover_image = '';
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadImage($_FILES['cover_image'], $upload_dir);
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
                $upload = uploadImage($file, $upload_dir);
                if ($upload['success']) {
                    $gallery_images[] = $upload['filename'];
                }
            }
        }
    }
    
    $gallery_json = !empty($gallery_images) ? json_encode($gallery_images) : null;
    
    // Build additional details JSON
    $additional_details = [];
    if ($type == 'rental') {
        $additional_details['bedrooms'] = $bedrooms;
        $additional_details['bathrooms'] = $bathrooms;
        $additional_details['area'] = $area;
    } elseif ($type == 'product') {
        $additional_details['year'] = $year;
        $additional_details['mileage'] = $mileage;
        $additional_details['fuel_type'] = $fuel_type;
        $additional_details['transmission'] = $transmission;
    } elseif ($type == 'job') {
        $additional_details['requirements'] = $requirements;
        $additional_details['employment_type'] = $employment_type;
    }
    
    $additional_json = !empty($additional_details) ? json_encode($additional_details) : null;
    
    if (empty($title) || empty($description) || $price <= 0) {
        $error = 'Please fill in all required fields';
    } elseif (empty($cover_image)) {
        $error = 'Please upload a cover image';
    } else {
        // Insert listing with pending approval
        $stmt = $conn->prepare("INSERT INTO listings (seller_id, type, title, description, price, category_id, location, cover_image, gallery_images, additional_details, approval_status, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')");
        $stmt->bind_param("isssdissss", $user_id, $type, $title, $description, $price, $category_id, $location, $cover_image, $gallery_json, $additional_json);
        
        if ($stmt->execute()) {
            $listing_id = $conn->insert_id;
            $success = "Listing submitted for admin approval. You will be notified once approved.";
            header("Refresh: 3; URL=listings.php");
        } else {
            $error = "Failed to post listing: " . $conn->error;
        }
    }
}

$conn->close();
?>

<style>
    .post-form { max-width: 900px; margin: 0 auto; }
    
    .card {
        background: white;
        border-radius: 28px;
        padding: 32px;
        box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
    }
    
    .card h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .card > p {
        color: #64748b;
        margin-bottom: 28px;
        padding-bottom: 16px;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .form-group {
        margin-bottom: 24px;
    }
    
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #1e293b;
        font-size: 14px;
    }
    
    label .required {
        color: #ef4444;
    }
    
    input, select, textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
    }
    
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    textarea {
        resize: vertical;
        min-height: 120px;
    }
    
    .type-selector {
        display: flex;
        gap: 16px;
        margin-bottom: 28px;
    }
    
    .type-option {
        flex: 1;
        padding: 20px;
        border: 2px solid #e2e8f0;
        border-radius: 16px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .type-option:hover {
        border-color: #667eea;
        background: #f8fafc;
    }
    
    .type-option.selected {
        border-color: #667eea;
        background: #eef2ff;
    }
    
    .type-option i {
        font-size: 36px;
        margin-bottom: 12px;
        display: block;
    }
    
    .type-option strong {
        display: block;
        margin-bottom: 4px;
        font-size: 16px;
    }
    
    .type-option small {
        font-size: 11px;
        color: #64748b;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    
    .form-row-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }
    
    .image-preview {
        display: flex;
        gap: 12px;
        margin-top: 12px;
        flex-wrap: wrap;
    }
    
    .preview-item {
        position: relative;
        width: 100px;
        height: 100px;
    }
    
    .preview-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 12px;
        border: 2px solid #e2e8f0;
    }
    
    .remove-image {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #ef4444;
        color: white;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 12px;
    }
    
    .btn-submit {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 16px;
    }
    
    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .error {
        background: #fee2e2;
        color: #dc2626;
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        border-left: 4px solid #dc2626;
    }
    
    .success {
        background: #d1fae5;
        color: #059669;
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        border-left: 4px solid #059669;
    }
    
    .info-text {
        font-size: 12px;
        color: #64748b;
        margin-top: 6px;
    }
    
    .approval-note {
        background: #fef3c7;
        padding: 16px;
        border-radius: 12px;
        margin-top: 24px;
        text-align: center;
        color: #92400e;
        font-size: 13px;
    }
    
    .dynamic-fields {
        display: none;
    }
    
    .dynamic-fields.active {
        display: block;
    }
    
    @media (max-width: 640px) {
        .card { padding: 20px; }
        .type-selector { flex-direction: column; }
        .form-row, .form-row-3 { grid-template-columns: 1fr; }
        .preview-item { width: 80px; height: 80px; }
    }
</style>

<div class="post-form">
    <div class="card">
        <h1><i class="fas fa-plus-circle"></i> Post New Listing</h1>
        <p>Your listing will be reviewed by admin before going live</p>
        
        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
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
                <label>Title *</label>
                <input type="text" name="title" required placeholder="e.g., Modern 2BR Apartment for Rent, 2020 Toyota Camry, Senior Web Developer Needed">
            </div>
            
            <div class="form-group">
                <label>Category *</label>
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
            
            <!-- Property Fields (Houses) -->
            <div id="propertyFields" class="dynamic-fields">
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
                        <input type="number" name="area" step="1" placeholder="Size in square meters">
                    </div>
                </div>
            </div>
            
            <!-- Car Fields -->
            <div id="carFields" class="dynamic-fields">
                <div class="form-row">
                    <div class="form-group">
                        <label>Year</label>
                        <input type="number" name="year" min="1980" max="2025" placeholder="Manufacturing year">
                    </div>
                    <div class="form-group">
                        <label>Mileage (km)</label>
                        <input type="number" name="mileage" step="1" placeholder="Kilometers driven">
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
                    <label>Requirements for Applicants</label>
                    <textarea name="requirements" rows="4" placeholder="List requirements for applicants:
• Required qualifications
• Experience needed
• Skills required
• Benefits offered"></textarea>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" required placeholder="Describe your property, car, or job opportunity in detail..."></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Price (ETB) *</label>
                    <input type="number" name="price" step="0.01" min="1" required placeholder="0.00">
                    <div class="info-text">For properties: monthly rent or sale price | For cars: selling price | For jobs: monthly salary</div>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g., Addis Ababa, Bole, Addis Ketema">
                </div>
            </div>
            
            <div class="form-group">
                <label>Cover Image *</label>
                <input type="file" name="cover_image" accept="image/*" required onchange="previewCoverImage(this)">
                <div class="info-text">Main image displayed in listings (max 5MB)</div>
                <div id="coverPreview" class="image-preview"></div>
            </div>
            
            <div class="form-group">
                <label>Gallery Images (Optional)</label>
                <input type="file" name="gallery_images[]" accept="image/*" multiple onchange="previewGalleryImages(this)">
                <div class="info-text">Additional images (max 5MB each)</div>
                <div id="galleryPreview" class="image-preview"></div>
            </div>
            
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit for Review</button>
        </form>
        
        <div class="approval-note">
            <i class="fas fa-clock"></i> <strong>Note:</strong> Your listing will be reviewed by an admin. You'll be notified once approved. After approval, you'll need to pay the deposit and commission to activate your listing.
        </div>
    </div>
</div>

<script>
    function selectType(type) {
        document.getElementById('listingType').value = type;
        document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('selected'));
        document.querySelector(`.type-option[data-type="${type}"]`).classList.add('selected');
        
        // Show/hide dynamic fields
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
        
        // Filter categories by type
        const categorySelect = document.getElementById('categorySelect');
        for (let i = 0; i < categorySelect.options.length; i++) {
            const option = categorySelect.options[i];
            if (option.value === '') continue;
            const optionType = option.getAttribute('data-type');
            option.style.display = optionType === type ? '' : 'none';
        }
    }
    
    let coverImageFile = null;
    let galleryFiles = [];
    
    function previewCoverImage(input) {
        const preview = document.getElementById('coverPreview');
        preview.innerHTML = '';
        if (input.files && input.files[0]) {
            coverImageFile = input.files[0];
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'preview-item';
                div.innerHTML = `<img src="${e.target.result}" alt="Cover"><div class="remove-image" onclick="removeCoverImage()">×</div>`;
                preview.appendChild(div);
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
    
    function removeCoverImage() {
        document.getElementById('coverPreview').innerHTML = '';
        document.querySelector('input[name="cover_image"]').value = '';
        coverImageFile = null;
    }
    
    function previewGalleryImages(input) {
        const preview = document.getElementById('galleryPreview');
        preview.innerHTML = '';
        galleryFiles = [];
        if (input.files) {
            Array.from(input.files).forEach((file, index) => {
                galleryFiles.push(file);
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `<img src="${e.target.result}" alt="Gallery"><div class="remove-image" onclick="removeGalleryImage(${index})">×</div>`;
                    preview.appendChild(div);
                }
                reader.readAsDataURL(file);
            });
        }
    }
    
    function removeGalleryImage(index) {
        galleryFiles.splice(index, 1);
        const input = document.querySelector('input[name="gallery_images[]"]');
        const dt = new DataTransfer();
        galleryFiles.forEach(file => dt.items.add(file));
        input.files = dt.files;
        previewGalleryImages(input);
    }
    
    // Initialize with rental type
    selectType('rental');
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>