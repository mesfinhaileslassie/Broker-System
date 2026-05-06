<?php
// auth/register.php - Complete Registration with Ethiopian Validation

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

$error = '';
$success = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize all inputs first
    $form_data = [
        'full_name' => sanitizeString($_POST['full_name'] ?? ''),
        'email' => sanitizeEmail($_POST['email'] ?? ''),
        'phone' => sanitizePhone($_POST['phone'] ?? ''),
        'password' => $_POST['password'] ?? '', // Don't sanitize password
        'confirm_password' => $_POST['confirm_password'] ?? '',
        'account_type' => sanitizeString($_POST['account_type'] ?? 'user'),
        'business_name' => sanitizeString($_POST['business_name'] ?? ''),
        'business_type' => sanitizeString($_POST['business_type'] ?? ''),
        'tin_number' => sanitizeString($_POST['tin_number'] ?? ''),
        'business_address' => sanitizeString($_POST['business_address'] ?? '')
    ];
    
    $errors = [];
    
    // ============================================
    // FULL NAME VALIDATION
    // ============================================
    if (empty($form_data['full_name'])) {
        $errors[] = "Full name is required";
    } elseif (strlen($form_data['full_name']) < 3) {
        $errors[] = "Full name must be at least 3 characters";
    } elseif (strlen($form_data['full_name']) > 100) {
        $errors[] = "Full name must not exceed 100 characters";
    } elseif (!preg_match('/^[a-zA-Z\s\'-]+$/', $form_data['full_name'])) {
        $errors[] = "Full name can only contain letters, spaces, hyphens, and apostrophes";
    }
    
    // ============================================
    // EMAIL VALIDATION (Real email format)
    // ============================================
    if (empty($form_data['email'])) {
        $errors[] = "Email address is required";
    } elseif (!validateEmail($form_data['email'])) {
        $errors[] = "Please enter a valid email address (e.g., name@example.com)";
    } elseif (strlen($form_data['email']) > 255) {
        $errors[] = "Email address is too long";
    }
    
    // ============================================
    // PHONE VALIDATION (Ethiopian format)
    // ============================================
    if (!empty($form_data['phone'])) {
        if (!validatePhone($form_data['phone'])) {
            $errors[] = "Please enter a valid Ethiopian phone number (e.g., +251911234567 or 0911234567)";
        }
    }
    
    // ============================================
    // PASSWORD VALIDATION (Strong password)
    // ============================================
    if (empty($form_data['password'])) {
        $errors[] = "Password is required";
    } else {
        $password_errors = validatePasswordStrength($form_data['password']);
        if (!empty($password_errors)) {
            $errors = array_merge($errors, $password_errors);
        }
        
        // Confirm password match
        if ($form_data['password'] !== $form_data['confirm_password']) {
            $errors[] = "Passwords do not match";
        }
    }
    
    // ============================================
    // ACCOUNT TYPE VALIDATION
    // ============================================
    $valid_account_types = ['user', 'company'];
    if (!in_array($form_data['account_type'], $valid_account_types)) {
        $errors[] = "Invalid account type selected";
    }
    
    // ============================================
    // COMPANY SPECIFIC VALIDATION
    // ============================================
    if ($form_data['account_type'] == 'company') {
        if (empty($form_data['business_name'])) {
            $errors[] = "Business name is required for company accounts";
        } elseif (strlen($form_data['business_name']) < 2) {
            $errors[] = "Business name must be at least 2 characters";
        } elseif (strlen($form_data['business_name']) > 150) {
            $errors[] = "Business name must not exceed 150 characters";
        }
        
        if (empty($form_data['tin_number'])) {
            $errors[] = "TIN (Tax Identification Number) is required for company accounts";
        } elseif (!validateTIN($form_data['tin_number'])) {
            $errors[] = "Please enter a valid TIN number (10-15 digits)";
        }
        
        if (!empty($form_data['business_type'])) {
            $valid_business_types = ['Technology', 'Construction', 'Manufacturing', 'Trading', 'Services', 'Retail', 'Other'];
            if (!in_array($form_data['business_type'], $valid_business_types)) {
                $errors[] = "Please select a valid business type";
            }
        }
    }
    
    // ============================================
    // DATABASE VALIDATION (Email exists?)
    // ============================================
    if (empty($errors)) {
        $conn = getDbConnection();
        
        if (emailExists($conn, $form_data['email'])) {
            $errors[] = "This email is already registered. Please login or use a different email.";
        }
        
        $conn->close();
    }
    
    // ============================================
    // PROCESS REGISTRATION
    // ============================================
    if (empty($errors)) {
        $conn = getDbConnection();
        $password_hash = password_hash($form_data['password'], PASSWORD_DEFAULT);
        
        $conn->begin_transaction();
        
        try {
            // Insert user
            $stmt = $conn->prepare("
                INSERT INTO users (full_name, email, phone, password_hash, role, is_verified, created_at) 
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");
            $stmt->bind_param("sssss", 
                $form_data['full_name'], 
                $form_data['email'], 
                $form_data['phone'], 
                $password_hash, 
                $form_data['account_type']
            );
            $stmt->execute();
            $user_id = $conn->insert_id;
            
            // If company, create company profile
            if ($form_data['account_type'] == 'company') {
                $stmt2 = $conn->prepare("
                    INSERT INTO companies (user_id, business_name, business_type, tin_number, address, is_approved, created_at) 
                    VALUES (?, ?, ?, ?, ?, 0, NOW())
                ");
                $stmt2->bind_param("issss", 
                    $user_id, 
                    $form_data['business_name'], 
                    $form_data['business_type'], 
                    $form_data['tin_number'], 
                    $form_data['business_address']
                );
                $stmt2->execute();
            }
            
            // Add welcome bonus (100 ETB for new users)
            $conn->query("UPDATE users SET balance = balance + 100 WHERE id = $user_id");
            $conn->query("
                INSERT INTO wallet_transactions (user_id, amount, type, description, created_at) 
                VALUES ($user_id, 100, 'deposit', 'Welcome bonus', NOW())
            ");
            
            $conn->commit();
            
            // Auto-login
            $userBalance = $conn->query("SELECT balance FROM users WHERE id = $user_id")->fetch_assoc();
            userLogin($user_id, $form_data['full_name'], $form_data['email'], $form_data['account_type'], $userBalance['balance']);
            
            // Redirect based on account type
            if ($form_data['account_type'] == 'company') {
                $success = 'Company account created successfully! Your account is pending admin approval.';
                header('Refresh: 3; URL=/broker_system/company/dashboard.php');
            } else {
                $success = 'Account created successfully! Welcome to Ethio Brokerplace!';
                header('Refresh: 2; URL=/broker_system/user/dashboard.php');
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Registration failed: " . $e->getMessage();
            $error = implode('<br>', $errors);
        }
        
        $conn->close();
    } else {
        $error = implode('<br>', $errors);
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

        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 560px;
            overflow: hidden;
        }

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

        .card-body { padding: 22px 28px 26px; }

        .alert {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            animation: fadeIn var(--transition);
        }

        .alert-error   { background: var(--error-bg);   border: 1px solid var(--error-border);   color: var(--error-text); }
        .alert-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success-text); }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .account-type-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .type-option {
            flex: 1;
            padding: 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }

        .type-option:hover {
            border-color: var(--brand);
            background: var(--brand-soft);
        }

        .type-option.selected {
            border-color: var(--brand);
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: white;
        }

        .type-option.selected i,
        .type-option.selected span {
            color: white;
        }

        .type-option i {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
            color: var(--brand);
        }

        .type-option span {
            font-size: 14px;
            font-weight: 600;
            display: block;
        }

        .type-option small {
            font-size: 11px;
            opacity: 0.7;
            display: block;
            margin-top: 4px;
        }

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

        .label-required {
            color: #ef4444;
            margin-left: 2px;
        }

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

        .input-wrap input, .input-wrap select {
            width: 100%;
            height: 42px;
            padding: 0 36px 0 34px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 13.5px;
            color: var(--text);
            background: #fafbff;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        .input-wrap select {
            padding: 0 12px 0 34px;
            cursor: pointer;
        }

        .input-wrap input:focus, .input-wrap select:focus {
            outline: none;
            border-color: var(--brand);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(79, 110, 247, 0.10);
        }

        .input-wrap input:focus ~ i.left,
        .input-wrap select:focus ~ i.left { color: var(--brand); }

        .input-wrap input.error {
            border-color: #ef4444;
        }

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

        .company-fields {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .company-fields.active {
            display: block;
        }

        .strength-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
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
            font-size: 10px;
            font-weight: 500;
            color: var(--muted);
            min-width: 60px;
            text-align: right;
        }

        .match-icon {
            position: absolute;
            right: 34px;
            font-size: 12px;
            transition: opacity var(--transition);
            opacity: 0;
        }

        .match-icon.visible { opacity: 1; }
        .match-icon.ok      { color: #15803d; }
        .match-icon.bad     { color: var(--error-text); }

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
            margin-top: 20px;
            transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
        }

        .btn-submit:hover {
            background: var(--brand-dark);
            box-shadow: 0 4px 14px rgba(79, 110, 247, 0.35);
            transform: translateY(-1px);
        }

        .btn-submit:active { transform: translateY(0); }

        .footer-link {
            margin-top: 20px;
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

        .info-text {
            font-size: 10px;
            color: var(--muted);
            margin-top: 4px;
        }

        .phone-hint {
            font-size: 10px;
            color: #059669;
            margin-top: 4px;
        }

        @media (max-width: 560px) {
            .form-grid           { grid-template-columns: 1fr; }
            .field.full          { grid-column: 1; }
            .bonus-pill          { display: none; }
            .account-type-selector { flex-direction: column; }
            .card-header,
            .card-body           { padding-left: 18px; padding-right: 18px; }
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
            <h1>Create Account</h1>
            <p>Join Ethio Brokerplace today</p>
        </div>
        <div class="bonus-pill">
            <i class="fas fa-gift"></i>
            <span>100 ETB bonus</span>
        </div>
    </div>

    <div class="card-body">

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-circle-check"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate id="registerForm">
            
            <!-- Account Type Selector -->
            <div class="account-type-selector">
                <div class="type-option <?php echo ($form_data['account_type'] ?? 'user') == 'user' ? 'selected' : ''; ?>" data-type="user" onclick="selectAccountType('user')">
                    <i class="fas fa-user"></i>
                    <span>Individual</span>
                    <small>Buy & sell as individual</small>
                </div>
                <div class="type-option <?php echo ($form_data['account_type'] ?? '') == 'company' ? 'selected' : ''; ?>" data-type="company" onclick="selectAccountType('company')">
                    <i class="fas fa-building"></i>
                    <span>Company</span>
                    <small>Post jobs & hire talent</small>
                </div>
            </div>
            <input type="hidden" name="account_type" id="accountType" value="<?php echo htmlspecialchars($form_data['account_type'] ?? 'user'); ?>">

            <div class="form-grid">

                <!-- Full Name -->
                <div class="field full">
                    <label for="full_name">Full Name <span class="label-required">*</span></label>
                    <div class="input-wrap">
                        <input type="text" id="full_name" name="full_name" placeholder="Abebe Kebede" required 
                               value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>">
                        <i class="fas fa-user left"></i>
                    </div>
                </div>

                <!-- Email -->
                <div class="field">
                    <label for="email">Email Address <span class="label-required">*</span></label>
                    <div class="input-wrap">
                        <input type="email" id="email" name="email" placeholder="you@example.com" required 
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                        <i class="fas fa-envelope left"></i>
                    </div>
                </div>

                <!-- Phone -->
                <div class="field">
                    <label for="phone">Phone Number</label>
                    <div class="input-wrap">
                        <input type="tel" id="phone" name="phone" placeholder="+251 9XX XXX XXX" 
                               value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>">
                        <i class="fas fa-phone left"></i>
                    </div>
                    <div class="phone-hint">
                        <i class="fas fa-info-circle"></i> Ethiopian format: +251XXXXXXXXX or 09XXXXXXXX
                    </div>
                </div>

                <!-- Company Fields (hidden by default) -->
                <div id="companyFields" class="company-fields <?php echo ($form_data['account_type'] ?? '') == 'company' ? 'active' : ''; ?>">
                    <div class="field full">
                        <label for="business_name">Business Name <span class="label-required">*</span></label>
                        <div class="input-wrap">
                            <input type="text" id="business_name" name="business_name" placeholder="Your Company Name" 
                                   value="<?php echo htmlspecialchars($form_data['business_name'] ?? ''); ?>">
                            <i class="fas fa-building left"></i>
                        </div>
                    </div>
                    
                    <div class="field full">
                        <label for="business_type">Business Type</label>
                        <div class="input-wrap">
                            <select id="business_type" name="business_type">
                                <option value="">Select business type</option>
                                <option value="Technology" <?php echo ($form_data['business_type'] ?? '') == 'Technology' ? 'selected' : ''; ?>>Technology / IT</option>
                                <option value="Construction" <?php echo ($form_data['business_type'] ?? '') == 'Construction' ? 'selected' : ''; ?>>Construction</option>
                                <option value="Manufacturing" <?php echo ($form_data['business_type'] ?? '') == 'Manufacturing' ? 'selected' : ''; ?>>Manufacturing</option>
                                <option value="Trading" <?php echo ($form_data['business_type'] ?? '') == 'Trading' ? 'selected' : ''; ?>>Trading / Import-Export</option>
                                <option value="Services" <?php echo ($form_data['business_type'] ?? '') == 'Services' ? 'selected' : ''; ?>>Services</option>
                                <option value="Retail" <?php echo ($form_data['business_type'] ?? '') == 'Retail' ? 'selected' : ''; ?>>Retail</option>
                                <option value="Other" <?php echo ($form_data['business_type'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <i class="fas fa-briefcase left"></i>
                        </div>
                    </div>
                    
                    <div class="field">
                        <label for="tin_number">TIN Number <span class="label-required">*</span></label>
                        <div class="input-wrap">
                            <input type="text" id="tin_number" name="tin_number" placeholder="1234567890" 
                                   value="<?php echo htmlspecialchars($form_data['tin_number'] ?? ''); ?>">
                            <i class="fas fa-id-card left"></i>
                        </div>
                        <div class="info-text">Tax Identification Number (10-15 digits)</div>
                    </div>
                    
                    <div class="field">
                        <label for="business_address">Business Address</label>
                        <div class="input-wrap">
                            <input type="text" id="business_address" name="business_address" placeholder="Addis Ababa, Bole Sub-city" 
                                   value="<?php echo htmlspecialchars($form_data['business_address'] ?? ''); ?>">
                            <i class="fas fa-map-marker-alt left"></i>
                        </div>
                    </div>
                </div>

                <!-- Password -->
                <div class="field">
                    <label for="password">Password <span class="label-required">*</span></label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" placeholder="Min. 6 characters" required minlength="6">
                        <i class="fas fa-lock left"></i>
                        <i class="fas fa-eye toggle-pw" id="togglePassword"></i>
                    </div>
                    <div class="strength-row">
                        <div class="strength-bar">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <span class="strength-label" id="strengthLabel"></span>
                    </div>
                    <div class="info-text">Must contain uppercase, lowercase, and number</div>
                </div>

                <!-- Confirm Password -->
                <div class="field">
                    <label for="confirm_password">Confirm Password <span class="label-required">*</span></label>
                    <div class="input-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required>
                        <i class="fas fa-lock left"></i>
                        <i class="fas match-icon" id="matchIcon"></i>
                        <i class="fas fa-eye toggle-pw" id="toggleConfirm"></i>
                    </div>
                </div>

            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-user-plus"></i>
                Create Account
            </button>
        </form>

        <p class="footer-link">
            Already have an account? <a href="login.php">Sign in</a>
        </p>

    </div>
</div>

<script>
    // Account type selection
    function selectAccountType(type) {
        document.getElementById('accountType').value = type;
        
        document.querySelectorAll('.type-option').forEach(opt => {
            opt.classList.remove('selected');
        });
        document.querySelector(`.type-option[data-type="${type}"]`).classList.add('selected');
        
        const companyFields = document.getElementById('companyFields');
        const businessName = document.getElementById('business_name');
        const tinNumber = document.getElementById('tin_number');
        
        if (type === 'company') {
            companyFields.classList.add('active');
            if (businessName) businessName.required = true;
            if (tinNumber) tinNumber.required = true;
        } else {
            companyFields.classList.remove('active');
            if (businessName) businessName.required = false;
            if (tinNumber) tinNumber.required = false;
        }
    }

    // Password strength checker
    const pwInput = document.getElementById('password');
    const strengthFill = document.getElementById('strengthFill');
    const strengthLbl = document.getElementById('strengthLabel');

    function checkPasswordStrength(password) {
        let score = 0;
        if (password.length >= 6) score++;
        if (password.length >= 10) score++;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;
        
        const levels = [
            { label: 'Very Weak', color: '#ef4444', width: '20%' },
            { label: 'Weak', color: '#f59e0b', width: '40%' },
            { label: 'Fair', color: '#f59e0b', width: '60%' },
            { label: 'Good', color: '#3b82f6', width: '80%' },
            { label: 'Strong', color: '#10b981', width: '100%' }
        ];
        
        const index = Math.min(Math.floor(score / 1.5), 4);
        const level = levels[index];
        
        strengthFill.style.width = level.width;
        strengthFill.style.backgroundColor = level.color;
        strengthLbl.textContent = password.length === 0 ? '' : level.label;
        strengthLbl.style.color = level.color;
        
        return score >= 3;
    }

    pwInput.addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkMatch();
    });

    // Password match checker
    const confirmInput = document.getElementById('confirm_password');
    const matchIcon = document.getElementById('matchIcon');

    function checkMatch() {
        const pw = pwInput.value;
        const cpw = confirmInput.value;
        
        if (!cpw) {
            matchIcon.classList.remove('visible', 'ok', 'bad', 'fa-check', 'fa-xmark');
            return;
        }
        
        const ok = pw === cpw && pw.length > 0;
        matchIcon.className = `fas match-icon visible ${ok ? 'ok fa-check' : 'bad fa-xmark'}`;
    }

    confirmInput.addEventListener('input', checkMatch);

    // Password visibility toggles
    function makeToggle(btnId, inputId) {
        const btn = document.getElementById(btnId);
        if (btn) {
            btn.addEventListener('click', function() {
                const inp = document.getElementById(inputId);
                const show = inp.type === 'password';
                inp.type = show ? 'text' : 'password';
                this.classList.toggle('fa-eye', !show);
                this.classList.toggle('fa-eye-slash', show);
            });
        }
    }

    makeToggle('togglePassword', 'password');
    makeToggle('toggleConfirm', 'confirm_password');
    
    // Phone number formatting hint
    const phoneInput = document.getElementById('phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            let value = this.value.replace(/[^0-9+]/g, '');
            if (value.startsWith('0') && value.length === 10) {
                this.style.borderColor = '#10b981';
            } else if (value.startsWith('+251') && value.length === 13) {
                this.style.borderColor = '#10b981';
            } else if (value === '') {
                this.style.borderColor = '';
            } else {
                this.style.borderColor = '#ef4444';
            }
        });
    }
</script>
</body>
</html>