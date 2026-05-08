<?php
// user/profile.php - Works with any column configuration

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'My Profile';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get existing columns
$columns_result = $conn->query("SHOW COLUMNS FROM users");
$existing_columns = [];
while ($col = $columns_result->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}

// Get user data - only select columns that exist
$select_fields = ['id'];
$available_fields = ['full_name', 'email', 'phone', 'role', 'balance', 'is_verified', 'is_suspended', 'address', 'city', 'bio', 'avatar', 'created_at', 'updated_at', 'last_login'];

foreach ($available_fields as $field) {
    if (in_array($field, $existing_columns)) {
        $select_fields[] = $field;
    }
}

$select_sql = "SELECT " . implode(", ", $select_fields) . " FROM users WHERE id = $user_id";
$user = $conn->query($select_sql)->fetch_assoc();

// Get statistics
$stats = [
    'listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id")->fetch_assoc()['count'] ?? 0,
    'active_listings' => $conn->query("SELECT COUNT(*) as count FROM listings WHERE seller_id = $user_id AND status = 'active' AND approval_status = 'approved'")->fetch_assoc()['count'] ?? 0,
    'transactions' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE buyer_id = $user_id OR seller_id = $user_id")->fetch_assoc()['count'] ?? 0,
    'completed_deals' => $conn->query("SELECT COUNT(*) as count FROM transactions WHERE (buyer_id = $user_id OR seller_id = $user_id) AND status = 'completed'")->fetch_assoc()['count'] ?? 0,
];

$message = '';
$error = '';

// Handle profile update - only update columns that exist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $update_fields = [];
    $update_values = [];
    $types = "";
    
    if (in_array('full_name', $existing_columns)) {
        $full_name = trim($_POST['full_name'] ?? '');
        if (empty($full_name)) {
            $error = "Full name is required";
        } else {
            $update_fields[] = "full_name = ?";
            $update_values[] = $full_name;
            $types .= "s";
            $_SESSION['user_name'] = $full_name;
        }
    }
    
    if (in_array('phone', $existing_columns) && !$error) {
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
        $update_fields[] = "phone = ?";
        $update_values[] = $phone;
        $types .= "s";
    }
    
    if (in_array('address', $existing_columns) && !$error) {
        $address = trim($_POST['address'] ?? '');
        $update_fields[] = "address = ?";
        $update_values[] = $address;
        $types .= "s";
    }
    
    if (in_array('city', $existing_columns) && !$error) {
        $city = trim($_POST['city'] ?? '');
        $update_fields[] = "city = ?";
        $update_values[] = $city;
        $types .= "s";
    }
    
    if (in_array('bio', $existing_columns) && !$error) {
        $bio = trim($_POST['bio'] ?? '');
        $update_fields[] = "bio = ?";
        $update_values[] = $bio;
        $types .= "s";
    }
    
    if (!empty($update_fields) && !$error) {
        $update_values[] = $user_id;
        $types .= "i";
        $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$update_values);
        
        if ($stmt->execute()) {
            $message = "Profile updated successfully!";
            // Refresh user data
            $select_sql = "SELECT " . implode(", ", $select_fields) . " FROM users WHERE id = $user_id";
            $user = $conn->query($select_sql)->fetch_assoc();
        } else {
            $error = "Failed to update profile: " . $conn->error;
        }
    }
}

// Handle avatar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar']) && isset($_FILES['avatar'])) {
    $upload_dir = '../uploads/avatars/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_errors = [];
    
    if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $file_errors[] = "Upload failed";
    }
    if ($_FILES['avatar']['size'] > 2097152) {
        $file_errors[] = "File too large (max 2MB)";
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        $file_errors[] = "Invalid file type. Use JPG, PNG, GIF, or WEBP";
    }
    
    if (empty($file_errors)) {
        $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
        $target_file = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $target_file)) {
            if (!empty($user['avatar']) && file_exists($upload_dir . $user['avatar'])) {
                unlink($upload_dir . $user['avatar']);
            }
            
            if (in_array('avatar', $existing_columns)) {
                $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $stmt->bind_param("si", $filename, $user_id);
                $stmt->execute();
            }
            $message = "Profile picture updated!";
            $user = $conn->query($select_sql)->fetch_assoc();
        } else {
            $error = "Failed to upload image";
        }
    } else {
        $error = implode('<br>', $file_errors);
    }
}

$conn->close();
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #f0f2f5; font-family: 'Inter', sans-serif; }
    
    .profile-page { max-width: 1200px; margin: 0 auto; padding: 20px; }
    
    /* Hero Section */
    .hero-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 30px;
        padding: 40px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }
    
    .hero-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        animation: moveBackground 40s linear infinite;
    }
    
    @keyframes moveBackground {
        0% { transform: translate(0, 0); }
        100% { transform: translate(30px, 30px); }
    }
    
    .hero-content {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 30px;
    }
    
    .user-info { display: flex; align-items: center; gap: 25px; flex-wrap: wrap; }
    
    .avatar-large {
        width: 100px;
        height: 100px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
        border: 3px solid rgba(255,255,255,0.5);
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
        overflow: hidden;
    }
    
    .avatar-large:hover { transform: scale(1.05); border-color: white; }
    .avatar-large img { width: 100%; height: 100%; object-fit: cover; }
    
    .avatar-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s;
        font-size: 24px;
    }
    
    .avatar-large:hover .avatar-overlay { opacity: 1; }
    
    .user-details h1 {
        font-size: 28px;
        font-weight: 700;
        color: white;
        margin-bottom: 8px;
    }
    
    .user-details p {
        color: rgba(255,255,255,0.9);
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }
    
    .badge-verified {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
    }
    
    .stats-row {
        display: flex;
        gap: 30px;
        background: rgba(255,255,255,0.15);
        padding: 15px 25px;
        border-radius: 50px;
        backdrop-filter: blur(10px);
    }
    
    .stat-item { text-align: center; }
    .stat-number { font-size: 24px; font-weight: 700; color: white; }
    .stat-label { font-size: 11px; color: rgba(255,255,255,0.8); text-transform: uppercase; }
    
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 25px rgba(0,0,0,0.1);
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #667eea20, #764ba220);
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    
    .stat-info h3 { font-size: 24px; font-weight: 700; color: #1e293b; }
    .stat-info p { font-size: 12px; color: #64748b; }
    
    /* Layout */
    .profile-layout {
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        gap: 25px;
    }
    
    /* Cards */
    .info-card, .edit-card {
        background: white;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .card-title {
        padding: 20px 25px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 16px;
        font-weight: 600;
        color: #1e293b;
    }
    
    .card-title i { margin-right: 10px; color: #667eea; }
    
    .info-list { padding: 20px 25px; }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .info-row:last-child { border-bottom: none; }
    
    .info-label {
        color: #64748b;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-label i { width: 18px; color: #667eea; }
    .info-value { font-weight: 500; color: #1e293b; font-size: 13px; text-align: right; }
    
    /* Form */
    .form-container { padding: 25px; }
    
    .form-group { margin-bottom: 20px; }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
        font-size: 13px;
    }
    
    .form-group label i { margin-right: 8px; color: #667eea; }
    
    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
    }
    
    .form-group input:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .form-group input:disabled {
        background: #f8fafc;
        color: #64748b;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .btn-save {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 10px;
    }
    
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .quick-actions {
        padding: 0 25px 25px 25px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .action-btn {
        flex: 1;
        padding: 10px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 40px;
        text-align: center;
        text-decoration: none;
        color: #64748b;
        font-size: 12px;
        font-weight: 500;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    
    .action-btn:hover {
        border-color: #667eea;
        color: #667eea;
        transform: translateY(-2px);
    }
    
    /* Alert */
    .alert {
        padding: 14px 18px;
        border-radius: 16px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #059669;
        border-left: 4px solid #059669;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border-left: 4px solid #dc2626;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-content {
        background: white;
        border-radius: 28px;
        padding: 30px;
        width: 450px;
        max-width: 90%;
        animation: modalIn 0.3s ease;
    }
    
    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.9); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .modal-header h3 { font-size: 20px; font-weight: 600; }
    
    .close-modal {
        cursor: pointer;
        font-size: 28px;
        color: #94a3b8;
        transition: color 0.3s;
    }
    
    .close-modal:hover { color: #ef4444; }
    
    .hint-text {
        font-size: 11px;
        color: #64748b;
        margin-top: 6px;
        display: block;
    }
    
    @media (max-width: 900px) {
        .profile-layout { grid-template-columns: 1fr; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .hero-content { flex-direction: column; text-align: center; }
        .user-info { justify-content: center; }
        .form-row { grid-template-columns: 1fr; }
    }
    
    @media (max-width: 500px) {
        .stats-grid { grid-template-columns: 1fr; }
        .stats-row { flex-wrap: wrap; justify-content: center; border-radius: 20px; }
        .quick-actions { flex-direction: column; }
    }
</style>

<div class="profile-page">
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="hero-content">
            <div class="user-info">
                <div class="avatar-large" onclick="openAvatarModal()">
                    <?php 
                    $avatar_path = !empty($user['avatar']) && file_exists('../uploads/avatars/' . $user['avatar']) 
                        ? '/broker_system/uploads/avatars/' . $user['avatar'] 
                        : null;
                    ?>
                    <?php if ($avatar_path): ?>
                        <img src="<?php echo $avatar_path; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                    <?php endif; ?>
                    <div class="avatar-overlay">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                <div class="user-details">
                    <h1><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></h1>
                    <p>
                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                        <?php if (isset($user['is_verified']) && $user['is_verified']): ?>
                            <span class="badge-verified"><i class="fas fa-check-circle"></i> Verified</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['active_listings']; ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['completed_deals']; ?></div>
                    <div class="stat-label">Deals</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-info">
                <h3><?php echo $stats['listings']; ?></h3>
                <p>Total Listings</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔄</div>
            <div class="stat-info">
                <h3><?php echo $stats['transactions']; ?></h3>
                <p>Transactions</p>
            </div>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="profile-layout">
        <!-- Left Column - Information -->
        <div class="info-card">
            <div class="card-title">
                <i class="fas fa-info-circle"></i> About
            </div>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-user"></i> Full Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['full_name'] ?? 'Not set'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                </div>
                <?php if (in_array('phone', $existing_columns)): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></span>
                </div>
                <?php endif; ?>
                <?php if (in_array('city', $existing_columns)): ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['city'] ?? 'Not provided'); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-tag"></i> Account Type</span>
                    <span class="info-value"><?php echo ucfirst($user['role'] ?? 'User'); ?></span>
                </div>
            </div>

            <?php if (in_array('bio', $existing_columns)): ?>
            <div class="card-title" style="border-top: 1px solid #e2e8f0;">
                <i class="fas fa-align-left"></i> Bio
            </div>
            <div class="info-list">
                <p style="font-size: 13px; color: #475569; line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($user['bio'] ?? 'No bio added yet.')); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="card-title" style="border-top: 1px solid #e2e8f0;">
                <i class="fas fa-rocket"></i> Quick Actions
            </div>
            <div class="quick-actions">
                <a href="post_listing.php" class="action-btn"><i class="fas fa-plus-circle"></i> Post</a>
                <a href="listings.php" class="action-btn"><i class="fas fa-box"></i> Listings</a>
                <a href="wallet.php" class="action-btn"><i class="fas fa-wallet"></i> Wallet</a>
                <a href="transactions.php" class="action-btn"><i class="fas fa-exchange-alt"></i> History</a>
                <a href="chat.php" class="action-btn"><i class="fas fa-comments"></i> Chat</a>
            </div>
        </div>

        <!-- Right Column - Edit Form -->
        <div class="edit-card">
            <div class="card-title">
                <i class="fas fa-edit"></i> Edit Profile
            </div>
            <div class="form-container">
                <?php if ($message): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <?php if (in_array('full_name', $existing_columns)): ?>
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                        <span class="hint-text">Email cannot be changed</span>
                    </div>
                    
                    <div class="form-row">
                        <?php if (in_array('phone', $existing_columns)): ?>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+251XXXXXXXXX">
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array('city', $existing_columns)): ?>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>" placeholder="Your city">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (in_array('address', $existing_columns)): ?>
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" placeholder="Your full address">
                    </div>
                    <?php endif; ?>
                    
                    <?php if (in_array('bio', $existing_columns)): ?>
                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Bio</label>
                        <textarea name="bio" rows="4" placeholder="Tell others about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" name="update_profile" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Avatar Upload Modal -->
<div id="avatarModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-camera"></i> Change Profile Picture</h3>
            <span class="close-modal" onclick="closeAvatarModal()">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Choose New Image</label>
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <span class="hint-text"><i class="fas fa-info-circle"></i> Max 2MB. Recommended: Square image, min 200x200px</span>
            </div>
            <button type="submit" name="upload_avatar" class="btn-save">
                <i class="fas fa-upload"></i> Upload Avatar
            </button>
        </form>
    </div>
</div>

<script>
    function openAvatarModal() {
        document.getElementById('avatarModal').style.display = 'flex';
    }
    
    function closeAvatarModal() {
        document.getElementById('avatarModal').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('avatarModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>