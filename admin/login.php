<?php
// admin/login.php - Admin Login Page with Modern UI

session_start();

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    require_once '../config/database.php';
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT id, full_name, email, password_hash FROM users WHERE email = ? AND role = 'admin'");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();
        if (password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['admin_email'] = $admin['email'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = "Invalid email or password";
        }
    } else {
        $error = "Admin account not found";
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Ethio Brokerplace</title>
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
            max-width: 440px;
            overflow: hidden;
        }

        .card-header {
            padding: 32px 32px 24px;
            border-bottom: 1px solid var(--border);
            text-align: center;
        }

        .logo {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .logo i { color: #fff; font-size: 32px; }

        .header-text h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .header-text p {
            font-size: 14px;
            color: var(--muted);
        }

        .admin-badge {
            display: inline-block;
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            color: #92400e;
            margin-top: 12px;
        }

        .card-body { padding: 32px; }

        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 24px;
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            animation: fadeIn var(--transition);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .field { margin-bottom: 20px; }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
            letter-spacing: 0.01em;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrap i.left {
            position: absolute;
            left: 14px;
            color: var(--muted);
            font-size: 15px;
            pointer-events: none;
            transition: color var(--transition);
        }

        .input-wrap input {
            width: 100%;
            height: 48px;
            padding: 0 45px 0 45px;
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
            right: 14px;
            cursor: pointer;
            color: var(--muted);
            font-size: 16px;
            transition: color var(--transition);
        }

        .toggle-pw:hover { color: var(--brand); }

        .btn-submit {
            width: 100%;
            height: 48px;
            background: var(--brand);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
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
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .footer-links a {
            color: var(--brand);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color var(--transition);
        }

        .footer-links a:hover { color: var(--brand-dark); }

        .security-badge {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .pill {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 30px;
            background: var(--brand-soft);
            font-size: 11px;
            font-weight: 500;
            color: var(--brand);
        }

        .pill i { font-size: 12px; }

        @media (max-width: 480px) {
            .card-header, .card-body { padding: 24px; }
            .logo { width: 55px; height: 55px; }
            .logo i { font-size: 26px; }
            .header-text h1 { font-size: 20px; }
        }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <div class="logo">
            <i class="fas fa-shield-alt"></i>
        </div>
        <div class="header-text">
            <h1>Admin Portal</h1>
            <p>Sign in to manage your platform</p>
        </div>
        <div class="admin-badge">
            <i class="fas fa-lock"></i> Restricted Access
        </div>
    </div>

    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label for="username">Email Address</label>
                <div class="input-wrap">
                    <input type="email" id="username" name="username" placeholder="admin@brokerplace.com" required autofocus>
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

            <button type="submit" class="btn-submit">
                <i class="fas fa-arrow-right-to-bracket"></i>
                Sign In
            </button>
        </form>

        <div class="footer-links">
            <a href="/broker_system/auth/login.php">← Back to User Login</a>
        </div>

        <div class="security-badge">
            <span class="pill"><i class="fas fa-shield-alt"></i> Secure Connection</span>
            <span class="pill"><i class="fas fa-user-shield"></i> Admin Access Only</span>
            <span class="pill"><i class="fas fa-lock"></i> Encrypted</span>
        </div>
    </div>
</div>

<script>
    // Password visibility toggle
    document.getElementById('togglePassword').addEventListener('click', function() {
        const password = document.getElementById('password');
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.classList.toggle('fa-eye');
        this.classList.toggle('fa-eye-slash');
    });
</script>

</body>
</html>