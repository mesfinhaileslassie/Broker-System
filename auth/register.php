<?php
// auth/register.php

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $password         = $_POST['password'] ?? '';
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

                $success = 'Account created! Redirecting to your dashboard…';
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
    <title>Create Account — Ethio Brokerplace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ── Reset & Base ──────────────────────────────── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brand:         #4f6ef7;
            --brand-dark:    #3a56d4;
            --brand-soft:    #eef1fe;
            --surface:       #ffffff;
            --bg:            #f3f5fb;
            --border:        #e4e7f0;
            --text:          #1a1d2e;
            --muted:         #6b7296;
            --success-bg:    #f0fdf4;
            --success-border:#bbf7d0;
            --success-text:  #15803d;
            --error-bg:      #fff5f5;
            --error-border:  #fecaca;
            --error-text:    #c0392b;
            --radius-sm:     8px;
            --radius-md:     14px;
            --radius-lg:     22px;
            --shadow-card:   0 8px 32px rgba(79, 110, 247, 0.10), 0 1px 4px rgba(0,0,0,0.06);
            --transition:    0.2s ease;
            --font:          'DM Sans', sans-serif;
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

        /* ── Card ──────────────────────────────────────── */
        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
        }

        /* ── Header ────────────────────────────────────── */
        .card-header {
            padding: 24px 28px 20px;
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

        /* Bonus pill inside header */
        .bonus-pill {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 5px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .bonus-pill i  { color: #f59e0b; font-size: 11px; }
        .bonus-pill span { font-size: 11px; font-weight: 600; color: #92400e; }

        /* ── Body ──────────────────────────────────────── */
        .card-body { padding: 22px 28px 26px; }

        /* ── Alert ─────────────────────────────────────── */
        .alert {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 16px;
            animation: fadeIn var(--transition);
        }

        .alert-error   { background: var(--error-bg);   border: 1px solid var(--error-border);   color: var(--error-text); }
        .alert-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success-text); }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Form Grid ─────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .field       { display: flex; flex-direction: column; }
        .field.full  { grid-column: 1 / -1; }

        label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 5px;
            letter-spacing: 0.01em;
        }

        /* ── Inputs ────────────────────────────────────── */
        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrap i.left {
            position: absolute;
            left: 11px;
            color: var(--muted);
            font-size: 13px;
            pointer-events: none;
            transition: color var(--transition);
        }

        .input-wrap input {
            width: 100%;
            height: 40px;
            padding: 0 36px 0 34px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 13.5px;
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
            right: 11px;
            cursor: pointer;
            color: var(--muted);
            font-size: 13px;
            transition: color var(--transition);
            padding: 4px;
        }

        .toggle-pw:hover { color: var(--brand); }

        /* ── Password strength ─────────────────────────── */
        .strength-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 5px;
        }

        .strength-bar {
            flex: 1;
            height: 3px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .strength-label {
            font-size: 10.5px;
            font-weight: 500;
            color: var(--muted);
            min-width: 70px;
            text-align: right;
            transition: color 0.3s;
        }

        /* ── Match indicator ───────────────────────────── */
        .match-icon {
            position: absolute;
            right: 34px; /* sits left of the toggle */
            font-size: 12px;
            transition: opacity var(--transition);
            opacity: 0;
        }

        .match-icon.visible { opacity: 1; }
        .match-icon.ok      { color: #15803d; }
        .match-icon.bad     { color: var(--error-text); }

        /* ── Submit ────────────────────────────────────── */
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
            margin-top: 18px;
            transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
        }

        .btn-submit:hover {
            background: var(--brand-dark);
            box-shadow: 0 4px 14px rgba(79, 110, 247, 0.35);
            transform: translateY(-1px);
        }

        .btn-submit:active { transform: translateY(0); }

        /* ── Footer link ───────────────────────────────── */
        .footer-link {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: 13px;
            color: var(--muted);
        }

        .footer-link a {
            color: var(--brand);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--transition);
        }

        .footer-link a:hover { color: var(--brand-dark); }

        /* ── Responsive ────────────────────────────────── */
        @media (max-width: 480px) {
            .form-grid           { grid-template-columns: 1fr; }
            .field.full          { grid-column: 1; }
            .bonus-pill          { display: none; }
            .card-header,
            .card-body           { padding-left: 18px; padding-right: 18px; }
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
            <h1>Create Account</h1>
            <p>Join Ethio Brokerplace today</p>
        </div>
        <div class="bonus-pill">
            <i class="fas fa-gift"></i>
            <span>100 ETB bonus</span>
        </div>
    </div>

    <!-- Body -->
    <div class="card-body">

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-circle-check"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="form-grid">

                <!-- Full Name -->
                <div class="field full">
                    <label for="full_name">Full Name</label>
                    <div class="input-wrap">
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Abebe Kebede"
                            required
                            autofocus
                            autocomplete="name"
                            value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                        >
                        <i class="fas fa-user left"></i>
                    </div>
                </div>

                <!-- Email -->
                <div class="field">
                    <label for="email">Email Address</label>
                    <div class="input-wrap">
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="you@example.com"
                            required
                            autocomplete="email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        >
                        <i class="fas fa-envelope left"></i>
                    </div>
                </div>

                <!-- Phone -->
                <div class="field">
                    <label for="phone">Phone <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
                    <div class="input-wrap">
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            placeholder="+251 9XX XXX XXX"
                            autocomplete="tel"
                            value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                        >
                        <i class="fas fa-phone left"></i>
                    </div>
                </div>

                <!-- Password -->
                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Min. 6 characters"
                            required
                            minlength="6"
                            autocomplete="new-password"
                        >
                        <i class="fas fa-lock left"></i>
                        <i class="fas fa-eye toggle-pw" id="togglePassword"></i>
                    </div>
                    <div class="strength-row">
                        <div class="strength-bar">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <span class="strength-label" id="strengthLabel"></span>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrap">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Re-enter password"
                            required
                            autocomplete="new-password"
                        >
                        <i class="fas fa-lock left"></i>
                        <i class="fas match-icon" id="matchIcon"></i>
                        <i class="fas fa-eye toggle-pw" id="toggleConfirm"></i>
                    </div>
                </div>

            </div><!-- /form-grid -->

            <button type="submit" class="btn-submit">
                <i class="fas fa-user-plus"></i>
                Create Account
            </button>
        </form>

        <p class="footer-link">
            Already have an account? <a href="login.php">Sign in</a>
        </p>

    </div><!-- /card-body -->
</div><!-- /card -->

<script>
    /* ── Password strength ──────────────────────────── */
    const pwInput      = document.getElementById('password');
    const strengthFill = document.getElementById('strengthFill');
    const strengthLbl  = document.getElementById('strengthLabel');

    const levels = [
        { label: '',            color: '',        w: '0%'   },
        { label: 'Weak',        color: '#ef4444', w: '25%'  },
        { label: 'Fair',        color: '#f59e0b', w: '50%'  },
        { label: 'Good',        color: '#3b82f6', w: '75%'  },
        { label: 'Strong',      color: '#10b981', w: '100%' },
    ];

    pwInput.addEventListener('input', function () {
        const v = this.value;
        let score = 0;
        if (v.length >= 6)                               score++;
        if (v.length >= 10)                              score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v))         score++;
        if (/[0-9]/.test(v))                             score++;
        if (/[^a-zA-Z0-9]/.test(v))                     score++;

        const lvl = v.length === 0 ? 0 : Math.min(Math.ceil(score / 1.25), 4);
        strengthFill.style.width           = levels[lvl].w;
        strengthFill.style.backgroundColor = levels[lvl].color;
        strengthLbl.style.color            = levels[lvl].color || 'var(--muted)';
        strengthLbl.textContent            = levels[lvl].label;

        checkMatch(); // re-check match if password changes
    });

    /* ── Confirm match indicator ────────────────────── */
    const confirmInput = document.getElementById('confirm_password');
    const matchIcon    = document.getElementById('matchIcon');

    function checkMatch() {
        const pw  = pwInput.value;
        const cpw = confirmInput.value;
        if (!cpw) {
            matchIcon.classList.remove('visible', 'ok', 'bad', 'fa-check', 'fa-xmark');
            return;
        }
        const ok = pw === cpw;
        matchIcon.className = `fas match-icon visible ${ok ? 'ok fa-check' : 'bad fa-xmark'}`;
    }

    confirmInput.addEventListener('input', checkMatch);

    /* ── Password toggles ───────────────────────────── */
    function makeToggle(btnId, inputId) {
        document.getElementById(btnId).addEventListener('click', function () {
            const inp  = document.getElementById(inputId);
            const show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            this.classList.toggle('fa-eye',      !show);
            this.classList.toggle('fa-eye-slash', show);
        });
    }

    makeToggle('togglePassword', 'password');
    makeToggle('toggleConfirm',  'confirm_password');
</script>
</body>
</html>