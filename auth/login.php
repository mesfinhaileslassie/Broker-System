<?php
// auth/login.php - Complete Login with Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

// If already logged in, redirect based on role
if (isLoggedIn()) {
    if ($_SESSION['user_role'] == 'admin') {
        header('Location: /broker_system/admin/dashboard.php');
    } elseif ($_SESSION['user_role'] == 'company') {
        header('Location: /broker_system/company/dashboard.php');
    } else {
        header('Location: /broker_system/user/dashboard.php');
    }
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $email = sanitizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false;
    
    $errors = [];
    
    // Validate email
    if (empty($email)) {
        $errors[] = "Email address is required";
    } elseif (!validateEmail($email)) {
        $errors[] = "Please enter a valid email address";
    }
    
    // Validate password
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    if (empty($errors)) {
        $conn = getDbConnection();
        
        // Get user by email
        $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, role, balance, is_suspended, is_verified FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check if suspended
            if ($user['is_suspended']) {
                $errors[] = "Your account has been suspended. Please contact support.";
            } 
            // Check if email verified (optional - uncomment if needed)
            // elseif (!$user['is_verified']) {
            //     $errors[] = "Please verify your email address before logging in.";
            // }
            // Verify password
            elseif (password_verify($password, $user['password_hash'])) {
                // Login successful
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_balance'] = $user['balance'];
                
                // Update last login
                $conn->query("UPDATE users SET last_login = NOW() WHERE id = {$user['id']}");
                
                // Remember me (30 days)
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                    $conn->query("INSERT INTO user_tokens (user_id, token, expires_at) VALUES ({$user['id']}, '$token', '$expires')");
                    setcookie('remember_token', $token, time() + (86400 * 30), '/');
                }
                
                // Role-based redirect
                if ($user['role'] == 'admin') {
                    header('Location: /broker_system/admin/dashboard.php');
                } elseif ($user['role'] == 'company') {
                    header('Location: /broker_system/company/dashboard.php');
                } else {
                    $redirect = $_SESSION['redirect_after_login'] ?? '/broker_system/user/dashboard.php';
                    unset($_SESSION['redirect_after_login']);
                    header("Location: $redirect");
                }
                exit;
            } else {
                $errors[] = "Invalid email or password";
            }
        } else {
            $errors[] = "No account found with this email address";
        }
        
        $conn->close();
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Ethio Brokerplace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brand:        #4f6ef7;
            --brand-dark:   #3a56d4;
            --brand-soft:   #eef1fe;
            --surface:      #ffffff;
            --bg:           #f3f5fb;
            --border:       #e4e7f0;
            --text:         #1a1d2e;
            --muted:        #6b7296;
            --error-bg:     #fff5f5;
            --error-border: #fecaca;
            --error-text:   #c0392b;
            --radius-sm:    8px;
            --radius-md:    14px;
            --radius-lg:    22px;
            --shadow-card:  0 8px 32px rgba(79, 110, 247, 0.10), 0 1px 4px rgba(0,0,0,0.06);
            --transition:   0.2s ease;
            --font:         'DM Sans', sans-serif;
            --mono:         'DM Mono', monospace;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background-image: radial-gradient(circle, #c7cef5 1px, transparent 1px);
            background-size: 22px 22px;
        }

        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }

        .card-header {
            padding: 28px 28px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logo {
            width: 46px;
            height: 46px;
            border-radius: var(--radius-sm);
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .logo i { color: #fff; font-size: 20px; }

        .header-text h1 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.2;
        }

        .header-text p {
            font-size: 12.5px;
            color: var(--muted);
            margin-top: 2px;
        }

        .card-body { padding: 24px 28px; }

        .alert {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            animation: fadeIn var(--transition);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .field { margin-bottom: 18px; }

        label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
            letter-spacing: 0.01em;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrap i.left {
            position: absolute;
            left: 12px;
            color: var(--muted);
            font-size: 14px;
            pointer-events: none;
            transition: color var(--transition);
        }

        .input-wrap input {
            width: 100%;
            height: 44px;
            padding: 0 40px 0 38px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 14px;
            color: var(--text);
            background: #fafbff;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        .input-wrap input:focus {
            outline: none;
            border-color: var(--brand);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(79, 110, 247, 0.10);
        }

        .input-wrap input:focus ~ i.left { color: var(--brand); }

        .toggle-pw {
            position: absolute;
            right: 12px;
            cursor: pointer;
            color: var(--muted);
            font-size: 14px;
            transition: color var(--transition);
            padding: 4px;
        }

        .toggle-pw:hover { color: var(--brand); }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .checkbox input {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .checkbox label {
            margin-bottom: 0;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-submit {
            width: 100%;
            height: 44px;
            background: var(--brand);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 16px;
            transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
        }

        .btn-submit:hover {
            background: var(--brand-dark);
            box-shadow: 0 4px 14px rgba(79, 110, 247, 0.35);
            transform: translateY(-1px);
        }

        .btn-submit:active { transform: translateY(0); }

        .footer-links {
            text-align: center;
            font-size: 13px;
            color: var(--muted);
        }

        .footer-links a {
            color: var(--brand);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--transition);
        }

        .footer-links a:hover { color: var(--brand-dark); }

        .demo {
            margin-top: 20px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .demo-toggle {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg);
            border: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            font-family: var(--font);
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            transition: background var(--transition);
        }

        .demo-toggle:hover { background: var(--brand-soft); color: var(--brand); }

        .demo-toggle .chevron {
            font-size: 11px;
            transition: transform 0.25s ease;
        }

        .demo-toggle[aria-expanded="true"] .chevron { transform: rotate(180deg); }

        .demo-body {
            display: none;
            padding: 12px 14px;
            background: var(--surface);
        }

        .demo-body.open { display: block; }

        .demo-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .demo-row:last-child { border-bottom: none; }

        .demo-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text);
        }

        .badge {
            font-size: 10px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 20px;
        }

        .badge-admin { background: #fff3e0; color: #b45309; }
        .badge-user  { background: #e8f0fe; color: #1a56db; }
        .badge-company { background: #d1fae5; color: #059669; }

        .demo-creds {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--muted);
            text-align: right;
        }

        .trust-row {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .pill {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            background: var(--brand-soft);
            font-size: 11px;
            font-weight: 500;
            color: var(--brand);
        }

        .pill i { font-size: 11px; }

        @media (max-width: 440px) {
            body { background-size: 18px 18px; }
            .card-header, .card-body { padding-left: 20px; padding-right: 20px; }
        }
    </style>
</head>
<body>

<div class="card">

    <div class="card-header">
        <div class="logo">
            <i class="fas fa-store"></i>
        </div>
        <div class="header-text">
            <h1>Ethio Brokerplace</h1>
            <p>Sign in to your account</p>
        </div>
    </div>

    <div class="card-body">

        <?php if ($error): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>

            <div class="field">
                <label for="email">Email address</label>
                <div class="input-wrap">
                    <input type="email" id="email" name="email" placeholder="you@example.com" required autofocus
                           value="<?php echo htmlspecialchars($email); ?>">
                    <i class="fas fa-envelope left"></i>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <i class="fas fa-lock left"></i>
                    <i class="fas fa-eye toggle-pw" id="togglePassword"></i>
                </div>
            </div>

            <div class="checkbox">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">Remember me</label>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-arrow-right-to-bracket"></i>
                Sign In
            </button>

        </form>

        <p class="footer-links">
            Don't have an account? <a href="register.php">Create one free</a>
        </p>

        <div class="demo">
            <button class="demo-toggle" id="demoToggle" aria-expanded="false">
                <span><i class="fas fa-circle-info"></i> Demo accounts</span>
                <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="demo-body" id="demoBody">
                <div class="demo-row">
                    <span class="demo-label">Admin</span>
                    <span class="badge badge-admin">Administrator</span>
                    <span class="demo-creds">admin@brokerplace.com · admin123</span>
                </div>
                <div class="demo-row">
                    <span class="demo-label">Company</span>
                    <span class="badge badge-company">Company</span>
                    <span class="demo-creds">company@example.com · password123</span>
                </div>
                <div class="demo-row">
                    <span class="demo-label">User</span>
                    <span class="badge badge-user">Regular</span>
                    <span class="demo-creds">user@example.com · password123</span>
                </div>
            </div>
        </div>

        <div class="trust-row">
            <span class="pill"><i class="fas fa-shield-alt"></i> Secure Escrow</span>
            <span class="pill"><i class="fas fa-clock"></i> 24/7 Support</span>
            <span class="pill"><i class="fas fa-gift"></i> 100 ETB Bonus</span>
        </div>

    </div>
</div>

<script>
    // Password visibility toggle
    document.getElementById('togglePassword').addEventListener('click', function() {
        const pw = document.getElementById('password');
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        this.classList.toggle('fa-eye', !show);
        this.classList.toggle('fa-eye-slash', show);
    });

    // Demo credentials accordion
    const demoToggle = document.getElementById('demoToggle');
    const demoBody = document.getElementById('demoBody');

    demoToggle.addEventListener('click', function() {
        const open = demoBody.classList.toggle('open');
        this.setAttribute('aria-expanded', open);
    });
</script>
</body>
</html>