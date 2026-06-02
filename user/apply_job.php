<?php
// user/apply_job.php - Apply for Job with Service Fee Only (No Deposit)
// REDESIGNED with attractive modern UI

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';
require_once '../includes/upload.php';

requireLogin();

$page_title = 'Apply for Job';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$job_id = sanitizeInt($_GET['id'] ?? 0);
$error = '';
$success = '';

// Get user current data
$user_data = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();

// Get job details
$job = $conn->query("
    SELECT l.*, u.full_name as company_name, u.id as company_id, u.email as company_email,
           u.phone as company_phone,
           l.admin_commission_percent
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $job_id 
    AND l.type = 'job' 
    AND l.status = 'active' 
    AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$job) {
    $_SESSION['error'] = "Job not found or no longer available";
    header('Location: jobs.php');
    exit;
}

// Check if already applied
$existing = $conn->query("
    SELECT id, status FROM transactions 
    WHERE listing_id = $job_id AND buyer_id = $user_id
");
if ($existing->num_rows > 0) {
    $existing_txn = $existing->fetch_assoc();
    header("Location: transaction.php?id={$existing_txn['id']}");
    exit;
}

// Create uploads directory if not exists
$upload_dir = '../uploads/resumes/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Calculate payment amount - ONLY SERVICE FEE (commission)
$commissionPercent = $job['admin_commission_percent'] ?? 15;
$serviceFee = $job['price'] * ($commissionPercent / 100);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get user information
    $first_name = sanitizeString($_POST['first_name'] ?? '');
    $last_name = sanitizeString($_POST['last_name'] ?? '');
    $age = sanitizeInt($_POST['age'] ?? 0);
    $gender = sanitizeString($_POST['gender'] ?? '');
    $email = sanitizeEmail($_POST['email'] ?? '');
    $phone = sanitizePhone($_POST['phone'] ?? '');
    $cover_letter = sanitizeString($_POST['cover_letter'] ?? '');
    $expected_salary = sanitizeFloat($_POST['expected_salary'] ?? $job['price']);
    $portfolio_url = sanitizeUrl($_POST['portfolio_url'] ?? '');
    $linkedin_url = sanitizeUrl($_POST['linkedin_url'] ?? '');
    $availability_date = sanitizeString($_POST['availability_date'] ?? '');
    $hear_about = sanitizeString($_POST['hear_about'] ?? '');
    
    // File upload for CV/Resume
    $resume_file = '';
    $resume_errors = [];
    
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 5242880; // 5MB
        
        if ($_FILES['resume']['size'] > $max_size) {
            $resume_errors[] = "Resume file must be less than 5MB";
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $_FILES['resume']['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            $resume_errors[] = "Resume must be PDF or DOC/DOCX format";
        }
        
        if (empty($resume_errors)) {
            $ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
            $filename = 'resume_' . $user_id . '_' . time() . '.' . $ext;
            $target_file = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $target_file)) {
                $resume_file = $filename;
            } else {
                $resume_errors[] = "Failed to upload resume";
            }
        }
    } else {
        $resume_errors[] = "Resume/CV is required";
    }
    
    $errors = [];
    
    // Validation
    if (empty($first_name)) {
        $errors[] = "First name is required";
    } elseif (strlen($first_name) < 2) {
        $errors[] = "First name must be at least 2 characters";
    }
    
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    } elseif (strlen($last_name) < 2) {
        $errors[] = "Last name must be at least 2 characters";
    }
    
    if ($age < 18 || $age > 100) {
        $errors[] = "Age must be between 18 and 100";
    }
    
    $valid_genders = ['Male', 'Female', 'Other', 'Prefer not to say'];
    if (!in_array($gender, $valid_genders)) {
        $errors[] = "Please select a valid gender";
    }
    
    if (empty($email)) {
        $errors[] = "Email address is required";
    } elseif (!validateEmail($email)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    } elseif (!validatePhone($phone)) {
        $errors[] = "Please enter a valid Ethiopian phone number (+251XXXXXXXXX)";
    }
    
    if (empty($cover_letter)) {
        $errors[] = "Please provide a cover letter explaining why you're a good fit";
    } elseif (strlen($cover_letter) < 50) {
        $errors[] = "Cover letter must be at least 50 characters";
    }
    
    if ($expected_salary < 0) {
        $errors[] = "Please enter a valid expected salary";
    }
    
    if (!empty($resume_errors)) {
        $errors = array_merge($errors, $resume_errors);
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Update user profile with collected info
            $update_user = $conn->prepare("
                UPDATE users 
                SET full_name = ?, 
                    first_name = ?,
                    last_name = ?,
                    age = ?,
                    gender = ?,
                    phone = ?,
                    email = ?
                WHERE id = ?
            ");
            $full_name = $first_name . ' ' . $last_name;
            $update_user->bind_param("sssissis", $full_name, $first_name, $last_name, $age, $gender, $phone, $email, $user_id);
            $update_user->execute();
            
            // Create transaction - ONLY SERVICE FEE
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    listing_id, buyer_id, seller_id, total_amount, 
                    deposit_amount, commission_amount, remaining_balance, 
                    status, created_at, cover_letter, expected_salary
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW(), ?, ?)
            ");
            $depositAmount = 0; // No deposit for jobs
            $remainingAmount = $job['price']; // Full salary to be paid after completion
            $stmt->bind_param("iiiddddsd", 
                $job_id, $user_id, $job['company_id'], 
                $job['price'], $depositAmount, $serviceFee, $remainingAmount,
                $cover_letter, $expected_salary
            );
            $stmt->execute();
            $transaction_id = $conn->insert_id;
            
            // Store application details
            $app_stmt = $conn->prepare("
                INSERT INTO job_applications (
                    transaction_id, job_id, applicant_id, resume_file, portfolio_url, 
                    linkedin_url, availability_date, cover_letter, expected_salary, 
                    hear_about, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $app_stmt->bind_param("iiisssssss", 
                $transaction_id, $job_id, $user_id, $resume_file, $portfolio_url,
                $linkedin_url, $availability_date, $cover_letter, $expected_salary, $hear_about
            );
            $app_stmt->execute();
            
            // Generate payment code for service fee only
            do {
                $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
            } while ($code_check->num_rows > 0);
            
            $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            
            $stmt2 = $conn->prepare("
                INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
                VALUES (?, ?, ?, ?, 'service_fee', ?, 'pending')
            ");
            $stmt2->bind_param("siids", $payment_code, $transaction_id, $serviceFee, $user_id, $expires_at);
            $stmt2->execute();
            
            // Create notification for company
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, created_at) 
                VALUES ({$job['company_id']}, 'New Job Application', 
                'A new application has been submitted for {$job['title']}', 
                'transaction.php?id=$transaction_id', NOW())
            ");
            
            $conn->commit();
            
            // Redirect to payment page
            header("Location: pay_application.php?transaction_id=$transaction_id&code=$payment_code");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to submit application: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for <?php echo htmlspecialchars($job['title']); ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #667eea20, #764ba220);
            min-height: 100vh;
        }
        
        .apply-container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        
        /* Job Header */
        .job-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 32px;
            padding: 40px;
            margin-bottom: 32px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .job-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            animation: moveBackground 40s linear infinite;
        }
        
        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(30px, 30px); }
        }
        
        .job-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 12px;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }
        
        .job-title {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
        }
        
        .company-name {
            font-size: 15px;
            opacity: 0.9;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .job-salary {
            font-size: 36px;
            font-weight: 800;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }
        
        .job-salary small {
            font-size: 14px;
            font-weight: normal;
            opacity: 0.8;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .card-title i {
            color: var(--primary);
            font-size: 24px;
        }
        
        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group.full-width {
            grid-column: span 2;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 13px;
        }
        
        .required {
            color: var(--danger);
            margin-left: 4px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 14px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 16px 12px 42px;
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }
        
        textarea {
            padding: 12px 16px;
            resize: vertical;
            min-height: 120px;
        }
        
        textarea + i {
            display: none;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        
        /* File Upload */
        .file-upload {
            border: 2px dashed var(--border);
            border-radius: 14px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: var(--light);
        }
        
        .file-upload:hover {
            border-color: var(--primary);
            background: #eef2ff;
        }
        
        .file-upload i {
            font-size: 40px;
            color: var(--primary);
            margin-bottom: 12px;
            display: block;
        }
        
        .file-upload p {
            font-size: 13px;
            color: var(--gray);
        }
        
        .file-upload small {
            font-size: 11px;
            color: var(--gray);
        }
        
        #fileName {
            margin-top: 12px;
            font-size: 12px;
            color: var(--success);
            display: none;
        }
        
        input[type="file"] {
            display: none;
        }
        
        /* Payment Box */
        .payment-box {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-radius: 24px;
            padding: 24px;
            text-align: center;
            border: 2px solid var(--success);
        }
        
        .payment-label {
            font-size: 13px;
            color: #065f46;
            margin-bottom: 8px;
        }
        
        .payment-amount {
            font-size: 42px;
            font-weight: 800;
            color: #059669;
        }
        
        .payment-note {
            font-size: 12px;
            color: #065f46;
            margin-top: 12px;
        }
        
        /* Buttons */
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 16px;
        }
        
        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102,126,234,0.4);
        }
        
        /* Alert */
        .alert {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-error {
            background: #fee2e2;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-success {
            background: #d1fae5;
            color: #059669;
            border-left: 4px solid #059669;
        }
        
        .info-text {
            font-size: 11px;
            color: var(--gray);
            margin-top: 6px;
        }
        
        /* Radio Group */
        .radio-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .radio-option input {
            width: auto;
            padding: 0;
            margin: 0;
        }
        
        .radio-option label {
            margin: 0;
            font-weight: normal;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
            .job-title {
                font-size: 24px;
            }
            .job-salary {
                font-size: 28px;
            }
            .card {
                padding: 24px;
            }
        }
    </style>
</head>
<body>

<div class="apply-container">
    <!-- Job Header -->
    <div class="job-header">
        <div class="job-badge">
            <i class="fas fa-briefcase"></i> Job Opportunity
        </div>
        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
        <div class="company-name">
            <i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?>
            <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                <i class="fas fa-check-circle"></i> Verified Employer
            </span>
        </div>
        <div class="job-salary">
            <?php echo formatMoney($job['price']); ?><small>/month</small>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="font-size: 20px;"></i>
            <div><?php echo $error; ?></div>
        </div>
    <?php endif; ?>
    
    <!-- Application Form -->
    <div class="card">
        <div class="card-title">
            <i class="fas fa-user-plus"></i>
            Complete Your Application
        </div>
        
        <form method="POST" enctype="multipart/form-data" id="applicationForm">
            <!-- Personal Information Section -->
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; color: var(--dark); margin-bottom: 16px;">
                    <i class="fas fa-user-circle" style="color: var(--primary);"></i> Personal Information
                </h3>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="first_name" required placeholder="Enter your first name" 
                               value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="last_name" required placeholder="Enter your last name"
                               value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Age <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-alt"></i>
                        <input type="number" name="age" required placeholder="18-100" min="18" max="100"
                               value="<?php echo htmlspecialchars($user_data['age'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-venus-mars"></i>
                        <select name="gender" required>
                            <option value="">Select gender</option>
                            <option value="Male" <?php echo ($user_data['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($user_data['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($user_data['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                            <option value="Prefer not to say" <?php echo ($user_data['gender'] ?? '') == 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" required placeholder="you@example.com"
                               value="<?php echo htmlspecialchars($user_data['email'] ?? $_SESSION['user_email']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Phone Number <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" name="phone" required placeholder="+251XXXXXXXXX"
                               value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
                    </div>
                    <div class="info-text">Format: +251912345678</div>
                </div>
            </div>
            
            <!-- Professional Information -->
            <div style="margin: 32px 0 24px;">
                <h3 style="font-size: 16px; color: var(--dark); margin-bottom: 16px;">
                    <i class="fas fa-briefcase" style="color: var(--primary);"></i> Professional Information
                </h3>
            </div>
            
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Cover Letter <span class="required">*</span></label>
                    <textarea name="cover_letter" required placeholder="Introduce yourself, explain why you're interested in this position, and highlight your relevant skills and experience..."></textarea>
                    <div class="info-text">Minimum 50 characters. Be specific about how you can contribute to the company.</div>
                </div>
                
                <div class="form-group">
                    <label>Expected Salary (ETB/month)</label>
                    <div class="input-wrapper">
                        <i class="fas fa-money-bill-wave"></i>
                        <input type="number" name="expected_salary" step="100" value="<?php echo $job['price']; ?>" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Availability Date</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-check"></i>
                        <input type="date" name="availability_date">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Portfolio/Website URL</label>
                    <div class="input-wrapper">
                        <i class="fas fa-globe"></i>
                        <input type="url" name="portfolio_url" placeholder="https://your-portfolio.com">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>LinkedIn Profile</label>
                    <div class="input-wrapper">
                        <i class="fab fa-linkedin"></i>
                        <input type="url" name="linkedin_url" placeholder="https://linkedin.com/in/yourprofile">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>How did you hear about us?</label>
                    <div class="input-wrapper">
                        <i class="fas fa-chart-line"></i>
                        <select name="hear_about">
                            <option value="">Select</option>
                            <option value="LinkedIn">LinkedIn</option>
                            <option value="Facebook">Facebook</option>
                            <option value="Telegram">Telegram</option>
                            <option value="Google">Google Search</option>
                            <option value="Friend">Friend Referral</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Resume Upload -->
            <div style="margin: 32px 0 24px;">
                <h3 style="font-size: 16px; color: var(--dark); margin-bottom: 16px;">
                    <i class="fas fa-file-alt" style="color: var(--primary);"></i> Documents
                </h3>
            </div>
            
            <div class="form-group full-width">
                <label>Resume/CV <span class="required">*</span></label>
                <div class="file-upload" onclick="document.getElementById('resumeFile').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Click to upload your Resume/CV</p>
                    <small>PDF, DOC, or DOCX (Max 5MB)</small>
                </div>
                <input type="file" name="resume" id="resumeFile" accept=".pdf,.doc,.docx">
                <div id="fileName" style="display: none; margin-top: 10px; padding: 8px; background: #d1fae5; border-radius: 8px; text-align: center;">
                    <i class="fas fa-check-circle"></i> <span id="selectedFileName"></span>
                </div>
            </div>
            
            <!-- Payment Section -->
            <div style="margin: 32px 0 24px;">
                <h3 style="font-size: 16px; color: var(--dark); margin-bottom: 16px;">
                    <i class="fas fa-credit-card" style="color: var(--primary);"></i> Payment Summary
                </h3>
            </div>
            
            <div class="payment-box">
                <div class="payment-label">
                    <i class="fas fa-shield-alt"></i> Service Fee (<?php echo $commissionPercent; ?>%)
                </div>
                <div class="payment-amount">
                    <?php echo formatMoney($serviceFee); ?>
                </div>
                <div class="payment-note">
                    <i class="fas fa-lock"></i> Secure payment held in escrow until job completion
                </div>
                <div class="payment-note" style="margin-top: 8px;">
                    <small>You will pay only the service fee now. The full salary (<?php echo formatMoney($job['price']); ?>) will be paid after you complete the job successfully.</small>
                </div>
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i>
                Submit Application & Pay Service Fee
            </button>
        </form>
    </div>
</div>

<script>
    // File upload display
    const fileInput = document.getElementById('resumeFile');
    const fileNameDiv = document.getElementById('fileName');
    const selectedFileName = document.getElementById('selectedFileName');
    
    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            selectedFileName.textContent = this.files[0].name;
            fileNameDiv.style.display = 'block';
        } else {
            fileNameDiv.style.display = 'none';
        }
    });
    
    // Phone number formatting
    const phoneInput = document.querySelector('input[name="phone"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            let value = this.value.replace(/[^0-9+]/g, '');
            if (value.length > 0 && !value.startsWith('+')) {
                if (value.startsWith('0')) {
                    value = '+251' + value.substring(1);
                } else if (value.length === 9) {
                    value = '+251' + value;
                }
                this.value = value;
            }
        });
    }
</script>

</body>
</html>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>