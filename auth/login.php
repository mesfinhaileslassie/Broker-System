<?php
// auth/login.php - Modern Unified Login Page

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if ($_SESSION['user_role'] == 'admin') {
        header('Location: /broker_system/admin/dashboard.php');
    } else {
        header('Location: /broker_system/user/dashboard.php');
    }
    exit;
}

$error = '';
$success = '';

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
                
                if ($user['role'] == 'admin') {
                    header('Location: /broker_system/admin/dashboard.php');
                } else {
                    $redirect = $_SESSION['redirect_after_login'] ?? '/broker_system/user/dashboard.php';
                    unset($_SESSION['redirect_after_login']);
                    header("Location: $redirect");
                }
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
    <title>Welcome Back - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated background shapes */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: moveBackground 60s linear infinite;
            pointer-events: none;
        }
        
        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(100px, 100px); }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
        }
        
        .brand-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 32px;
            text-align: center;
            color: white;
        }
        
        .brand-header h1 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .brand-header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .brand-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .form-container {
            padding: 40px 32px;
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
        
        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-group i {
            position: absolute;
            left: 16px;
            color: #94a3b8;
            font-size: 18px;
        }
        
        input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        .password-toggle {
            position: absolute;
            right: 16px;
            left: auto;
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.3s;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
            position: relative;
            overflow: hidden;
        }
        
        button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        button:hover::before {
            width: 300px;
            height: 300px;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
        }
        
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 13px;
            border-left: 4px solid #dc2626;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .register-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }
        
        .register-link p {
            color: #64748b;
            font-size: 14px;
        }
        
        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .register-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .features {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }
        
        .feature {
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }
        
        .feature i {
            font-size: 20px;
            color: #667eea;
            margin-bottom: 6px;
            display: block;
        }
        
        @media (max-width: 480px) {
            .login-container {
                border-radius: 24px;
            }
            
            .brand-header {
                padding: 30px 24px;
            }
            
            .form-container {
                padding: 30px 24px;
            }
            
            .brand-header h1 {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="brand-header">
            <div class="brand-icon">
                <i class="fas fa-store"></i>
            </div>
            <h1>Welcome Back</h1>
            <p>Sign in to continue to Ethio Brokerplace</p>
        </div>
        
        <div class="form-container">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <div class="input-group">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" placeholder="you@example.com" required autofocus>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="password" placeholder="Enter your password" required>
                        <i class="fas fa-eye password-toggle" id="togglePassword" style="left: auto; right: 16px; cursor: pointer;"></i>
                    </div>
                </div>
                
                <button type="submit">
                    <i class="fas fa-arrow-right-to-bracket"></i> Sign In
                </button>
            </form>
            
            <div class="register-link">
                <p>Don't have an account? <a href="register.php">Create free account</a></p>
            </div>
            
            <div class="features">
                <div class="feature">
                    <i class="fas fa-shield-alt"></i>
                    <span>Secure Escrow</span>
                </div>
                <div class="feature">
                    <i class="fas fa-clock"></i>
                    <span>24/7 Support</span>
                </div>
                <div class="feature">
                    <i class="fas fa-gem"></i>
                    <span>100 ETB Bonus</span>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        
        togglePassword.addEventListener('click', function() {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>