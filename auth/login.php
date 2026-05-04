<?php
// auth/login.php - User login (FIXED - no duplicate session)

// Remove this line if present:
// session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password';
    } else {
        $conn = getDbConnection();
        
        $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, role, balance, is_suspended FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if ($user['is_suspended']) {
                $error = 'Your account has been suspended. Please contact support.';
            } elseif (password_verify($password, $user['password_hash'])) {
                userLogin($user['id'], $user['full_name'], $user['email'], $user['role'], $user['balance']);
                
                // Redirect to intended page or dashboard
                $redirect = $_SESSION['redirect_after_login'] ?? '/broker_system/user/dashboard.php';
                unset($_SESSION['redirect_after_login']);
                header("Location: $redirect");
                exit;
            } else {
                $error = 'Invalid email or password';
            }
        } else {
            $error = 'Invalid email or password';
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
    <title>Login - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-container { background: white; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); width: 100%; max-width: 420px; padding: 40px; }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { font-size: 28px; color: #667eea; }
        .form-group { margin-bottom: 24px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        input:focus { outline: none; border-color: #667eea; }
        button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
        button:hover { background: #5a67d8; }
        .error { background: #fee; color: #c33; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .register-link { text-align: center; margin-top: 20px; font-size: 14px; }
        .register-link a { color: #667eea; text-decoration: none; }
        .admin-link { text-align: center; margin-top: 15px; font-size: 12px; }
        .admin-link a { color: #999; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>🏪 Ethio Brokerplace</h1>
            <p>Welcome back!</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@example.com" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit"><i class="fas fa-sign-in-alt"></i> Login</button>
        </form>
        
        <div class="register-link">
            Don't have an account? <a href="register.php">Create one (Get 100 ETB bonus!)</a>
        </div>
        
        <div class="admin-link">
            <a href="/broker_system/admin/login.php">Admin Login →</a>
        </div>
    </div>
</body>
</html>