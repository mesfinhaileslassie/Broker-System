<?php
// user/apply_job.php - Apply for Job with Service Fee Only

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
    $portfolio_url = sanitizeUrl($_POST['portfolio_url'] ?? '');
    
    // File upload for CV/Resume
    $resume_file = '';
    $resume_errors = [];
    
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK && $_FILES['resume']['size'] > 0) {
        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 5242880;
        
        if ($_FILES['resume']['size'] > $max_size) {
            $resume_errors[] = "File must be less than 5MB";
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $_FILES['resume']['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            $resume_errors[] = "PDF or DOC/DOCX only";
        }
        
        if (empty($resume_errors)) {
            $ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
            $filename = 'resume_' . $user_id . '_' . time() . '.' . $ext;
            $target_file = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $target_file)) {
                $resume_file = $filename;
            }
        }
    }
    
    // Update user profile
    if (!empty($first_name) && !empty($last_name)) {
        $full_name = $first_name . ' ' . $last_name;
        $conn->query("UPDATE users SET full_name = '$full_name', first_name = '$first_name', last_name = '$last_name', age = $age, gender = '$gender', phone = '$phone', email = '$email' WHERE id = $user_id");
    }
    
    // Create transaction - ONLY SERVICE FEE
    $depositAmount = 0;
    $remainingAmount = $job['price'];
    
    $stmt = $conn->prepare("
        INSERT INTO transactions (
            listing_id, buyer_id, seller_id, total_amount, 
            deposit_amount, commission_amount, remaining_balance, 
            status, created_at, cover_letter
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW(), ?)
    ");
    $stmt->bind_param("iiidddds", $job_id, $user_id, $job['company_id'], $job['price'], $depositAmount, $serviceFee, $remainingAmount, $cover_letter);
    $stmt->execute();
    $transaction_id = $conn->insert_id;
    
    // Generate payment code
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
    } while ($code_check->num_rows > 0);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    
    $stmt2 = $conn->prepare("INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) VALUES (?, ?, ?, ?, 'service_fee', ?, 'pending')");
    $stmt2->bind_param("siids", $payment_code, $transaction_id, $serviceFee, $user_id, $expires_at);
    $stmt2->execute();
    
    // Create notification for company
    $conn->query("INSERT INTO notifications (user_id, title, message, link, created_at) VALUES ({$job['company_id']}, 'New Job Application', 'A new application has been submitted for {$job['title']}', 'transaction.php?id=$transaction_id', NOW())");
    
    $conn->close();
    
    header("Location: pay_application.php?transaction_id=$transaction_id&code=$payment_code");
    exit;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for <?php echo htmlspecialchars($job['title']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea15, #764ba215); min-height: 100vh; }
        
        .apply-container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        
        .job-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 32px;
            padding: 32px;
            margin-bottom: 28px;
            color: white;
        }
        
        .job-title { font-size: 28px; font-weight: 800; margin-bottom: 8px; }
        .company-name { font-size: 14px; opacity: 0.9; margin-bottom: 16px; }
        .job-salary { font-size: 32px; font-weight: 800; }
        .job-salary small { font-size: 14px; font-weight: normal; opacity: 0.8; }
        
        .card {
            background: white;
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .card-title i { color: #667eea; font-size: 24px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
        .form-group.full-width { grid-column: span 2; }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
            font-size: 13px;
        }
        
        .required { color: #ef4444; margin-left: 4px; }
        
        .input-wrapper { position: relative; }
        .input-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 16px 12px 42px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }
        
        textarea {
            padding: 12px 16px;
            min-height: 120px;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        
        input.valid, select.valid, textarea.valid { border-color: #10b981; background: #f0fdf4; }
        input.invalid, select.invalid, textarea.invalid { border-color: #ef4444; background: #fef2f2; }
        
        .error-msg {
            font-size: 11px;
            color: #ef4444;
            margin-top: 6px;
            display: none;
        }
        
        .error-msg.show { display: block; }
        
        .valid-msg {
            font-size: 11px;
            color: #10b981;
            margin-top: 6px;
            display: none;
        }
        
        .valid-msg.show { display: block; }
        
        .radio-group {
            display: flex;
            gap: 24px;
            align-items: center;
            padding: 10px 0;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .radio-option input {
            width: 18px;
            height: 18px;
            margin: 0;
            padding: 0;
        }
        
        .file-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 14px;
            padding: 25px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8fafc;
        }
        
        .file-upload:hover { border-color: #667eea; background: #eef2ff; }
        .file-upload i { font-size: 36px; color: #667eea; margin-bottom: 10px; display: block; }
        .file-upload p { font-size: 13px; color: #64748b; }
        .file-upload small { font-size: 11px; color: #64748b; }
        input[type="file"] { display: none; }
        
        .payment-box {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-radius: 24px;
            padding: 24px;
            text-align: center;
            border: 2px solid #10b981;
            margin-top: 24px;
        }
        
        .payment-amount { font-size: 38px; font-weight: 800; color: #059669; }
        
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 24px;
        }
        
        .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(102,126,234,0.4); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        
        .info-text { font-size: 11px; color: #64748b; margin-top: 6px; }
        
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
            .job-title { font-size: 22px; }
            .job-salary { font-size: 24px; }
            .card { padding: 24px; }
        }
    </style>
</head>
<body>

<div class="apply-container">
    <div class="job-header">
        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
        <div class="company-name"><i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?></div>
        <div class="job-salary"><?php echo formatMoney($job['price']); ?><small>/month</small></div>
    </div>
    
    <div class="card">
        <div class="card-title">
            <i class="fas fa-user-plus"></i> Complete Your Application
        </div>
        
        <form method="POST" enctype="multipart/form-data" id="applicationForm">
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="first_name" id="firstName" placeholder="Enter your first name" value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>">
                    </div>
                    <div class="error-msg" id="firstNameError">First name is required (min 2 letters)</div>
                    <div class="valid-msg" id="firstNameValid">✓ Looks good</div>
                </div>
                
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="last_name" id="lastName" placeholder="Enter your last name" value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>">
                    </div>
                    <div class="error-msg" id="lastNameError">Last name is required (min 2 letters)</div>
                    <div class="valid-msg" id="lastNameValid">✓ Looks good</div>
                </div>
                
                <div class="form-group">
                    <label>Age <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-alt"></i>
                        <input type="number" name="age" id="age" placeholder="18-100" min="18" max="100" value="<?php echo htmlspecialchars($user_data['age'] ?? ''); ?>">
                    </div>
                    <div class="error-msg" id="ageError">Age must be between 18 and 100</div>
                    <div class="valid-msg" id="ageValid">✓ Looks good</div>
                </div>
                
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <div class="radio-group" id="genderGroup">
                        <label class="radio-option">
                            <input type="radio" name="gender" value="Male" <?php echo ($user_data['gender'] ?? '') == 'Male' ? 'checked' : ''; ?>>
                            <span>Male</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="gender" value="Female" <?php echo ($user_data['gender'] ?? '') == 'Female' ? 'checked' : ''; ?>>
                            <span>Female</span>
                        </label>
                    </div>
                    <div class="error-msg" id="genderError">Please select Male or Female</div>
                </div>
                
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" id="email" placeholder="you@example.com" value="<?php echo htmlspecialchars($user_data['email'] ?? $_SESSION['user_email']); ?>">
                    </div>
                    <div class="error-msg" id="emailError">Enter a valid email address</div>
                    <div class="valid-msg" id="emailValid">✓ Valid email</div>
                </div>
                
                <div class="form-group">
                    <label>Phone Number <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" name="phone" id="phone" placeholder="+251912345678" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
                    </div>
                    <div class="error-msg" id="phoneError">Enter valid Ethiopian number (+251XXXXXXXXX)</div>
                    <div class="valid-msg" id="phoneValid">✓ Valid number</div>
                </div>
                
                <div class="form-group full-width">
                    <label>Cover Letter <span class="required">*</span></label>
                    <textarea name="cover_letter" id="coverLetter" placeholder="Introduce yourself, explain why you're interested, and highlight your relevant skills... (Minimum 50 characters)"></textarea>
                    <div class="error-msg" id="coverLetterError">Cover letter must be at least 50 characters</div>
                    <div class="valid-msg" id="coverLetterValid">✓ Good length</div>
                    <div class="info-text" id="charCount">0 / 50 characters minimum</div>
                </div>
                
                <div class="form-group">
                    <label>Portfolio/Website (Optional)</label>
                    <div class="input-wrapper">
                        <i class="fas fa-globe"></i>
                        <input type="url" name="portfolio_url" id="portfolioUrl" placeholder="https://your-portfolio.com">
                    </div>
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
                    <div class="error-msg" id="resumeError">Resume/CV is required (PDF, DOC, DOCX)</div>
                </div>
            </div>
            
            <div class="payment-box">
                <div style="font-size: 13px; color: #065f46;"><i class="fas fa-shield-alt"></i> Service Fee (<?php echo $commissionPercent; ?>%)</div>
                <div class="payment-amount"><?php echo formatMoney($serviceFee); ?></div>
                <div style="font-size: 11px; color: #065f46; margin-top: 8px;">Secure payment held in escrow until job completion</div>
            </div>
            
            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="fas fa-paper-plane"></i> Submit Application & Pay Service Fee
            </button>
        </form>
    </div>
</div>

<script>
    // Real-time validation functions
    function validateFirstName() {
        const input = document.getElementById('firstName');
        const value = input.value.trim();
        const isValid = value.length >= 2 && /^[a-zA-Z\s\'-]+$/.test(value);
        
        if (value.length === 0) {
            input.classList.remove('valid', 'invalid');
            document.getElementById('firstNameError').classList.remove('show');
            document.getElementById('firstNameValid').classList.remove('show');
        } else if (isValid) {
            input.classList.add('valid');
            input.classList.remove('invalid');
            document.getElementById('firstNameError').classList.remove('show');
            document.getElementById('firstNameValid').classList.add('show');
        } else {
            input.classList.add('invalid');
            input.classList.remove('valid');
            document.getElementById('firstNameError').classList.add('show');
            document.getElementById('firstNameValid').classList.remove('show');
        }
        return isValid;
    }
    
    function validateLastName() {
        const input = document.getElementById('lastName');
        const value = input.value.trim();
        const isValid = value.length >= 2 && /^[a-zA-Z\s\'-]+$/.test(value);
        
        if (value.length === 0) {
            input.classList.remove('valid', 'invalid');
            document.getElementById('lastNameError').classList.remove('show');
            document.getElementById('lastNameValid').classList.remove('show');
        } else if (isValid) {
            input.classList.add('valid');
            input.classList.remove('invalid');
            document.getElementById('lastNameError').classList.remove('show');
            document.getElementById('lastNameValid').classList.add('show');
        } else {
            input.classList.add('invalid');
            input.classList.remove('valid');
            document.getElementById('lastNameError').classList.add('show');
            document.getElementById('lastNameValid').classList.remove('show');
        }
        return isValid;
    }
    
    function validateAge() {
        const input = document.getElementById('age');
        const value = parseInt(input.value);
        const isValid = value >= 18 && value <= 100;
        
        if (input.value === '') {
            input.classList.remove('valid', 'invalid');
            document.getElementById('ageError').classList.remove('show');
            document.getElementById('ageValid').classList.remove('show');
        } else if (isValid) {
            input.classList.add('valid');
            input.classList.remove('invalid');
            document.getElementById('ageError').classList.remove('show');
            document.getElementById('ageValid').classList.add('show');
        } else {
            input.classList.add('invalid');
            input.classList.remove('valid');
            document.getElementById('ageError').classList.add('show');
            document.getElementById('ageValid').classList.remove('show');
        }
        return isValid;
    }
    
    function validateGender() {
        const radios = document.querySelectorAll('input[name="gender"]');
        let isChecked = false;
        radios.forEach(radio => { if (radio.checked) isChecked = true; });
        
        const errorEl = document.getElementById('genderError');
        if (!isChecked && document.querySelector('input[name="gender"]:checked') === null) {
            errorEl.classList.add('show');
        } else {
            errorEl.classList.remove('show');
        }
        return isChecked;
    }
    
    function validateEmail() {
        const input = document.getElementById('email');
        const value = input.value.trim();
        const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        
        if (value === '') {
            input.classList.remove('valid', 'invalid');
            document.getElementById('emailError').classList.remove('show');
            document.getElementById('emailValid').classList.remove('show');
        } else if (isValid) {
            input.classList.add('valid');
            input.classList.remove('invalid');
            document.getElementById('emailError').classList.remove('show');
            document.getElementById('emailValid').classList.add('show');
        } else {
            input.classList.add('invalid');
            input.classList.remove('valid');
            document.getElementById('emailError').classList.add('show');
            document.getElementById('emailValid').classList.remove('show');
        }
        return isValid;
    }
    
    function validatePhone() {
        const input = document.getElementById('phone');
        const value = input.value.trim();
        const isValid = /^\+251[0-9]{9}$/.test(value);
        
        if (value === '') {
            input.classList.remove('valid', 'invalid');
            document.getElementById('phoneError').classList.remove('show');
            document.getElementById('phoneValid').classList.remove('show');
        } else if (isValid) {
            input.classList.add('valid');
            input.classList.remove('invalid');
            document.getElementById('phoneError').classList.remove('show');
            document.getElementById('phoneValid').classList.add('show');
        } else {
            input.classList.add('invalid');
            input.classList.remove('valid');
            document.getElementById('phoneError').classList.add('show');
            document.getElementById('phoneValid').classList.remove('show');
        }
        return isValid;
    }
    
    function validateCoverLetter() {
        const input = document.getElementById('coverLetter');
        const value = input.value.trim();
        const length = value.length;
        const isValid = length >= 50;
        
        document.getElementById('charCount').innerHTML = `${length} / 50 characters minimum`;
        
        if (value === '') {
            input.classList.remove('valid', 'invalid');
            document.getElementById('coverLetterError').classList.remove('show');
            document.getElementById('coverLetterValid').classList.remove('show');
        } else if (isValid) {
            input.classList.add('valid');
            input.classList.remove('invalid');
            document.getElementById('coverLetterError').classList.remove('show');
            document.getElementById('coverLetterValid').classList.add('show');
        } else {
            input.classList.add('invalid');
            input.classList.remove('valid');
            document.getElementById('coverLetterError').classList.add('show');
            document.getElementById('coverLetterValid').classList.remove('show');
        }
        return isValid;
    }
    
    function validateResume() {
        const fileInput = document.getElementById('resumeFile');
        const hasFile = fileInput.files.length > 0;
        
        if (hasFile) {
            document.getElementById('resumeError').classList.remove('show');
        } else {
            document.getElementById('resumeError').classList.add('show');
        }
        return hasFile;
    }
    
    function validateForm() {
        const isValid = validateFirstName() && validateLastName() && validateAge() && 
                        validateGender() && validateEmail() && validatePhone() && 
                        validateCoverLetter() && validateResume();
        
        document.getElementById('submitBtn').disabled = !isValid;
        return isValid;
    }
    
    // Attach event listeners
    document.getElementById('firstName').addEventListener('input', () => { validateFirstName(); validateForm(); });
    document.getElementById('lastName').addEventListener('input', () => { validateLastName(); validateForm(); });
    document.getElementById('age').addEventListener('input', () => { validateAge(); validateForm(); });
    document.querySelectorAll('input[name="gender"]').forEach(radio => {
        radio.addEventListener('change', () => { validateGender(); validateForm(); });
    });
    document.getElementById('email').addEventListener('input', () => { validateEmail(); validateForm(); });
    document.getElementById('phone').addEventListener('input', () => { validatePhone(); validateForm(); });
    document.getElementById('coverLetter').addEventListener('input', () => { validateCoverLetter(); validateForm(); });
    document.getElementById('resumeFile').addEventListener('change', () => { validateResume(); validateForm(); });
    
    // File upload display
    const fileInput = document.getElementById('resumeFile');
    const fileNameDiv = document.getElementById('fileName');
    const selectedFileName = document.getElementById('selectedFileName');
    
    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            selectedFileName.textContent = this.files[0].name;
            fileNameDiv.style.display = 'block';
            validateResume();
            validateForm();
        } else {
            fileNameDiv.style.display = 'none';
            validateResume();
            validateForm();
        }
    });
    
    // Phone number auto-format
    const phoneInput = document.getElementById('phone');
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
        validatePhone();
        validateForm();
    });
    
    // Initial validation
    validateForm();
</script>

</body>
</html>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>