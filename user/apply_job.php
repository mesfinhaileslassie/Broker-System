<?php
// user/apply_job.php - Apply for Job with Service Fee Payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';

requireLogin();

$page_title = 'Apply for Job';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$job_id = intval($_GET['id'] ?? 0);
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
    AND l.approval_status = 'approved'
    AND l.status = 'active'
")->fetch_assoc();

if (!$job) {
    $_SESSION['error'] = "Job not found or no longer available";
    header('Location: jobs.php');
    exit;
}

// Check if already applied
$existing = $conn->query("
    SELECT ja.id, ja.status FROM job_applications ja
    WHERE ja.job_id = $job_id AND ja.applicant_id = $user_id
");
if ($existing->num_rows > 0) {
    $existing_app = $existing->fetch_assoc();
    if ($existing_app['status'] == 'pending') {
        $_SESSION['error'] = "You have already applied for this job. Your application is pending review.";
    } elseif ($existing_app['status'] == 'accepted') {
        $_SESSION['success'] = "You have been accepted for this job! Please check your email.";
    } else {
        $_SESSION['error'] = "You have already applied for this job.";
    }
    header("Location: jobs.php");
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
    $age = intval($_POST['age'] ?? 0);
    $gender = sanitizeString($_POST['gender'] ?? '');
    $email = sanitizeEmail($_POST['email'] ?? '');
    $phone = sanitizePhone($_POST['phone'] ?? '');
    $cover_letter = sanitizeString($_POST['cover_letter'] ?? '');
    $portfolio_url = sanitizeUrl($_POST['portfolio_url'] ?? '');
    $linkedin_url = sanitizeUrl($_POST['linkedin_url'] ?? '');
    $expected_salary = sanitizeFloat($_POST['expected_salary'] ?? $job['price']);
    $availability_date = sanitizeString($_POST['availability_date'] ?? '');
    
    // File upload for CV/Resume
    $resume_file = '';
    $resume_errors = [];
    
    if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK && $_FILES['resume']['size'] > 0) {
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
    
    if (!in_array($gender, ['Male', 'Female'])) {
        $errors[] = "Please select a valid gender (Male or Female)";
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
        $errors[] = "Cover letter is required";
    } elseif (strlen($cover_letter) < 50) {
        $errors[] = "Cover letter must be at least 50 characters";
    } elseif (strlen($cover_letter) > 5000) {
        $errors[] = "Cover letter must not exceed 5000 characters";
    }
    
    if (!empty($resume_errors)) {
        $errors = array_merge($errors, $resume_errors);
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Update user profile
            $full_name = $first_name . ' ' . $last_name;
            $conn->query("
                UPDATE users 
                SET full_name = '$full_name', 
                    first_name = '$first_name',
                    last_name = '$last_name',
                    age = $age,
                    gender = '$gender',
                    phone = '$phone',
                    email = '$email'
                WHERE id = $user_id
            ");
            
            // Create transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    listing_id, buyer_id, seller_id, total_amount, 
                    deposit_amount, commission_amount, remaining_balance, 
                    status, created_at, cover_letter, expected_salary
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW(), ?, ?)
            ");
            $depositAmount = 0;
            $remainingAmount = $job['price'];
            $stmt->bind_param("iiiddddsd", 
                $job_id, $user_id, $job['company_id'], 
                $job['price'], $depositAmount, $serviceFee, $remainingAmount,
                $cover_letter, $expected_salary
            );
            $stmt->execute();
            $transaction_id = $conn->insert_id;
            
            // Store application details in job_applications table
            $app_stmt = $conn->prepare("
                INSERT INTO job_applications (
                    transaction_id, job_id, applicant_id, resume_file, resume_url,
                    portfolio_url, linkedin_url, cover_letter, expected_salary, 
                    availability_date, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $app_stmt->bind_param("iiisssssds", 
                $transaction_id, $job_id, $user_id, $resume_file, '',
                $portfolio_url, $linkedin_url, $cover_letter, $expected_salary, $availability_date
            );
            $app_stmt->execute();
            
            // Generate payment code for service fee
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
            
            // Create notification for employer
            $notification_message = "📝 New Job Application!\n\n";
            $notification_message .= "Position: {$job['title']}\n";
            $notification_message .= "Applicant: $full_name\n";
            $notification_message .= "Email: $email\n";
            $notification_message .= "Phone: $phone\n";
            $notification_message .= "Applied: " . date('M d, Y H:i') . "\n";
            $notification_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $notification_message .= "Click to view full application details.";
            
            $conn->query("
                INSERT INTO notifications (user_id, title, message, link, created_at) 
                VALUES ({$job['company_id']}, '📝 New Job Application', 
                '$notification_message', 
                '/broker_system/company/applications.php?job_id=$job_id', NOW())
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

<!-- HTML form remains the same as before -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for <?php echo htmlspecialchars($job['title']); ?> - Ethio Brokerplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Your existing styles */
        .apply-container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .job-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            color: white;
        }
        .job-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .company-name { font-size: 14px; opacity: 0.9; margin-bottom: 16px; }
        .job-salary { font-size: 32px; font-weight: 800; margin-top: 16px; }
        .job-salary small { font-size: 14px; font-weight: normal; opacity: 0.8; }
        
        .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid #e2e8f0; display: flex; align-items: center; gap: 10px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
        .form-group.full-width { grid-column: span 2; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 13px; }
        .required { color: #ef4444; }
        .input-wrapper { position: relative; }
        .input-wrapper i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        input, select, textarea { width: 100%; padding: 12px 16px 12px 42px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 14px; transition: all 0.3s; }
        textarea { padding: 12px 16px; min-height: 120px; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        
        .file-upload { border: 2px dashed #e2e8f0; border-radius: 14px; padding: 25px; text-align: center; cursor: pointer; background: #f8fafc; }
        .file-upload:hover { border-color: #667eea; background: #eef2ff; }
        .file-upload i { font-size: 36px; color: #667eea; margin-bottom: 10px; display: block; }
        input[type="file"] { display: none; }
        
        .payment-box { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-radius: 20px; padding: 20px; text-align: center; margin-top: 24px; }
        .payment-amount { font-size: 32px; font-weight: 800; color: #059669; }
        .btn-submit { width: 100%; padding: 16px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 24px; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(102,126,234,0.4); }
        .alert-error { background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #dc2626; }
        .info-text { font-size: 11px; color: #64748b; margin-top: 6px; }
        
        .radio-group { display: flex; gap: 24px; align-items: center; padding: 10px 0; }
        .radio-option { display: flex; align-items: center; gap: 8px; }
        .radio-option input { width: 18px; height: 18px; margin: 0; }
        
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
            .job-title { font-size: 22px; }
            .job-salary { font-size: 24px; }
            .card { padding: 20px; }
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
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-title"><i class="fas fa-file-alt"></i> Job Application</div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="first_name" required placeholder="First name" value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="last_name" required placeholder="Last name" value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Age <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-alt"></i>
                        <input type="number" name="age" required placeholder="18-100" min="18" max="100" value="<?php echo htmlspecialchars($user_data['age'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="gender" value="Male" <?php echo ($user_data['gender'] ?? '') == 'Male' ? 'checked' : ''; ?>> Male
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="gender" value="Female" <?php echo ($user_data['gender'] ?? '') == 'Female' ? 'checked' : ''; ?>> Female
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" required placeholder="you@example.com" value="<?php echo htmlspecialchars($user_data['email'] ?? $_SESSION['user_email']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Phone Number <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" name="phone" required placeholder="+251912345678" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
                    </div>
                    <div class="info-text">Format: +251912345678</div>
                </div>
                
                <div class="form-group full-width">
                    <label>Cover Letter <span class="required">*</span></label>
                    <textarea name="cover_letter" required placeholder="Introduce yourself, explain why you're interested in this position, and highlight your relevant skills and experience... (Minimum 50 characters)"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Portfolio/Website URL (Optional)</label>
                    <div class="input-wrapper">
                        <i class="fas fa-globe"></i>
                        <input type="url" name="portfolio_url" placeholder="https://your-portfolio.com">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>LinkedIn Profile (Optional)</label>
                    <div class="input-wrapper">
                        <i class="fab fa-linkedin"></i>
                        <input type="url" name="linkedin_url" placeholder="https://linkedin.com/in/yourprofile">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Expected Salary (ETB/month)</label>
                    <div class="input-wrapper">
                        <i class="fas fa-money-bill-wave"></i>
                        <input type="number" name="expected_salary" step="100" value="<?php echo $job['price']; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Availability Date</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-check"></i>
                        <input type="date" name="availability_date">
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label>Resume/CV <span class="required">*</span></label>
                    <div class="file-upload" onclick="document.getElementById('resumeFile').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Click to upload your Resume/CV</p>
                        <small>PDF, DOC, or DOCX (Max 5MB)</small>
                    </div>
                    <input type="file" name="resume" id="resumeFile" accept=".pdf,.doc,.docx" required>
                    <div id="fileName" style="display: none; margin-top: 10px; padding: 8px; background: #d1fae5; border-radius: 8px; text-align: center;">
                        <i class="fas fa-check-circle"></i> <span id="selectedFileName"></span>
                    </div>
                </div>
            </div>
            
            <div class="payment-box">
                <div style="font-size: 13px; color: #065f46;"><i class="fas fa-shield-alt"></i> Service Fee (<?php echo $commissionPercent; ?>%)</div>
                <div class="payment-amount"><?php echo formatMoney($serviceFee); ?></div>
                <div style="font-size: 11px; color: #065f46; margin-top: 8px;">Secure payment held in escrow until job completion</div>
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Submit Application & Pay Service Fee
            </button>
        </form>
    </div>
</div>

<script>
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