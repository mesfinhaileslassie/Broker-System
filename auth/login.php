<?php
// auth/login.php - Unified Login Page (Fixed Redirect Loop)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

if (isLoggedIn()) {
    if ($_SESSION['user_role'] == 'admin') {
        header('Location: /broker_system/admin/dashboard.php');
    } else {
        header('Location: /broker_system/user/dashboard.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
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
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id']        = $user['id'];
                $_SESSION['user_name']      = $user['full_name'];
                $_SESSION['user_email']     = $user['email'];
                $_SESSION['user_role']      = $user['role'];
                $_SESSION['user_balance']   = $user['balance'];

                $conn->query("UPDATE users SET last_login = NOW() WHERE id = {$user['id']}");

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
    <title>Sign In — Ethio Brokerplace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ── Reset & Base ──────────────────────────────── */
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
            /* Subtle dot-grid background */
            background-image: radial-gradient(circle, #c7cef5 1px, transparent 1px);
            background-size: 22px 22px;
        }

        /* ── Card ──────────────────────────────────────── */
        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }

        /* ── Header ────────────────────────────────────── */
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

        /* ── Body ──────────────────────────────────────── */
        .card-body { padding: 24px 28px; }

        /* ── Alert ─────────────────────────────────────── */
        .alert {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 18px;
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            animation: fadeIn var(--transition);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Form ──────────────────────────────────────── */
        .field { margin-bottom: 16px; }

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
            height: 42px;
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

        /* ── Submit Button ─────────────────────────────── */
        .btn-submit {
            width: 100%;
            height: 42px;
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
            margin-top: 20px;
            transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
        }

        .btn-submit:hover {
            background: var(--brand-dark);
            box-shadow: 0 4px 14px rgba(79, 110, 247, 0.35);
            transform: translateY(-1px);
        }

        .btn-submit:active { transform: translateY(0); }

        /* ── Footer links ──────────────────────────────── */
        .footer-links {
            margin-top: 16px;
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

        /* ── Demo Credentials ──────────────────────────── */
        .demo {
            margin-top: 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .demo-toggle {
            width: 100%;
            padding: 9px 14px;
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
            padding: 6px 0;
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

        .demo-creds {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--muted);
            text-align: right;
        }

        /* ── Trust Pills ───────────────────────────────── */
        .trust-row {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .pill {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            background: var(--brand-soft);
            font-size: 11px;
            font-weight: 500;
            color: var(--brand);
        }

        .pill i { font-size: 10px; }

        /* ── Responsive ────────────────────────────────── */
        @media (max-width: 440px) {
            body { background-size: 18px 18px; }
            .card-header, .card-body { padding-left: 20px; padding-right: 20px; }
        }
    </style>
</head>
<body>

<div class="card">

    <!-- Header -->
    <div class="card-header">
        <div class="logo">
            <i class="fas fa-store"></i>
        </div>
        <div class="header-text">
            <h1>Ethio Brokerplace</h1>
            <p>Sign in to your account</p>
        </div>
    </div>

    <!-- Body -->
    <div class="card-body">

        <?php if ($error): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>

            <div class="field">
                <label for="email">Email address</label>
                <div class="input-wrap">
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        required
                        autofocus
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        autocomplete="email"
                    >
                    <i class="fas fa-envelope left"></i>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                    <i class="fas fa-lock left"></i>
                    <i class="fas fa-eye toggle-pw" id="togglePassword" title="Show/hide password"></i>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-arrow-right-to-bracket"></i>
                Sign In
            </button>

        </form>

        <p class="footer-links">
            Don't have an account? <a href="register.php">Create one free</a>
        </p>

        <!-- Demo Credentials (collapsible) -->
        <div class="demo">
            <button class="demo-toggle" id="demoToggle" aria-expanded="false" aria-controls="demoBody">
                <span><i class="fas fa-circle-info" style="margin-right:6px;"></i>Demo accounts</span>
                <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="demo-body" id="demoBody">
                <div class="demo-row">
                    <span class="demo-label">Admin</span>
                    <span class="badge badge-admin">Administrator</span>
                    <span class="demo-creds">admin@brokerplace.com · admin123</span>
                </div>
                <div class="demo-row">
                    <span class="demo-label">User</span>
                    <span class="badge badge-user">Regular</span>
                    <span class="demo-creds">user@example.com · password123</span>
                </div>
                <div class="demo-row">
                    <span class="demo-label">Company</span>
                    <span class="badge badge-user">Company</span>
                    <span class="demo-creds">company@example.com · password123</span>
                </div>
            </div>
        </div>

        <!-- Trust Pills -->
        <div class="trust-row">
            <span class="pill"><i class="fas fa-shield-alt"></i> Secure Escrow</span>
            <span class="pill"><i class="fas fa-clock"></i> 24/7 Support</span>
            <span class="pill"><i class="fas fa-gift"></i> 100 ETB Bonus</span>
        </div>

    </div><!-- /card-body -->
</div><!-- /card -->

<script>
    // Password toggle
    document.getElementById('togglePassword').addEventListener('click', function () {
        const pw   = document.getElementById('password');
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        this.classList.toggle('fa-eye',       !show);
        this.classList.toggle('fa-eye-slash',  show);
    });

    // Demo credentials accordion
    const demoToggle = document.getElementById('demoToggle');
    const demoBody   = document.getElementById('demoBody');

    demoToggle.addEventListener('click', function () {
        const open = demoBody.classList.toggle('open');
        this.setAttribute('aria-expanded', open);
    });
</script>
</body>
</html>