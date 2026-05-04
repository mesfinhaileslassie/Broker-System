<?php
// auth/register.php - User registration (FIXED - no duplicate session)

// Remove this line if present:
// session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';  // This will handle session_start()

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($full_name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        $conn = getDbConnection();
        
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Email already registered';
        } else {
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, is_verified) VALUES (?, ?, ?, ?, 'user', 1)");
            $stmt->bind_param("ssss", $full_name, $email, $phone, $password_hash);
            
            if ($stmt->execute()) {
                $user_id = $conn->insert_id;
                
                // Add welcome bonus to wallet
                $conn->query("UPDATE users SET balance = balance + 100 WHERE id = $user_id");
                
                // Record welcome bonus transaction
                $conn->query("INSERT INTO wallet_transactions (user_id, amount, type, description) VALUES ($user_id, 100, 'deposit', 'Welcome bonus')");
                
                // Auto login
                $userBalance = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
                userLogin($user_id, $full_name, $email, 'user', $userBalance['balance']);
                
                $success = 'Registration successful! Redirecting to dashboard...';
                header('Refresh: 2; URL=/broker_system/user/dashboard.php');
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
        
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .register-container { background: white; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); width: 100%; max-width: 500px; padding: 40px; }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { font-size: 28px; color: #667eea; }
        .logo p { font-size: 14px; color: #666; margin-top: 8px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; }
        input:focus { outline: none; border-color: #667eea; }
        button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.3s; }
        button:hover { background: #5a67d8; }
        .error { background: #fee; color: #c33; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .login-link { text-align: center; margin-top: 20px; font-size: 14px; }
        .login-link a { color: #667eea; text-decoration: none; }
        .info-text { font-size: 12px; color: #888; margin-top: 4px; }
        .welcome-bonus { background: #fff3cd; padding: 12px; border-radius: 8px; margin-top: 16px; font-size: 13px; text-align: center; color: #856404; }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <h1>🏪 Ethio Brokerplace</h1>
            <p>Create your account</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" placeholder="+251XXXXXXXXX" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                <div class="info-text">Optional, but recommended for verification</div>
            </div>
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required minlength="6">
                <div class="info-text">Minimum 6 characters</div>
            </div>
            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit"><i class="fas fa-user-plus"></i> Register</button>
        </form>
        
        <div class="welcome-bonus">
            <i class="fas fa-gift"></i> Get 100 ETB welcome bonus on registration!
        </div>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
        
        <div class="login-link" style="margin-top: 10px;">
            <a href="/broker_system/admin/login.php">Admin Login →</a>
        </div>
    </div>
</body>
</html>