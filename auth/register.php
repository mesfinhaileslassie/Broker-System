<?php
// auth/register.php - Modern Registration Page with White Background

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
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
        
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Email already registered';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, is_verified) VALUES (?, ?, ?, ?, 'user', 1)");
            $stmt->bind_param("ssss", $full_name, $email, $phone, $password_hash);
            
            if ($stmt->execute()) {
                $user_id = $conn->insert_id;
                $conn->query("UPDATE users SET balance = balance + 100 WHERE id = $user_id");
                $conn->query("INSERT INTO wallet_transactions (user_id, amount, type, description) VALUES ($user_id, 100, 'deposit', 'Welcome bonus')");
                
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
    <title>Create Account - Ethio Brokerplace</title>
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
            padding: 20px;
            position: relative;
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
            content: '🎁';
            position: absolute;
            top: 15%;
            left: 3%;
            font-size: 80px;
            opacity: 0.03;
            animation: float 20s infinite;
        }

        .decoration::after {
            content: '✨';
            position: absolute;
            bottom: 15%;
            right: 3%;
            font-size: 100px;
            opacity: 0.03;
            animation: float 15s infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }

        /* Register Container */
        .register-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
        }

        /* Card */
        .card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.2);
        }

        /* Header */
        .card-header {
            background: white;
            padding: 32px 32px 24px;
            text-align: center;
            border-bottom: 1px solid #f0f2f5;
        }

        .logo-wrapper {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            width: 65px;
            height: 65px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.3);
        }

        .logo-icon {
            font-size: 30px;
            color: white;
        }

        .card-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .card-header p {
            font-size: 13px;
            color: #64748b;
        }

        /* Form */
        .card-body {
            padding: 32px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group.full-width {
            grid-column: span 2;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
            font-size: 12px;
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
            font-size: 16px;
            transition: color 0.3s;
        }

        .input-wrapper input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
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
        }

        .password-strength {
            margin-top: 8px;
            font-size: 11px;
            color: #64748b;
        }

        .strength-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-bar-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            border-radius: 2px;
        }

        /* Alert Messages */
        .alert {
            padding: 12px 16px;
            border-radius: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
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

        .alert-success {
            background: #f0fdf4;
            color: #059669;
            border: 1px solid #dcfce7;
        }

        /* Register Button */
        .btn-register {
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
            margin-top: 8px;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
        }

        /* Bonus Badge */
        .bonus-badge {
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            padding: 14px;
            border-radius: 16px;
            text-align: center;
            margin: 24px 0 20px;
            border: 1px solid #fde68a;
        }

        .bonus-badge i {
            color: #f59e0b;
            margin-right: 8px;
            font-size: 18px;
        }

        .bonus-badge span {
            color: #92400e;
            font-size: 13px;
            font-weight: 600;
        }

        /* Login Link */
        .login-link {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #f0f2f5;
        }

        .login-link p {
            color: #64748b;
            font-size: 13px;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .login-link a:hover {
            color: #764ba2;
        }

        @media (max-width: 520px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .input-group.full-width {
                grid-column: span 1;
            }
            
            .card-header {
                padding: 28px 24px 20px;
            }
            
            .card-body {
                padding: 28px 24px;
            }
            
            .logo-wrapper {
                width: 55px;
                height: 55px;
            }
            
            .logo-icon {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="decoration"></div>

    <div class="register-container">
        <div class="card">
            <div class="card-header">
                <div class="logo-wrapper">
                    <i class="fas fa-user-plus logo-icon"></i>
                </div>
                <h1>Create Account</h1>
                <p>Join Ethio Brokerplace today</p>
            </div>
            
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="input-group full-width">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="full_name" placeholder="Enter your full name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="input-group">
                            <label>Email Address</label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" name="email" placeholder="you@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <label>Phone Number</label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone input-icon"></i>
                                <input type="tel" name="phone" placeholder="+251XXXXXXXXX" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="input-group">
                            <label>Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" name="password" id="password" placeholder="Create a password" required minlength="6">
                                <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                            </div>
                            <div class="password-strength" id="passwordStrength"></div>
                            <div class="strength-bar">
                                <div class="strength-bar-fill" id="strengthBar"></div>
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <label>Confirm Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" name="confirm_password" id="confirmPassword" placeholder="Confirm your password" required>
                                <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-register">
                        <i class="fas fa-arrow-right-to-bracket"></i> Create Account
                    </button>
                </form>
                
                <div class="bonus-badge">
                    <i class="fas fa-gift"></i>
                    <span>Get 100 ETB welcome bonus on registration!</span>
                </div>
                
                <div class="login-link">
                    <p>Already have an account? <a href="login.php">Sign in</a></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthText = document.getElementById('passwordStrength');
        const strengthBar = document.getElementById('strengthBar');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            let message = '';
            let color = '';
            let width = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            
            if (strength <= 1) {
                message = 'Weak password';
                color = '#dc2626';
                width = '25%';
            } else if (strength <= 3) {
                message = 'Medium password';
                color = '#f59e0b';
                width = '50%';
            } else if (strength <= 4) {
                message = 'Strong password';
                color = '#10b981';
                width = '75%';
            } else {
                message = 'Very strong password';
                color = '#059669';
                width = '100%';
            }
            
            strengthText.textContent = message;
            strengthBar.style.backgroundColor = color;
            strengthBar.style.width = width;
        });
        
        // Password visibility toggles
        const togglePassword = document.getElementById('togglePassword');
        const toggleConfirm = document.getElementById('toggleConfirmPassword');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        
        togglePassword.addEventListener('click', function() {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
        
        toggleConfirm.addEventListener('click', function() {
            const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPassword.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>