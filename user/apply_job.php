<?php
// user/apply_job.php - Apply for Job with Payment

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/validation.php';

requireLogin();

$page_title = 'Apply for Job';
ob_start();

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$job_id = sanitizeInt($_GET['id'] ?? 0);
$error = '';
$success = '';

// Get job details
$job = $conn->query("
    SELECT l.*, u.full_name as company_name, u.id as company_id,
           l.admin_deposit_percent, l.admin_commission_percent
    FROM listings l
    JOIN users u ON l.seller_id = u.id
    WHERE l.id = $job_id 
    AND l.type = 'job' 
    AND l.status = 'active' 
    AND l.approval_status = 'approved'
")->fetch_assoc();

if (!$job) {
    header('Location: jobs.php');
    exit;
}

// Check if already applied
$existing = $conn->query("
    SELECT id FROM transactions 
    WHERE listing_id = $job_id AND buyer_id = $user_id
");
if ($existing->num_rows > 0) {
    $existing_txn = $existing->fetch_assoc();
    header("Location: transaction.php?id={$existing_txn['id']}");
    exit;
}

// Calculate payment amounts
$depositPercent = $job['admin_deposit_percent'] ?? getSetting("deposit_percent_job", 30);
$commissionPercent = $job['admin_commission_percent'] ?? getSetting("commission_percent_job", 15);
$depositAmount = $job['price'] * ($depositPercent / 100);
$commissionAmount = $job['price'] * ($commissionPercent / 100);
$totalUpfront = $depositAmount + $commissionAmount;
$remainingAmount = $job['price'] - $depositAmount;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cover_letter = sanitizeString($_POST['cover_letter'] ?? '');
    $expected_salary = sanitizeFloat($_POST['expected_salary'] ?? $job['price']);
    
    $errors = [];
    
    if (empty($cover_letter)) {
        $errors[] = "Please provide a cover letter explaining why you're a good fit";
    }
    if (strlen($cover_letter) < 50) {
        $errors[] = "Cover letter must be at least 50 characters";
    }
    if (strlen($cover_letter) > 5000) {
        $errors[] = "Cover letter must not exceed 5000 characters";
    }
    if ($expected_salary < 0) {
        $errors[] = "Please enter a valid expected salary";
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Create transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    listing_id, buyer_id, seller_id, total_amount, 
                    deposit_amount, commission_amount, remaining_balance, 
                    status, created_at, cover_letter, expected_salary
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'awaiting_buyer_deposit', NOW(), ?, ?)
            ");
            $stmt->bind_param("iiiddddsd", 
                $job_id, $user_id, $job['company_id'], 
                $job['price'], $depositAmount, $commissionAmount, $remainingAmount,
                $cover_letter, $expected_salary
            );
            $stmt->execute();
            $transaction_id = $conn->insert_id;
            
            // Generate payment code
            do {
                $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                $code_check = $conn->query("SELECT id FROM payment_codes WHERE code = '$payment_code'");
            } while ($code_check->num_rows > 0);
            
            $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            
            // Store payment code
            $stmt2 = $conn->prepare("
                INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status) 
                VALUES (?, ?, ?, ?, 'deposit_buyer', ?, 'pending')
            ");
            $stmt2->bind_param("siids", $payment_code, $transaction_id, $totalUpfront, $user_id, $expires_at);
            $stmt2->execute();
            
            // Create notification for company
            $conn->query("
                INSERT INTO notifications (user_id, title, message, created_at) 
                VALUES ({$job['company_id']}, 'New Job Application', 
                'A new application has been submitted for {$job['title']}', NOW())
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

<style>
    .apply-container { max-width: 800px; margin: 0 auto; }
    .job-header { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 24px; padding: 28px; color: white; margin-bottom: 28px; }
    .job-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
    .company-name { font-size: 14px; opacity: 0.9; margin-bottom: 16px; }
    .job-salary { font-size: 24px; font-weight: 700; margin-top: 16px; }
    
    .card { background: white; border-radius: 24px; padding: 28px; margin-bottom: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .form-group { margin-bottom: 24px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 14px; }
    .required { color: #ef4444; }
    input, textarea { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.3s; }
    input:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
    textarea { resize: vertical; min-height: 150px; }
    
    .payment-breakdown { background: #f8fafc; border-radius: 20px; padding: 20px; margin-bottom: 24px; }
    .breakdown-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
    .breakdown-item:last-child { border-bottom: none; }
    .breakdown-item.total { font-weight: 700; font-size: 18px; margin-top: 8px; padding-top: 12px; border-top: 2px solid #e2e8f0; }
    
    .btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
    
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-error { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }
    .info-text { font-size: 11px; color: #64748b; margin-top: 6px; }
    
    @media (max-width: 640px) {
        .job-title { font-size: 22px; }
        .job-salary { font-size: 20px; }
        .card { padding: 20px; }
    }
</style>

<div class="apply-container">
    <!-- Job Header -->
    <div class="job-header">
        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
        <div class="company-name"><i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?></div>
        <div class="job-salary"><?php echo formatMoney($job['price']); ?>/month</div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Application Form -->
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;"><i class="fas fa-file-alt"></i> Job Application</h2>
        
        <form method="POST">
            <div class="form-group">
                <label>Cover Letter <span class="required">*</span></label>
                <textarea name="cover_letter" required placeholder="Introduce yourself, explain why you're interested in this position, and highlight your relevant skills and experience..."></textarea>
                <div class="info-text">Minimum 50 characters. Be specific about how you can contribute to the company.</div>
            </div>
            
            <div class="form-group">
                <label>Expected Salary (ETB/month)</label>
                <input type="number" name="expected_salary" step="100" value="<?php echo $job['price']; ?>" min="0">
                <div class="info-text">Your expected monthly salary (optional)</div>
            </div>
            
            <!-- Payment Breakdown -->
            <div class="payment-breakdown">
                <h3 style="font-size: 16px; margin-bottom: 16px;">Payment Summary</h3>
                <div class="breakdown-item">
                    <span>Monthly Salary</span>
                    <span><?php echo formatMoney($job['price']); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Deposit (<?php echo $depositPercent; ?>%)</span>
                    <span><?php echo formatMoney($depositAmount); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Service Fee (<?php echo $commissionPercent; ?>%)</span>
                    <span><?php echo formatMoney($commissionAmount); ?></span>
                </div>
                <div class="breakdown-item total">
                    <span>You Pay Today (Deposit + Fee)</span>
                    <span><?php echo formatMoney($totalUpfront); ?></span>
                </div>
                <div class="breakdown-item">
                    <span>Remaining (paid after job completion)</span>
                    <span><?php echo formatMoney($remainingAmount); ?></span>
                </div>
            </div>
            
            <div class="info-text" style="background: #dbeafe; padding: 12px; border-radius: 12px; margin-bottom: 20px;">
                <i class="fas fa-shield-alt"></i> <strong>Secure Escrow Payment</strong><br>
                Your deposit and fee are held in escrow until you confirm job completion. You're protected against fraud.
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Submit Application & Pay
            </button>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
?>