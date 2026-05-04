<?php
// user/profile.php - Profile Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: /broker_system/auth/login.php');
    exit;
}

$page_title = 'My Profile';
ob_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// Get user data
$user = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();

$message = '';
$error = '';

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $city = trim($_POST['city']);
    
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, address = ?, city = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $full_name, $phone, $address, $city, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['user_name'] = $full_name;
        $message = "Profile updated successfully!";
        // Refresh user data
        $user = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();
    } else {
        $error = "Failed to update profile";
    }
}

$conn->close();
?>

<style>
    .page-header {
        margin-bottom: 28px;
    }
    
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
    }
    
    .profile-card {
        background: white;
        border-radius: 20px;
        padding: 32px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .profile-header {
        display: flex;
        align-items: center;
        gap: 24px;
        margin-bottom: 32px;
        padding-bottom: 24px;
        border-bottom: 1px solid #f1f5f9;
        flex-wrap: wrap;
    }
    
    .profile-avatar {
        width: 100px;
        height: 100px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: white;
    }
    
    .profile-info h2 {
        font-size: 24px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }
    
    .profile-info p {
        color: #64748b;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
    }
    
    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .form-group input:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    
    .btn {
        padding: 12px 28px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    }
    
    .message {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    .message-success {
        background: #d1fae5;
        color: #059669;
        border: 1px solid #a7f3d0;
    }
    
    .message-error {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        .profile-header {
            flex-direction: column;
            text-align: center;
        }
    }
</style>

<div class="page-header">
    <h1>My Profile</h1>
    <p>Manage your personal information</p>
</div>

<div class="profile-card">
    <?php if ($message): ?>
        <div class="message message-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message message-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="profile-header">
        <div class="profile-avatar">
            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
        </div>
        <div class="profile-info">
            <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
            <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
            <p><i class="fas fa-calendar"></i> Member since <?php echo date('F Y', strtotime($user['created_at'])); ?></p>
        </div>
    </div>
    
    <form method="POST">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
        </div>
        
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="background: #f1f5f9;">
            <small style="color: #64748b;">Email cannot be changed</small>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+251XXXXXXXXX">
            </div>
            <div class="form-group">
                <label>City</label>
                <input type="text" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>" placeholder="e.g., Addis Ababa">
            </div>
        </div>
        
        <div class="form-group">
            <label>Address</label>
            <textarea name="address" rows="3" placeholder="Your full address"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
        </div>
        
        <button type="submit" class="btn"><i class="fas fa-save"></i> Save Changes</button>
    </form>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>