<?php
// auth/login.php - Unified Login Page (Fixed Redirect Loop)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check if already logged in - but don't redirect if we're already trying to login
if (isLoggedIn()) {
    // Redirect based on role
    if ($_SESSION['user_role'] == 'admin') {
        header('Location: /broker_system/admin/dashboard.php');
    } else {
        header('Location: /broker_system/user/dashboard.php');
    }
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
                // Set session
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_balance'] = $user['balance'];
                
                // Update last login
                $conn->query("UPDATE users SET last_login = NOW() WHERE id = {$user['id']}");
                
                // Redirect based on role
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f7fa 0%, #eef2f7 100%);
            position: relative;
            padding: 20px;
        }

        /* Decorative Elements */
        .decoration {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }

        .decoration::before {
            content: '🏪';
            position: absolute;
            top: 10%;
            left: 5%;
            font-size: 120px;
            opacity: 0.03;
            animation: float 20s infinite;
        }

        .decoration::after {
            content: '💰';
            position: absolute;
            bottom: 10%;
            right: 5%;
            font-size: 100px;
            opacity: 0.03;
            animation: float 15s infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }

        /* Login Container */
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
        }

        /* Main Card */
        .card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.2);
        }

        /* Header */
        .card-header {
            background: white;
            padding: 40px 32px 24px;
            text-align: center;
            border-bottom: 1px solid #f0f2f5;
        }

        .logo-wrapper {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.3);
        }

        .logo-icon {
            font-size: 32px;
            color: white;
        }

        .card-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .card-header p {
            font-size: 14px;
            color: #64748b;
        }

        .role-badge {
            display: inline-block;
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            color: #64748b;
            margin-top: 12px;
        }

        .role-badge i {
            margin-right: 4px;
            font-size: 10px;
        }

        /* Form */
        .card-body {
            padding: 32px;
        }

        .input-group {
            margin-bottom: 24px;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
            font-size: 13px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            color: #94a3b8;
            font-size: 18px;
            transition: color 0.3s;
        }

        .input-wrapper input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            background: #fafbfc;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.08);
        }

        .input-wrapper input:focus + .input-icon {
            color: #667eea;
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

        /* Alert Messages */
        .alert {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }

        /* Login Button */
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 24px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
        }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 24px 0;
            color: #94a3b8;
            font-size: 12px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e2e8f0;
        }

        .divider span {
            margin: 0 16px;
        }

        /* Register Link */
        .register-link {
            text-align: center;
            margin-bottom: 24px;
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
        }

        /* Features Grid */
        .features {
            display: flex;
            justify-content: center;
            gap: 24px;
            padding-top: 24px;
            border-top: 1px solid #f0f2f5;
        }

        .feature {
            text-align: center;
            flex: 1;
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            background: #f0f4ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
        }

        .feature-icon i {
            font-size: 18px;
            color: #667eea;
        }

        .feature span {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }

        /* Demo Credentials */
        .demo-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin-top: 20px;
            border: 1px solid #e2e8f0;
        }

        .demo-title {
            font-size: 12px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .demo-title i {
            color: #667eea;
        }

        .demo-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            font-size: 12px;
        }

        .demo-role {
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .demo-role.admin {
            background: #fed7aa;
            color: #9a3412;
        }

        .demo-role.user {
            background: #dbeafe;
            color: #1e40af;
        }

        .demo-credentials {
            font-family: monospace;
            font-size: 11px;
            color: #64748b;
        }

        @media (max-width: 480px) {
            .card-header {
                padding: 32px 24px 20px;
            }
            
            .card-body {
                padding: 28px 24px;
            }
            
            .logo-wrapper {
                width: 60px;
                height: 60px;
            }
            
            .logo-icon {
                font-size: 28px;
            }
            
            .card-header h1 {
                font-size: 24px;
            }
            
            .features {
                gap: 16px;
            }
            
            .demo-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="decoration"></div>

    <div class="login-container">
        <div class="card">
            <div class="card-header">
                <div class="logo-wrapper">
                    <i class="fas fa-store logo-icon"></i>
                </div>
                <h1>Welcome Back</h1>
                <p>Sign in to continue to Ethio Brokerplace</p>
                <div class="role-badge">
                    <i class="fas fa-users"></i> One account for all services
                </div>
            </div>
            
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="input-group">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" name="email" placeholder="Enter your email address" required autofocus value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" placeholder="Enter your password" required>
                            <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="fas fa-arrow-right-to-bracket"></i> Sign In
                    </button>
                </form>
                
                <div class="divider">
                    <span>New to Brokerplace?</span>
                </div>
                
                <div class="register-link">
                    <p>Don't have an account? <a href="register.php">Create free account</a></p>
                </div>
                
                <!-- Demo Credentials Box -->
                <div class="demo-box">
                    <div class="demo-title">
                        <i class="fas fa-info-circle"></i>
                        Demo Accounts
                    </div>
                    <div class="demo-row">
                        <span><strong>Admin Account</strong></span>
                        <span class="demo-role admin">Administrator</span>
                    </div>
                    <div class="demo-row">
                        <span class="demo-credentials">admin@brokerplace.com</span>
                        <span class="demo-credentials">admin123</span>
                    </div>
                    <div style="border-top: 1px solid #e2e8f0; margin: 10px 0;"></div>
                    <div class="demo-row">
                        <span><strong>User Account</strong></span>
                        <span class="demo-role user">Regular User</span>
                    </div>
                    <div class="demo-row">
                        <span class="demo-credentials">user@example.com</span>
                        <span class="demo-credentials">password123</span>
                    </div>
                    <div style="border-top: 1px solid #e2e8f0; margin: 10px 0;"></div>
                    <div class="demo-row">
                        <span><strong>Company Account</strong></span>
                        <span class="demo-role user">Company</span>
                    </div>
                    <div class="demo-row">
                        <span class="demo-credentials">company@example.com</span>
                        <span class="demo-credentials">password123</span>
                    </div>
                </div>
                
                <div class="features">
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <span>Secure Escrow</span>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <span>24/7 Support</span>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">
                            <i class="fas fa-gem"></i>
                        </div>
                        <span>100 ETB Bonus</span>
                    </div>
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
        
        // Clear any stuck session indications
        if (performance.navigation.type === 1) {
            // Page was reloaded, check if we need to clear anything
            console.log('Page reloaded');
        }
    </script>
</body>
</html>